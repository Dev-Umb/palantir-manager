const api=require('../../services/api');
const b=require('../../services/business');
const u=require('../../utils/page');
const empty={name:'',address:'',level:'',remark:'',contactName:'',contactPhone:''};
Page({
 data:{draft:{...empty},levels:['未设置','A','B','C'],allowed:false,busy:false,savedId:'',error:''},
 onLoad(){this.disposed=false;u.attach(this,{draft:{...empty},allowed:false,busy:false,savedId:''});},
 onShow(){if(u.requireLogin())this.setData({allowed:b.canCreateCustomer(api.user())});},
 onUnload(){this.disposed=true;u.unload.call(this);},
 input(e){if(!this.data.busy&&!this.data.savedId)this.setData({['draft.'+e.currentTarget.dataset.key]:e.detail.value});},
 level(e){if(!this.data.busy&&!this.data.savedId)this.setData({'draft.level':Number(e.detail.value)?this.data.levels[Number(e.detail.value)]:''});},
 openSaved(){if(this.data.savedId)wx.redirectTo({url:'/pages/customer/customer?id='+encodeURIComponent(this.data.savedId),fail:()=>{if(!this.disposed)this.setData({error:'客户已保存，请返回客户列表查看，勿重复新增。'});}});},
 async save(){
  if(this.data.busy||!b.canCreateCustomer(api.user()))return;
  if(this.data.savedId){this.openSaved();return;}
  const d=Object.fromEntries(Object.entries(this.data.draft).map(([k,v])=>[k,v.trim()]));
  if(!d.name){this.setData({error:'请填写客户名称'});return;}
  if(d.contactPhone&&!d.contactName){this.setData({error:'填写电话时请同时填写联系人姓名'});return;}
  const epoch=api.epoch();
  this.setData({busy:true,error:''});
  try{
   const payload={name:d.name,address:d.address,level:d.level,remark:d.remark};
   if(d.contactName)payload.contact={name:d.contactName,phone:d.contactPhone};
   const result=await api.request('/project-customers','POST',payload);
   if(this.disposed||epoch!==api.epoch())return;
   this.setData({savedId:result.customer.id});
   wx.showToast({title:'客户已新增',icon:'success'});
   this.openSaved();
  }catch(e){if(!this.disposed&&epoch===api.epoch())this.setData({error:e.message||'保存失败，请重试'});}
  finally{if(!this.disposed&&epoch===api.epoch())this.setData({busy:false});}
 }
});
