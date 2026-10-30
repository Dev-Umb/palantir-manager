const test=require('node:test'),assert=require('node:assert/strict'),fs=require('node:fs'),vm=require('node:vm'),path=require('node:path');
const root=path.join(__dirname,'..');
function load(api){let p;const visits=[];vm.runInNewContext(fs.readFileSync(path.join(root,'pages/tools/tools.js'),'utf8'),{Page:d=>p=d,require:n=>n.endsWith('/api')?api:n.endsWith('/page')?{navigate:url=>visits.push(url)}:{},setTimeout,clearTimeout});p.data=JSON.parse(JSON.stringify(p.data));p.setData=d=>Object.assign(p.data,d);p.isCurrent=()=>true;return {p,visits};}
test('personal notification center combines approval and AI results without auto-reading or losing existing messages',async()=>{
 const calls=[];const {p,visits}=load({query:o=>new URLSearchParams(o).toString(),page:async()=>({notifications:{data:[{id:1}],last_page:2},tenderNotifications:{data:[{id:2}],last_page:3}}),request:async url=>{calls.push(url);return {notices:{data:[{id:3,version_id:4,message:'审批通过'}],last_page:2},aiDraftNotices:{data:[{id:'draft',title:'住宿费'}],last_page:3}};}});
 await p.loadNotifications();assert.equal(p.data.items[0].id,1);assert.equal(p.data.tenders[0].id,2);assert.equal(p.data.reimbursementNotices[0].message,'审批通过');assert.equal(p.data.aiDraftNotices[0].id,'draft');assert.equal(calls.length,1);assert.doesNotMatch(calls[0],/read/);
 p.openReimbursementNotice({currentTarget:{dataset:{id:4}}});p.openAiDraftNotice({currentTarget:{dataset:{id:'draft'}}});p.openAiDraftNotice({currentTarget:{dataset:{id:'draft',version:5}}});assert.deepEqual(visits,['/pages/reimbursements/detail?id=4','/pages/reimbursements/form?draft=draft','/pages/reimbursements/detail?id=5']);
 p.refresh=()=>{};p.noticePage({currentTarget:{dataset:{kind:'ai',step:1}}});assert.equal(p.data.aiNoticePage,2);assert.equal(p.data.reimbursementPage,1);
});
test('reimbursement notice failure preserves project and tender notices and exposes retry',async()=>{
 const {p}=load({query:()=>'',page:async()=>({notifications:{data:[{id:1}],last_page:1},tenderNotifications:{data:[{id:2}],last_page:1}}),request:async()=>{throw Error('网络异常');}});await p.loadNotifications();assert.equal(p.data.items.length,1);assert.equal(p.data.tenders.length,1);assert.equal(p.data.reimbursementNoticesError,'网络异常');
});
test('draft source and unread dot replace verbose header while invoice fields and submission stay visible',()=>{
 const list=fs.readFileSync(path.join(root,'pages/reimbursements/index.wxml'),'utf8'),form=fs.readFileSync(path.join(root,'pages/reimbursements/form.wxml'),'utf8'),mine=fs.readFileSync(path.join(root,'pages/mine/mine.wxml'),'utf8');
 assert.match(list,/wx:if="{{item.unread}}" class="draft-unread-dot"/);assert.match(list,/AI批量识别创建/);assert.doesNotMatch(list,/bindtap="notices"/);assert.match(mine,/data-kind="notifications" bindtap="openTool"/);
 assert.doesNotMatch(form,/AI 已整理|{{batchIssues}}/);for(const text of ['费用说明','金额（元）','开票日期','bindtap="submit"','bindtap="applyInvoice"'])assert.ok(form.includes(text),text);
});
