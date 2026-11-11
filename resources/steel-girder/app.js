'use strict';
(() => {
const $=id=>document.getElementById(id);
const capabilities=window.SteelGirderConfig;
function hydrateSheets(sheets){
 return sheets.map(data=>{
  const cells=new Map(data.cells.map(cell=>[cell.ref,cell])),rows=new Map();
  for(const cell of cells.values()){if(!rows.has(cell.row))rows.set(cell.row,[]);rows.get(cell.row).push(cell);}
  for(const row of rows.values())row.sort((a,b)=>a.col-b.col);
  const blank=(r,c)=>({row:r,col:c,ref:colName(c)+r,value:null,formula:false,error:false,missingCache:false});
  return {...data,cells,rows,raw(r,c){return cells.get(colName(c)+r)||blank(r,c);},at(r,c){const m=data.merges.find(m=>r>=m.start.row&&r<=m.end.row&&c>=m.start.col&&c<=m.end.col);return m?cells.get(m.ref)||blank(m.start.row,m.start.col):this.raw(r,c);},endCol(cell){return data.merges.find(m=>m.ref===cell.ref)?.end.col||cell.col;}};
 });
}

const text=v=>String(v??'').trim();
const norm=v=>text(v).normalize('NFKC').replace(/[\s（）()]/g,'').toLowerCase();
const escape=v=>String(v??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const fmt=(v,d=2)=>new Intl.NumberFormat('zh-CN',{minimumFractionDigits:d,maximumFractionDigits:d}).format(v);
const colName=n=>{let s='';for(;n>0;n=Math.floor((n-1)/26))s=String.fromCharCode(65+(n-1)%26)+s;return s;};
function address(ref){const m=/^([A-Z]{1,3})([1-9]\d*)$/.exec(ref||'');if(!m)throw Error('文件包含无效的单元格位置。');const col=[...m[1]].reduce((a,c)=>a*26+c.charCodeAt(0)-64,0),row=Number(m[2]);if(row>20000||col>16384)throw Error('工作表超过支持的范围（20,000行）。');return{row,col};}
const nodes=(x,n)=>Array.from(x.getElementsByTagNameNS('*',n));
function xml(raw,label){if(/<!DOCTYPE|<!ENTITY/i.test(raw))throw Error('不支持此Excel内部XML格式。');const doc=new DOMParser().parseFromString(raw,'application/xml');if(doc.getElementsByTagName('parsererror').length)throw Error(label+'的数据损坏，无法读取。');return doc;}
async function readXlsx(buffer){
 if(buffer.byteLength>10*1024*1024)throw Error('文件超过10MB，请精简无用图片或空行后重试。');
 let zip;try{zip=await JSZip.loadAsync(buffer);}catch{throw Error('无法读取Excel，请使用未加密的 .xlsx 文件。');}
 const entries=Object.values(zip.files);if(entries.length>5000||entries.reduce((n,f)=>n+(f._data?.uncompressedSize||0),0)>40*1024*1024)throw Error('Excel展开数据过大，请精简后重试。');
 let expanded=0;
 const getXml=async p=>{const f=zip.file(p);if(!f)throw Error('Excel缺少必要的数据文件：'+p);const raw=await f.async('string');expanded+=raw.length;if(expanded>40*1024*1024)throw Error('Excel内容过大，请精简后重试。');return xml(raw,p);};
 const workbook=await getXml('xl/workbook.xml'),rels=await getXml('xl/_rels/workbook.xml.rels'),targets=new Map();
 for(const r of nodes(rels,'Relationship')){if(r.getAttribute('TargetMode')==='External')continue;const t=r.getAttribute('Target');if(!t)continue;const clean=[];for(const part of(t.startsWith('/')?t.slice(1):'xl/'+t).split('/')){if(part==='..')clean.pop();else if(part&&part!=='.')clean.push(part);}targets.set(r.getAttribute('Id'),clean.join('/'));}
 const strings=zip.file('xl/sharedStrings.xml')?nodes(await getXml('xl/sharedStrings.xml'),'si').map(si=>nodes(si,'t').map(t=>t.textContent).join('')):[];
 const sheets=[];
 for(const s of nodes(workbook,'sheet')){
  const name=s.getAttribute('name'),rid=s.getAttribute('r:id')||s.getAttributeNS('http://schemas.openxmlformats.org/officeDocument/2006/relationships','id');
  const target=targets.get(rid);if(!target)throw Error('工作表“'+name+'”的链接无效。');
  if(!/worksheets\//.test(target))continue;
  const doc=await getXml(target),cells=new Map(),rows=new Map(),merges=[];let maxRow=0,maxCol=0;
  for(const c of nodes(doc,'c')){
   const ref=c.getAttribute('r'),p=address(ref),type=c.getAttribute('t'),v=nodes(c,'v')[0],f=nodes(c,'f')[0];let value=null;
   if(type==='inlineStr')value=nodes(c,'t').map(t=>t.textContent).join('');
   else if(type==='s'){const i=Number(v?.textContent);if(!v||!Number.isInteger(i)||strings[i]===undefined)throw Error(name+'!'+ref+'的文本索引损坏。');value=strings[i];}
   else if(v){if(type==='str'||type==='e')value=v.textContent;else if(type==='b')value=v.textContent==='1';else if(v.textContent!=='')value=Number(v.textContent);}
   if(value===null&&!f)continue;
   const cell={ref,...p,value,formula:!!f,error:type==='e',missingCache:!!f&&type!=='str'&&(!v||v.textContent==='')};
   cells.set(ref,cell);if(!rows.has(p.row))rows.set(p.row,[]);rows.get(p.row).push(cell);maxRow=Math.max(maxRow,p.row);maxCol=Math.max(maxCol,p.col);
  }
  for(const m of nodes(doc,'mergeCell')){const [a,b]=m.getAttribute('ref').split(':');merges.push({start:address(a),end:address(b||a),ref:a});}
  for(const row of rows.values())row.sort((a,b)=>a.col-b.col);
  const blank=(r,c)=>({row:r,col:c,ref:colName(c)+r,value:null,formula:false,error:false,missingCache:false});
  const sheet={name,cells,rows,merges,maxRow,maxCol,raw(r,c){return cells.get(colName(c)+r)||blank(r,c);},at(r,c){const m=merges.find(m=>r>=m.start.row&&r<=m.end.row&&c>=m.start.col&&c<=m.end.col);return m?cells.get(m.ref)||blank(m.start.row,m.start.col):this.raw(r,c);},endCol(cell){return merges.find(m=>m.ref===cell.ref)?.end.col||cell.col;}};
  sheets.push(sheet);
 }
 return sheets;
}
const source=(s,c)=>s.name+'!'+c.ref;
function measure(s,c,unit='kg',factor=1){
 const item={value:null,state:'pending',source:source(s,c),unit,reason:'待填写'};
 if(c.error)return{...item,state:'error',reason:'Excel公式错误（'+text(c.value)+'），请修正后保存重传'};
 if(c.missingCache)return{...item,state:'error',reason:'公式没有保存结果，请在Excel／WPS中重算并保存后重新上传'};
 if(typeof c.value==='number'&&Number.isFinite(c.value)&&c.value>=0)return{...item,value:c.value*factor,state:'number',reason:''};
 if(c.value===null||text(c.value)===''||/^(待填写|待补|未填写|未提供|—|\/|-)$/.test(text(c.value)))return{...item,reason:text(c.value)||'待填写'};
 return{...item,state:'error',reason:'应为非负数值，当前内容为“'+text(c.value).slice(0,60)+'”'};
}
function matches(s,predicate){return[...s.cells.values()].filter(c=>typeof c.value==='string'&&c.col<=4&&predicate(norm(c.value))).sort((a,b)=>a.row-b.row);}
function rowMetric(s,predicate,label,unit='kg',factor=1){
 const found=matches(s,predicate);
 if(found.length!==1)return{state:'missing',value:null,unit,source:s.name,reason:found.length?'出现多个“'+label+'”，无法确定取值':'未找到“'+label+'”'};
 const labelCell=found[0],end=s.endCol(labelCell),raw=(s.rows.get(labelCell.row)||[]).filter(c=>c.col>end),value=raw[0]||s.at(labelCell.row,end+1);
 return{...measure(s,value,unit,factor),labelSource:source(s,labelCell)};
}
function weightFactor(s,predicate){const hit=[...s.cells.values()].find(c=>typeof c.value==='string'&&predicate(norm(c.value)));return hit&&/(?:[（(,，\s])(?:t|吨)[）)\s]*$/i.test(text(hit.value))?1000:1;}
function detailHeaders(s){
 const found=[];
 for(const [r,cells]of s.rows){if(!cells.some(c=>norm(c.value)==='数量')||!cells.some(c=>norm(c.value)==='名称')||!cells.some(c=>norm(c.value)==='编号'))continue;
  const pick=fn=>cells.find(c=>fn(norm(c.value)))?.col;
  found.push({row:r,name:pick(n=>n==='名称'),id:pick(n=>n==='编号'),spec:pick(n=>n.startsWith('规格')),qty:pick(n=>n==='数量'),note:pick(n=>n==='备注'),unit:pick(n=>n==='单位'||n==='计量单位'),grade:pick(n=>n==='材质'||n==='等级'||n==='材质/等级'),net:pick(n=>n==='净重'||n==='净重kg'),inside:pick(n=>n.startsWith('内面积')),outside:pick(n=>n.startsWith('外面积'))});
 }
 return found.sort((a,b)=>a.row-b.row);
}
function extractMaterials(s,issues){
 const headers=matches(s,n=>n.includes('板厚')&&n.includes('型号')),ends=matches(s,n=>n==='钢材合计');
 if(headers.length!==1||ends.length!==1||ends[0].row<=headers[0].row){issues.push('未识别到完整的统一材料清单，请保留“板厚／型号”和“钢材合计”表头。');return[];}
 const result=[];let grade='材质未注明';
 const factor=weightFactor(s,n=>n.startsWith('总重'));
 for(let r=headers[0].row+1;r<ends[0].row;r++){
  const row=(s.rows.get(r)||[]).filter(c=>text(c.value)!==''||c.formula);if(!row.length)continue;
  const c=row[0],label=c.error||c.missingCache?'材料名称待重算':text(c.value);if(!label||/^(注[:：]|说明)|合计|小计/.test(label))continue;
  const end=s.endCol(c),weight=row.find(x=>x.col>end);
  if(!weight&&!c.formula&&end>=s.endCol(headers[0])+1){grade=label;continue;}
  if(end>=s.maxCol&&typeof c.value==='string'){grade=label;continue;}
  const numberLabel=typeof c.value==='number'||/^\d+(?:\.\d+)?$/.test(label);
  const spec=numberLabel?'钢板 δ'+label+' mm':label;
  result.push({grade,spec,weight:measure(s,weight||s.at(r,end+1),'kg',factor),source:source(s,c)});
 }
 return result;
}
function classify(name){if(/剪力钉|栓钉|圆柱头焊钉|焊接螺柱/.test(name))return'stud';if(/螺栓/.test(name))return'bolt';return null;}
function extractFasteners(s,headers,issues){
 const records=[],used=new Set();let h=headers[0];
 for(let r=h.row+1;r<=s.maxRow;r++){
  if(headers.some(x=>x.row===r)){h=headers.find(x=>x.row===r);continue;}
  const raw=s.rows.get(r)||[];if(!raw.length)continue;
  const a=text(s.at(r,h.name).value),b=text(s.at(r,h.id).value);
  if(/合计|小计|总计|汇总|重量|^注[:：]|^说明/.test(a+' '+b))continue;
  const kind=classify(b)||classify(a);if(!kind)continue;
  const qtyCell=s.at(r,h.qty);if(used.has(qtyCell.ref))continue;
  // A carried merged group name alone is not a material row.
  const hasOwnContent=raw.some(c=>(c.col===h.id||c.col===h.spec||c.col===h.qty)&&(text(c.value)!==''||c.formula));if(!hasOwnContent)continue;
  used.add(qtyCell.ref);
  const specCell=s.at(r,h.spec),parts=[];for(let c=h.spec;c<h.qty;c++){const part=s.at(r,c);if(!parts.some(p=>p.ref===part.ref)&&(text(part.value)!==''||part.formula))parts.push(part);}
  const spec=parts.some(c=>c.error||c.missingCache)?'规格待重算':parts.map(c=>text(c.value)).join(' × ')||'规格未注明',note=text(h.note?s.at(r,h.note).value:'');
  const unitCell=h.unit?s.at(r,h.unit):null,unitText=text(unitCell?.value),unitMatch=note.match(/(?:^|[；;，,、\s]|单位[:：]?)(套|个|根|枚|件|只)(?=$|[；;，,、\s])/);
  const unit=unitText||unitMatch?.[1]||'单位未注明';
  const full=[a,b,note,text(h.grade?s.at(r,h.grade).value:'')].join(' ');
  const grades=[...new Set((full.match(/\d+(?:\.\d+)?\s*级|\bQ\d{3}[A-Za-z]*\b|\bHPB\d+\b/gi)||[]).map(x=>x.replace(/\s/g,'')))];
  const grade=grades.join(' / ')||'未注明';
  const quantity=measure(s,qtyCell,unit);if(quantity.state==='number'&&!Number.isSafeInteger(quantity.value)){quantity.state='error';quantity.value=null;quantity.reason='安装数量须为非负整数';}
  records.push({kind,name:classify(b)?b:a,spec,grade,unit,quantity,sources:[source(s,qtyCell)],identitySource:source(s,specCell)});
 }
 const groups=new Map();
 for(const rec of records){const key=[rec.kind,norm(rec.spec).replace(/[ΦφØø]/g,'φ').replace(/[xX*]/g,'×'),norm(rec.grade),rec.unit,/^规格(?:未注明|待重算)$/.test(rec.spec)?rec.identitySource:''].join('|');
  if(!groups.has(key))groups.set(key,{...rec,total:0,filled:0,missing:0,sources:[],names:[],problems:[]});
  const g=groups.get(key);g.sources.push(...rec.sources);g.names.push(rec.name);
  if(rec.quantity.state==='number'){g.total+=rec.quantity.value;g.filled++;}else{g.missing++;g.problems.push(rec.quantity.source+'：'+rec.quantity.reason);}
 }
 const sorted=[...groups.values()].sort((a,b)=>a.kind.localeCompare(b.kind)||a.spec.localeCompare(b.spec,'zh-CN',{numeric:true})||a.grade.localeCompare(b.grade)||a.unit.localeCompare(b.unit));
 for(const g of sorted)issues.push(...g.problems);
 return sorted;
}
function quantityMetric(rows,kind){
 const selected=rows.filter(x=>x.kind===kind);if(!selected.length)return{state:'absent',values:[],sources:[],note:'原表暂无记录'};
 const units=new Map();for(const r of selected){if(!units.has(r.unit))units.set(r.unit,{unit:r.unit,value:0,filled:0,missing:0});const g=units.get(r.unit);g.value+=r.total;g.filled+=r.filled;g.missing+=r.missing;}
 const values=[...units.values()];return{state:values.some(x=>x.filled)?'number':'pending',values,sources:selected.flatMap(x=>x.sources),note:values.some(x=>x.missing)?'仅合计已填数量':values.length>1?'不同单位分别合计':'按原表安装数量合计'};
}
function parseWorkbook(sheets){
 let detail=sheets.find(s=>norm(s.name)==='钢箱梁明细表'),summary=sheets.find(s=>norm(s.name)==='材料汇总');
 if(!detail){const list=sheets.filter(s=>detailHeaders(s).length);if(list.length===1)detail=list[0];}
 if(!summary){const list=sheets.filter(s=>matches(s,n=>n==='已计重量合计').length&&matches(s,n=>n.startsWith('净重合计')).length);if(list.length===1)summary=list[0];}
 if(!detail||!summary||detail===summary)throw Error('未找到“钢箱梁明细表”和“材料汇总”。请选择已确认的两表格式。');
 const headers=detailHeaders(detail);if(!headers.length||headers.some(h=>!h.spec||!h.qty))throw Error('第一表缺少名称、编号、规格或数量表头。');
 const issues=[],factor=weightFactor(summary,n=>n.startsWith('重量合计'));
 const design=rowMetric(summary,n=>n==='已计重量合计','已计重量合计','kg',factor),net=rowMetric(summary,n=>n.startsWith('净重合计'),'净重合计','kg',factor);
 const inside=rowMetric(summary,n=>n==='内表面涂装','内表面涂装','m²'),outside=rowMetric(summary,n=>n==='外表面涂装','外表面涂装','m²');
 const deckLabel=n=>n==='桥面板面积'||n==='桥面板涂装面积';
 const hasDeck=matches(summary,deckLabel).length>0;
 const deck=hasDeck?rowMetric(summary,deckLabel,'桥面板面积','m²'):{state:'absent',value:null,unit:'m²',source:summary.name,reason:'材料表无桥面板面积项'};
 const quantityOnly=[...summary.cells.values()].some(c=>typeof c.value==='string'&&['螺栓','剪力钉','仅统计数量','不计重量'].every(t=>norm(c.value).includes(t)));
 const boltWeight=quantityOnly?{state:'not-applicable',value:null,source:summary.name,unit:'kg',reason:'按原表仅统计数量'}:rowMetric(summary,n=>n==='螺栓重量','螺栓重量','kg',factor),steel=rowMetric(summary,n=>n==='钢材合计','钢材合计','kg',weightFactor(summary,n=>n.startsWith('总重')));
 const materials=extractMaterials(summary,issues),fasteners=extractFasteners(detail,headers,issues);
 for(const m of[design,net,inside,outside,deck,boltWeight,steel,...materials.map(m=>m.weight)])if(m.state==='error'||m.state==='missing')issues.push(m.source+'：'+m.reason);
 for(const s of[detail,summary])for(const c of s.cells.values())if(c.error||c.missingCache){const m=measure(s,c);issues.push(m.source+'：'+m.reason);}
 return{design,net,inside,outside,deck,hasDeck,quantityOnly,boltWeight,steel,materials,fasteners,studs:quantityMetric(fasteners,'stud'),bolts:quantityMetric(fasteners,'bolt'),issues:[...new Set(issues)],detailName:detail.name,summaryName:summary.name,netColumn:colName(headers[0].net||13),insideColumn:colName(headers[0].inside||15),outsideColumn:colName(headers[0].outside||16)};
}
let model=null,scopeInfo=null,unit='t',serial=0,busy=false,exporting=false,sourceWorkbook=null,editingNote=null;
const reviews=new Map(),reviewNotes=new Map();
let activeProject=null,projectBusy=false,projectLoading=false,projects=[],saveQueue=Promise.resolve(true),pendingSaves=0,saveRevision=0,saveFailed=false,deleteTarget=null;
let drawingBusy=false,drawingFiles=[],activeModule='drawings';
window.SteelGirderHasUnsavedChanges=()=>pendingSaves>0||saveFailed||drawingBusy;
const reviewTitles={design:'图纸设计重量',net:'已填净重',studs:'剪力钉需安装数量',bolts:'螺栓需安装数量',inside:'内表面',outside:'外表面',deck:'桥面板'};
function projectName(filename){
 const original=text(filename).replace(/\.xlsx$/i,'');
 const cleanScope=value=>value
  .replace(/[（(](?:单幅(?:原表|统计)?|双幅(?:原表|统计)?|单双幅)[）)]/g,'')
  .replace(/(^|[\s_—–-]+)(?:单幅(?:原表|统计)?|双幅(?:原表|统计)?|单双幅)(?=$|[\s_—–-])/g,'$1')
  .replace(/^(?:单幅(?:原表|统计)?|双幅(?:原表|统计)?|单双幅)|(?:单幅(?:原表|统计)?|双幅(?:原表|统计)?|单双幅)$/g,'')
  .replace(/([_—–-])\1+/g,'$1').replace(/^[\s_—–-]+|[\s_—–-]+$/g,'').trim();
 let name=original,previous;
 // Remove only known trailing workbook labels; keep project, mileage and span text.
 do{
  previous=name;
  name=name.replace(/(?:[\s_—–-]*[（(](?:单幅|双幅|单幅原表|双幅原表|口径未注明|含核对备注)[）)]|[\s_—–-]+(?:单幅原表|双幅原表|口径未注明|双幅统计|原表统计|含核对备注|单幅|双幅|净重联动|桥面板面积联动|面积联动))$/,'').trim();
 }while(name!==previous);
 const base=cleanScope(name||original);
 const project=cleanScope(base.replace(/[\s_—–-]*(?:钢箱梁)?材料(?:明细|汇总)?表$/,''));
 return project||base||'材料总览';
}
function detectScope(sheets,m,filename){
 const sourceSheets=sheets.filter(s=>s.name===m.detailName||s.name===m.summaryName);
 const titles=sourceSheets.flatMap(s=>[s.name,...[...s.cells.values()].filter(c=>c.row<=3&&c.col<=6&&typeof c.value==='string').map(c=>[c.ref,c.value])]);
 const headerSignature=JSON.stringify(titles),savedSheet=sheets.filter(s=>s.raw(1,6).value===MaterialReviewXlsx.marker);
 const choose=values=>{const joined=values.map(v=>Array.isArray(v)?v[1]:v).join(' ').normalize('NFKC'),single=joined.includes('单幅'),double=joined.includes('双幅');return single&&double||joined.includes('单双幅')?'conflict':single?'single':double?'double':null;};
 // An old exported suffix describes its displayed result, not the source scope.
 const sourceName=filename.replace(/\.xlsx$/i,'').replace(/_(?:双幅统计|原表统计)_含核对备注(?:\s*[（(]\d+[）)])?$/,'');
 const filenameMode=choose([sourceName]);
 if(filenameMode)return{mode:filenameMode==='conflict'?'unknown':filenameMode,origin:filenameMode==='conflict'?'filename-conflict':'filename',headerSignature};
 if(savedSheet.length===1)try{const saved=JSON.parse(savedSheet[0].raw(2,6).value);if(saved.version===1&&['single','double','unknown'].includes(saved.sourceScope)&&saved.headerSignature===headerSignature)return{mode:saved.sourceScope,origin:'saved',headerSignature};}catch{}
 const titleMode=choose(titles);
 return{mode:titleMode&&titleMode!=='conflict'?titleMode:'unknown',origin:titleMode==='conflict'?'conflict':titleMode?'title':'unknown',headerSignature};
}
function scaleModel(raw,mode){
 // Derive every view from the unchanged source model, never from a previous view.
 const multiplier=mode==='single'?2:1,issues=[...raw.issues];
 const scale=v=>{
  if(v.state!=='number')return{...v};const value=v.value*multiplier;
  if(!Number.isFinite(value)){issues.push(v.source+'：数值过大，无法完成双幅换算');return{...v,state:'error',value:null,reason:'数值过大，无法换算'};}return{...v,value};
 };
 const m={...raw,sourceScope:mode,multiplier,outputScope:mode==='unknown'?'unknown':'double',issues};
 for(const id of ['design','net','inside','outside','deck','boltWeight','steel'])m[id]=scale(raw[id]);
 m.materials=raw.materials.map(r=>({...r,weight:scale(r.weight)}));
 m.fasteners=raw.fasteners.map(r=>{const total=r.total*multiplier;if(!Number.isSafeInteger(total)){issues.push(r.spec+'：换算数量超出整数精度范围');return{...r,total:0,filled:0,missing:r.filled+r.missing};}return{...r,total};});
 m.studs=quantityMetric(m.fasteners,'stud');m.bolts=quantityMetric(m.fasteners,'bolt');return m;
}
function renderScope(){
 const title=projectName(activeProject?.name||model.filename)+' · '+(model.outputScope==='double'?'双幅统计':'原表统计（口径待确认）');
 $('project-title').textContent=title;document.title=title+' · 钢箱梁深化工作台';
 const origin=scopeInfo.origin==='filename'?'按文件名':scopeInfo.origin==='saved'?'已恢复口径':'按表内标题';
 const description=model.sourceScope==='single'?'单幅原表 × 2 → 双幅统计（'+origin+'）':model.sourceScope==='double'?'双幅原表 × 1（'+origin+'）':scopeInfo.origin==='filename-conflict'?'文件名同时标注单／双幅，请明确一种后重新导入；暂按原表显示':'请在文件名中注明“单幅”或“双幅”后重新导入；暂按原表显示';
 $('file-meta').textContent='已导入 · '+description+(model.importInfo||'');
 $('file-meta').classList.toggle('scope-pending',model.sourceScope==='unknown');
}
const mass=v=>unit==='t'&&v>0&&v<0.5?'<0.001':fmt(unit==='t'?v/1000:v,unit==='t'?3:2);
function reviewSwitch(id,title){const checked=reviews.get(id)===true,note=reviewNotes.get(id)||'';return`<div class="review-control" data-review-control="${id}"><div class="review-switch" role="group" data-review-title="${escape(title)}" aria-label="${escape(title)}核对状态"><button type="button" data-review="${id}" data-checked="false" aria-pressed="${!checked}">未核对</button><button type="button" data-review="${id}" data-checked="true" aria-pressed="${checked}">已核对</button></div><button class="note-toggle" type="button" data-note="${id}" aria-label="${escape(title)}${note?'编辑':'添加'}备注" ${checked&&!note?'hidden':''}>${note?'编辑备注':'+ 备注'}</button><p class="note-preview" ${note?'':'hidden'} title="${escape(note)}">${escape(note)}</p></div>`;}
function updateReviewControl(id){
 const root=document.querySelector(`[data-review-control="${id}"]`);if(!root)return;
 const note=reviewNotes.get(id)||'',checked=reviews.get(id)===true;
 for(const b of root.querySelectorAll('[data-review]'))b.setAttribute('aria-pressed',String((b.dataset.checked==='true')===checked));
 const b=root.querySelector('[data-note]'),p=root.querySelector('.note-preview');b.hidden=checked&&!note;b.textContent=note?'编辑备注':'+ 备注';b.setAttribute('aria-label',reviewTitles[id]+(note?'编辑':'添加')+'备注');p.hidden=!note;p.textContent=note;p.title=note;
}
function metricCard({id,title,kind,value,unitText,note,warning=false,bar=''}){return`<article class="metric ${kind}" data-metric="${id}"><div class="metric-label">${escape(title)}</div><div class="metric-value">${value}${unitText?`<span class="unit">${escape(unitText)}</span>`:''}</div><p class="metric-note${warning?' warning':''}">${escape(note)}</p>${bar}${reviewSwitch(id,title)}</article>`;}
function numberValue(m,formatter=mass){return m.state==='number'?`<span class="number">${escape(formatter(m.value))}</span>`:`<span class="pending">${escape(m.state==='error'?'需重算':m.state==='missing'?'未找到':'待填写')}</span>`;}
function fastenerCard(rows,kind){
 const selected=rows.filter(r=>r.kind===kind),id=kind==='bolt'?'bolts':'studs',name=kind==='bolt'?'螺栓':'剪力钉',title=name+'需安装数量';
 const items=selected.map(r=>{
  const names=[...new Set(r.names)],type=kind==='bolt'?(names.some(n=>/高强/.test(n))?'高强螺栓':names.some(n=>/普通/.test(n))?'普通螺栓':''):'';
  const detail=[type,r.grade==='未注明'?'':r.grade].filter(Boolean);
  return`<li class="fastener-item"><div class="fastener-spec"><span>${escape(r.spec)}</span>${detail.length?`<small>${detail.map(t=>`<span>${escape(t)}</span>`).join('')}</small>`:''}</div><div class="fastener-quantity">${r.filled?`<strong>${fmt(r.total,0)}</strong><span class="unit">${escape(r.unit)}</span>`:'<span class="pending">待填写</span>'}${r.missing&&r.filled?'<small>已填数量</small>':''}</div></li>`;
 }).join('');
 return`<article class="metric install fastener-card ${kind}-card" data-metric="${id}"><div class="metric-label">${title}</div>${selected.length?`<ul class="fastener-list" aria-label="按规格统计${name}数量">${items}</ul>`:'<div class="metric-value"><span class="pending">未收录</span></div>'}${reviewSwitch(id,title)}</article>`;
}
function renderCards(){
 const m=model,boltGap=!m.quantityOnly&&m.boltWeight.state!=='number';
 const max=Math.max(m.design.state==='number'?m.design.value:0,m.net.state==='number'?m.net.value:0,1);
 const bar=(metric,id)=>`<div class="weight-track" aria-hidden="true">${metric.state==='number'?`<span class="weight-fill ${id}" style="width:${Math.max(0,metric.value/max*100)}%"></span>`:''}</div>`;
 $('weight-metrics').dataset.unit=unit;
 $('weight-metrics').innerHTML=[
  metricCard({id:'design',title:'图纸设计重量',kind:'weight',value:numberValue(m.design),unitText:m.design.state==='number'?unit:'',note:m.quantityOnly?'主体＋附属钢材':boltGap?'螺栓重量未完整计入':'主体＋附属＋已填螺栓重量',warning:boltGap,bar:bar(m.design,'design')}),
  metricCard({id:'net',title:'已填净重',kind:'weight',value:numberValue(m.net),unitText:m.net.state==='number'?unit:'',note:'已填写部分 · 空白未计',bar:bar(m.net,'net')})
 ].join('');
 $('metrics').innerHTML=[
  fastenerCard(m.fasteners,'stud'),
  fastenerCard(m.fasteners,'bolt')
 ].join('');
 $('issues').hidden=!m.issues.length;$('issues').innerHTML=m.issues.length?`<details><summary>有 ${m.issues.length} 项数据需要检查，其他可用数据已展示</summary><ul>${m.issues.map(x=>`<li>${escape(x)}</li>`).join('')}</ul></details>`:'';
}
function renderAreaChart(){
 const rows=[{id:'inside',label:'内表面',m:model.inside,color:'#286d5a'},{id:'outside',label:'外表面',m:model.outside,color:'#8daec6'}];
 if(model.hasDeck)rows.push({id:'deck',label:'桥面板',m:model.deck,color:'#c6a064'});
 const all=rows.every(r=>r.m.state==='number'),any=rows.some(r=>r.m.state==='number'),total=rows.reduce((n,r)=>n+(r.m.state==='number'?r.m.value:0),0),circ=2*Math.PI*70;
 let segments='',offset=0;
 if(all&&total>0)for(const r of rows){const arc=r.m.value/total*circ;if(arc>0)segments+=`<circle data-segment="${r.id}" cx="90" cy="90" r="70" fill="none" stroke="${r.color}" stroke-width="14" stroke-dasharray="${arc} ${circ-arc}" stroke-dashoffset="${-offset}" transform="rotate(-90 90 90)"/>`;offset+=arc;}
 const val=m=>m.state==='number'?fmt(m.value)+' m²':m.state==='error'?'需重算':m.state==='missing'?'未找到':'待填写';
 const label=all?'涂装面积合计 m²':any?'已填面积合计 m²':'涂装面积合计';
 const note=!all?(any?'部分面积待完善，暂不显示比例':'填写面积后显示比例'):total===0?'合计为 0，暂无面积比例':'';
 $('area-chart').innerHTML=`<div class="donut"><svg viewBox="0 0 180 180" role="img" aria-label="${escape(rows.map(r=>r.label+' '+val(r.m)).join('；'))}"><title>涂装面积构成</title><circle cx="90" cy="90" r="70" fill="none" stroke="#edf2ef" stroke-width="14"/>${segments}</svg><div class="donut-center"><strong>${any?fmt(total):'待填写'}</strong><span>${label}</span></div></div><div class="area-legend">${rows.map(r=>`<div class="area-item" data-area="${r.id}"><div class="area-item-heading"><span class="area-label"><span class="dot" style="background:${r.color}"></span>${r.label}</span><strong class="area-value">${escape(val(r.m))}</strong></div>${reviewSwitch(r.id,r.label)}</div>`).join('')}${note?`<p class="legend-note">${note}</p>`:''}</div>`;
}
function render(){renderScope();renderCards();renderAreaChart();actionState();}
function status(message,kind='success'){$('status').hidden=false;$('status').className=kind;$('status').textContent=message;}
function reviewSignature(id,m){
 const v=m[id],data=id==='studs'||id==='bolts'?m.fasteners.filter(r=>r.kind===(id==='studs'?'stud':'bolt')).map(r=>[r.spec,r.grade,r.unit,r.total,r.filled,r.missing,[...new Set(r.names)].sort()]):[v.state,v.value,v.unit];
 return JSON.stringify({scope:m.sourceScope,data});
}
function restoreReviews(sheets,m){
 const matches=sheets.filter(s=>s.raw(1,6).value===MaterialReviewXlsx.marker);
 if(matches.length!==1)return{name:null,entries:[],changed:0};
 const sheet=matches[0],entries=[],seen=new Set();let changed=0;
 for(const row of sheet.rows.keys()){
  if(row<7)continue;const id=sheet.raw(row,6).value;if(!Object.hasOwn(reviewTitles,id)||id==='deck'&&!m.hasDeck||seen.has(id))continue;seen.add(id);
  const raw=sheet.raw(row,5),note=typeof raw.value==='string'&&!raw.error?raw.value:'',same=sheet.raw(row,7).value===reviewSignature(id,m);
  const wasChecked=sheet.raw(row,4).value==='已核对';if(wasChecked&&!same)changed++;
  entries.push({id,note,checked:wasChecked&&same});
 }
 return{name:sheet.name,entries,changed};
}
function exportRows(){
 const ids=['design','net','studs','bolts','inside','outside',...(model.hasDeck?['deck']:[])];
 return ids.map(id=>{
  let value,unitText;
  if(id==='studs'||id==='bolts'){
   const rows=model.fasteners.filter(r=>r.kind===(id==='studs'?'stud':'bolt'));
   value=rows.length?rows.map(r=>r.spec+(r.grade==='未注明'?'':'（'+r.grade+'）')+'：'+(r.filled?fmt(r.total,0)+' '+r.unit:'待填写')+(r.missing&&r.filled?'（已填数量）':'')).join('\n'):'未收录';unitText='';
  }else{const v=model[id];value=v.state==='number'?v.value:v.state==='error'?'需重算':v.state==='missing'?'未找到':'待填写';unitText=v.unit;}
  return{id,label:reviewTitles[id],value,unit:unitText,checked:reviews.get(id)===true,note:reviewNotes.get(id)||'',signature:reviewSignature(id,model)};
 });
}
function selectModule(name){
 if(!['drawings','materials'].includes(name))return;
 activeModule=name;
 for(const key of ['drawings','materials']){const selected=key===name;$(key+'-section').hidden=!selected;$(key+'-tab').setAttribute('aria-selected',String(selected));$(key+'-tab').tabIndex=selected?0:-1;}
}
function drawingStatus(message,kind='info'){const el=$('drawing-status');el.hidden=!message;el.textContent=message;el.className='drawing-status '+kind;}
function drawingSize(size){return size>=1024*1024?(size/1024/1024).toFixed(1)+' MB':size>=1024?(size/1024).toFixed(1)+' KB':size+' B';}
function renderDrawings(){
 $('drawing-count').textContent=drawingFiles.length+' 份图纸';$('drawing-empty').hidden=!!drawingFiles.length;
 $('drawing-list').innerHTML=drawingFiles.map(f=>'<li><span class="drawing-file-badge '+(f.type==='DWG'?'dwg':'')+'">'+f.type+'</span><div class="drawing-file-copy"><strong>'+escape(f.name)+'</strong><small>'+drawingSize(f.size)+' · '+new Date(f.addedAt).toLocaleString('zh-CN',{hour12:false})+' 导入</small></div><div class="drawing-row-actions"><button type="button" data-export-drawing="'+escape(f.id)+'" aria-label="导出 '+escape(f.name)+'">导出</button><button type="button" data-remove-drawing="'+escape(f.id)+'" aria-label="移除 '+escape(f.name)+'">移除</button></div></li>').join('');
 actionState();
}
async function refreshDrawings(){drawingFiles=await MaterialProjectStore.listDrawings(activeProject.id);renderDrawings();}
function canUseDrawings(){return activeProject&&!busy&&!exporting&&!projectBusy&&!pendingSaves&&!drawingBusy;}
async function importDrawings(files){
 if(!capabilities.manage||!files?.length||!canUseDrawings())return;
 drawingBusy=true;actionState();drawingStatus('正在读取并保存图纸…','busy');
 try{
  const result={added:0,skipped:0};
  for(const file of files){
   const type=file.name.split('.').pop().toUpperCase();
   if(!['PDF','DWG'].includes(type))throw Error(file.name+'：仅支持 PDF、DWG。');
   if(!file.size)throw Error(file.name+'：文件为空。');
   if(file.size>200*1024*1024)throw Error(file.name+'：超过单份 200 MB 限制。');
   const header=new TextDecoder('latin1').decode(await file.slice(0,1024).arrayBuffer());
   if(type==='PDF'?!header.includes('%PDF-'):!/^AC10\d{2}/.test(header))throw Error(file.name+'：文件内容与扩展名不符，请选择有效图纸。');
   drawingStatus('正在保存 '+file.name+'…','busy');
   const uploaded=await MaterialProjectStore.addDrawings(activeProject.id,[{name:file.name,blob:file}]);result.added+=uploaded.added;result.skipped+=uploaded.skipped;
  }
  activeProject.updatedAt=Date.now();
  await refreshDrawings();drawingStatus((result.added?'导入成功：已保存 '+result.added+' 份图纸。':'没有新增图纸。')+(result.skipped?'已跳过 '+result.skipped+' 份重复文件。':''),'success');
 }catch(e){await refreshDrawings().catch(()=>{});drawingStatus('导入未完成，已成功上传的图纸保留：'+storageReason(e),'error');}
 finally{drawingBusy=false;$('drawing-input').value='';actionState();}
}
function drawingExportName(name){return name.replace(/[\\/:*?"<>|\x00-\x1f]/g,'_').replace(/[. ]+$/,'')||'项目图纸';}
async function exportDrawings(id=null){
 if(!capabilities.export||!canUseDrawings()||!drawingFiles.length)return;
 const selected=id?drawingFiles.find(f=>f.id===id):null;
 const name=id?drawingExportName(selected.name):drawingExportName(activeProject.name)+'_图纸.zip';
 drawingBusy=true;actionState();let writer=null;
 try{
  let handle=null;
  if(typeof window.showSaveFilePicker==='function')try{handle=await window.showSaveFilePicker({suggestedName:name});}catch(e){if(!['SecurityError','NotSupportedError'].includes(e.name))throw e;}
  drawingStatus('正在读取图纸…','busy');
  const response=await MaterialProjectStore.downloadDrawings(activeProject.id,id);
  if(handle){writer=await handle.createWritable();await response.body.pipeTo(writer);writer=null;drawingStatus('导出成功：'+name,'success');}
  else{const blob=await response.blob(),url=URL.createObjectURL(blob),a=document.createElement('a');a.href=url;a.download=name;document.body.appendChild(a);a.click();a.remove();setTimeout(()=>URL.revokeObjectURL(url),60000);drawingStatus('已发起下载：'+name+'。请在浏览器下载列表确认保存完成。');}
 }catch(e){if(writer)try{await writer.abort();}catch{}drawingStatus(e.name==='AbortError'?'已取消导出。':'导出失败：'+e.message,'error');}
 finally{drawingBusy=false;actionState();}
}
async function removeDrawing(id){
 if(!capabilities.manage||!canUseDrawings())return;const file=drawingFiles.find(f=>f.id===id);if(!file)return;
 if(!window.confirm('从当前项目移除“'+file.name+'”？\nTOS 原文件不会删除。'))return;
 drawingBusy=true;actionState();
 try{await MaterialProjectStore.removeDrawing(activeProject.id,id);await refreshDrawings();drawingStatus('已从项目移除：'+file.name,'success');}
 catch(e){drawingStatus('移除失败：'+storageReason(e),'error');}
 finally{drawingBusy=false;actionState();}
}
function actionState(){
 const locked=busy||exporting||projectBusy||pendingSaves>0||drawingBusy;
 $('upload-btn').disabled=locked||!activeProject;$('export-btn').disabled=busy||exporting||projectBusy||drawingBusy||!model;$('export-label').textContent=exporting?'正在导出…':'导出材料表';
 $('drawing-upload-btn').disabled=locked||!activeProject;$('drawing-export-all').disabled=locked||!drawingFiles.length;
 $('drawing-drop-zone').setAttribute('aria-disabled',String(locked||!activeProject));
 document.querySelectorAll('[data-export-drawing],[data-remove-drawing]').forEach(b=>b.disabled=locked);
 for(const id of ['drawings-tab','materials-tab'])$(id).disabled=locked;
 for(const id of ['project-home-btn','new-project-btn','select-project-btn'])$(id).disabled=locked;
 $('open-project-btn').disabled=locked||!$('project-select').value;
 $('delete-project-btn').disabled=locked||!$('project-select').value;
 $('confirm-delete-project').disabled=projectBusy||!deleteTarget;
 $('confirm-delete-project').textContent=projectBusy&&deleteTarget?'正在删除…':'确定删除';
 document.querySelectorAll('[data-close-dialog="delete-project-dialog"]').forEach(b=>{b.disabled=projectBusy;});
 $('project-select').disabled=projectBusy||!projects.length;
 $('create-project-submit').disabled=projectBusy;$('create-project-submit').textContent=projectBusy?'正在创建…':'创建并进入';
 for(const id of ['new-project-btn','create-project-submit','delete-project-btn','confirm-delete-project','upload-btn','drawing-upload-btn']){const el=$(id);el.hidden=!capabilities.manage;el.disabled=el.disabled||!capabilities.manage;}
 for(const id of ['export-btn','drawing-export-all']){const el=$(id);el.hidden=!capabilities.export;el.disabled=el.disabled||!capabilities.export;}
 document.querySelectorAll('[data-remove-drawing],[data-review],[data-note]').forEach(el=>{el.disabled=locked||!capabilities.manage;});
 document.querySelectorAll('[data-export-drawing]').forEach(el=>{el.hidden=!capabilities.export;el.disabled=locked||!capabilities.export;});
 for(const id of ['drawing-drop-zone','drop-zone']){$(id).hidden=!capabilities.manage;}
 $('project-retry-save').hidden=!capabilities.manage||!saveFailed;
 $('project-retry-save').disabled=locked;
 $('project-reload').disabled=locked;
}
function projectMessage(id,message,error=false){const el=$(id);el.hidden=!message;el.textContent=message;el.classList.toggle('error',error);}
function storageReason(e){return e?.message||'服务器暂时不可用，请重试。';}
function saveMessage(message,error=false){$('project-save-status').textContent=message;$('project-save-status').classList.toggle('error',error);$('project-retry-save').hidden=!error;}
function queueProjectSave(){
 if(!activeProject||projectLoading||!capabilities.manage)return Promise.resolve(true);
 const meta={...activeProject,updatedAt:Date.now(),filename:model?.filename||''};activeProject=meta;
 const payload=model?{buffer:sourceWorkbook.buffer,filename:model.filename,reviews:exportRows().map(({id,checked,note,signature})=>({id,checked,note,signature})),unit}:null;
 const revision=++saveRevision;pendingSaves++;saveMessage('正在保存项目…');actionState();
 saveQueue=saveQueue.then(async()=>{
  try{await MaterialProjectStore.save(meta,payload);if(activeProject?.id===meta.id&&revision===saveRevision){saveFailed=false;saveMessage('已保存到服务器 · '+new Date(meta.updatedAt).toLocaleTimeString('zh-CN',{hour:'2-digit',minute:'2-digit',hour12:false}));}return true;}
  catch(e){if(activeProject?.id===meta.id&&revision===saveRevision){saveFailed=true;saveMessage('项目未保存：'+storageReason(e),true);}return false;}
  finally{pendingSaves--;actionState();}
 });
 return saveQueue;
}
function renderProjectOptions(){
 const selected=$('project-select').value;
 $('project-select').replaceChildren(new Option(projects.length?'请选择项目':'暂无项目，请先新建',''),...projects.map(p=>new Option(p.name+(p.hasUnreviewed?'（未完结）':''),p.id)));
 if(projects.some(p=>p.id===selected))$('project-select').value=selected;
 actionState();
}
async function refreshProjects(){
 try{projects=await MaterialProjectStore.list();$('project-count').textContent=projects.length?'已保存 '+projects.length+' 个项目':'暂无项目 · 从新建开始';projectMessage('project-home-status','');projectMessage('project-list-status','');renderProjectOptions();return true;}
 catch(e){projects=[];renderProjectOptions();const message='无法读取项目：'+storageReason(e);projectMessage('project-home-status',message,true);projectMessage('project-list-status',message,true);return false;}
}
function resetDashboard(){
 drawingFiles=[];renderDrawings();drawingStatus('');$('drawing-input').value='';selectModule('drawings');
 serial++;model=null;scopeInfo=null;sourceWorkbook=null;reviews.clear();reviewNotes.clear();unit='t';saveFailed=false;
 $('dashboard').hidden=true;$('empty-guide').hidden=false;$('drop-zone').classList.remove('loaded');$('file-name').textContent='拖入材料表，生成总览';$('file-meta').textContent='选择 .xlsx 文件 · 文件名可注明“单幅”或“双幅”';$('file-meta').classList.remove('scope-pending');$('drop-zone').querySelector('.drop-action').innerHTML='选择文件 <span>↗</span>';
 $('upload-label').textContent='导入材料表';$('status').hidden=true;$('export-status').hidden=true;$('review-status').textContent='';$('file-input').value='';
 for(const id of ['weight-metrics','metrics','area-chart','issues'])$(id).replaceChildren();$('issues').hidden=true;
 $('project-title').textContent='材料总览';$('active-project-name').textContent='';$('note-input').value='';
 document.querySelectorAll('button[data-unit]').forEach(b=>b.setAttribute('aria-pressed',String(b.dataset.unit===unit)));
}
async function openProject(id){
 if(busy||exporting||projectBusy||pendingSaves||drawingBusy)return;
 projectBusy=true;actionState();projectMessage('project-list-status','正在打开项目…');
 try{
  const record=await MaterialProjectStore.get(id);if(!record)throw Error('没有找到该项目，请刷新列表后重试。');
  resetDashboard();activeProject=record.meta;$('active-project-name').textContent=record.meta.name;
  $('drawing-project-title').textContent=record.meta.name;
  $('project-title').textContent=record.meta.name+' · 材料统计';document.title=record.meta.name+' · 钢箱梁深化工作台';
  $('project-home').hidden=true;$('project-workspace').hidden=false;
  for(const dialog of ['select-project-dialog','create-project-dialog'])if($(dialog).open)$(dialog).close();
  if(record.data){projectLoading=true;saveMessage('正在恢复项目…');const loaded=await importFile(new File([],record.data.filename,{type:'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'}),record.data);saveMessage(loaded?'已恢复共享项目':'材料表无法恢复，请重新导入。');}
  else saveMessage('项目已就绪');
  try{await refreshDrawings();}catch(e){drawingStatus('读取图纸失败：'+storageReason(e),'error');}
  $('project-home-btn').focus();
 }catch(e){projectMessage('project-list-status','打开失败：'+storageReason(e),true);projectMessage('project-home-status','打开失败：'+storageReason(e),true);if(activeProject){saveFailed=true;saveMessage('重新载入失败：'+storageReason(e),true);}}
 finally{projectLoading=false;projectBusy=false;actionState();}
}
async function showProjectHome(){
 if(busy||exporting||projectBusy||pendingSaves||drawingBusy)return;
 if(saveFailed){saveMessage('还有修改未保存，请重试保存；也可先导出材料表备份。',true);return;}
 activeProject=null;resetDashboard();$('project-workspace').hidden=true;$('project-home').hidden=false;document.title='钢箱梁深化工作台';$('home-title').focus();actionState();await refreshProjects();
}
function newProjectDialog(){if(projectBusy||busy||exporting||pendingSaves||drawingBusy)return;if($('select-project-dialog').open)$('select-project-dialog').close();$('new-project-name').value='';projectMessage('create-project-error','');$('create-project-dialog').showModal();$('new-project-name').focus();}
function requestProjectDeletion(){
 if(projectBusy||busy||exporting||pendingSaves||drawingBusy)return;
 const selected=projects.find(p=>p.id===$('project-select').value);if(!selected)return;
 deleteTarget={id:selected.id,name:selected.name};$('delete-project-name').textContent=selected.name;
 projectMessage('delete-project-error','');actionState();$('delete-project-dialog').showModal();$('cancel-delete-project').focus();
}
async function deleteProject(){
 if(!deleteTarget||projectBusy||busy||exporting||pendingSaves||drawingBusy)return;
 const target={...deleteTarget};projectBusy=true;actionState();projectMessage('delete-project-error','');
 try{
  const removed=await MaterialProjectStore.remove(target.id);
  $('delete-project-dialog').close();deleteTarget=null;
  if(await refreshProjects())projectMessage('project-list-status',removed?'已删除项目“'+target.name+'”，原文件仍保留在 TOS。':'该项目已不存在，项目列表已更新。');
 }catch(e){projectMessage('delete-project-error','删除失败：'+storageReason(e),true);}
 finally{projectBusy=false;actionState();if(!$('delete-project-dialog').open){if(projects.length)$('project-select').focus();else $('select-project-dialog').querySelector('[data-close-dialog]').focus();}}
}
async function createProject(event){
 event.preventDefault();if(projectBusy)return;const name=text($('new-project-name').value);
 if(!name){projectMessage('create-project-error','请填写项目名称。',true);return;}
 projectBusy=true;actionState();let created=null;
 try{
  const all=await MaterialProjectStore.list();if(all.some(p=>norm(p.name)===norm(name)))throw Error('已有同名项目，请通过“选择项目”继续使用。');
  const now=Date.now(),meta={id:crypto.randomUUID(),name,createdAt:now,updatedAt:now,filename:''};await MaterialProjectStore.create(meta);created=meta.id;
 }catch(e){projectMessage('create-project-error','创建失败：'+storageReason(e),true);}
 finally{projectBusy=false;actionState();}
 if(created)await openProject(created);
}
function exportStatus(message,kind='info'){$('export-status').hidden=false;$('export-status').className=kind;$('export-status').textContent=message;}
async function exportWorkbook(){
 if(!capabilities.export||!model||!sourceWorkbook||busy||exporting||drawingBusy)return;
 const snapshot={...sourceWorkbook,filename:model.filename,rows:exportRows(),scope:{version:1,sourceScope:model.sourceScope,multiplier:model.multiplier,outputScope:model.outputScope,headerSignature:scopeInfo.headerSignature}};
 const baseName=model.filename.replace(/\.xlsx$/i,'').replace(/(?:(?:_单幅原表|_双幅原表|_口径未注明|_双幅统计|_原表统计)?_含核对备注)+$/,'');
 const name=baseName+(model.sourceScope==='single'?'_单幅原表':model.sourceScope==='double'?'_双幅原表':'_口径未注明')+'_含核对备注.xlsx';
 exporting=true;actionState();exportStatus('请选择保存位置…','busy');let writer=null;
 try{
  // Invoke the picker before asynchronous ZIP work so user activation is retained.
  let handle=null;
  if(typeof window.showSaveFilePicker==='function')try{handle=await window.showSaveFilePicker({suggestedName:name,types:[{description:'Excel材料表',accept:{'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet':['.xlsx']}}]});}
  catch(e){if(e.name!=='SecurityError'&&e.name!=='NotSupportedError')throw e;}
  exportStatus('正在生成材料表与核对记录…','busy');
  if(!snapshot.buffer)snapshot.buffer=await MaterialProjectStore.original(activeProject.id);
  const bytes=await MaterialReviewXlsx.create(snapshot),blob=new Blob([bytes],{type:'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'});
  if(handle){writer=await handle.createWritable();await writer.write(blob);await writer.close();writer=null;exportStatus('导出成功：'+(handle.name||name)+'。已包含'+(model.outputScope==='double'?'双幅统计':'原表统计')+'、核对状态和备注，原材料表保留。','success');}
  else{
   const url=URL.createObjectURL(blob),a=document.createElement('a');a.href=url;a.download=name;document.body.appendChild(a);a.click();a.remove();setTimeout(()=>URL.revokeObjectURL(url),60000);
   exportStatus('已生成并发起下载：'+name+'。请在浏览器下载列表确认保存完成。','info');
  }
 }catch(e){
  if(writer)try{await writer.abort();}catch{}
  const reason=e.name==='NotAllowedError'?'没有写入权限，请选择可写入的文件夹':e.name==='SecurityError'?'当前浏览器不允许选择保存位置，请在 Edge 或 Chrome 中直接打开此HTML后重试':e.name==='NotFoundError'?'保存位置不存在或已断开':e.message||'文件未能保存';
  exportStatus(e.name==='AbortError'?'已取消导出，未保存文件。':'导出失败：'+reason,e.name==='AbortError'?'info':'error');
 }finally{exporting=false;actionState();}
}
async function importFile(file,storedData=null){
 if(!file||!activeProject||drawingBusy||(!storedData&&(!capabilities.manage||projectBusy||pendingSaves)))return false;
 if(exporting){status('材料表正在导出，请稍后再导入。','error');return;}
 const current=++serial;busy=true;actionState();$('drop-zone').setAttribute('aria-busy','true');status('正在读取 '+file.name+'…','busy');
 try{
  if(!/\.xlsx$/i.test(file.name))throw Error('请选择 .xlsx 文件；旧版 .xls 请先另存为 .xlsx。');
  if(file.size>10*1024*1024)throw Error('文件超过10MB，请精简后重试。');
  const buffer=storedData?null:await file.arrayBuffer(),sheets=storedData?hydrateSheets(storedData.sheets):await readXlsx(buffer),parsed=parseWorkbook(sheets);if(current!==serial)return;
  const selectedScope=detectScope(sheets,parsed,file.name),view=scaleModel(parsed,selectedScope.mode),restored=restoreReviews(sheets,view);
  const importInfo=(restored.entries.length?' · 已恢复核对记录':'')+(restored.changed?' · '+restored.changed+'项数据或口径已变更，恢复未核对':'')+(view.issues.length?' · 部分数据需检查':'');
  scopeInfo=selectedScope;model={...view,filename:file.name,importInfo};sourceWorkbook={buffer,reviewSheetName:restored.name};reviews.clear();reviewNotes.clear();for(const r of restored.entries){reviews.set(r.id,r.checked);if(r.note)reviewNotes.set(r.id,r.note);}$('review-status').textContent='';
  if(storedData){reviews.clear();reviewNotes.clear();for(const r of storedData.reviews||[]){if(!Object.hasOwn(reviewTitles,r.id)||r.id==='deck'&&!model.hasDeck)continue;reviews.set(r.id,r.checked===true&&r.signature===reviewSignature(r.id,model));if(typeof r.note==='string'&&r.note)reviewNotes.set(r.id,r.note);}unit=storedData.unit==='kg'?'kg':'t';document.querySelectorAll('button[data-unit]').forEach(b=>b.setAttribute('aria-pressed',String(b.dataset.unit===unit)));}
  if($('note-dialog').open)$('note-dialog').close();$('export-status').hidden=true;
  $('file-name').textContent=file.name;
  $('dashboard').hidden=false;$('empty-guide').hidden=true;$('drop-zone').classList.add('loaded');$('drop-zone').querySelector('.drop-action').innerHTML='更换文件 <span>↗</span>';
  $('upload-label').textContent='更换材料表';render();
  const saved=storedData||await queueProjectSave();
  status(saved?'已读取材料表'+(model.issues.length?' · 部分数据需检查':''):'材料表尚未保存，请查看保存提示并重试。',saved?'success':'error');return !!saved;
 }catch(e){if(current!==serial)return false;status('导入失败：'+(e.message||'文件无法读取')+(model?' 当前仍显示：'+model.filename:' 请检查文件后重试。'),'error');return false;}
 finally{if(current===serial){busy=false;actionState();$('drop-zone').removeAttribute('aria-busy');$('file-input').value='';}}
}
for(const name of ['drawings','materials']){
 $(name+'-tab').addEventListener('click',()=>selectModule(name));
 $(name+'-tab').addEventListener('keydown',e=>{if(['ArrowLeft','ArrowRight','Home','End'].includes(e.key)){e.preventDefault();const next=e.key==='Home'?'drawings':e.key==='End'?'materials':name==='drawings'?'materials':'drawings';if(!$(next+'-tab').disabled){selectModule(next);$(next+'-tab').focus();}}});
}
$('drawing-upload-btn').addEventListener('click',()=>{if(canUseDrawings())$('drawing-input').click();});
$('drawing-input').addEventListener('change',e=>importDrawings(Array.from(e.target.files||[])));
$('drawing-export-all').addEventListener('click',()=>exportDrawings());
$('drawing-drop-zone').addEventListener('click',()=>{if(canUseDrawings())$('drawing-input').click();});
$('drawing-drop-zone').addEventListener('keydown',e=>{if((e.key==='Enter'||e.key===' ')&&canUseDrawings()){e.preventDefault();$('drawing-input').click();}});
for(const type of ['dragover','dragenter'])$('drawing-drop-zone').addEventListener(type,e=>{e.preventDefault();if(canUseDrawings())$('drawing-drop-zone').classList.add('dragging');});
$('drawing-drop-zone').addEventListener('dragleave',e=>{if(!$('drawing-drop-zone').contains(e.relatedTarget))$('drawing-drop-zone').classList.remove('dragging');});
$('drawing-drop-zone').addEventListener('drop',e=>{e.preventDefault();$('drawing-drop-zone').classList.remove('dragging');importDrawings(Array.from(e.dataTransfer.files));});
$('drawing-list').addEventListener('click',e=>{const out=e.target.closest('[data-export-drawing]'),remove=e.target.closest('[data-remove-drawing]');if(out)exportDrawings(out.dataset.exportDrawing);if(remove)removeDrawing(remove.dataset.removeDrawing);});
$('upload-btn').addEventListener('click',()=>$('file-input').click());
$('export-btn').addEventListener('click',exportWorkbook);
$('file-input').addEventListener('change',e=>importFile(e.target.files?.[0]));
$('dashboard').addEventListener('click',e=>{
 if(!model||projectBusy||busy||drawingBusy)return;
 const note=e.target.closest('button[data-note]');if(note){editingNote=note.dataset.note;$('note-title').textContent=reviewTitles[editingNote]+' · 核对备注';$('note-context').textContent=reviews.get(editingNote)?'当前状态：已核对':'当前状态：未核对';$('note-input').value=reviewNotes.get(editingNote)||'';$('note-dialog').showModal();$('note-input').focus();return;}
 const button=e.target.closest('button[data-review]');if(!button)return;const id=button.dataset.review,checked=button.dataset.checked==='true';reviews.set(id,checked);updateReviewControl(id);$('review-status').textContent=reviewTitles[id]+'已标记为'+(checked?'已核对':'未核对');queueProjectSave();
});
for(const id of ['note-cancel','note-close'])$(id).addEventListener('click',()=>$('note-dialog').close());
$('note-dialog').addEventListener('close',()=>{editingNote=null;});
$('note-save').addEventListener('click',()=>{if(!editingNote)return;const id=editingNote,value=$('note-input').value.trim();if(value)reviewNotes.set(id,value);else reviewNotes.delete(id);updateReviewControl(id);$('review-status').textContent=reviewTitles[id]+'备注正在保存';$('note-dialog').close();document.querySelector(`[data-note="${id}"]`)?.focus();queueProjectSave();});
$('drop-zone').addEventListener('click',()=>{if(!busy)$('file-input').click();});
$('drop-zone').addEventListener('keydown',e=>{if((e.key==='Enter'||e.key===' ')&&!busy){e.preventDefault();$('file-input').click();}});
for(const event of['dragover','dragenter'])$('drop-zone').addEventListener(event,e=>{e.preventDefault();$('drop-zone').classList.add('dragging');});
$('drop-zone').addEventListener('dragleave',e=>{if(!$('drop-zone').contains(e.relatedTarget))$('drop-zone').classList.remove('dragging');});
$('drop-zone').addEventListener('drop',e=>{e.preventDefault();$('drop-zone').classList.remove('dragging');const files=e.dataTransfer.files;if(files.length!==1){status('每次请选择一份材料表，多个文件不会合并导入。','error');return;}importFile(files[0]);});
document.addEventListener('dragover',e=>e.preventDefault());document.addEventListener('drop',e=>e.preventDefault());
document.querySelectorAll('button[data-unit]').forEach(b=>b.addEventListener('click',()=>{if(projectBusy||busy||drawingBusy)return;unit=b.dataset.unit;document.querySelectorAll('button[data-unit]').forEach(x=>x.setAttribute('aria-pressed',String(x.dataset.unit===unit)));if(model){render();queueProjectSave();}}));
$('new-project-btn').addEventListener('click',newProjectDialog);
$('create-project-form').addEventListener('submit',createProject);
$('select-project-btn').addEventListener('click',async()=>{$('project-select').value='';actionState();$('select-project-dialog').showModal();projectMessage('project-list-status','正在读取项目…');await refreshProjects();$('project-select').focus();});
$('project-select').addEventListener('change',actionState);
$('open-project-btn').addEventListener('click',()=>{const id=$('project-select').value;if(id)openProject(id);});
$('delete-project-btn').addEventListener('click',requestProjectDeletion);
$('confirm-delete-project').addEventListener('click',deleteProject);
$('delete-project-dialog').addEventListener('close',()=>{deleteTarget=null;actionState();});
$('project-reload').addEventListener('click',()=>{if(!saveFailed||window.confirm('未保存的修改将被放弃。建议先导出材料表留存，再重新载入。'))openProject(activeProject.id);});
$('project-home-btn').addEventListener('click',showProjectHome);$('project-retry-save').addEventListener('click',()=>queueProjectSave());
document.querySelectorAll('[data-close-dialog]').forEach(b=>b.addEventListener('click',()=>{if(!projectBusy)$(b.dataset.closeDialog).close();}));
for(const id of ['create-project-dialog','select-project-dialog','delete-project-dialog'])$(id).addEventListener('cancel',e=>{if(projectBusy)e.preventDefault();});
window.addEventListener('beforeunload',e=>{if(pendingSaves||saveFailed||drawingBusy){e.preventDefault();e.returnValue='';}});
actionState();refreshProjects();
const reducedMotion=matchMedia('(prefers-reduced-motion: reduce)');
let userMotion=null;
function updateMotion(){
 const enabled=userMotion??!reducedMotion.matches;
 document.body.dataset.motion=enabled?'on':'off';
 $('motion-toggle').setAttribute('aria-pressed',String(enabled));
 $('motion-toggle').title=enabled?'暂停背景动效':'开启背景动效';
 $('motion-label').textContent=enabled?'动态背景':'静态背景';
}
$('motion-toggle').addEventListener('click',()=>{userMotion=document.body.dataset.motion!=='on';updateMotion();});
reducedMotion.addEventListener('change',updateMotion);
document.addEventListener('visibilitychange',()=>{document.body.dataset.away=String(document.hidden);});
document.body.dataset.away=String(document.hidden);updateMotion();
})();
