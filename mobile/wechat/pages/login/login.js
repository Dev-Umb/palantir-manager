const api=require('../../services/api');
Page({
 data:{binding:false,bindingCode:'',busy:false,loading:true,configured:false,error:''},
 async onLoad(options={}){
  this.webLogin=/^[a-f0-9]{32}$/.test(options.webLogin||'')?options.webLogin:'';
  this.noticePage = options.notice === 'approval' ? '/pages/reimbursements/index?approval=1'
   : options.notice === 'result' ? '/pages/reimbursements/index' : '';
  if(!this.noticePage){await this.refresh();return;}
  try {
   if(!api.user()) await api.wechatLogin();
   this.openNotice();
  } catch(error) {
   await this.refresh();
   this.setData({error:error.message});
  }
 },
 openNotice(){
  wx.redirectTo({url:this.noticePage,fail:()=>this.setData({loading:false,configured:api.wechatConfigured(),error:'无法打开报销页面，请重新登录后重试。'})});
 },
 async refresh(){if(this.data.busy)return;this.setData({loading:true,error:''});try{await api.session();this.setData({configured:api.wechatConfigured()});}catch(e){this.setData({error:e.message});}finally{this.setData({loading:false});}},
 input(e){this.setData({bindingCode:e.detail.value.toUpperCase().replace(/\s/g,'')});},
 toggleBinding(){if(!this.data.busy)this.setData({binding:!this.data.binding,bindingCode:'',error:''});},
 async submit(){if(this.data.busy||this.data.loading||!this.data.configured)return;
 if(this.data.binding&&!/^[A-F0-9]{16}$/.test(this.data.bindingCode)){this.setData({error:'请输入后台生成的 16 位绑定码'});return;}
 this.setData({busy:true,error:''});try{await api.wechatLogin(this.data.binding?this.data.bindingCode:'');this.setData({bindingCode:''});if(this.noticePage){this.openNotice();return;}if(this.webLogin){wx.redirectTo({url:'/pages/web-login/web-login?scene='+this.webLogin});return;}wx.switchTab({url:'/pages/index/index',fail:()=>this.setData({error:'登录成功，但工作台未打开，请重新打开小程序。'})});}catch(e){this.setData({error:e.message});}finally{this.setData({busy:false});}}
});
