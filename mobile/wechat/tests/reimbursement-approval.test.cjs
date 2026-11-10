const test=require('node:test'),assert=require('node:assert/strict'),fs=require('node:fs'),vm=require('node:vm'),path=require('node:path');
const root=path.join(__dirname,'..');
function setup(name='reimbursements/detail'){
 let user={id:1,roles:[{name:'admin'}],permissions:['reimbursements.submit']},epoch=0;
 const clears=[],calls=[],visits=[],modals=[];
 const record={id:7,title:'交通费',total_cents:100,status:'pending',node_index:0,can_decide:true,items:[],flow:{nodes:[{name:'经理审批',people:[],mode:'all'}]}};
 const api={user:()=>user,epoch:()=>epoch,onClear:fn=>{clears.push(fn);return ()=>{};},session:async()=>user,query:o=>new URLSearchParams(o).toString(),dashboard:async()=>({}),request:async(url,method,data)=>{calls.push({url,method,data});return url.includes('/approvals?')?{records:{data:[record],total:1,last_page:1}}:{record:{...record,can_decide:method!=='POST'}};}};
 const wx={showModal:o=>{modals.push(o);o.success({confirm:true});},navigateTo:o=>{visits.push(o.url);o.complete();},reLaunch(){}};
 function module(file){const m={exports:{}};vm.runInNewContext(fs.readFileSync(path.join(root,file),'utf8'),{module:m,require:()=>api,wx});return m.exports;}
 const oa=module('pages/reimbursements/common.js'),u=module('utils/page.js');let p;
 vm.runInNewContext(fs.readFileSync(path.join(root,'pages',name+'.js'),'utf8'),{Page:d=>p=d,require:n=>n.endsWith('/api')?api:n.endsWith('/page')?u:n.endsWith('/common')||n==='./common'?oa:n.endsWith('/business')?{home:()=>({})}:{},wx,setTimeout,clearTimeout});
 p.data=structuredClone(p.data);p.setData=o=>Object.assign(p.data,o);p.onLoad({id:7,approval:'1'});
 return {p,api,wx,record,calls,visits,modals,role(roles){user={...user,roles};},clear(){user=null;epoch++;clears.forEach(fn=>fn());}};
}
const event=decision=>({currentTarget:{dataset:{decision}}});
test('administrator workbench entry preserves ordinary create and hides after refreshed role loss',async()=>{
 const h=setup('index/index');await h.p.refresh();assert.equal(h.p.data.canApprove,true);assert.equal(h.p.data.canReimburse,true);h.p.approvals();assert.deepEqual(h.visits,['/pages/reimbursements/index?approval=1']);h.role([]);await h.p.refresh();assert.equal(h.p.data.canApprove,false);assert.equal(h.p.data.canReimburse,true);h.p.approvals();assert.equal(h.visits.length,1);
});
test('approval inbox loads actual server totals, empty and denied states never leave old rows',async()=>{
 const h=setup('reimbursements/index');await h.p.refresh();assert.equal(h.p.data.total,1);assert.match(h.calls[0].url,/^\/reimbursements\/approvals\?page=1$/);h.api.request=async()=>({records:{data:[],total:0,last_page:1}});await h.p.refresh();assert.equal(h.p.data.rows.length,0);assert.equal(h.p.data.total,0);h.role([]);await h.p.refresh();assert.match(h.p.data.error,/无权/);assert.equal(h.p.data.rows.length,0);
});
test('ordinary assigned account retains detail and files without mobile decision actions',async()=>{
 const h=setup();h.role([]);await h.p.refresh();assert.equal(h.p.data.record.id,7);assert.equal(h.p.data.canApprove,false);await h.p.decide(event('approve'));assert.equal(h.calls.filter(c=>c.method==='POST').length,0);
});
test('approve confirmation commits original node once and updates server result',async()=>{
 const h=setup();await h.p.refresh();let confirm;h.wx.showModal=o=>confirm=o.success;const pending=h.p.decide(event('approve'));await h.p.decide(event('approve'));confirm({confirm:true});await pending;const posts=h.calls.filter(c=>c.method==='POST');assert.equal(posts.length,1);assert.equal(posts[0].data.node_index,0);assert.equal(posts[0].data.decision,'approve');assert.equal(h.p.data.canApprove,false);assert.equal(h.p.data.decisionResult,'已提交通过意见');assert.equal(h.p.data.busy,false);
});
test('rejection requires reason, confirmation cancellation does not send, valid rejection sends trimmed note',async()=>{
 const h=setup();await h.p.refresh();await h.p.decide(event('reject'));assert.match(h.p.data.error,/驳回原因/);assert.equal(h.modals.length,0);h.p.note({detail:{value:'  补充发票  '}});h.wx.showModal=o=>o.success({confirm:false});await h.p.decide(event('reject'));assert.equal(h.calls.filter(c=>c.method==='POST').length,0);h.wx.showModal=o=>o.success({confirm:true});await h.p.decide(event('reject'));assert.equal(h.calls.at(-1).data.note,'补充发票');assert.equal(h.p.data.decisionNote,'');
});
test('timeout retains note and prevents retry until explicit refresh; stale node refreshes state',async()=>{
 const h=setup();await h.p.refresh();const original=h.api.request;h.p.note({detail:{value:'核对金额'}});let posts=0;h.api.request=async(url,method)=>{if(method==='POST'){posts++;throw Error('timeout');}return original(url,method);};await h.p.decide(event('approve'));assert.match(h.p.data.error,/结果尚未确认/);assert.equal(h.p.data.decisionNote,'核对金额');await h.p.decide(event('approve'));assert.equal(posts,1);await h.p.refresh();h.api.request=async(url,method)=>{if(method==='POST')throw Object.assign(Error('审批状态已变化'),{status:409});return {record:{...h.record,can_decide:false,status:'approved'}};};await h.p.decide(event('approve'));assert.equal(h.p.data.record.status,'approved');assert.equal(h.p.data.canApprove,false);assert.match(h.p.data.error,/状态已变化/);
});
test('validation keeps editable opinion and refreshed role revocation stops submission',async()=>{
 const h=setup();await h.p.refresh();h.p.note({detail:{value:'说明'}});h.api.request=async()=>{throw Object.assign(Error('意见不符合要求'),{status:422});};await h.p.decide(event('reject'));assert.equal(h.p.data.canApprove,true);assert.equal(h.p.data.decisionNote,'说明');h.api.session=async()=>{h.role([]);return h.api.user();};await h.p.decide(event('approve'));assert.equal(h.p.data.canApprove,false);assert.equal(h.p.data.record,null);
});
test('logout during confirmation or POST discards old actions and results',async()=>{
 const h=setup();await h.p.refresh();let confirm;h.wx.showModal=o=>confirm=o.success;const pending=h.p.decide(event('approve'));h.clear();confirm({confirm:true});await pending;assert.equal(h.calls.filter(c=>c.method==='POST').length,0);assert.equal(h.p.data.record,null);
 const g=setup();await g.p.refresh();let finish;g.api.request=()=>new Promise(r=>finish=r);const saving=g.p.decide(event('approve'));await new Promise(r=>setImmediate(r));g.clear();finish({record:g.record});await saving;assert.equal(g.p.data.record,null);assert.equal(g.p.data.decisionResult,'');assert.equal(g.p.data.canApprove,false);
});
test('unload or logout discards late inbox response',async()=>{
 const h=setup('reimbursements/index');let finish;h.api.request=()=>new Promise(r=>finish=r);const pending=h.p.refresh();await new Promise(r=>setImmediate(r));h.clear();finish({records:{data:[h.record],total:1,last_page:1}});await pending;assert.equal(h.p.data.rows.length,0);assert.equal(h.p.data.total,0);
});

