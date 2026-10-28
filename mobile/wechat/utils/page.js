const api=require('../services/api');
function attach(page, empty) { page.release=api.onClear(()=>{page.sequence=(page.sequence||0)+1;page.setData({...empty,user:null,loading:false,error:'请先登录'});}); }
async function run(page, action) {
 const sequence=page.sequence=(page.sequence||0)+1, epoch=api.epoch();
 page.setData({error:'',loading:true,user:api.user()});
 try {const data=await action();if(sequence===page.sequence&&epoch===api.epoch())page.setData(data||{});}
 catch(error){if(sequence===page.sequence&&epoch===api.epoch())page.setData({error:error.message||'读取失败，请重试'});}
 finally{if(sequence===page.sequence)page.setData({loading:false,refreshing:false});}
}
let navigating=false;
function navigate(url){if(navigating)return;navigating=true;wx.navigateTo({url,complete(){navigating=false;},fail(error){wx.showModal({title:'无法打开页面',content:(error&&error.errMsg)||'请返回工作台后重试',showCancel:false});}});}
function login(){if(navigating)return;navigating=true;wx.reLaunch({url:'/pages/login/login',complete(){navigating=false;},fail(error){wx.showModal({title:'无法打开登录页',content:(error&&error.errMsg)||'请重新打开小程序',showCancel:false});}});}
function requireLogin(){if(api.user())return true;login();return false;}
function unavailable(){wx.showModal({title:'暂未接入',content:'请在平台网页使用此功能。',showCancel:false});}
function openProject(e){navigate('/pages/detail/detail?id='+encodeURIComponent(e.currentTarget.dataset.id));}
function openTool(e){navigate('/pages/tools/tools?kind='+encodeURIComponent(e.currentTarget.dataset.kind));}
function unload(){this.sequence=(this.sequence||0)+1;if(this.release)this.release();}
module.exports={requireLogin,navigate,attach,run,login,unavailable,openProject,openTool,unload};
