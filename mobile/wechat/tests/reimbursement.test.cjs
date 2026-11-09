const test=require('node:test'),assert=require('node:assert/strict'),fs=require('node:fs'),vm=require('node:vm'),path=require('node:path');
const commonPath=path.join(__dirname,'../pages/reimbursements/common.js');
function common(api={onClear(){}}){const module={exports:{}};vm.runInNewContext(fs.readFileSync(commonPath,'utf8'),{module,require:()=>api,wx:{}});return module.exports;}
function load(name,api,wx={}){let p;vm.runInNewContext(fs.readFileSync(path.join(__dirname,'../pages/reimbursements/'+name+'.js'),'utf8'),{setTimeout,clearTimeout,console,Page:d=>p=d,require:n=>n.endsWith('/api')?api:n==='./common'?common(api):{unload(){},attach(){},requireLogin:()=>true,run(page,action){page.loaded=action().then(data=>page.setData(data));return page.loaded;}},wx});p.data=JSON.parse(JSON.stringify(p.data));p.setData=o=>Object.assign(p.data,o);return p;}
test('money is exact and invalid values cannot become submit totals',()=>{const c=common();assert.equal(c.cents('0.10')+c.cents('0.20'),30);for(const v of ['0','-1','0.001','1e2','','01','00.10'])assert.equal(c.cents(v),null);assert.equal(c.money(30),'0.30');});
test('submission denies missing invoices and retries same frozen payload after uncertain timeout',async()=>{const calls=[];let fail=true;const api={onClear(){},epoch:()=>1,request:async(p,m,data)=>{calls.push(JSON.parse(JSON.stringify(data)));if(fail)throw Error('timeout');return {record:{id:9}};}};let redirect;const p=load('form',api,{redirectTo:o=>redirect=o.url});p.allowed=true;p.key='one';p.data.title='差旅';p.data.note='拜访';p.data.items=[{description:'车票',amount:'0.10',attachments:[],failed:[]}];await p.submit();assert.equal(calls.length,0);p.data.items[0].attachments=[{id:'invoice'}];await p.submit();assert.equal(calls.length,1);assert.match(p.data.error,/同一申请/);p.data.title='改动';fail=false;await p.submit();assert.deepEqual(calls[0],calls[1]);assert.equal(redirect,'/pages/reimbursements/detail?id=9');});
test('subscription denial still submits and account change discards pending authorization',async()=>{let calls=0;const api={onClear(){},epoch:()=>1,request:async(p,m,data)=>{calls++;assert.equal(data.wechat_subscribed,false);return {record:{id:1}};}};const p=load('form',api,{requestSubscribeMessage:o=>o.fail(),redirectTo(){}});p.allowed=true;p.template='template';p.key='key';Object.assign(p.data,{title:'差旅',note:'说明',items:[{description:'车票',amount:'1',attachments:[{id:'one'}],failed:[]}]});await p.submit();assert.equal(calls,1);});
test('all reimbursement WXML handlers exist, historical IDs are precomputed and administrator-only approval controls',()=>{for(const name of ['index','form','detail','notices']){const p=load(name,{onClear(){}});const wxml=fs.readFileSync(path.join(__dirname,'../pages/reimbursements/'+name+'.wxml'),'utf8');for(const m of wxml.matchAll(/bind(?:tap|input|change)="([^"]+)"/g))assert.equal(typeof p[m[1]],'function',name+':'+m[1]);assert.doesNotMatch(wxml,/演示审批人|审批记录|\.split\(/);}const detail=fs.readFileSync(path.join(__dirname,'../pages/reimbursements/detail.wxml'),'utf8');assert.match(detail,/一键下载全部发票/);assert.match(detail,/class="eye-icon"/);assert.match(detail,/申请信息/);assert.match(detail,/wx:if="{{canApprove}}"/);assert.match(detail,/bindtap="decide"/);assert.doesNotMatch(detail,/data-key="info"/);});

test('empty expenses and changed account cannot submit a retained draft',async()=>{let calls=0;const api={onClear(){},epoch:()=>2,request:async()=>{calls++;return {record:{id:1}};}};const p=load('form',api,{redirectTo(){}});p.allowed=true;Object.assign(p.data,{title:'差旅',note:'说明',items:[]});await p.submit();assert.equal(calls,0);p.epoch=1;p.pending={submission_key:'old-user-draft'};await p.submit();assert.equal(calls,0);});
test('mobile cards retain status and amount hierarchy, form keeps persistent field labels',()=>{const list=fs.readFileSync(path.join(__dirname,'../pages/reimbursements/index.wxml'),'utf8');for(const text of ['record-footer','record-amount','item.status','item.reimbursement_id','data-scope="cc"','bindtap="open"'])assert.ok(list.includes(text),text);const form=fs.readFileSync(path.join(__dirname,'../pages/reimbursements/form.wxml'),'utf8');for(const label of ['报销标题','报销类型','报销说明','费用说明','金额（元）'])assert.ok(form.includes('<view class="field-label">'+label+'</view>'));assert.match(form,/合计金额/);});
test('notice dates are human readable with an invalid-date fallback',()=>{assert.equal(common().timestamp('2026-10-28T09:32:00'),'2026-10-28 09:32');assert.equal(common().timestamp('bad'),'—');});
test('invoice recognition preserves manual amount until confirmation and rejects stale results',async()=>{
 let epoch=1,confirm;const api={onClear(){},epoch:()=>epoch,request:async()=>({fields:{invoice_number:'12345678',total_cents:11300},warnings:[]})};const p=load('form',api,{showModal:o=>confirm=o.success});const file={id:'a'},row={amount:'9.00',description:'手写说明',invoice_date:'',attachments:[file]};p.data.items=[row];await p.recognizeFile(file);assert.ok(p.data.invoiceReview);assert.equal(row.amount,'9.00');p.cancelInvoiceReview();p.applyInvoice({currentTarget:{dataset:{i:0,j:0}}});p.cancelInvoiceReview();assert.equal(row.amount,'9.00');p.applyInvoice({currentTarget:{dataset:{i:0,j:0}}});p.confirmInvoiceReview();assert.equal(row.amount,'113.00');assert.equal(p.data.total,'113.00');p.applyInvoice({currentTarget:{dataset:{i:0,j:0}}});epoch=2;row.amount='8.00';p.confirmInvoiceReview();assert.equal(row.amount,'8.00');
 let done;api.request=()=>new Promise(r=>done=r);const pending=p.recognizeFile(file);p.data.items=[];done({fields:{total_cents:500},warnings:[]});await pending;assert.equal(file.recognition.fields.total_cents,11300);
});
test('failed recognition keeps the uploaded attachment and supports retry',async()=>{let fail=true;const api={onClear(){},epoch:()=>1,request:async()=>{if(fail)throw Error('暂不可用');return {fields:{total_cents:100},warnings:[]};}};const p=load('form',api),file={id:'a'};p.data.items=[{amount:'2.00',description:'手写内容',attachments:[file]}];await p.recognizeFile(file);assert.equal(file.recognitionError,'暂不可用');assert.equal(p.data.items[0].attachments.length,1);fail=false;await p.recognizeFile(file);assert.equal(file.recognition.fields.total_cents,100);assert.equal(p.data.items[0].amount,'2.00');});

test('invoice dates and descriptions preserve manual notes and do not duplicate recognized lines',()=>{
 const c=common();assert.equal(c.validInvoiceDate('2024-02-29'),true);for(const d of ['2026-02-29','2026-02-30','2026-1-01','',null])assert.equal(c.validInvoiceDate(d),false);
 const fields={invoice_number:'12345678',buyer:'测试购买方',seller:'测试销售方',invoice_date:'2026-10-29',total_cents:100};
 const first=c.invoiceDescription('手写说明',fields);assert.equal(c.invoiceDescription(first,fields),first);assert.match(first,/手写说明/);assert.match(first,/购买方：测试购买方/);assert.doesNotMatch(first,/2026-10-29|100/);
});
test('review applies complete fields, missing data preserves input and oversized notes apply nothing',()=>{
 let confirm;const p=load('form',{onClear(){},epoch:()=>1},{showModal:o=>confirm=o.success});
 const file={id:'a',recognition:{fields:{total_cents:11300,invoice_date:'2026-10-29',invoice_number:'12345678'}}};
 const row={description:'手写说明',amount:'9.00',invoice_date:'2026-10-28',attachments:[file]};p.data.items=[row];const e={currentTarget:{dataset:{i:0,j:0}}};
 p.applyInvoice(e);p.confirmInvoiceReview();assert.equal(row.invoice_date,'2026-10-29');assert.equal(row.amount,'113.00');assert.match(row.description,/手写说明\n发票号码：12345678/);
 p.applyInvoice(e);p.confirmInvoiceReview();assert.equal(row.description.split('12345678').length,2);
 file.recognition.fields={buyer:'购买方'};p.applyInvoice(e);p.confirmInvoiceReview();assert.equal(row.invoice_date,'2026-10-29');assert.equal(row.amount,'113.00');
 p.applyInvoice(e);row.description='更新的手写内容';p.confirmInvoiceReview();assert.equal(row.description,'更新的手写内容');
 row.description='长'.repeat(500);file.recognition.fields={total_cents:200,invoice_date:'2026-10-20',seller:'新销售方'};p.applyInvoice(e);assert.match(p.data.error,/500/);assert.equal(row.amount,'113.00');assert.equal(row.invoice_date,'2026-10-29');
});
test('each added invoice gets its own expense and failed retry does not add another row',async()=>{
 let fail=false,n=0;const api={onClear(){},epoch:()=>1,upload:async()=>{if(fail)throw Error('上传失败');return {file:{id:String(++n),mime:'image/png',name:'票.png'}};}};const p=load('form',api);const f={path:'one',name:'票.png',size:20};
 await p.upload(0,f);p.data.items[0].amount='10.00';p.data.items[0].description='原费用';
 fail=true;await p.upload(0,{...f,path:'two'});assert.equal(p.data.items.length,2);assert.equal(p.data.items[0].amount,'10.00');assert.equal(p.data.items[1].failed.length,1);
 fail=false;await p.upload(1,{...f,path:'two'});assert.equal(p.data.items.length,2);assert.equal(p.data.items[1].attachments.length,1);assert.equal(p.data.items[1].failed.length,0);
 p.data.items=Array.from({length:50},()=>({attachments:[{id:'old'}],failed:[]}));await p.upload(0,{...f,path:'three'});assert.equal(p.data.items.length,50);assert.equal(n,2);assert.match(p.data.error,/50/);
});

test('recognition reviews open automatically in sequence and results are not repeated under files',async()=>{
 const api={onClear(){},epoch:()=>1,request:async()=>({fields:{total_cents:100,invoice_date:'2026-10-29'},warnings:[]})};
 const p=load('form',api);const first={id:'a',name:'第一张.pdf'},second={id:'b',name:'第二张.pdf'};
 p.data.items=[{amount:'',invoice_date:'',description:'第一项',attachments:[first]},{amount:'',invoice_date:'',description:'第二项',attachments:[second]}];
 await p.recognizeFile(first);await p.recognizeFile(second);assert.equal(p.data.invoiceReview.name,'第一张.pdf');p.confirmInvoiceReview();await new Promise(resolve=>setImmediate(resolve));assert.equal(p.data.invoiceReview.name,'第二张.pdf');p.cancelInvoiceReview();await p.invoiceReviews;assert.equal(p.data.items[0].invoice_date,'2026-10-29');assert.equal(p.data.items[1].invoice_date,'');
 const template=fs.readFileSync(path.join(__dirname,'../pages/reimbursements/form.wxml'),'utf8');assert.doesNotMatch(template,/发票识别 · 待核对|wx:for="{{file.recognitionLines}}"/);assert.match(template,/expense-fields/);assert.match(template,/review-description/);assert.match(template,/invoiceReview.descriptionLines/);
});

test('leaving the form resolves the review queue and prevents late review dialogs',async()=>{
 const api={onClear(){},epoch:()=>1,request:async()=>({fields:{total_cents:100},warnings:[]})};const p=load('form',api);const first={id:'a'},second={id:'b'};p.data.items=[{description:'a',amount:'',attachments:[first]},{description:'b',amount:'',attachments:[second]}];await p.recognizeFile(first);await p.recognizeFile(second);p.onUnload();await p.invoiceReviews;assert.equal(p.data.invoiceReview,null);assert.equal(p.data.items[0].amount,'');
});

test('draft saves incomplete input, retries same request then saves newer edits without losing attachments',async()=>{
 const calls=[];let fail=true;const api={onClear(){},epoch:()=>1,request:async(path,method,data)=>{calls.push(JSON.parse(JSON.stringify(data)));if(fail)throw Error('断网');return {draft:{revision:data.revision+1}};}};
 const p=load('form',api);p.draftId='draft';p.draftRevision=2;p.data.items=[{description:'手写',amount:'1.',invoice_date:'2026-',attachments:[{id:'pdf'}],failed:[]}];
 await assert.rejects(p.flushDraft(),/断网/);assert.match(p.data.draftStatus,/保存失败/);assert.equal(p.data.items[0].amount,'1.');
 p.data.title='新修改';fail=false;await p.flushDraft();assert.deepEqual(calls[0],calls[1]);assert.equal(calls[2].payload.title,'新修改');assert.equal(calls[2].payload.items[0].attachments[0],'pdf');assert.equal(p.data.draftStatus,'已保存');
 const count=calls.length;await p.flushDraft();assert.equal(calls.length,count);
});
test('draft conflict blocks overwrites and an account switch drops late save response',async()=>{
 const api={onClear(){},epoch:()=>1,request:async()=>{throw Object.assign(Error('版本冲突'),{status:409});}};const p=load('form',api);p.draftId='draft';p.draftRevision=1;
 await assert.rejects(p.flushDraft(),/版本冲突/);assert.equal(p.data.draftConflict,true);await assert.rejects(p.flushDraft(),/冲突/);
 let done,epoch=1;api.epoch=()=>epoch;api.request=()=>new Promise(resolve=>done=resolve);p.data.draftConflict=false;p.saveRequest=null;const request=p.flushDraft();epoch=2;done({draft:{revision:9}});await request;assert.equal(p.draftRevision,1);
});
test('PDF upload includes current draft snapshot and idempotent operation, then submission flushes edits',async()=>{
 const uploads=[],requests=[];let fail=true;const api={onClear(){},epoch:()=>1,upload:async(path,file,fields)=>{uploads.push(fields);if(fail)throw Error('timeout');return {file:{id:'pdf',name:'票.pdf',mime:'application/pdf'},draft:{revision:2}};},request:async(path,method,data)=>{requests.push({path,data});if(method==='PUT')return {draft:{revision:data.revision+1}};return {record:{id:1}};}};
 const p=load('form',api,{redirectTo(){}});p.draftId='draft';p.draftRevision=0;p.key='submit';p.allowed=true;p.recognizeFile=()=>{};const f={path:'pdf',name:'票.pdf',size:40};
 p.data.title='报销';p.data.note='说明';p.data.items[0].description='费用';p.data.items[0].amount='1';
 await p.upload(0,f);fail=false;await p.upload(0,f);assert.equal(uploads[0].draft_context,uploads[1].draft_context);assert.equal(JSON.parse(uploads[0].draft_context).payload.title,'报销');assert.equal(p.data.items[0].attachments.length,1);
 await p.submit();assert.equal(requests[0].path,'/reimbursements/drafts/draft');assert.equal(requests[1].data.draft_revision,3);assert.equal(requests[1].data.draft_id,'draft');clearTimeout(p.draftTimer);
});

test('new form offers draft recovery, starting new retains saved drafts and direct restore includes original fields',async()=>{
 const draft={id:'draft',revision:2,updated_at:'2026-10-29T08:00:00Z',payload:{title:'保留的报销',note:'说明',type:'其他',cc:[],items:[{description:'未完成',amount:'1.',invoice_date:'',attachments:[{id:'pdf'}]}]}};
 const api={onClear:()=>()=>{},epoch:()=>1,request:async(path)=>path.endsWith('/options')?{types:['差旅交通','客户招待','办公采购','其他'],capabilities:{submit:true},users:[]}:path==='/reimbursements/drafts'?{records:{data:[draft]}}:{draft}};
 let prompts=0;const fresh=load('form',api,{showModal:o=>{prompts++;o.success({confirm:false});}});fresh.onLoad({});await fresh.loaded;assert.equal(prompts,1);assert.equal(fresh.data.title,'');assert.equal(fresh.draftRevision,0);fresh.onUnload();
 const resumed=load('form',api,{showModal:o=>o.success({confirm:true})});resumed.onLoad({});await resumed.loaded;assert.equal(resumed.data.title,'保留的报销');assert.equal(resumed.data.items[0].amount,'1.');assert.equal(resumed.data.items[0].attachments[0].id,'pdf');clearTimeout(resumed.draftTimer);resumed.closed=true;
});

function deletionPage(confirm=true){
 let dialog,calls=[],epoch=1;
 const api={onClear(){},epoch:()=>epoch,request:async(...args)=>{calls.push(args);}};
 const p=load('index',api,{showModal:o=>{dialog=o;},showToast(){}});
 p.visible=true;Object.assign(p.data,{scope:'drafts',rows:[{id:'draft',revision:3,title:'差旅报销'}],page:2});
 let refreshed=0;p.refresh=async()=>{refreshed++;};
 return {p,api,calls,event:{currentTarget:{dataset:{id:'draft'}}},confirm:()=>dialog.success({confirm}),changeAccount:()=>epoch++,refreshed:()=>refreshed};
}
test('draft trash confirms and preserves cancellation and continue-edit',async()=>{
 const x=deletionPage(false);const pending=x.p.deleteDraft(x.event);assert.equal(x.calls.length,0);x.confirm();await pending;assert.equal(x.p.data.rows.length,1);assert.equal(x.calls.length,0);
 const wxml=fs.readFileSync(path.join(__dirname,'../pages/reimbursements/index.wxml'),'utf8');assert.match(wxml,/catchtap="deleteDraft"/);assert.match(wxml,/继续编辑/);assert.match(wxml,/aria-label="删除草稿/);assert.match(wxml,/wx:if="{{scope==='drafts'}}" class="draft-delete delete-icon-button"/);
});
test('draft deletion removes only after success and adjusts an emptied last page',async()=>{
 const x=deletionPage();let finish;x.api.request=(...args)=>{x.calls.push(args);return new Promise(r=>finish=r);};const pending=x.p.deleteDraft(x.event);x.confirm();await Promise.resolve();assert.equal(x.p.data.rows.length,1);await x.p.deleteDraft(x.event);assert.equal(x.calls.length,1);finish({});await pending;assert.equal(x.p.data.rows.length,0);assert.equal(x.p.data.page,1);assert.equal(x.refreshed(),1);assert.equal(x.calls[0][1],'DELETE');assert.equal(x.calls[0][2].revision,3);assert.equal(x.p.data.deleting,'');
});
test('failed delete retains card; account switch or non-draft scope sends no delete',async()=>{
 const x=deletionPage();x.api.request=async()=>{throw Error('草稿已更新');};let pending=x.p.deleteDraft(x.event);x.confirm();await pending;assert.equal(x.p.data.rows.length,1);assert.match(x.p.data.error,/草稿已更新/);
 const y=deletionPage();pending=y.p.deleteDraft(y.event);y.changeAccount();y.confirm();await pending;assert.equal(y.calls.length,0);y.p.data.scope='mine';await y.p.deleteDraft(y.event);assert.equal(y.calls.length,0);
});

test('expense red trash keeps removal, upload protection and remaining expense amounts',()=>{
 const p=load('form',{onClear(){}});p.data.items=[{amount:'113.00',attachments:[{id:'first'}]},{amount:'20.50',attachments:[{id:'second'}]}];const e={currentTarget:{dataset:{i:0}}};p.data.uploading=true;p.remove(e);assert.equal(p.data.items.length,2);p.data.uploading=false;p.remove(e);assert.equal(p.data.items.length,1);assert.equal(p.data.items[0].attachments[0].id,'second');assert.equal(p.data.total,'20.50');
 const w=fs.readFileSync(path.join(__dirname,'../pages/reimbursements/form.wxml'),'utf8');assert.match(w,/class="delete-icon-button" aria-label="删除费用/);assert.match(w,/wx:if="{{items.length>1}}"/);assert.match(w,/bindtap="removeFile">移除/);const css=fs.readFileSync(path.join(__dirname,'../pages/reimbursements/common.wxss'),'utf8');assert.match(css,/color:#d93025/);
});

test('multi-ticket evidence creates editable expenses once and preserves manual edits on retry',async()=>{
 const file={id:'pdf',name:'多票.pdf'},candidates=[{id:'1-1',page:1,amount:'10.00',description:'发票 A',number:'A12345678'},{id:'2-1',page:2,amount:'20.00',description:'发票 B',number:'B12345678'}];
 const p=load('form',{onClear(){},epoch:()=>1,request:async()=>({status:'completed',result:{items:candidates}})});
 p.data.items=[{key:'row',description:'',amount:'',invoice_date:'',attachments:[file],failed:[]}];
 await p.recognizeEvidence(file);assert.equal(p.data.items.length,2);assert.equal(p.data.total,'30.00');
 p.data.items[0].amount='12.00';p.data.items[0].description='手工确认';await p.recognizeEvidence(file,true);
 assert.equal(p.data.items.length,2);assert.equal(p.data.items[0].amount,'12.00');assert.equal(p.data.items[0].description,'手工确认');assert.equal(p.draftPayload().items[1].evidence_source.candidate_id,'2-1');
});
test('evidence does not repartition a manually filled expense and ignores late account responses',async()=>{
 const file={id:'pdf',name:'票.pdf'};let epoch=1,finish;const p=load('form',{onClear(){},epoch:()=>epoch,request:()=>new Promise(r=>finish=r)});
 p.data.items=[{key:'r',description:'手填',amount:'9.00',attachments:[file],failed:[]}];
 let call=p.recognizeEvidence(file);finish({status:'completed',result:{items:[{id:'1-1',amount:'10.00'}]}});await call;
 assert.equal(p.data.items.length,1);assert.equal(p.data.items[0].amount,'9.00');assert.match(file.recognitionError,/手工/);
 call=p.recognizeEvidence(file);epoch=2;finish({status:'completed',result:{items:[{id:'2-1',amount:'99.00'}]}});await call;assert.equal(p.data.items.length,1);
});
test('batch upload retries failed file in its original expense and leaves successful files intact',async()=>{
 let fail=true,n=0;const p=load('form',{onClear(){},epoch:()=>1,upload:async()=>{if(fail)throw Error('断网');return {file:{id:String(++n),name:'a.png',mime:'image/png'}};}});
 p.recognizeEvidence=async()=>{};
 const file={path:'/a.png',name:'a.png',size:2};await p.uploadAttachments([file]);assert.equal(p.data.items.length,1);assert.equal(p.data.attachmentCards[0].red,true);
 const retry=p.localFiles[0];p.localFiles=[];fail=false;await p.uploadAttachments([retry]);assert.equal(p.data.items.length,1);assert.equal(p.data.items[0].failed.length,0);assert.equal(p.data.items[0].attachments.length,1);
});
test('local preview works before upload and does not clear failure status',async()=>{
 let path;const p=load('form',{onClear(){},epoch:()=>1},{openDocument:o=>{path=o.filePath;o.success();}});
 p.data.attachmentCards=[{name:'a.pdf',path:'/queued.pdf',red:true}];await p.previewAttachment({currentTarget:{dataset:{index:0}}});assert.equal(path,'/queued.pdf');assert.equal(p.data.attachmentCards[0].red,true);
});
test('explicitly associating payment evidence removes generated duplicate expense but preserves target amount',async()=>{
 const invoice={id:'invoice',name:'发票.pdf'},payment={id:'payment',name:'付款.png',evidence_status:'attention'};
 const p=load('form',{onClear(){},epoch:()=>1,request:async()=>({status:'reviewed'})},{showActionSheet:o=>o.success({tapIndex:0}),showModal:o=>o.success({confirm:true})});
 p.data.items=[{key:'a',description:'原始发票',amount:'60.00',attachments:[invoice],failed:[]},{key:'b',description:'付款凭证',amount:'60.00',attachments:[payment],failed:[],evidence_source:{upload_id:'payment',candidate_id:'1-1'}}];
 p.refreshAttachments();p.associateEvidence({currentTarget:{dataset:{index:1}}});await new Promise(resolve=>setImmediate(resolve));
 assert.equal(p.data.items.length,1);assert.equal(p.data.items[0].amount,'60.00');assert.equal(p.data.items[0].attachments.length,2);assert.equal(payment.evidence_status,'reviewed');assert.equal(p.data.total,'60.00');
});
test('removing attachment while recognition is in flight prevents late expense insertion',async()=>{
 let finish;const p=load('form',{onClear(){},epoch:()=>1,request:()=>new Promise(r=>finish=r)});const file={id:'a'};p.data.items[0].attachments=[file];const call=p.recognizeEvidence(file);p.removeFile({currentTarget:{dataset:{i:0,j:0}}});finish({status:'completed',result:{items:[{id:'1-1',amount:'90.00'}]}});await call;assert.equal(p.data.items.length,1);assert.equal(p.data.items[0].amount,'');assert.equal(p.data.items[0].attachments.length,0);
});

test('expense attachment picker keeps album and PDF paths alongside chat images',()=>{
 let sheet,picker,album,uploaded;const p=load('form',{onClear(){}},{showActionSheet:o=>sheet=o,chooseMessageFile:o=>picker=o,chooseMedia:o=>album=o});
 p.upload=(i,f)=>uploaded={i,f};const event={currentTarget:{dataset:{i:'2'}}};p.choose(event);
 assert.deepEqual(Array.from(sheet.itemList),['拍照或相册','聊天中的 PDF 文件','聊天中的图片']);
 sheet.success({tapIndex:2});assert.equal(picker.type,'image');assert.equal(picker.extension,undefined);
 const file={path:'chat.jpg',name:'chat.jpg',size:1};picker.success({tempFiles:[file]});assert.equal(uploaded.i,2);assert.equal(uploaded.f,file);
 sheet.success({tapIndex:1});assert.equal(picker.type,'file');assert.deepEqual(Array.from(picker.extension),['pdf']);
 sheet.success({tapIndex:0});assert.deepEqual(Array.from(album.mediaType),['image']);
 album.success({tempFiles:[{tempFilePath:'album.png',size:1}]});assert.equal(uploaded.f.path,'album.png');
 sheet=null;p.pending={};p.choose(event);assert.equal(sheet,null);
});

test('finished batch cards disappear while drafts, progress and failed files remain visible',async()=>{
 let batches=[{id:'done',status:'completed',failed_names:[]},{id:'review',status:'attention',failed_names:[]},{id:'running',status:'processing',failed_names:[]},{id:'failed',status:'attention',failed_names:['bad.pdf']},{id:'upload',status:'uploading',failed_names:[]}];
 const draft={id:'draft',revision:1,batch_id:'done',unread:true,payload:{title:'已生成草稿',items:[{amount:'10.00'}]}};
 const api={onClear(){},query:()=>'',request:async url=>url==='/reimbursements/options'?{batch_enabled:true,capabilities:{submit:true},types:[]}:url.startsWith('/reimbursements/batches')?{records:{last_page:1,data:batches}}:{records:{last_page:1,data:[draft]}}};
 const p=load('index',api);p.data.scope='drafts';await p.refresh();
 assert.deepEqual(Array.from(p.data.batches,b=>b.id),['running','failed']);assert.equal(p.data.rows[0].id,'draft');assert.equal(p.data.rows[0].total,'10.00');assert.equal(p.data.rows[0].unread,true);
 batches=batches.map(b=>({...b,status:'completed',failed_names:[]}));await p.refresh();assert.equal(p.data.batches.length,0);assert.equal(p.data.rows.length,1);
 const template=fs.readFileSync(path.join(__dirname,'../pages/reimbursements/index.wxml'),'utf8');assert.match(template,/scope==='drafts' && batches.length/);
});