test('compact detail retains invoice actions without verbose recognition text',()=>{const wxml=fs.readFileSync(path.join(root,'pages/reimbursements/detail.wxml'),'utf8');assert.match(wxml,/record.expenseSummary.groups/);assert.match(wxml,/data-i="{{file.i}}"/);assert.match(wxml,/bindtap="zip"/);assert.doesNotMatch(wxml,/expense.description|expense.evidence_source.candidate_id|expense.invoice_date/);});

test('home approval entry sits between collection overview and reimbursement actions',()=>{
 const wxml=fs.readFileSync(path.join(root,'pages/index/index.wxml'),'utf8');
 const overview=wxml.indexOf('class="overview box"'),entry=wxml.indexOf('class="oa-entry"'),forms=wxml.indexOf('reimbursement-actions');
 assert.ok(overview>=0 && overview<entry && entry<forms);
 assert.match(wxml,/<button wx:if="{{canApprove}}" class="oa-entry" bindtap="approvals">/);
 for(const action of ['createReimbursement','batchReimbursement','create','projects'])assert.ok(wxml.includes('bindtap="'+action+'"'));
});
test('approval cards foreground applicant and amount while preserving ordinary cards and detail navigation',()=>{
 const wxml=fs.readFileSync(path.join(root,'pages/reimbursements/index.wxml'),'utf8');
 const approval=wxml.slice(wxml.indexOf('<block wx:if="{{approval}}">'),wxml.indexOf('<block wx:else>'));
 assert.ok(approval.indexOf('item.owner.name')<approval.indexOf('item.title'));
 assert.ok(approval.indexOf('item.total')<approval.indexOf('item.title'));
 for(const field of ['item.title','item.note','item.type','item.date','item.state','item.reimbursement_id','item.number','item.nodes[item.node_index].name'])assert.ok(approval.includes(field),field);
 assert.match(wxml,/data-id="{{item.id}}" bindtap="open"/);
 const ordinary=wxml.slice(wxml.indexOf('<block wx:else>'));
 for(const capability of ['draft-source','draft-unread-dot','catchtap="deleteDraft"','record-amount','继续编辑'])assert.ok(ordinary.includes(capability),capability);
 assert.doesNotMatch(approval,/bindtap="decide"/);
});
