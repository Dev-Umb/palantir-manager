(function(root){
'use strict';
const boot=root.PRODUCTION_BOOT;
let dataset,pending,initialRead=true;
async function request(path,body){
 const response=await fetch(boot.base+'/'+path,{method:body?'POST':'GET',credentials:'same-origin',headers:{Accept:'application/json','Content-Type':'application/json','X-CSRF-TOKEN':boot.csrf},body:body?JSON.stringify(body):undefined});
 if([401,419].includes(response.status)||response.redirected)throw Error('登录状态已失效或请求被重定向，请保留当前输入，在新标签页重新登录后重试。');
 const result=await response.json().catch(()=>null);
 if(!response.ok)throw Error(Object.values(result?.errors||{}).flat()[0]||result?.message||'保存失败，请保留输入。');
 if(!result||!Number.isInteger(result.revision)||!['records','imports','progressRecords','progressImports','materialDemandPlans'].every(key=>Array.isArray(result[key])))throw Error('未取得完整工程数据，已停止保存并保留输入，请稍后重试；仍失败时请重新登录。');
 return result;
}
const ready=request('state').then(d=>{dataset=d;});
const legacy=boot.legacyPreferences||{}, queues=new Map(), failures=new Map();let writes=0;
function locationOf(key){
 if(key.startsWith('xyc-production-v1-online-'))return ['material-arrivals','material_arrival_preferences',key.split('-').at(-1)];
 return ['template-accessories','template_accessory_preferences',key.includes('detail-draft')?'detail':key.includes('inline')?'inline':key.includes('filters')?'filters':'draft'];
}
const cache=new Map();
const privateState={
 getItem(key){if(cache.has(key))return cache.get(key);const [,table,name]=locationOf(key);return legacy[table]?.[name]?.value??null;},
 setItem(key,value){cache.set(key,value);writes++;const [module,table,name]=locationOf(key);const task=(queues.get(key)||Promise.resolve()).then(async()=>{try{
  const current=legacy[table]?.[name]||{revision:0};const response=await fetch('/'+module+'/preferences',{method:'PUT',credentials:'same-origin',headers:{Accept:'application/json','Content-Type':'application/json','X-CSRF-TOKEN':boot.csrf},body:JSON.stringify({key:name,value,expected_revision:current.revision})});const result=await response.json();if(!response.ok)throw Error(Object.values(result.errors||{}).flat()[0]||result.message||'个人状态保存失败');legacy[table]??={};legacy[table][name]={value,revision:result.revision};failures.delete(key);
 }catch(e){failures.set(key,e);document.getElementById('save-status').textContent='个人草稿/筛选未保存：'+e.message;}finally{writes--;}});queues.set(key,task);return task;},
 removeItem(key){return this.setItem(key,null);},
 async flush(){await Promise.all(queues.values());if(failures.size)throw [...failures.values()][0];}
};
window.addEventListener('beforeunload',e=>{if(writes||failures.size){e.preventDefault();e.returnValue='';}});

async function send(operation,clean,revision){
 const fields={operation,expected_revision:revision};if(clean)fields.dataset_json=JSON.stringify(clean);
 if(operation==='restore'){fields.dataset_json=JSON.stringify(clean);fields.checksum=await MaterialHash.hash(fields.dataset_json);}
 const signature=JSON.stringify(fields);if(!pending||pending.signature!==signature)pending={signature,id:crypto.randomUUID()};
 dataset=await request('mutate',{...fields,request_id:pending.id});pending=null;
}
root.ProductionDatabase={privateState,snapshot:()=>request('snapshot')};
root.createWorkbenchStore=()=>({ready,async read(){await ready;if(initialRead){initialRead=false;return structuredClone(dataset);}return structuredClone(dataset=await request('state'));},async transact(mutator,write=false){await ready;const current=structuredClone(dataset=await request('state'));const result=mutator(current);if(result?.then)throw Error('事务不能包含异步操作。');if(write)await send('save',current,dataset.revision);return result;},replace:(clean,revision)=>send('restore',clean,revision),recover:revision=>send('undo',null,revision)});
root.WorkbenchLibraries={load:()=>root.WorkbenchExcel.write()};
})(window);
(function(){
 const caps=PRODUCTION_BOOT.capabilities;
 const manage=caps['material-arrivals'].manage,template=caps['template-accessories'].manage;
 const both=action=>caps['material-arrivals'][action]&&caps['template-accessories'][action];
 function protect(doc,nested=false){
  const rules=nested?[[template,'#add-record,#delete-records,#save-detail,#import-records,#undo-delete'],[caps['template-accessories'].export,'#export-records'],[both('export'),'#backup-project,#mobile-backup,#upgrade-backup'],[both('recover'),'#restore-project,#mobile-restore']]:[[manage,'#material-entry,#add,#import-excel,#save-record,#demand-add,#demand-save'],[template,'#p-add,#p-import,#p-save'],[caps['material-arrivals'].export,'#export-excel,#demand-export'],[both('export'),'#backup'],[both('recover'),'#restore,#undo-restore']];
  for(const [allowed,selector]of rules)if(!allowed)doc.querySelectorAll(selector).forEach(el=>{el.hidden=true;});
 }
 window.addEventListener('workbench-ready',()=>{protect(document);new MutationObserver(()=>protect(document)).observe(document.body,{subtree:true,childList:true});});
 window.addEventListener('production-ui-ready',()=>{const doc=document.getElementById('production-dashboard-host').contentDocument;protect(doc,true);new MutationObserver(()=>protect(doc,true)).observe(doc.body,{subtree:true,childList:true});});
})();

window.addEventListener('production-ui-ready',()=>{if(PRODUCTION_BOOT.initialModule==='material')WorkbenchHost.material('ledger');else if(PRODUCTION_BOOT.initialModule==='template')WorkbenchHost.show('台车模板');});
