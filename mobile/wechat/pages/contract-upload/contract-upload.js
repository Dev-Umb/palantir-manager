const api=require('../../services/api');
const b=require('../../services/business');
const u=require('../../utils/page');
const picker=require('../tools/intake');
Page({
 data:{projectName:'',contractName:'',existing:false,allowed:false,loading:false,busy:false,error:'',uploadFile:null,intakeActive:false,statusIndex:0,statuses:['未签署','已签署'],typeIndex:0,types:['未填写','销售合同','加工合同','补充协议'],amount:'',signedDate:'',saved:false,uncertain:false},
 onLoad(q){this.project=q.project;this.contract=q.contract||'';this.epoch=api.epoch();this.disposed=false;u.attach(this,{projectName:'',contractName:'',allowed:false,uploadFile:null,amount:'',signedDate:'',busy:false});this.refresh();},
 onUnload(){this.disposed=true;u.unload.call(this);},
 isCurrent(){return !this.disposed&&this.epoch===api.epoch();},
 safeData(data){if(this.isCurrent())this.setData(data);},
 async refresh(){return u.run(this,async()=>{const r=await b.detail(this.project);const contract=(r.selectedRecord.contracts||[]).find(c=>c.id===this.contract);if(this.contract&&!contract)throw Error('合同不存在或无权查看');return {projectName:r.project.name,existing:!!this.contract,contractName:contract?(contract.title||contract.code):'新建合同',allowed:!!(r.can.manage_contracts&&r.can.update&&r.selectedRecord.can_update!==false)};});},
 selectContractFile:picker.selectContractFile,
 chooseFile(){if(this.data.allowed&&!this.data.saved&&!this.data.uncertain)picker.chooseFile.call(this);},
 chooseContractImage(){if(this.data.allowed&&!this.data.saved&&!this.data.uncertain)picker.chooseContractImage.call(this);},
 input(e){if(!this.data.busy)this.setData({[e.currentTarget.dataset.key]:e.detail.value});},
 back(){wx.navigateBack();},
 async submit(){
  if(!this.isCurrent()||!this.data.allowed||this.data.busy||this.data.saved||this.data.uncertain)return;
  const d=this.data;
  if(!d.uploadFile){this.setData({error:'请先选择合同文件'});return;}
  if(!d.existing&&d.amount.trim()!==''&&!Number.isFinite(Number(d.amount))){this.setData({error:'请填写有效金额，未确认可留空'});return;}
  this.setData({busy:true,error:''});
  let sent=false;
  try{
   const confirmed=await new Promise(resolve=>wx.showModal({title:'上传合同附件',content:d.projectName+' · '+d.contractName+'\n'+d.uploadFile.name+'\n文件将直接保存，不进行 AI 识别。',success:r=>resolve(!!r.confirm),fail:()=>resolve(false)}));
   if(!confirmed||!this.isCurrent())return;
   const form=this.contract?{contract_id:this.contract}:{status:d.statuses[Number(d.statusIndex)],ctype:d.types[Number(d.typeIndex)]==='未填写'?'':d.types[Number(d.typeIndex)],amount:d.amount.trim(),signed_date:d.signedDate};
   sent=true;
   await api.upload('/projects/'+encodeURIComponent(this.project)+'/contract-uploads',d.uploadFile.path,form);
   this.safeData({saved:true,uploadFile:null});
  }catch(e){
   const uncertain=sent&&(!e.status||e.status>=500);
   this.safeData({uncertain,error:uncertain?'上传结果尚未确认，请返回项目核对附件后再操作，避免重复上传。':e.message});
  }finally{this.safeData({busy:false});}
 }
});
