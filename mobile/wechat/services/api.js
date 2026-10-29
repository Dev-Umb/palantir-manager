const ORIGIN = 'https://palantir.umb.ink';
let cookies = [], csrfToken = '', version = '', user = null, generation = 0, dashboardPath = '/', wechatConfigured = false;
const listeners = new Set();
function clear() {
  cookies = []; csrfToken = ''; version = ''; user = null; generation++;
  listeners.forEach(fn => fn());
}
function url(path) {
  if (typeof path !== 'string' || !/^\/(?!\/)/.test(path) || /[\\\r\n]/.test(path)) throw new Error('请求地址无效');
  return ORIGIN + path;
}
function rememberCookies(response) {
  const header = response.header || {};
  const raw = response.cookies || header['Set-Cookie'] || header['set-cookie'] || [];
  const lines = Array.isArray(raw) ? raw : raw.split(/,(?=\s*[^;,=\s]+=)/);
  lines.forEach(line => {
    const parts = line.split(';').map(v => v.trim()), pair = parts.shift(), split = pair.indexOf('=');
    if (split < 1) return;
    const name = pair.slice(0, split), value = pair.slice(split + 1);
    const attributes = {};
    parts.forEach(part => { const i = part.indexOf('='); attributes[(i < 0 ? part : part.slice(0, i)).toLowerCase()] = i < 0 ? true : part.slice(i + 1); });
    if (attributes.domain && attributes.domain.replace(/^\./, '') !== 'palantir.umb.ink') return;
    const path = attributes.path || '/';
    const expires = attributes['max-age'] !== undefined ? Date.now() + Number(attributes['max-age']) * 1000 : attributes.expires ? Date.parse(attributes.expires) : Infinity;
    cookies = cookies.filter(c => c.name !== name || c.path !== path);
    if (expires > Date.now()) cookies.push({ name, value, path, expires });
  });
}
function request(path, method = 'GET', data, inertia = false, binary = false, uploadPath) {
  const target = url(path), epoch = generation;
  const pathname = path.split('?')[0];
  const cookie = cookies.filter(c => c.expires > Date.now() && (pathname === c.path || pathname.startsWith(c.path.endsWith('/') ? c.path : c.path + '/'))).sort((a,b)=>b.path.length-a.path.length).map(c=>c.name+'='+c.value).join('; ');
  const send = options => {if (!uploadPath) return wx.request(options);delete options.header['Content-Type'];delete options.responseType;delete options.data;delete options.method;return wx.uploadFile({...options,filePath:uploadPath,name:'file',formData:data||{},timeout:120000});};
  return new Promise((resolve, reject) => send({
    url: target, method, data, timeout: 20000, responseType: binary ? 'arraybuffer' : 'text',
    header: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest', ...(cookie ? { Cookie: cookie } : {}), ...(csrfToken ? {'X-CSRF-TOKEN': csrfToken} : {}), ...(inertia ? {'X-Inertia':'true', ...(version ? {'X-Inertia-Version':version} : {})} : {}) },
    success(response) {
      if (epoch !== generation) return reject(new Error('账号已变更，请刷新页面'));
      rememberCookies(response);
      const status = response.statusCode; let body = response.data;
      if (typeof body === 'string') { try { body = JSON.parse(body); } catch (_) {} }
      const contentType=(response.header||{})['Content-Type'] || (response.header||{})['content-type'] || '';
      if (binary && status === 200 && !/text\/html/i.test(contentType)) return resolve(body);
      if (status === 401 || status === 419 || (body && body.component === 'Auth/Login') || (typeof body === 'string' && /"component"\s*:\s*"Auth\/Login"/.test(body.replace(/&quot;/g,'"'))) || (binary && /text\/html/i.test(contentType))) {
        clear(); return reject(new Error('登录已失效，请重新登录'));
      }
      if (status >= 200 && status < 300 && body && typeof body === 'object') return resolve(body);
      const messages = {413:'文件过大，请选择不超过 100 MB 的合同文件',403:'当前账号无权访问',404:path === '/miniapp/session' ? '小程序登录接口尚未部署，请稍后重试' : '记录不存在或已被删除',429:'操作过于频繁，请稍后重试'};
      const error = new Error(messages[status] || (body && body.errors && Object.values(body.errors).flat().join('；')) || (body && body.message) || '服务暂不可用，请重试');
      error.status = status; reject(error);
    },
    fail(error) { reject(new Error(/domain list/i.test(error && error.errMsg || '') ? '请先将 palantir.umb.ink 配置为小程序 request 合法域名，再刷新开发者工具配置' : '网络连接失败，请检查网络后重试')); }
  }));
}
async function session() {
  const result = await request('/miniapp/session');
  csrfToken = result.csrfToken; version = result.version; user = result.user; dashboardPath = result.dashboardPath || '/'; wechatConfigured = result.wechatConfigured === true;
  return user;
}
async function login(email, password) {
  clear(); await session();
  const result = await request('/miniapp/session', 'POST', {email:email.trim(), password});
  csrfToken = result.csrfToken; version = result.version; user = result.user; dashboardPath = result.dashboardPath || '/'; wechatConfigured = result.wechatConfigured === true;
  return user;
}
async function wechatLogin(bindingCode = '') {
  clear(); await session();
  if (!wechatConfigured) throw new Error('微信登录尚未配置，请联系管理员');
  const epoch = generation;
  const code = await new Promise((resolve,reject)=>wx.login({timeout:10000,success:r=>r.code?resolve(r.code):reject(new Error('未能获取微信凭证，请重试')),fail:()=>reject(new Error('微信登录失败，请重试'))}));
  if (epoch !== generation) throw new Error('账号已变更，请重新登录');
  const result = await request('/miniapp/wechat/login','POST',{code,...(bindingCode?{binding_code:bindingCode}:{})});
  csrfToken=result.csrfToken;version=result.version;user=result.user;dashboardPath=result.dashboardPath||'/';
  return user;
}
async function logout() {
  try { await request('/miniapp/session','DELETE'); } finally { clear(); }
}
async function page(path) {
  if (!user) throw new Error('请先登录');
  try {
    const result = await request(path, 'GET', undefined, true);
    if (!result.props) throw new Error('服务返回格式异常');
    return result.props;
  } catch (error) {
    if (error.status !== 409) throw error;
    await session();
    if (!user) throw new Error('登录已失效，请重新登录');
    const result = await request(path, 'GET', undefined, true);
    if (!result.props) throw new Error('服务返回格式异常');
    return result.props;
  }
}
function query(values) {return Object.entries(values).filter(([,v])=>v!=='' && v!==undefined).map(([k,v])=>encodeURIComponent(k)+'='+encodeURIComponent(v)).join('&');}
module.exports = {upload:(path,filePath,fields)=>request(path,'POST',fields,false,false,filePath),request, page, session, login, wechatLogin, wechatConfigured:()=>wechatConfigured, logout, clear, query, dashboard:()=>page(dashboardPath), user:()=>user, epoch:()=>generation, onClear(fn){listeners.add(fn); return ()=>listeners.delete(fn);}};
