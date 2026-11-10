const api=require('../../services/api'),u=require('../../utils/page'),oa=require('./common'),attachments=require('../../services/attachments');
Page({data:{record:null,loading:false,error:'',expenses:false,progress:true,cc:true,busy:false,canApprove:false,decisionNote:'',decisionResult:''},onLoad(o){this.id=o.id;u.attach(this,{record:null,canApprove:false,decisionNote:'',decisionResult:'',busy:false});},onUnload:u.unload,onShow(){if(u.requireLogin())this.refresh();},refresh(){if(this.data.busy)return;this.setData({canApprove:false,record:null});return u.run(this,async()=>{await api.session();const record=oa.display((await api.request('/reimbursements/versions/'+this.id)).record);return {record,canApprove:oa.isAdmin()&&record.can_decide};});},toggle(e){const k=e.currentTarget.dataset.key;this.setData({[k]:!this.data[k]});},version(e){u.navigate('/pages/reimbursements/detail?id='+e.currentTarget.dataset.id);},resubmit(){u.navigate('/pages/reimbursements/form?id='+this.id);},
 note(e){if(!this.data.busy)this.setData({decisionNote:e.detail.value});},
 async decide(e){
  if(this.data.busy||this.data.loading||!this.data.canApprove||!oa.isAdmin())return;
  const decision=e.currentTarget.dataset.decision,record=this.data.record,note=this.data.decisionNote.trim();
  if(!['approve','reject'].includes(decision))return;
  if(decision==='reject'&&!note){this.setData({error:'请填写驳回原因'});return;}
  const epoch=api.epoch(),sequence=this.sequence,actor=api.user().id;
  const current=()=>epoch===api.epoch()&&sequence===this.sequence;
  this.setData({busy:true,error:'',decisionResult:''});
  let refresh=false,sent=false;
  try{
   const confirmed=await new Promise((resolve,reject)=>wx.showModal({title:decision==='approve'?'确认通过报销？':'确认驳回报销？',content:record.title+' · ¥'+record.total,success:r=>resolve(r.confirm),fail:()=>reject(Error('无法确认，请重试'))}));
   if(!current()||!confirmed)return;
   await api.session();
   if(!current())return;
   if(api.user()?.id!==actor||!oa.isAdmin()){this.setData({canApprove:false,record:null,decisionNote:''});throw Error('当前账号无权审批，请刷新');}
   sent=true;
   const response=await api.request('/reimbursements/versions/'+record.id+'/decision','POST',{node_index:record.node_index,decision,note});
   if(!current())return;
   const updated=oa.display(response.record);
   this.setData({record:updated,canApprove:oa.isAdmin()&&updated.can_decide,decisionNote:'',decisionResult:decision==='approve'?'已提交通过意见':'已驳回'});
  }catch(error){
   if(current()){
    refresh=error.status===403||error.status===409;
    this.setData({canApprove:error.status===422&&oa.isAdmin(),error:!error.status&&sent?error.message+'；审批结果尚未确认，请刷新核对后再操作':error.message});
   }
  }finally{if(current())this.setData({busy:false});}
  if(refresh&&current()){
   const message=this.data.error;
   await this.refresh();
   if(epoch===api.epoch()&&this.sequence===sequence+1)this.setData({error:message});
  }
 },
 async file(e){if(this.data.busy)return;const {i,j,action}=e.currentTarget.dataset,file=this.data.record.items[i].attachments[j];this.setData({busy:true,error:''});try{if(action==='preview')await attachments.open({content_url:file.preview_url,kind:file.mime==='application/pdf'?'pdf':'image',name:file.name});else await oa.download(file.download_url,file.name);}catch(e){this.setData({error:e.message});}finally{this.setData({busy:false});}},
 async zip(){if(this.data.busy)return;this.setData({busy:true,error:''});try{const r=this.data.record;await oa.download(r.zip_url,'BX-'+r.reimbursement_id+'-V'+r.number+'-发票.zip');}catch(e){this.setData({error:e.message});}finally{this.setData({busy:false});}}
});
