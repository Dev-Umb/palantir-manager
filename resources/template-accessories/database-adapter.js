(function(root){
'use strict';
const boot=root.TEMPLATE_BOOT;
let dataset,preferences={},pendingMutation;
const cache=new Map(),queues=new Map(),failures=new Map();
let privatePending=0;
const status=()=>window.dispatchEvent(new Event('private-state-updated'));
async function request(url,method='GET',body){
 let response;
 for(let attempt=0;attempt<2;attempt++){
  try{response=await fetch(url,{method,credentials:'same-origin',headers:{Accept:'application/json','Content-Type':'application/json','X-CSRF-TOKEN':boot.csrf},body:body===undefined?undefined:JSON.stringify(body)});break;}
  catch(e){if(attempt)throw Error('网络中断，请保留输入并重试。');}
 }
 const result=await response.json().catch(()=>({}));
 if(!response.ok)throw Object.assign(Error(Object.values(result.errors||{}).flat()[0]||result.message||'请求失败，请重试。'),{status:response.status});
 return result;
}
const ready=request(boot.urls.state).then(r=>{dataset=r.dataset;preferences=r.preferences;for(const [k,v]of Object.entries(preferences))cache.set(k,v.value);});
const privateState={
 getItem:key=>cache.get(key)??null,
 setItem(key,value){
  cache.set(key,value);privatePending++;status();
  const task=(queues.get(key)||ready).then(async()=>{
   try{const current=preferences[key]||{revision:0};const r=await request(boot.urls.preferences,'PUT',{key,value,expected_revision:current.revision});preferences[key]={value,revision:r.revision};failures.delete(key);}
   catch(e){failures.set(key,e);}
   finally{privatePending--;status();}
  });queues.set(key,task);return task;
 },
 removeItem(key){return this.setItem(key,null);},
 async flush(){await Promise.all([...queues.values()]);if(failures.size)throw [...failures.values()][0];},
 get pending(){return privatePending>0;},get error(){return [...failures.values()][0]?.message||'';}
};
async function mutate(operation,fields,revision=dataset.revision){
 const signature=JSON.stringify({operation,...fields,expected_revision:revision});
 if(!pendingMutation||pendingMutation.signature!==signature)pendingMutation={signature,id:crypto.randomUUID()};
 try{const r=await request(boot.urls.mutate,'POST',{...JSON.parse(signature),request_id:pendingMutation.id});dataset=r.dataset;pendingMutation=null;return r.result;}
 catch(e){if(e.status)pendingMutation=null;throw e;}
}
const store={
 async read(initial=false){await ready;if(initial)return structuredClone(dataset);const r=await request(boot.urls.state);dataset=r.dataset;return structuredClone(dataset);},
 async export(){dataset=await request(boot.urls.export);return structuredClone(dataset);},
 saveBatch:(entries,revision)=>mutate('save',{entries},revision),
 remove:(id,version,deleted)=>mutate('delete',{id,version,deleted}),
 import:(rows,hash,name,kind,revision,replace)=>mutate('import',{rows,hash,name,kind,replace},revision),
 async replace(clean,revision){const dataset_json=JSON.stringify(clean);return mutate('restore',{dataset_json,checksum:await MaterialHash.hash(dataset_json)},revision);},
 recover:revision=>mutate('undo',{},revision)
};
root.TemplateDatabase={ready,privateState,store};
})(window);
