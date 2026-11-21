const test=require('node:test'),assert=require('node:assert/strict'),fs=require('node:fs'),vm=require('node:vm'),path=require('node:path');
function load(api,wx={}){const module={exports:{}};vm.runInNewContext(fs.readFileSync(path.join(__dirname,'../services/wechat.js'),'utf8'),{module,exports:module.exports,require:n=>api,wx,Promise});return module.exports;}
function state(overrides={}){return {configured:true,bound:true,addressable:true,templates:[{key:'reimbursement_approval',template_id:'tmpl-a'},{key:'reimbursement_result',template_id:'tmpl-r'}],grants:{},...overrides};}
test('prepare caches configured templates and refreshes legacy openid bindings',async()=>{
 const calls=[];let reads=0;
 const api={request:async(url,method,data)=>{calls.push({url,method,data});if(url==='/miniapp/wechat/subscriptions'){reads++;return reads===1?state({addressable:false}):state();}if(url==='/miniapp/wechat/refresh')return {ok:true};}};
 const wx={login:o=>o.success({code:'code-1'})};
 const wechat=load(api,wx);
 await wechat.prepare();
 assert.deepEqual(calls.map(c=>(c.method||'GET')+' '+c.url),['GET /miniapp/wechat/subscriptions','POST /miniapp/wechat/refresh','GET /miniapp/wechat/subscriptions']);
 assert.equal(calls[1].data.code,'code-1');
 const accepted=[];wx.requestSubscribeMessage=o=>{o.success({'tmpl-a':'accept','tmpl-r':'reject'});};api.request=async(url,method,data)=>{if(url==='/miniapp/wechat/subscriptions')accepted.push(data);};
 let done=false;wechat.request(['reimbursement_approval','reimbursement_result'],()=>{done=true;});
 await new Promise(r=>setTimeout(r,0));
 assert.deepEqual(JSON.parse(JSON.stringify(accepted)),[{accepted:['reimbursement_approval']}]);
 assert.equal(done,true);
});
test('request without loaded templates navigates without a native dialog',()=>{
 let subscribed=false,opened=false;const wx={requestSubscribeMessage(){subscribed=true;}};
 const wechat=load({request:async()=>({})},wx);
 wechat.request(['reimbursement_approval'],()=>{opened=true;});
 assert.equal(subscribed,false);assert.equal(opened,true);
});
test('rejection still opens the destination and does not post grants',async()=>{
 let posted=false,done=false;const wx={requestSubscribeMessage:o=>o.success({})};
 const api={request:async(url,method)=>{if(method==='POST')posted=true;if(url==='/miniapp/wechat/subscriptions')return state();}};
 const wechat=load(api,wx);await wechat.prepare();
 wechat.request(['reimbursement_approval'],()=>{done=true;});
 await new Promise(r=>setTimeout(r,0));
 assert.equal(done,true);assert.equal(posted,false);
});
test('reimbursement navigation tops up subscriptions before opening, other pages do not',()=>{
 function loadPage(wechat){const module={exports:{}};const api={onClear(){},epoch:()=>1,user:()=>({})};const wx={navigateTo:o=>{opened.push(o.url);o.complete();}};const opened=[];vm.runInNewContext(fs.readFileSync(path.join(__dirname,'../utils/page.js'),'utf8'),{module,exports:module.exports,require:n=>n.endsWith('/api')?api:n.endsWith('/wechat')?wechat:{},wx});return {page:module.exports,opened};}
 let requested=null;
 const {page,opened}=loadPage({prepare(){},request(keys,done){requested=keys;done();}});
 page.navigate('/pages/reimbursements/index?approval=1');
 assert.deepEqual([...requested],['reimbursement_approval']);
 assert.deepEqual(opened,['/pages/reimbursements/index?approval=1']);
 page.navigate('/pages/reimbursements/index');
 assert.deepEqual([...requested],['reimbursement_result']);
 requested=null;page.navigate('/pages/projects/projects');
 assert.equal(requested,null);assert.deepEqual(opened,['/pages/reimbursements/index?approval=1','/pages/reimbursements/index','/pages/projects/projects']);
});
