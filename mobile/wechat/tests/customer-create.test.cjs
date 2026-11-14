const test=require('node:test'),assert=require('node:assert/strict'),fs=require('node:fs'),vm=require('node:vm');
const b=require('../services/business');
function load(api,wxOverrides={},pageUtils={}){let definition;const wx={showToast(){},redirectTo(o){o.fail();},...wxOverrides};vm.runInNewContext(fs.readFileSync(__dirname+'/../pages/customer-create/customer-create.js','utf8'),{Page:d=>definition=d,require:n=>n.endsWith('/api')?api:n.endsWith('/business')?b:pageUtils,wx});return {...definition,data:structuredClone(definition.data),setData(d){Object.assign(this.data,d);}};}
const user=()=>({roles:[{name:'business'}]});
test('customer permissions match Web manager roles',()=>{for(const name of ['admin','business'])assert.equal(b.canCreateCustomer({roles:[{name}]}),true);for(const name of ['finance','tender','technician'])assert.equal(b.canCreateCustomer({roles:[{name}]}),false);assert.equal(b.canCreateCustomer(null),false);});
test('customer and contact save atomically; duplicate taps and navigation failure cannot create again',async()=>{let release,calls=0;const p=load({user,epoch:()=>1,request:async(url,method,data)=>{calls++;assert.equal(url,'/project-customers');assert.equal(method,'POST');assert.equal(data.name,'客户');assert.equal(data.contact.phone,'010-123');await new Promise(r=>release=r);return {customer:{id:'c1'}};}});Object.assign(p.data.draft,{name:' 客户 ',contactName:'张工',contactPhone:'010-123'});const saving=p.save();await p.save();assert.equal(calls,1);release();await saving;assert.equal(p.data.savedId,'c1');assert.match(p.data.error,/已保存/);await p.save();assert.equal(calls,1);});
test('empty contact omitted and errors preserve customer draft',async()=>{const p=load({user,epoch:()=>1,request:async(_,__,data)=>{assert.equal(data.contact,undefined);throw Error('权限已变更');}});p.data.draft.name='客户';await p.save();assert.equal(p.data.draft.name,'客户');assert.equal(p.data.error,'权限已变更');assert.equal(p.data.busy,false);});
test('missing customer, partial contact and forbidden users never submit',async()=>{let calls=0;const api={user,epoch:()=>1,request:async()=>calls++};const p=load(api);p.data.moreOpen=true;await p.save();assert.equal(p.data.moreOpen,false);p.data.draft.name='客户';p.data.draft.contactPhone='123';await p.save();assert.match(p.data.error,/联系人/);api.user=()=>({roles:[]});p.data.draft.contactName='联系人';await p.save();assert.equal(calls,0);});
test('logout during save does not expose old customer or navigate',async()=>{let epoch=1,release;const p=load({user,epoch:()=>epoch,request:async()=>{await new Promise(r=>release=r);return {customer:{id:'private'}};}});p.data.draft.name='客户';const saving=p.save();epoch++;release();await saving;assert.equal(p.data.savedId,'');});
test('customer list preserves query pagination and existing rows while exposing permitted create entry',async()=>{
 let definition,params,navigated;const api={user,epoch:()=>1};const business={...b,list:async(object,values)=>{assert.equal(object,'customer');params=values;return {records:{data:[{id:'existing',contacts:[{name:'联系人'}],cooperation_projects:[{id:'project'}]}],current_page:2,last_page:4,total:70}};}};
 vm.runInNewContext(fs.readFileSync(__dirname+'/../pages/customers/customers.js','utf8'),{Page:d=>definition=d,require:n=>n.endsWith('/api')?api:n.endsWith('/business')?business:{run:async(p,f)=>p.setData(await f()),navigate:url=>navigated=url}});
 const p={...definition,data:structuredClone(definition.data),setData(d){Object.assign(this.data,d);}};p.data.query='客户';await p.load(2);assert.equal(params.q,'客户');assert.equal(params.page,2);assert.equal(p.data.last,4);assert.equal(p.data.total,70);assert.equal(p.data.customers[0].contacts[0].name,'联系人');assert.equal(p.data.customers[0].cooperation_projects[0].id,'project');assert.equal(p.data.canCreate,true);p.create();assert.match(navigated,/customer-create/);
 api.user=()=>({roles:[{name:'finance'}]});await p.load(2);assert.equal(p.data.canCreate,false);navigated=null;p.create();assert.equal(navigated,null);
});
test('project secondary form prefills search, returns draft without writing, and preserves optional values when folded',async()=>{
 let receive,returned,navigations=0,calls=0;const p=load({user,epoch:()=>1,request:async()=>calls++},{navigateBack(){navigations++;}},{attach(){}});
 p.getOpenerEventChannel=()=>({on(_,fn){receive=fn;},emit(_,d){returned=d;}});p.onLoad({from:'project'});receive({name:' 搜索的客户 ',level:'A',customer_nature:'国央企',contactName:'张工',contactPhone:'123'});
 assert.equal(p.data.draft.name,' 搜索的客户 ');assert.equal(p.data.moreOpen,false);assert.equal(p.data.levelIndex,1);assert.equal(p.data.natureIndex,1);p.toggleMore();p.toggleMore();assert.equal(p.data.draft.level,'A');
 await p.save();await p.save();assert.equal(calls,0);assert.equal(navigations,1);assert.equal(returned.name,'搜索的客户');assert.equal(returned.customer_nature,'国央企');assert.equal(returned.contactName,'张工');
});
test('secondary form cancellation and stale session never emit or create customer',async()=>{
 let received,emits=0,calls=0,epoch=1;const p=load({user,epoch:()=>epoch,request:async()=>calls++},{},{attach(){},unload(){}});
 p.getOpenerEventChannel=()=>({on(_,fn){received=fn;},emit(){emits++;}});p.onLoad({from:'project'});received({name:'取消客户'});p.onUnload();assert.equal(calls,0);assert.equal(emits,0);
 p.disposed=false;epoch++;await p.save();assert.equal(emits,0);assert.equal(calls,0);assert.match(p.data.error,/失效/);
});
test('secondary navigation failure retains data and can return again without a business write',async()=>{
 let emits=0;const p=load({user,epoch:()=>1,request:async()=>assert.fail('no write')},{navigateBack(o){o.fail();}});
 p.data.fromProject=true;p.sessionEpoch=1;p.channel={emit(){emits++;}};p.data.draft.name='客户';await p.save();assert.equal(p.data.busy,false);assert.match(p.data.error,/返回/);assert.equal(p.data.draft.name,'客户');await p.save();assert.equal(emits,2);
});

test('optional fields use a short secondary view and retain a fixed primary action',()=>{
 const html=fs.readFileSync(__dirname+'/../pages/customer-create/customer-create.wxml','utf8');assert.match(html,/wx:if="{{!moreOpen}}"/);assert.match(html,/返回基本资料/);assert.ok(html.indexOf('class="customer-footer"')>html.indexOf('</scroll-view>'));
});
