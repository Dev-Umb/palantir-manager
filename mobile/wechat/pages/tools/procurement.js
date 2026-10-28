const api=require('../../services/api');
module.exports={
 procurementInput(e){this.setData({procurementQuery:e.detail.value});},
 async loadProcurement(){return this.act(async()=>{const r=await api.page('/procurement-hub?'+api.query({q:this.data.procurementQuery,page:this.data.procurementPage}));this.safeData({notices:r.notices.data,procurementPage:r.notices.current_page,procurementLast:r.notices.last_page,procurementTotal:r.notices.total});});},
 searchProcurement(){this.setData({procurementPage:1,notice:null});return this.loadProcurement();},
 nextProcurement(){if(this.data.procurementPage<this.data.procurementLast){this.setData({procurementPage:this.data.procurementPage+1});return this.loadProcurement();}},
 previousProcurement(){if(this.data.procurementPage>1){this.setData({procurementPage:this.data.procurementPage-1});return this.loadProcurement();}},
 async openNotice(e){return this.act(async()=>{const r=await api.page('/procurement-hub/notices/'+encodeURIComponent(e.currentTarget.dataset.id));this.safeData({notice:r.notice,noticeReport:(r.reports||[]).map(x=>({id:x.id,text:JSON.stringify(x.result||{})}))});});},
 closeNotice(){this.setData({notice:null});}
};
