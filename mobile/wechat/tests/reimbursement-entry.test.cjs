const test=require('node:test'),assert=require('node:assert/strict'),fs=require('node:fs'),vm=require('node:vm'),path=require('node:path');
const root=path.join(__dirname,'..');
function page(name,permissions){let result;const visits=[];const user={permissions};const api={user:()=>user,dashboard:async()=>({})};const u={navigate:url=>visits.push(url),run:async(p,fn)=>Object.assign(p.data,await fn())};vm.runInNewContext(fs.readFileSync(path.join(root,`pages/${name}/${name}.js`),'utf8'),{Page:p=>result=p,require:n=>n.endsWith('/api')?api:n.endsWith('/business')?{home:()=>({})}:u});return {p:result,visits};}
test('home starts the form directly, while personal page retains reimbursement queries',async()=>{
 const {p,visits}=page('index',['reimbursements.submit','object.project.create']);await p.refresh();assert.equal(p.data.canReimburse,true);assert.equal(p.data.canCreate,true);p.createReimbursement();assert.deepEqual(visits,['/pages/reimbursements/form']);
 const home=fs.readFileSync(path.join(root,'pages/index/index.wxml'),'utf8');assert.match(home,/class="primary reimbursement-create" bindtap="createReimbursement"/);assert.match(home,/bindtap="create"/);assert.match(home,/bindtap="projects"/);assert.doesNotMatch(home,/bindtap="reimbursement"/);
 const account=page('mine',[]);account.p.reimbursements();assert.deepEqual(account.visits,['/pages/reimbursements/index']);const mine=fs.readFileSync(path.join(root,'pages/mine/mine.wxml'),'utf8');for(const label of ['我的报销','通知中心','账号信息','退出登录'])assert.ok(mine.includes(label));
});
test('home does not expose or navigate a create entry without submit permission',async()=>{
 const {p,visits}=page('index',[]);await p.refresh();assert.equal(p.data.canReimburse,false);p.createReimbursement();assert.deepEqual(visits,[]);assert.match(fs.readFileSync(path.join(root,'pages/index/index.wxml'),'utf8'),/wx:if="{{canReimburse}}"/);
});
