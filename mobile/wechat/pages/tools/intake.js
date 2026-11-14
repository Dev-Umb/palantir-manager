const api=require('../../services/api');
const statusLabels={staged:'排队识别',analyzing:'识别中',review:'待核对',failed:'识别失败',confirmed:'已归档'};
module.exports={
 async loadIntakes(){return this.act(async()=>{const r=await api.request('/ai/contracts');this.safeData({intakes:(r.intakes||[]).map(i=>({...i,statusLabel:statusLabels[i.status]||i.status}))});});},
 selectContractFile(file){
  if(!file||!file.path){this.safeData({error:'未取得文件，请重新选择'});return;}
  if(!Number.isFinite(file.size)||file.size<=0){this.safeData({error:'文件为空或大小无效，请重新选择'});return;}
  if(file.size>100*1024*1024){this.safeData({error:'文件不能超过 100 MB'});return;}
  if(!/\.(pdf|jpe?g|png)$/i.test(file.name)){this.safeData({error:'请选择 PDF、JPG 或 PNG 文件'});return;}
  this.safeData({uploadFile:file,error:''});
 },
 chooseFile(){
  if(this.data.busy||this.data.intakeActive)return;
  wx.chooseMessageFile({count:1,type:'all',success:r=>this.selectContractFile(r.tempFiles&&r.tempFiles[0]),fail:e=>{if(!/cancel/i.test(e.errMsg||''))this.safeData({error:'选择文件失败，请重试'});}});
 },
 chooseContractImage(){
  if(this.data.busy||this.data.intakeActive)return;
  wx.chooseMedia({count:1,mediaType:['image'],sourceType:['album','camera'],sizeType:['original'],success:r=>{
   const file=r.tempFiles&&r.tempFiles[0];
   if(!file){this.safeData({error:'未取得图片，请重新选择'});return;}
   const path=file.tempFilePath;
   this.selectContractFile({path,size:file.size,name:(path||'').split('/').pop()});
  },fail:e=>{if(!/cancel/i.test(e.errMsg||''))this.safeData({error:'选择图片失败，请检查相册或相机权限后重试'});}});
 },
 uploadContract(){if(!this.data.uploadFile||this.data.busy||this.data.intakeActive)return;return this.confirmAction('上传合同并识别','文件将上传到线上平台并调用 AI 识别，归档前需要你核对确认。',async()=>{const r=await api.upload('/ai/contracts',this.data.uploadFile.path);if(this.isCurrent()){this.applyIntake(r);this.schedule();}});},
 applyIntake(intake){const changed=!this.data.intake||this.data.intake.id!==intake.id||this.data.intake.status!==intake.status;this.safeData({intake,intakeStatus:statusLabels[intake.status]||intake.status,intakeActive:['staged','analyzing'].includes(intake.status)});if(changed){const e=intake.extraction||{};this.safeData({intakeFields:{amount:e.amount==null?'':e.amount,ctype:e.ctype||'',signed_date:e.signed_date||'',contract_qty:e.contract_qty==null?'':e.contract_qty},intakeProject:null,intakeProjects:[],contractChoice:'',intakePreview:null,updateAmount:false,projectWeight:e.weight_tonnes==null?'':e.weight_tonnes,projectSearch:e.project_no||e.project_name||''});}},
 async openIntake(e){return this.act(async()=>{const r=await api.request('/ai/contracts/'+encodeURIComponent(e.currentTarget.dataset.id));if(this.isCurrent()){this.applyIntake(r);this.schedule();}});},
 async pollIntake(){if(!this.data.intake)return;return this.act(async()=>{const r=await api.request('/ai/contracts/'+encodeURIComponent(this.data.intake.id));if(this.isCurrent())this.applyIntake(r);});},
 retryIntake(){return this.confirmAction('重新识别','重新调用 AI 识别这个文件。',async()=>{const r=await api.request('/ai/contracts/'+encodeURIComponent(this.data.intake.id)+'/retry','POST');if(this.isCurrent()){this.applyIntake(r);this.schedule();}});},
 projectSearchInput(e){this.setData({projectSearch:e.detail.value});},
 async searchIntakeProjects(){return this.act(async()=>{const r=await api.request('/ai/contracts/projects?'+api.query({search:this.data.projectSearch}));this.safeData({intakeProjects:r.projects,projectsTruncated:!!r.truncated});});},
 async selectIntakeProject(e){if(this.data.busy)return;this.setData({intakeProject:null,contractChoice:'',intakePreview:null,updateAmount:false});return this.act(async()=>{const r=await api.request('/ai/contracts/projects/'+encodeURIComponent(e.currentTarget.dataset.id));this.safeData({intakeProject:r,intakeProjects:[]});});},
 selectIntakeContract(e){const id=e.currentTarget.dataset.id;const old=(this.data.intakeProject.contracts||[]).find(c=>c.id===id);const extracted=this.data.intake.extraction||{};const fields={};['amount','ctype','signed_date','contract_qty'].forEach(k=>{fields[k]=extracted[k]!=null?extracted[k]:old&&old.fields[k]!=null?old.fields[k]:'';});this.setData({contractChoice:id,intakeFields:fields,intakePreview:null});},
 intakeInput(e){this.setData({['intakeFields.'+e.currentTarget.dataset.key]:e.detail.value,intakePreview:null});},
 weightInput(e){this.setData({projectWeight:e.detail.value,intakePreview:null});},
 amountSwitch(e){this.setData({updateAmount:e.detail.value,intakePreview:null});},
 previewIntake(){if(!this.data.intakeProject||!this.data.contractChoice){this.setData({error:'请选择项目和新建/已有合同'});return;}return this.act(async()=>{const r=await api.request('/ai/contracts/'+encodeURIComponent(this.data.intake.id)+'/preview','POST',{project_id:this.data.intakeProject.id,contract_id:this.data.contractChoice==='__new__'?null:this.data.contractChoice,fields:Object.fromEntries(Object.entries(this.data.intakeFields).map(([k,v])=>[k,v===''?null:v])),update_project_amount:this.data.updateAmount,project_weight:this.data.projectWeight===''?null:this.data.projectWeight});this.safeData({intakePreview:r});});},
 confirmIntake(){if(!this.data.intakePreview)return;return this.confirmAction('确认归档到线上项目','请核对下方全部差异与联动影响。确认后将更新真实合同及项目。',async()=>{try{const r=await api.request('/ai/contracts/'+encodeURIComponent(this.data.intake.id)+'/confirm','POST',{token:this.data.intakePreview.token});if(this.isCurrent())this.applyIntake({...this.data.intake,status:'confirmed',result:r});}catch(e){this.safeData({intakePreview:null});throw e;}});}
};
