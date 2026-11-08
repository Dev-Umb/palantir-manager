const api=require('../../services/api');
Page({
 data:{loading:true,busy:false,error:'',account:'',status:'pending'},
 onLoad(o={}){let scene='';try{scene=decodeURIComponent(o.scene||'');}catch(_){}this.id=/^[a-f0-9]{32}$/.test(scene)?scene:'';this.load();},
 async load(){if(!this.id){this.setData({loading:false,error:'登录码无效，请在电脑端刷新'});return;}try{if(!api.user())await api.session();if(!api.user()){wx.redirectTo({url:'/pages/login/login?webLogin='+this.id});return;}const r=await api.request('/miniapp/web-login/'+this.id);this.setData({account:r.account,status:r.status});}catch(e){this.setData({error:e.message});}finally{this.setData({loading:false});}},
 async decide(e){if(this.data.busy||this.data.loading||this.data.error||this.data.status!=='pending')return;this.setData({busy:true});try{const r=await api.request('/miniapp/web-login/'+this.id,'POST',{confirm:e.currentTarget.dataset.confirm==='yes'});this.setData({status:r.status});}catch(e){this.setData({error:e.message});}finally{this.setData({busy:false});}}
});
