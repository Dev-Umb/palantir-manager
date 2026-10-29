const api=require('../../services/api'),u=require('../../utils/page'),oa=require('./common');
const emptyItem=()=>({key:oa.uuid(),description:'',invoice_date:'',amount:'',attachments:[],failed:[]});
Page({data:{title:'',note:'',typeIndex:0,types:['差旅交通','客户招待','办公采购','其他'],items:[emptyItem()],cc:[],users:[],total:'0.00',busy:false,uploading:false,error:'',loading:false},
 onLoad(o){this.closed=false;this.key=oa.uuid();this.epoch=api.epoch();this.clearDraft=api.onClear(()=>{this.cancelInvoiceReview();this.pending=null;this.previous=null;this.allowed=false;this.setData({busy:false,uploading:false});});u.attach(this,{items:[],users:[],title:'',note:'',cc:[],total:'0.00'});if(!u.requireLogin())return;u.run(this,async()=>{const options=await api.request('/reimbursements/options');this.template=options.wechat_template;this.allowed=options.capabilities.submit;if(!this.allowed)throw Error('当前账号不能发起报销');let values={};if(o.id){const r=(await api.request('/reimbursements/versions/'+o.id)).record;if(!r.can_resubmit)throw Error('此申请不可修改重提');this.previous=r;values={title:r.title,note:r.note,typeIndex:this.data.types.indexOf(r.type),items:r.items.map(i=>({...i,key:oa.uuid(),failed:[]})),cc:r.cc.map(p=>String(p.id)),total:oa.money(r.total_cents)};}return {...values,users:options.users.map(p=>({...p,checked:(values.cc||[]).includes(String(p.id))}))};});},onUnload(){this.closed=true;this.cancelInvoiceReview();if(this.clearDraft)this.clearDraft();u.unload.call(this);},
 input(e){if(this.pending)return;this.setData({[e.currentTarget.dataset.key]:e.detail.value});},type(e){if(!this.pending)this.setData({typeIndex:Number(e.detail.value)});},
 clearDate(e){this.itemInput({...e,detail:{value:''}});},
 itemInput(e){if(this.pending)return;const {i,key}=e.currentTarget.dataset,items=this.data.items;items[i][key]=e.detail.value;this.setData({items,total:oa.money(items.reduce((sum,r)=>sum+(oa.cents(r.amount)||0),0))});},
 add(){if(!this.pending && this.data.items.length<50)this.setData({items:[...this.data.items,emptyItem()]});},remove(e){if(this.pending || this.data.uploading)return;const items=this.data.items.filter((_,i)=>i!==Number(e.currentTarget.dataset.i));this.setData({items,total:oa.money(items.reduce((sum,r)=>sum+(oa.cents(r.amount)||0),0))});},
 cc(e){if(!this.pending)this.setData({cc:e.detail.value});},
 choose(e){if(this.pending || this.data.uploading)return;const i=Number(e.currentTarget.dataset.i);wx.showActionSheet({itemList:['拍照或相册','选择聊天中的发票'],success:r=>{if(r.tapIndex===0)wx.chooseMedia({count:1,mediaType:['image'],success:r=>{const f=r.tempFiles[0];this.upload(i,{path:f.tempFilePath,name:f.tempFilePath.split('/').pop(),size:f.size});}});else wx.chooseMessageFile({count:1,type:'file',extension:['pdf','jpg','jpeg','png'],success:r=>this.upload(i,r.tempFiles[0])});}});},
 async upload(i,file){
  if(this.data.uploading || this.data.busy || this.pending)return;
  if(file.size>20*1024*1024 || !/\.(pdf|jpe?g|png)$/i.test(file.name)){this.setData({error:'请选择 20 MB 以内的 PDF、JPG 或 PNG'});return;}
  const epoch=api.epoch();let row=this.data.items[i];if(!row)return;
  const retry=row.failed.some(f=>f.path===file.path);
  if(!retry && (row.attachments.length || row.failed.length)){
   if(this.data.items.length>=50){this.setData({error:'每张发票独立一项费用，最多添加 50 项。'});return;}
   row=emptyItem();this.data.items.push(row);
  }
  const started=Date.now();this.setData({items:this.data.items,uploading:true,error:''});
  try{const result=await api.upload('/reimbursements/uploads',file.path);if(this.closed || epoch!==api.epoch() || !this.data.items.includes(row))return;
   console.info('reimbursement.upload_timing',{total_ms:Date.now()-started,...result.timing});
   row.attachments.push(result.file);row.failed=row.failed.filter(f=>f.path!==file.path);this.setData({items:this.data.items});
   if(result.file.mime==='application/pdf')this.recognizeFile(result.file);
  }catch(e){if(this.closed || epoch!==api.epoch() || !this.data.items.includes(row))return;if(!row.failed.some(f=>f.path===file.path))row.failed.push(file);this.setData({items:this.data.items,error:e.message});}
  finally{if(!this.closed && epoch===api.epoch())this.setData({uploading:false});}
 },
 async recognizeFile(file){if(file.recognizing || this.pending || this.data.busy)return;const epoch=api.epoch();file.recognizing=true;file.recognitionError='';this.setData({items:this.data.items});try{const result=await api.request('/reimbursements/uploads/'+file.id+'/recognize','POST',{});if(this.closed || epoch!==api.epoch() || this.pending || this.data.busy || !this.data.items.some(r=>r.attachments.includes(file)))return;file.recognition=result;if(!Object.values(result.fields||{}).some(value=>value!==null && value!==undefined))file.recognitionError='未识别出可填写信息，请手动填写或重新识别。';this.invoiceReviews=(this.invoiceReviews||Promise.resolve()).then(()=>{const i=this.data.items.findIndex(row=>row.attachments.includes(file));if(this.closed || epoch!==api.epoch() || this.pending || this.data.busy || i<0)return;return this.applyInvoice({currentTarget:{dataset:{i,j:this.data.items[i].attachments.indexOf(file)}}});});file.recognitionLines=[['发票号码',result.fields.invoice_number],['发票代码',result.fields.invoice_code],['开票日期',result.fields.invoice_date],['购买方',result.fields.buyer],['销售方',result.fields.seller],['价税合计',result.fields.total_cents?oa.money(result.fields.total_cents)+' 元':null]].filter(p=>p[1]).map(p=>p[0]+'：'+p[1]);}catch(e){if(!this.closed && epoch===api.epoch() && !this.pending)file.recognitionError=e.message;}finally{if(!this.closed && epoch===api.epoch()){file.recognizing=false;this.setData({items:this.data.items});}}},
 recognize(e){const {i,j}=e.currentTarget.dataset;this.recognizeFile(this.data.items[i].attachments[j]);},
 applyInvoice(e){
  if(this.closed || this.pending || this.data.busy)return;
  const {i,j}=e.currentTarget.dataset,row=this.data.items[i],file=row.attachments[j],fields=file.recognition?.fields;
  if(!fields || !Object.values(fields).some(value=>value!==null && value!==undefined))return;
  const epoch=api.epoch(),previous={amount:row.amount,date:row.invoice_date||'',description:row.description};
  const amount=Number.isInteger(fields.total_cents) && fields.total_cents>0?oa.money(fields.total_cents):previous.amount;
  const date=oa.validInvoiceDate(fields.invoice_date)?fields.invoice_date:previous.date;
  const description=oa.invoiceDescription(previous.description,fields);
  if([...description].length>500){this.setData({error:'回填后费用说明超过 500 字，请先整理说明再重试。'});return;}
  if(this.reviewContext)return;
  return new Promise(resolve=>{
   this.reviewContext={row,file,epoch,previous,resolve};
   this.setData({invoiceReview:{amount,date,description,descriptionLines:description.split('\n'),name:file.name||'发票',previousAmount:previous.amount && previous.amount!==amount?previous.amount:'',previousDate:previous.date && previous.date!==date?previous.date:'',warnings:file.recognition.warnings||[]}});
  });
 },
 stopReviewTouch(){},
 cancelInvoiceReview(){const review=this.reviewContext;this.reviewContext=null;this.setData({invoiceReview:null});if(review)review.resolve();},
 confirmInvoiceReview(){
  const review=this.reviewContext,next=this.data.invoiceReview;if(!review || !next)return;
  const {row,file,epoch,previous}=review;
  if(!this.closed && !this.pending && !this.data.busy && epoch===api.epoch() && this.data.items.includes(row) && row.attachments.includes(file) && row.amount===previous.amount && (row.invoice_date||'')===previous.date && row.description===previous.description){
   row.amount=next.amount;row.invoice_date=next.date;row.description=next.description;
   this.setData({items:this.data.items,total:oa.money(this.data.items.reduce((sum,r)=>sum+(oa.cents(r.amount)||0),0))});
  }
  this.cancelInvoiceReview();
 },
 retry(e){const {i,j}=e.currentTarget.dataset;this.upload(i,this.data.items[i].failed[j]);},removeFile(e){if(this.pending || this.data.uploading)return;const {i,j,failed}=e.currentTarget.dataset,items=this.data.items;items[i][failed?'failed':'attachments'].splice(j,1);this.setData({items});},
 async submit(){if(this.epoch!==undefined && this.epoch!==api.epoch())return;if(this.data.busy || this.data.uploading || !this.allowed)return;const d=this.data;if(!this.pending && (!d.title.trim() || !d.note.trim() || !d.items.length || d.items.some(i=>(i.invoice_date && !oa.validInvoiceDate(i.invoice_date)) || !i.description.trim() || !oa.cents(i.amount) || !i.attachments.length || i.failed.length))){this.setData({error:'请填写标题、说明和有效金额，并完成每项费用的发票上传。'});return;}const epoch=api.epoch();this.setData({busy:true,error:''});try{if(!this.pending){let subscribed=false;if(this.template){subscribed=await new Promise(resolve=>wx.requestSubscribeMessage({tmplIds:[this.template],success:r=>resolve(r[this.template]==='accept'),fail:()=>resolve(false)}));}if(this.closed || epoch!==api.epoch())return;this.pending={submission_key:this.key,title:d.title.trim(),note:d.note.trim(),type:d.types[d.typeIndex],items:d.items.map(i=>({description:i.description.trim(),amount:i.amount,invoice_date:i.invoice_date||null,attachments:i.attachments.map(a=>a.id)})),cc:d.cc.map(Number),wechat_subscribed:subscribed,...(this.previous?{reimbursement_id:this.previous.reimbursement_id,expected_version:this.previous.number}:{})};}const result=await api.request('/reimbursements','POST',this.pending);if(this.closed || epoch!==api.epoch())return;wx.redirectTo({url:'/pages/reimbursements/detail?id='+result.record.id});}catch(e){if(this.closed || epoch!==api.epoch())return;if(e.status>=400 && e.status<500){this.pending=null;this.key=oa.uuid();}this.setData({error:e.message+(this.pending?'；再次提交将重试同一申请，填写内容暂时锁定。':'')});}finally{if(!this.closed && epoch===api.epoch())this.setData({busy:false});}}
});
