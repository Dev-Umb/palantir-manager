const api=require('../../services/api');
const b=require('../../services/business');
const u=require('../../utils/page');
Page({
 data:{user:null,unpaid:'—',occurred:'—',paid:'—',projectCount:'—',coverage:'',projects:[],notices:[],count:0,canCreate:false,canReimburse:false,canProcurement:false,error:'',loading:false},
 onLoad(){u.attach(this,{unpaid:'—',occurred:'—',paid:'—',projectCount:'—',coverage:'',projects:[],notices:[],count:0,canCreate:false,canReimburse:false,canProcurement:false});},
 onUnload:u.unload,onShow(){if(u.requireLogin())this.refresh();},
 refresh(){return u.run(this,async()=>{if(!api.user())return {};const p=await api.dashboard();return {...b.home(p),canProcurement:(p.nav||[]).some(n=>n.key==='hub'&&n.visible===true),canCreate:(api.user().permissions||[]).includes('object.project.create'),canReimburse:(api.user().permissions||[]).includes('reimbursements.submit')};});},
 createReimbursement(){if((api.user()?.permissions||[]).includes('reimbursements.submit'))u.navigate('/pages/reimbursements/form');},
 login:u.login,openProject:u.openProject,openTool:u.openTool,
 projects(){wx.switchTab({url:'/pages/projects/projects'});},
 create(){u.navigate('/pages/edit/edit');}
});
