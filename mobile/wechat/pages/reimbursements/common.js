const api = require('../../services/api');
const states = {pending:'审批中',approved:'审批通过',rejected:'已驳回'};
function cents(value) {if(!/^(?:0|[1-9]\d{0,6})(\.\d{1,2})?$/.test(value))return null;const [a,b='']=String(value).split('.');const n=Number(a)*100+Number(b.padEnd(2,'0'));return n>0?n:null;}
function timestamp(value){const date=new Date(value);if(Number.isNaN(date.getTime()))return '—';const pad=n=>String(n).padStart(2,'0');return `${date.getFullYear()}-${pad(date.getMonth()+1)}-${pad(date.getDate())} ${pad(date.getHours())}:${pad(date.getMinutes())}`;}
function money(value){return (value/100).toFixed(2);}
function uuid(){return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g,c=>{const r=Math.floor(Math.random()*16);return (c==='x'?r:(r&3)|8).toString(16);});}
function shortDescription(value){return Array.from((value||'').split('\n')[0].trim()).slice(0,20).join('');}
function expenseSummary(items){
 const categories=[['通信费',/通信|话费|电信服务/],['名片费',/名片/],['邮费',/邮费|邮寄|快递|寄件|物流运单|快运/],['住宿费',/住宿|房费/],['加油费',/汽油|柴油|加油/],['交通费',/出租车|网约车|打车|火车票|机票|高铁|客运/],['停车费',/停车/],['过路费',/过路|通行费/],['餐费',/餐费|餐饮|用餐/]];
 const groups=[],byCategory=new Map(),files=[],seen=new Set();
 items.forEach((item,i)=>{
  const text=(item.description||'').split('\n')[0].trim(),matches=categories.filter(([,pattern])=>pattern.test(text));
  const category=matches.length===1?matches[0][0]:null;
  let group=category&&byCategory.get(category);
  if(!group){group={description:Array.from(category||text||'其他费用').slice(0,20).join(''),cents:0,count:0};groups.push(group);if(category)byCategory.set(category,group);}
  group.cents+=item.cents;group.count++;
  (item.attachments||[]).forEach((file,j)=>{if(!seen.has(file.id)){seen.add(file.id);files.push({...file,i,j,kind:file.mime==='application/pdf'?'PDF':'图片'});}});
 });
 return {groups:groups.map(g=>({...g,total:money(g.cents)})),files};
}
function display(r){return {...r,expenseSummary:expenseSummary(r.items||[]),versions:(r.versions||[]).map(v=>({...v,id:v.url.split('/').pop()})),state:states[r.status],total:money(r.total_cents),date:(r.created_at||'').slice(0,10),items:(r.items||[]).map(i=>({...i,total:money(i.cents),attachments:i.attachments.map(a=>({...a,kind:a.mime==='application/pdf'?'PDF':'图片'}))})),nodes:(r.flow?.nodes||[]).map((n,i)=>({...n,peopleText:n.people.map(p=>p.name).join('、'),state:i<r.node_index?'已通过':i===r.node_index?states[r.status]:'待处理',decisions:(r.decisions||[]).filter(d=>d.node===i).map(d=>({...d,label:d.decision==='approve'?'同意':'驳回'}))})),fixedCc:(r.flow?.cc||[]).map(p=>p.name).join('、')||'无',extraCc:(r.cc||[]).map(p=>p.name).join('、')||'无'};}
const files=new Set();api.onClear(()=>{files.forEach(p=>wx.getFileSystemManager().unlink({filePath:p,fail(){}}));files.clear();});
async function download(url,name){const epoch=api.epoch();const data=await api.request(url,'GET',undefined,false,true);if(epoch!==api.epoch())throw Error('账号已变化');const path=wx.env.USER_DATA_PATH+'/'+Date.now()+'-'+name.replace(/[\/\\\x00-\x1f]/g,'_');await new Promise((resolve,reject)=>wx.getFileSystemManager().writeFile({filePath:path,data,success:resolve,fail:()=>reject(Error('保存失败，请检查可用空间'))}));if(epoch!==api.epoch()){wx.getFileSystemManager().unlink({filePath:path,fail(){}});throw Error('账号已变化');}files.add(path);await new Promise((resolve,reject)=>wx.shareFileMessage({filePath:path,fileName:name,success:resolve,fail:()=>reject(Error('已取消或无法导出文件，可重试'))}));}
function validInvoiceDate(value){if(!/^\d{4}-\d{2}-\d{2}$/.test(value||''))return false;const d=new Date(value+'T00:00:00Z');return !Number.isNaN(d.getTime()) && d.toISOString().slice(0,10)===value;}
function invoiceDescription(previous,fields){const lines=previous.trim()?previous.trim().split('\n'):[];for(const [key,label] of [['invoice_number','发票号码'],['invoice_code','发票代码'],['buyer','购买方'],['seller','销售方']]){if(typeof fields[key]!=='string' || !fields[key].trim())continue;const line=label+'：'+fields[key].trim();if(!lines.includes(line))lines.push(line);}return lines.join('\n');}
function isAdmin(){const roles=api.user()?.roles||[];return roles.some(r=>r.name==='admin')&&!roles.some(r=>r.name==='timebook_operator');}
module.exports={shortDescription,expenseSummary,isAdmin,validInvoiceDate,invoiceDescription,timestamp,cents,money,uuid,display,download,states};
