const api=require('../../services/api');
const b=require('../../services/business');
const u=require('../../utils/page');
Page({
 data:{user:null,unpaid:'—',occurred:'—',paid:'—',projectCount:'—',coverage:'',projects:[],notices:[],count:0,canCreate:false,error:'',loading:false},
 onLoad(){u.attach(this,{unpaid:'—',occurred:'—',paid:'—',projectCount:'—',coverage:'',projects:[],notices:[],count:0,canCreate:false});},
 onUnload:u.unload,onShow(){if(u.requireLogin())this.refresh();},
 refresh(){return u.run(this,async()=>{if(!api.user())return {};const p=await api.dashboard();return {...b.home(p),canCreate:(api.user().permissions||[]).includes('object.project.create')};});},
 reimbursement(){u.navigate('/pages/reimbursements/index');},
 login:u.login,openProject:u.openProject,openTool:u.openTool,
 projects(){wx.switchTab({url:'/pages/projects/projects'});},
 create(){u.navigate('/pages/edit/edit');}
});
