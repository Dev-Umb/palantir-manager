const api=require('../../services/api');
const b=require('../../services/business');
const u=require('../../utils/page');
const empty={name:'',address:'',level:'',customer_nature:'',remark:'',contactName:'',contactPhone:''};
Page({
 data:{levelIndex:0,natureIndex:0,moreOpen:false,fromProject:false,natures:['未设置','国央企','私企'],draft:{...empty},levels:['未设置','A','B','C'],allowed:false,busy:false,savedId:'',error:''},
 onLoad(q={}){this.disposed=false;this.sessionEpoch=api.epoch();this.setData({fromProject:q.from==='project'});if(this.data.fromProject){this.channel=this.getOpenerEventChannel();this.channel.on('customerDraft',draft=>{if(!this.disposed&&this.sessionEpoch===api.epoch())this.setData({draft:{...empty,...draft},levelIndex:Math.max(0,this.data.levels.indexOf(draft.level)),natureIndex:Math.max(0,this.data.natures.indexOf(draft.customer_nature))});});}u.attach(this,{draft:{...empty},allowed:false,busy:false,savedId:''});},
 onShow(){if(u.requireLogin())this.setData({allowed:b.canCreateCustomer(api.user())});},
 onUnload(){this.disposed=true;u.unload.call(this);},
 input(e){if(!this.data.busy&&!this.data.savedId)this.setData({['draft.'+e.currentTarget.dataset.key]:e.detail.value});},
 level(e){if(!this.data.busy&&!this.data.savedId)this.setData({levelIndex:Number(e.detail.value),'draft.level':Number(e.detail.value)?this.data.levels[Number(e.detail.value)]:''});},
 nature(e){if(!this.data.busy)this.setData({natureIndex:Number(e.detail.value),'draft.customer_nature':Number(e.detail.value)?this.data.natures[Number(e.detail.value)]:''});},
 toggleMore(){this.setData({moreOpen:!this.data.moreOpen});},
 openSaved(){if(this.data.savedId)wx.redirectTo({url:'/pages/customer/customer?id='+encodeURIComponent(this.data.savedId),fail:()=>{if(!this.disposed)this.setData({error:'客户已保存，请返回客户列表查看，勿重复新增。'});}});},
 async save(){
  if(this.data.busy||!b.canCreateCustomer(api.user()))return;
  if(this.data.savedId){this.openSaved();return;}
  const d=Object.fromEntries(Object.entries(this.data.draft).map(([k,v])=>[k,v.trim()]));
  if(!d.name){this.setData({moreOpen:false,error:'请填写客户名称'});return;}
  if(d.contactPhone&&!d.contactName){this.setData({moreOpen:false,error:'填写电话时请同时填写联系人姓名'});return;}
  const epoch=api.epoch();
  if(this.data.fromProject){
   if(this.sessionEpoch!==epoch||!this.channel){this.setData({error:'项目页面已失效，请返回项目后重试'});return;}
   this.setData({busy:true,error:''});
   this.channel.emit('customerDraft',d);
   wx.navigateBack({fail:()=>{if(!this.disposed&&epoch===api.epoch())this.setData({busy:false,error:'无法返回项目，请点击左上角返回，填写内容已带回项目'});}});
   return;
  }
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
