(function(root){
'use strict';
const kinds=['台车模板','开挖台架','液压配件'];
const stages=['未出图','审核中','未下放','已下放','生产中','已做完'];
const shipping=['未发','部分已发','已发','待确认'];
const groups=['已做完、已发','已做完 / 生产中 / 已下放 · 未发或部分已发','未出图 / 审核中 / 未下放'];
const common=[['project','项目名称','text'],['subproject','隧道 / 子项目','text'],['type','模板类型','text'],['estimated','预估吨位','number'],['shipped','发货吨位','number']];
const tail=[['progress','模板进度','stage'],['shipping','是否发货','shipping'],['orderDate','下放日期','date'],['shipDate','最后发货日期','date'],['note','备注','text']];
const fields={
 '台车模板':[...common,['intelligent','智能部分（单独报价）','number'],['quantity','模板数量（套数）','number'],...tail],
 '开挖台架':[...common,['quantity','模板数量（套数）','number'],...tail],
 '液压配件':[['project','项目名称','text'],['type','配件类型','text'],['hydraulic','液压组件（套）','number'],['screws','丝杆（根）','number'],['pins','销轴（根）','number'],['jacks','千斤顶丝杆（件）','number'],['shipped','发货吨位','number'],['progress','模板进度','stage'],['shipping','是否发货','shipping'],['orderDate','下放日期','date'],['shipDate','最后发货日期','date'],['note','备注','text'],['parent','父记录','text']]
};
const copy=x=>JSON.parse(JSON.stringify(x));
const group=r=>r.shipping==='已发'?0:['已做完','生产中','已下放'].includes(r.progress)||r.shipping==='部分已发'?1:2;
const key=r=>JSON.stringify([r.kind,r.project,r.subproject||'',r.type].map(v=>String(v).trim()));
const content=r=>JSON.stringify(fields[r.kind].map(([k])=>r[k]??null));
const signature=r=>JSON.stringify(['quantity','hydraulic','screws','pins','jacks'].map(k=>r[k]??null));
function valid(r){
 if(!r||!kinds.includes(r.kind)||typeof r.id!=='string'||!r.id||!Number.isInteger(r.version)||r.version<1||!Number.isInteger(r.seq)||r.seq<1)throw Error('项目记录编号或版本无效。');
 for(const [k,label,type] of fields[r.kind]){
  const v=r[k];if(type==='number'){if(v!=null&&(typeof v!=='number'||!Number.isFinite(v)||v<0||v>1e9))throw Error(label+'应为非负数，未填写时请留空。');}
  else if(typeof v!=='string'||v.length>5000)throw Error(label+'格式不正确。');
  if(type==='date'&&v&&!root.MaterialCore.date(v))throw Error(label+'不是有效日期。');
 }
 if(!r.project.trim()||!r.type.trim())throw Error('请填写项目名称和类型。');
 if(!stages.includes(r.progress)||!shipping.includes(r.shipping))throw Error('请选择有效的模板进度和发货情况。');
 if(r.shipping==='已发'&&r.progress!=='已做完')throw Error('已发项目的模板进度应为“已做完”；尚在生产的分批发货请选择“部分已发”。');
 if(r.deleted!=null&&typeof r.deleted!=='boolean')throw Error('删除状态无效。');
 return r;
}
function validate(d){
 if(!Array.isArray(d.progressRecords)||d.progressRecords.length>50000||!Array.isArray(d.progressImports)||d.progressImports.length>5000)throw Error('进度工程结构异常。');
 const ids=new Set();d.progressRecords.forEach(r=>{valid(r);if(ids.has(r.id))throw Error('进度记录编号重复。');ids.add(r.id);});
 if(!d.progressImports.every(i=>i&&typeof i.hash==='string'&&typeof i.name==='string'&&kinds.includes(i.kind)))throw Error('进度导入历史异常。');
 return d;
}
function total(rows,key){const values=rows.map(r=>r[key]).filter(v=>typeof v==='number');return values.length?values.reduce((a,v)=>a+v,0):null;}
function sorted(rows){return [...rows].sort((a,b)=>group(a)-group(b)||a.seq-b.seq||a.id.localeCompare(b.id));}
function save(d,r,expected){valid(r);const i=d.progressRecords.findIndex(x=>x.id===r.id);if(i<0){if(expected)throw Error('原记录不存在。');d.progressRecords.push({...copy(r),seq:Math.max(0,...d.progressRecords.map(x=>x.seq))+1,version:1});}else{const old=d.progressRecords[i];if(old.deleted||old.version!==expected)throw Error('记录已在另一页面修改，请重新打开后编辑。');d.progressRecords[i]={...copy(r),seq:old.seq,version:old.version+1};}}
function remove(d,id,version,deleted){const r=d.progressRecords.find(x=>x.id===id);if(!r||r.version!==version)throw Error('记录已变化，请刷新后重试。');r.deleted=deleted;r.version++;}
function plan(d,rows,hash,kind){
 if(d.progressImports.some(x=>x.hash===hash&&x.kind===kind))return {sameFile:true,add:[],update:[],same:rows.length,ambiguous:[]};
 const remaining=d.progressRecords.filter(r=>!r.deleted&&r.kind===kind),used=new Set(),result={sameFile:false,add:[],update:[],same:0,ambiguous:[]};
 for(const r of rows){valid(r);const matches=remaining.filter(e=>key(e)===key(r)&&!used.has(e.id));let old=matches.find(e=>content(e)===content(r));
  if(old){used.add(old.id);result.same++;continue;}
  if(matches.length===1)old=matches[0];else if(matches.length>1){const exact=matches.filter(e=>signature(e)===signature(r));if(exact.length===1)old=exact[0];else{result.ambiguous.push(r);continue;}}
  if(old){used.add(old.id);result.update.push({old,record:r});}else result.add.push(r);
 }
 return result;
}
function applyImport(d,rows,hash,name,kind,revision,replace){
 if(d.revision!==revision)throw Error('预览后数据已变化，请重新导入核对。');const p=plan(d,rows,hash,kind);if(p.sameFile)return p;if(p.ambiguous.length)throw Error('同名项目无法唯一对应，本次未导入。');
 let seq=Math.max(0,...d.progressRecords.map(r=>r.seq));
 p.add.forEach((r,i)=>d.progressRecords.push({...copy(r),sourceRow:null,id:'import-'+hash.slice(0,24)+'-'+i,seq:++seq,version:1}));
 if(replace)p.update.forEach(({old,record})=>save(d,{...record,id:old.id,seq:old.seq,sourceRow:old.sourceRow},old.version));
 // A file with skipped updates can be imported again later to accept those updates.
 if(replace||!p.update.length)d.progressImports.push({hash,name,kind,at:new Date().toISOString()});return p;
}
const api={kinds,stages,shipping,groups,fields,copy,group,key,content,signature,valid,validate,total,sorted,save,remove,plan,applyImport};
root.ProgressCore=api;
})(typeof window!=='undefined'?window:globalThis);
