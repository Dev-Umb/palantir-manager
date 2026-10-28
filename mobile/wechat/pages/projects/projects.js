const api=require('../../services/api');const b=require('../../services/business');const u=require('../../utils/page');
Page({
 data:{user:null,projects:[],query:'',statuses:['全部'],status:'全部',owners:[{id:'',label:'全部业务员'}],ownerId:'',ownerLabel:'全部业务员',page:1,last:1,total:0,canCreate:false,error:'',loading:false},
 onLoad(){u.attach(this,{projects:[],total:0,canCreate:false,owners:[{id:'',label:'全部业务员'}],ownerId:'',ownerLabel:'全部业务员'});},
 onUnload(){clearTimeout(this.searchTimer);u.unload.call(this);},onHide(){clearTimeout(this.searchTimer);},onShow(){if(u.requireLogin())this.refresh();},
 refresh(){clearTimeout(this.searchTimer);return this.load(1);},
 load(page){return u.run(this,async()=>{if(!api.user())return {};const params={page,q:this.data.query.trim()};let index=0;
 if(this.data.status!=='全部'){params['filters['+index+'][field]']='overall_status';params['filters['+index+'][operator]']='equals';params['filters['+index+'][value]']=this.data.status;index++;}
 if(this.data.ownerId){params['filters['+index+'][field]']='business_owner_user_id';params['filters['+index+'][operator]']='equals';params['filters['+index+'][value]']=this.data.ownerId;}
 const p=await b.list('project',params);const f=(p.currentObject.fields||[]).find(f=>f.key==='overall_status');const ownerOptions=p.relationOptions&&p.relationOptions.business_owner_user_id;
 return {projects:p.records.data.map(b.project),page:p.records.current_page,last:p.records.last_page,total:p.records.total,canCreate:p.can.create,statuses:['全部',...(f&&f.options||[])],owners:[{id:'',label:'全部业务员'},...(ownerOptions&&ownerOptions.items||[])]};});},
 input(e){clearTimeout(this.searchTimer);this.setData({query:e.detail.value});this.sequence=(this.sequence||0)+1;this.searchTimer=setTimeout(()=>this.refresh(),350);},
 search(){if(wx.hideKeyboard)wx.hideKeyboard();return this.refresh();},
 filter(e){const status=e.currentTarget.dataset.status;if(!this.data.statuses.includes(status))return;this.setData({status});return this.refresh();},
 ownerChange(e){const owner=this.data.owners[Number(e.detail.value)];if(!owner)return;this.setData({ownerId:String(owner.id),ownerLabel:owner.label});return this.refresh();},
 clearFilters(){this.setData({query:'',status:'全部',ownerId:'',ownerLabel:'全部业务员'});return this.refresh();},
 previous(){if(this.data.page>1&&!this.data.loading)return this.load(this.data.page-1);},next(){if(this.data.page<this.data.last&&!this.data.loading)return this.load(this.data.page+1);},openProject:u.openProject,login:u.login,create(){u.navigate('/pages/edit/edit');}
});
