const test=require('node:test'),assert=require('node:assert/strict'),fs=require('node:fs'),vm=require('node:vm');
const picker=require('../pages/tools/intake');
function load(overrides={}){
 let definition,epoch=1,calls=[],release;
 const api={epoch:()=>epoch,upload:async(...args)=>{calls.push(args);if(overrides.wait)await new Promise(r=>release=r);if(overrides.error)throw overrides.error;return {saved:true};}};
 const result={project:{name:'项目'},selectedRecord:{can_update:true,contracts:[{id:'c1',code:'合同一'}]},can:{manage_contracts:true,update:true},...overrides.result};
 const utils={attach(){},unload(){},run:async(p,f)=>p.setData(await f())};
 const wx={showModal:o=>o.success({confirm:overrides.confirm!==false}),navigateBack(){},chooseMessageFile:o=>o.success({tempFiles:[{path:'/file.pdf',name:'合同.pdf',size:20}]}),chooseMedia:o=>o.success({tempFiles:[{tempFilePath:'/photo.jpg',size:20}]})};
 vm.runInNewContext(fs.readFileSync(__dirname+'/../pages/contract-upload/contract-upload.js','utf8'),{Page:d=>definition=d,require:n=>n.endsWith('/api')?api:n.endsWith('/business')?{detail:async()=>result}:n.endsWith('/page')?utils:picker,wx});
 const p={...definition,data:structuredClone(definition.data),setData(d){Object.assign(this.data,d);}};
 // Shared picker resolves wx in its own module context.
 global.wx=wx;
 p.project='p1';p.contract=overrides.newContract?'':'c1';p.epoch=1;p.data.allowed=true;p.data.existing=!overrides.newContract;p.data.projectName='项目';p.data.contractName='合同一';
 p.data.uploadFile={path:'/file.pdf',name:'合同.pdf',size:20};
 return {p,calls,changeSession(){epoch++;},release(){release();},result};
}
test('existing contract sends only target and file, saves once and does not call AI',async()=>{
 const {p,calls,release}=load({wait:true});const pending=p.submit();await new Promise(r=>setImmediate(r));await p.submit();assert.equal(calls.length,1);release();await pending;
 assert.equal(calls[0][0],'/projects/p1/contract-uploads');assert.deepEqual(Object.keys(calls[0][2]),['contract_id']);assert.equal(calls[0][2].contract_id,'c1');assert.equal(p.data.saved,true);await p.submit();assert.equal(calls.length,1);
});
test('new contract preserves unknown and zero amount and explicitly selected status',async()=>{
 for(const amount of ['', '0']){const {p,calls}=load({newContract:true});p.data.amount=amount;p.data.statusIndex=1;await p.submit();assert.equal(calls[0][2].amount,amount);assert.equal(calls[0][2].status,'已签署');assert.equal(calls[0][2].contract_id,undefined);}
});
test('cancel, denied access, missing file and invalid amount never upload',async()=>{
 for(const kind of ['cancel','denied','missing','amount']){const {p,calls}=load({confirm:kind!=='cancel',newContract:true});if(kind==='denied')p.data.allowed=false;if(kind==='missing')p.data.uploadFile=null;if(kind==='amount')p.data.amount='abc';await p.submit();assert.equal(calls.length,0);assert.equal(p.data.busy,false);}
});
test('validation failure preserves selected file; uncertain network outcome blocks duplicate retries',async()=>{
 const denied=load({error:Object.assign(Error('文件无效'),{status:422})});await denied.p.submit();assert.equal(denied.p.data.uploadFile.name,'合同.pdf');assert.equal(denied.p.data.error,'文件无效');assert.equal(denied.p.data.uncertain,false);
 const network=load({error:Error('网络连接失败')});await network.p.submit();assert.equal(network.p.data.uncertain,true);await network.p.submit();assert.equal(network.calls.length,1);assert.match(network.p.data.error,/核对/);
});
test('session switch or unload during upload cannot publish stale success',async()=>{
 for(const unload of [false,true]){const {p,changeSession,release}=load({wait:true});const pending=p.submit();await new Promise(r=>setImmediate(r));if(unload)p.onUnload();else changeSession();release();await pending;assert.equal(p.data.saved,false);}
});
test('detail authorization and contract existence are enforced on opening the form',async()=>{
 const {p,result}=load();await p.refresh();assert.equal(p.data.allowed,true);assert.equal(p.data.contractName,'合同一');result.selectedRecord.can_update=false;await p.refresh();assert.equal(p.data.allowed,false);p.contract='foreign';await assert.rejects(p.refresh(),/不存在/);
});
test('file selector validates size and format without losing selected file',()=>{
 const {p}=load();p.selectContractFile({path:'/bad.exe',name:'bad.exe',size:20});assert.match(p.data.error,/PDF/);assert.equal(p.data.uploadFile.name,'合同.pdf');p.selectContractFile({path:'/large.pdf',name:'large.pdf',size:101*1024*1024});assert.match(p.data.error,/100 MB/);
});
test('project detail retains tabs and preview and exposes only authorized direct upload entries',async()=>{
 let definition,navigated;const result={project:{name:'项目'},selectedRecord:{can_update:true,contracts:[{id:'c1',payload:{amount:0},attachment_previews:{contract_attachments:[{name:'原合同.pdf'}]}}]},can:{update:true,manage_contracts:true},currentObject:{fields:[]}};
 const utils={attach(){},unload(){},run:async(p,f)=>p.setData(await f()),navigate:url=>navigated=url};
 vm.runInNewContext(fs.readFileSync(__dirname+'/../pages/detail/detail.js','utf8'),{Page:d=>definition=d,require:n=>n.endsWith('/business')?{detail:async()=>result,rows:()=>[],money:v=>String(v)}:n.endsWith('/page')?utils:{open:async()=>{}}});
 const p={...definition,data:structuredClone(definition.data),setData(d){Object.assign(this.data,d);},id:'p1'};await p.refresh();assert.equal(p.data.contracts[0].files[0].name,'原合同.pdf');assert.equal(p.data.tabs.join(','),'概况,合同,回款');p.upload({currentTarget:{dataset:{id:'c1'}}});assert.match(navigated,/project=p1&contract=c1/);result.can.manage_contracts=false;await p.refresh();navigated=null;p.upload({currentTarget:{dataset:{id:'c1'}}});assert.equal(navigated,null);
 const html=fs.readFileSync(__dirname+'/../pages/detail/detail.wxml','utf8');assert.doesNotMatch(html,/bindtap="unavailable"/);assert.match(html,/bindtap="attachment"/);assert.match(fs.readFileSync(__dirname+'/../pages/mine/mine.wxml','utf8'),/data-kind="upload"/);
});
