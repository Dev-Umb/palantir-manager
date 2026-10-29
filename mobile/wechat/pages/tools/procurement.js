const api=require('../../services/api');
const u=require('../../utils/page');
const fields={quantity:'采购规模',published:'公告发布时间',registration:'报名 / 文件获取',deadline:'报价 / 投标截止',unit:'采购单位',code:'项目编号',material:'材料与规格',terms:'采购条款',contacts:'联系方式',judgment:'采集端判断',issues:'待核实事项',platform:'来源平台',category:'产品分类',subtype:'采购性质',status:'公告状态',framework:'框架协议',evidence:'证据文件（未上传）'};
function value(v){return v==null||v===''?'未提供':typeof v==='boolean'?(v?'是':'否'):Array.isArray(v)?v.map(value).join('\n'):typeof v==='object'?Object.entries(v).map(([k,x])=>k+'：'+value(x)).join('\n'):String(v);}
function row(n){const p=n.payload||{};return {...n,p,priority:n.priority||p.priority||'待核实',issueText:value(p.issues),hasIssues:!!n.has_issues||(Array.isArray(p.issues)?p.issues.length>0:!!p.issues),fields:Object.entries(fields).map(([key,label])=>({key,label,value:value(p[key])}))};}
module.exports={
 procurementBack(){if(this.data.kind==='procurement'&&this.data.notice)this.closeNotice();},
 procurementInput(e){this.setData({procurementQuery:e.detail.value});},
 async loadProcurement(){return this.act(async()=>{let r;try{r=await api.page('/procurement-hub?'+api.query({...this.data.procurementFilters,q:this.data.procurementQuery,page:this.data.procurementPage}));}catch(e){if(e.status===403)this.safeData({notices:[],notice:null,procurementLoaded:false,procurementTotals:{},procurementMethods:[],procurementTotal:0});throw e;}this.safeData({notices:r.notices.data.map(row),procurementPage:r.notices.current_page,procurementLast:r.notices.last_page,procurementTotal:r.totals?.count??r.notices.total,procurementTotals:r.totals?.priority||{},procurementMethods:['全部采购方式',...(r.methods||[])],procurementLoaded:true});});},
 searchProcurement(){if(this.data.busy)return;this.setData({procurementPage:1,notice:null});return this.loadProcurement();},
 nextProcurement(){if(!this.data.busy&&this.data.procurementPage<this.data.procurementLast){this.setData({procurementPage:this.data.procurementPage+1});return this.loadProcurement();}},
 previousProcurement(){if(!this.data.busy&&this.data.procurementPage>1){this.setData({procurementPage:this.data.procurementPage-1});return this.loadProcurement();}},
 quickProcurement(e){if(this.data.busy)return;this.setData({procurementFilters:{...this.data.procurementFilters,priority:e.currentTarget.dataset.value},procurementPage:1});return this.loadProcurement();},
 showProcurementFilters(){this.setData({procurementSheet:true,procurementDraft:{...this.data.procurementFilters},procurementFilterError:''});},
 closeProcurementFilters(){this.setData({procurementSheet:false});},
 editProcurementFilter(e){const key=e.currentTarget.dataset.key;let v=e.detail.value;if(key==='method')v=this.data.procurementMethods[Number(v)]||'';if(v==='全部采购方式')v='';if(key==='time')v=['published','registration','deadline'][Number(v)];if(key==='priority')v=['','P1','P2','P3','待核实'][Number(v)];this.setData({procurementDraft:{...this.data.procurementDraft,[key]:v}});},
 resetProcurementFilters(){this.setData({procurementDraft:{method:'',priority:'',time:'published',start:'',end:''},procurementFilterError:''});},
 applyProcurementFilters(){const d=this.data.procurementDraft;if(d.start&&d.end&&d.start>d.end){this.setData({procurementFilterError:'开始日期不能晚于结束日期'});return;}if(this.data.busy)return;this.setData({procurementFilters:{...d},procurementSheet:false,procurementPage:1});return this.loadProcurement();},
 procurementScroll(e){if(!this.data.notice)this.procurementListScroll=e.detail.scrollTop;},
 async openNotice(e){return this.act(async()=>{const r=await api.page('/procurement-hub/notices/'+encodeURIComponent(e.currentTarget.dataset.id));this.safeData({notice:row(r.notice),procurementWarnings:(r.warnings||[]).filter(w=>w!==r.notice.payload?.issues).map(value),procurementScrollTop:0});});},
 closeNotice(){this.setData({notice:null,procurementScrollTop:this.procurementListScroll||0});},
 copyProcurementSource(){const url=this.data.notice?.p.url;if(/^https?:\/\//i.test(url||''))wx.setClipboardData({data:url});else wx.showToast({title:'暂无有效原文链接',icon:'none'});},
 analyzeProcurement(){const n=this.data.notice;if(n)u.navigate('/pages/tools/tools?kind=ai&procurementId='+encodeURIComponent(n.id));}
};
