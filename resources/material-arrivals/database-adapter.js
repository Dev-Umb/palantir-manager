(function (root) {
'use strict';
const boot = root.MATERIAL_BOOT;
let preferences = {}, dataset;
const queues = new Map();
async function request(url, method = 'GET', body) {
    let response;
    for (let attempt = 0; attempt < 2; attempt++) {
        try {
            response = await fetch(url, {method, credentials: 'same-origin', headers: {'Accept':'application/json', 'Content-Type':'application/json', 'X-CSRF-TOKEN':boot.csrf}, body: body === undefined ? undefined : JSON.stringify(body)});
            break;
        } catch (error) {
            if (attempt) throw Error('网络中断，提交结果尚未确认，请保留输入并用相同内容重试。');
        }
    }
    const result = await response.json().catch(() => ({}));
    if (!response.ok) throw Object.assign(Error(Object.values(result.errors || {}).flat()[0] || result.message || '请求失败，请保留输入后重试。'), {status:response.status});
    return result;
}
const ready = request(boot.urls.state).then(result => {dataset=result.dataset;preferences=result.preferences;});
const keyOf = key => key.split('-').at(-1);
const privateState = {
    getItem(key) {return preferences[keyOf(key)]?.value ?? null;},
    setItem(key, value) {
        key=keyOf(key);
        const task=(queues.get(key)||ready).catch(()=>{}).then(async()=>{
            const current=preferences[key]||{value:null,revision:0};
            const result=await request(boot.urls.preferences,'PUT',{key,value,expected_revision:current.revision});
            preferences[key]={value,revision:result.revision};
        });
        queues.set(key,task);
        return task;
    },
    removeItem(key) {return this.setItem(key,null);},
    async flush() {await Promise.all([...queues.values()]);},
};
let pending;
async function mutate(operation, fields, expectedRevision) {
    const signature=JSON.stringify({operation,...fields,expected_revision:expectedRevision});
    if (!pending || pending.signature!==signature) pending={signature,request_id:crypto.randomUUID()};
    try {
        const result=await request(boot.urls.mutate,'POST',{...JSON.parse(signature),request_id:pending.request_id});
        pending=null;dataset=result.dataset;
        return result.result;
    } catch(error) {
        if(error.status) pending=null;
        throw error;
    }
}
root.MaterialDatabase={ready,privateState};
root.createWorkbenchStore=()=>({
    ready,
    async read(initial=false){await ready;if(initial)return structuredClone(dataset);const result=await request(boot.urls.state);dataset=result.dataset;return structuredClone(dataset);},
    async export(){await ready;return request(boot.urls.export);},
    save:(record,version,revision)=>mutate('save',{record,version:version??null},revision),
    remove:(id,version,deleted,revision)=>mutate('delete',{id,version,deleted},revision),
    import:(rows,hash,name,revision)=>mutate('import',{rows,hash,name},revision),
    async replace(clean,revision){const dataset_json=JSON.stringify(clean);return mutate('restore',{dataset_json,checksum:await MaterialHash.hash(dataset_json)},revision);},
    recover:revision=>mutate('undo',{},revision),
});
})(window);
