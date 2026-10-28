const api = require('./api');
const money = value => value === null || value === undefined || value === '' || !Number.isFinite(Number(value)) ? '—' : Number(value).toLocaleString('en-US', {minimumFractionDigits:2, maximumFractionDigits:2});
function rows(record, fields) {return (fields || []).filter(f => !['files','file'].includes(f.type)).map(f=>({key:f.key,label:f.label || f.key,value:record.display && record.display[f.key] !== undefined ? String(record.display[f.key] ?? '—') : String((record.payload && record.payload[f.key]) ?? '—')}));}
function project(record) {
  const p=record.payload || {}, d=record.display || {};
  return {...record,statusTone:p.overall_status==='已完成'?'blue':p.overall_status==='投标中'?'orange':'green',name:p.name || record.title, status:p.overall_status || '未维护状态',customer:d.customer_id || '未关联客户',owner:d.business_owner_user_id || '—',remark:p.remark || '',amountText:money(p.contract_amount),incurredText:money(p.occurred_amount),paidText:money(p.paid_amount),unpaidText:money(p.unpaid_amount),progress:p.occurred_amount === null || p.occurred_amount === undefined || p.occurred_amount === '' || Number(p.occurred_amount) === 0 || p.paid_amount === null || p.paid_amount === undefined || p.paid_amount === '' ? '—' : (Number(p.paid_amount)/Number(p.occurred_amount)*100).toFixed(1)+'%',lastPayment:d.last_payment_date || p.last_payment_date || '—'};
}
async function list(object, values={}) {return api.page('/objects/'+object+'?'+api.query({per_page:20,...values}));}
async function detail(id) {
  const result = await list('project',{record:id,per_page:1});
  if (!result.selectedRecord) throw new Error('项目不存在或无权查看');
  return {...result, project:project(result.selectedRecord)};
}
function editable(fields) { return fields.filter(f=>!f.readonly && !f.system && !['readonly','lookup','derived','files','file'].includes(f.type)); }
function savePayload(record, values, fields) {
  const payload = {...(record && record.payload || {}),...values};
  // File fields use upload tokens; omitting them preserves existing attachments on the server.
  fields.filter(f=>['file','files'].includes(f.type)).forEach(f=>delete payload[f.key]);
  return {payload};
}
function compactMoney(value) {
 if (value === null || value === undefined || value === '' || !Number.isFinite(Number(value))) return '—';
 const number=Number(value), absolute=Math.abs(number);
 return absolute>=100000000?(number/100000000).toFixed(2)+' 亿':absolute>=10000?(number/10000).toFixed(2)+' 万':money(number);
}
function home(props) {
 const panel=props.cockpit && props.cockpit.panels && props.cockpit.panels.project_amounts;
 const metrics=panel && panel.company || [];
 const metric=key=>metrics.find(m=>m.key===key);
 const unpaid=metric('unpaid_amount');
 return {unpaid:money(unpaid&&unpaid.value),occurred:compactMoney((metric('occurred_amount')||{}).value),paid:compactMoney((metric('paid_amount')||{}).value),projectCount:panel && panel.projects_count!==undefined?panel.projects_count:'—',coverage:unpaid&&unpaid.coverage?'已维护 '+unpaid.coverage.valid+'/'+unpaid.coverage.total:'',projects:(props.recentProjects||[]).slice(0,4).map(project),notices:(props.notificationRisks||[]).filter(n=>!n.read).slice(0,2),count:props.notificationUnreadCount||0};
}
module.exports={money,compactMoney,home,rows,project,list,detail,editable,savePayload};
