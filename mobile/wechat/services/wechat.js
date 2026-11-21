const api=require('./api');
let templates=[];
let pending=null;

async function refreshOpenid(){
 return new Promise(resolve=>{
  try{wx.login({timeout:10000,success:r=>{if(!r.code)return resolve(false);api.request('/miniapp/wechat/refresh','POST',{code:r.code}).then(()=>resolve(true)).catch(()=>resolve(false));},fail:()=>resolve(false)});}catch(e){resolve(false);}
 });
}
async function prepare(){
 if(pending)return pending;
 pending=(async()=>{
  try{let r=await api.request('/miniapp/wechat/subscriptions');if(r&&r.configured===true&&r.bound===true&&r.addressable!==true){await refreshOpenid();r=await api.request('/miniapp/wechat/subscriptions');}templates=Array.isArray(r&&r.templates)?r.templates:[];}catch(e){templates=[];}finally{pending=null;}
 })();
 return pending;
}
function request(keys,done){
 const list=templates.filter(t=>keys.includes(t.key));
 const ids=list.map(t=>t.template_id).filter(Boolean).slice(0,5);
 const finish=fn=>{try{fn&&fn();}catch(e){}};
 if(!ids.length||!wx.requestSubscribeMessage){finish(done);return;}
 try{
  wx.requestSubscribeMessage({tmplIds:ids,success:r=>{const accepted=list.filter(t=>r[t.template_id]==='accept').map(t=>t.key);if(accepted.length)api.request('/miniapp/wechat/subscriptions','POST',{accepted}).catch(()=>{});finish(done);},fail:()=>finish(done)});
 }catch(e){finish(done);}
}
module.exports={prepare,request};
