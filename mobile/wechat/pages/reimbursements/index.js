const api=require('../../services/api'),u=require('../../utils/page'),oa=require('./common');
Page({data:{scope:'mine',status:'',type:'',rows:[],page:1,last:1,loading:false,deleting:'',error:'',canSubmit:false,batchEnabled:false,batches:[],batchPage:1,batchLast:1,statusLabels:['全部状态','审批中','审批通过','已驳回'],types:['全部类型','差旅交通','客户招待','办公采购','其他'],statusIndex:0,typeIndex:0},
 onLoad(q={}){if(q.scope==='drafts')this.setData({scope:'drafts'});u.attach(this,{rows:[]});},onUnload(){clearTimeout(this.batchTimer);this.visible=false;u.unload.call(this);},onHide(){this.visible=false;clearTimeout(this.batchTimer);},onShow(){this.visible=true;if(u.requireLogin())this.refresh();},
 refresh(){clearTimeout(this.batchTimer);return u.run(this,async()=>{const [r,o]=await Promise.all([api.request((this.data.scope==='drafts'?'/reimbursements/drafts?':'/reimbursements?')+api.query({scope:this.data.scope,status:this.data.status,type:this.data.type,page:this.data.page})),api.request('/reimbursements/options')]);let batches=[],batchLast=1;if(o.batch_enabled&&o.capabilities.submit){const result=await api.request('/reimbursements/batches?page='+this.data.batchPage);batchLast=result.records.last_page;batches=result.records.data.filter(b=>b.status!=='uploading').slice(0,5).map(b=>({...b,label:{uploading:'等待上传',processing:'正在识别',attention:'已整理，请查看草稿',completed:'已整理，请查看草稿'}[b.status]}));}if(this.visible && this.data.scope==='drafts' && batches.some(b=>b.status==='processing'))this.batchTimer=setTimeout(()=>this.refresh(),5000);return {batchLast,batchEnabled:o.batch_enabled,batches,types:['全部类型',...o.types],typeLabels:['全部类型',...o.types.map(t=>t==='差旅交通'?'差旅交通费':t)],rows:r.records.data.map(r=>this.data.scope==='drafts'?{id:r.id,revision:r.revision,title:r.payload.title||'未命名报销',batchId:r.batch_id,unread:r.unread,type:r.payload.type||'未选择类型',date:oa.timestamp(r.updated_at),state:'草稿',total:oa.money(r.payload.items.reduce((sum,i)=>sum+(oa.cents(i.amount)||0),0))}:oa.display(r)),last:r.records.last_page,canSubmit:o.capabilities.submit};});},
 scope(e){this.setData({scope:e.currentTarget.dataset.scope,page:1});this.refresh();},filter(e){const key=e.currentTarget.dataset.key,index=Number(e.detail.value);this.setData(key==='status'?{status:['','pending','approved','rejected'][index],statusIndex:index,page:1}:{type:index?this.data.types[index]:'',typeIndex:index,page:1});this.refresh();},
 previous(){if(this.data.page>1){this.setData({page:this.data.page-1});this.refresh();}},next(){if(this.data.page<this.data.last){this.setData({page:this.data.page+1});this.refresh();}},
 async deleteDraft(e){
  const id=e.currentTarget.dataset.id,row=this.data.rows.find(r=>r.id===id);
  if(this.data.scope!=='drafts'||!row||this.data.deleting)return;
  const epoch=api.epoch();this.setData({deleting:id});
  try{
   const result=await new Promise(resolve=>wx.showModal({title:'删除草稿',content:'确定删除“'+row.title+'”吗？删除后无法继续编辑。',confirmText:'删除',confirmColor:'#c0392b',cancelText:'取消',success:resolve,fail:()=>resolve({confirm:false})}));
   if(!result.confirm||epoch!==api.epoch()||!this.visible)return;
   await api.request('/reimbursements/drafts/'+id,'DELETE',{revision:row.revision});
   if(epoch!==api.epoch()||!this.visible)return;
   if(this.data.scope==='drafts')this.setData({rows:this.data.rows.filter(r=>r.id!==id),page:this.data.rows.length===1?Math.max(1,this.data.page-1):this.data.page});
   wx.showToast({title:'草稿已删除',icon:'success'});await this.refresh();
  }catch(error){if(epoch===api.epoch()&&this.visible)this.setData({error:error.message||'删除失败，请重试'});}
  finally{this.setData({deleting:''});}
 },
 open(e){u.navigate((this.data.scope==='drafts'?'/pages/reimbursements/form?draft=':'/pages/reimbursements/detail?id=')+e.currentTarget.dataset.id);},batch(e){u.navigate('/pages/reimbursements/batch'+(e.currentTarget.dataset.id?'?id='+e.currentTarget.dataset.id:''));},create(){u.navigate('/pages/reimbursements/form');},notices(){u.navigate('/pages/reimbursements/notices');}
});
