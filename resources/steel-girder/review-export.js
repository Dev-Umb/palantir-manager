'use strict';
// Add a review sheet to a copy of the uploaded OpenXML package. Source worksheet
// parts are kept byte-for-byte, including formulas, cached values and formatting.
window.MaterialReviewXlsx=(()=>{
 const marker='steel-material-dashboard-review:v1';
 const ns='http://schemas.openxmlformats.org/spreadsheetml/2006/main';
 const relNs='http://schemas.openxmlformats.org/package/2006/relationships';
 const officeRel='http://schemas.openxmlformats.org/officeDocument/2006/relationships';
 const typeNs='http://schemas.openxmlformats.org/package/2006/content-types';
 const list=(x,n)=>Array.from(x.getElementsByTagNameNS('*',n));
 const clean=v=>String(v??'').replace(/[\u0000-\u0008\u000B\u000C\u000E-\u001F\uFFFE\uFFFF]/g,'').toWellFormed();
 const esc=v=>clean(v).replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&apos;'}[c]));
 function parse(raw){if(/<!DOCTYPE|<!ENTITY/i.test(raw))throw Error('Excel内部XML格式不受支持');const d=new DOMParser().parseFromString(raw,'application/xml');if(list(d,'parsererror').length)throw Error('Excel内部XML损坏');return d;}
 const serialize=d=>'<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'+new XMLSerializer().serializeToString(d).replace(/^\s*<\?xml\b[^?]*\?>\s*/i,'');
 const partPath=t=>{const a=[];for(const p of(t.startsWith('/')?t.slice(1):'xl/'+t).split('/')){if(p==='..')a.pop();else if(p&&p!=='.')a.push(p);}return a.join('/');};
 function add(doc,parent,namespace,name,attrs={}){const e=doc.createElementNS(namespace,name);for(const[k,v]of Object.entries(attrs))e.setAttribute(k,String(v));parent.appendChild(e);return e;}
 function override(types,path,contentType){if(!list(types,'Override').some(e=>e.getAttribute('PartName')==='/'+path))add(types,types.documentElement,typeNs,'Override',{PartName:'/'+path,ContentType:contentType});}
 function relationship(rels,target,type){let n=1;const used=new Set(list(rels,'Relationship').map(e=>e.getAttribute('Id')));while(used.has('rIdMaterialReview'+n))n++;const id='rIdMaterialReview'+n;add(rels,rels.documentElement,relNs,'Relationship',{Id:id,Type:officeRel+'/'+type,Target:target});return id;}
 function styles(doc){
  const root=doc.documentElement;
  const collection=name=>{let e=Array.from(root.children).find(e=>e.localName===name);if(!e)throw Error('原表样式不完整，缺少'+name);return e;};
  const append=(name,fragment)=>{const parent=collection(name),index=parent.children.length;parent.appendChild(doc.importNode(parse('<root xmlns="'+ns+'">'+fragment+'</root>').documentElement.firstElementChild,true));parent.setAttribute('count',parent.children.length);return index;};
  const font=(color,bold=false,size=11)=>append('fonts',`<font>${bold?'<b/>':''}<sz val="${size}"/><color rgb="FF${color}"/><name val="Microsoft YaHei"/></font>`);
  const fill=color=>append('fills',`<fill><patternFill patternType="solid"><fgColor rgb="FF${color}"/><bgColor indexed="64"/></patternFill></fill>`);
  const normal=font('294B40'),white=font('FFFFFF',true),title=font('286D5A',true,16),muted=font('708372',false,10),green=font('286D5A',true),amber=font('94702D',true);
  const dark=fill('286D5A'),yes=fill('EAF2E5'),no=fill('FFF4DC');
  const border=append('borders','<border><left/><right/><top/><bottom style="hair"><color rgb="FFDCE6DA"/></bottom><diagonal/></border>');
  const xf=(f,background=0,b=border,numFmtId=0,align='left')=>append('cellXfs',`<xf numFmtId="${numFmtId}" fontId="${f}" fillId="${background}" borderId="${b}" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1" applyNumberFormat="1"><alignment horizontal="${align}" vertical="top" wrapText="1"/></xf>`);
  return{text:xf(normal),number:xf(normal,0,border,4,'right'),header:xf(white,dark),title:xf(title,0,0),muted:xf(muted,0,0),yes:xf(green,yes),no:xf(amber,no)};
 }
 function sheetXml(rows,s,filename,scope){
  const cell=(ref,value,style)=>{
   if(typeof value==='number'&&Number.isFinite(value))return`<c r="${ref}" s="${style}"><v>${value}</v></c>`;
   if(clean(value).length>32767)throw Error('备注或统计内容超过Excel单元格长度限制，请缩短后重试');
   return`<c r="${ref}" s="${style}" t="inlineStr"><is><t xml:space="preserve">${esc(value)}</t></is></c>`;
  };
  const date=new Date().toLocaleString('zh-CN',{hour12:false});
  const doubled=scope?.outputScope==='double';
  const scopeText=scope?.sourceScope==='single'?'原表为单幅，按相同两幅 × 2 统计':scope?.sourceScope==='double'?'原表为双幅，按原值 × 1 统计':'单／双幅口径未确认，暂按原表值统计';
  let data=`<row r="1" ht="32" customHeight="1">${cell('A1',doubled?'双幅材料统计与核对':'材料核对记录',s.title)}${cell('F1',marker,s.text)}</row>`;
  data+=`<row r="2" ht="28" customHeight="1">${cell('A2','来源文件：'+filename,s.muted)}${scope?cell('F2',JSON.stringify(scope),s.text):''}</row><row r="3" ht="24" customHeight="1">${cell('A3','导出时间：'+date,s.muted)}</row>`;
  data+=`<row r="4" ht="32" customHeight="1">${cell('A4',scopeText+'。本表为导出时的统计记录，重量单位为kg；原材料明细及汇总保留原值。',s.muted)}</row>`;
  data+='<row r="6" ht="26" customHeight="1">'+['项目',doubled?'双幅统计值':'原表统计值','单位','核对状态','备注'].map((v,i)=>cell(String.fromCharCode(65+i)+'6',v,s.header)).join('')+'</row>';
  for(const[r,i]of rows.map((r,i)=>[r,i+7])){
   const lines=v=>clean(v).split('\n').reduce((n,line)=>n+Math.max(1,Math.ceil(line.length/30)),0);
   const height=Math.min(409,Math.max(42,Math.max(lines(r.value),lines(r.note))*17+12));
   data+=`<row r="${i}" ht="${height}" customHeight="1">${cell('A'+i,r.label,s.text)}${cell('B'+i,r.value,typeof r.value==='number'?s.number:s.text)}${cell('C'+i,r.unit,s.text)}${cell('D'+i,r.checked?'已核对':'未核对',r.checked?s.yes:s.no)}${cell('E'+i,r.note,s.text)}${cell('F'+i,r.id,s.text)}${cell('G'+i,r.signature,s.text)}</row>`;
  }
  const end=6+rows.length;
  return`<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="${ns}"><dimension ref="A1:G${end}"/><sheetViews><sheetView showGridLines="0" workbookViewId="0"><pane ySplit="6" topLeftCell="A7" activePane="bottomLeft" state="frozen"/><selection pane="bottomLeft" activeCell="A7" sqref="A7"/></sheetView></sheetViews><sheetFormatPr defaultRowHeight="20"/><cols><col min="1" max="1" width="24" customWidth="1"/><col min="2" max="2" width="48" customWidth="1"/><col min="3" max="3" width="10" customWidth="1"/><col min="4" max="4" width="14" customWidth="1"/><col min="5" max="5" width="58" customWidth="1"/><col min="6" max="7" width="12" hidden="1" customWidth="1"/></cols><sheetData>${data}</sheetData><autoFilter ref="A6:E${end}"/><mergeCells count="4"><mergeCell ref="A1:E1"/><mergeCell ref="A2:E2"/><mergeCell ref="A3:E3"/><mergeCell ref="A4:E4"/></mergeCells><pageMargins left="0.3" right="0.3" top="0.5" bottom="0.5" header="0.2" footer="0.2"/><pageSetup paperSize="9" orientation="landscape" fitToWidth="1" fitToHeight="0"/></worksheet>`;
 }
 async function create({buffer,filename,rows,reviewSheetName,scope}){
  const zip=await JSZip.loadAsync(buffer);
  const get=async p=>{const f=zip.file(p);if(!f)throw Error('Excel缺少'+p);return parse(await f.async('string'));};
  const book=await get('xl/workbook.xml'),rels=await get('xl/_rels/workbook.xml.rels'),types=await get('[Content_Types].xml');
  const sheets=list(book,'sheet'),sheetRoot=list(book,'sheets')[0];if(!sheetRoot)throw Error('Excel工作表目录不完整');
  const old=reviewSheetName?sheets.find(e=>e.getAttribute('name')===reviewSheetName):null;
  let target,name;
  if(old){
   name=old.getAttribute('name');const rid=old.getAttributeNS(officeRel,'id');const rel=list(rels,'Relationship').find(e=>e.getAttribute('Id')===rid);
   if(!rel||rel.getAttribute('TargetMode')==='External')throw Error('原核对记录工作表链接无效');target=partPath(rel.getAttribute('Target'));
   // The app only passes a sheet name with the explicit exported marker.
  }else{
   const names=new Set(sheets.map(e=>e.getAttribute('name')));name='核对记录';for(let n=2;names.has(name);n++)name='核对记录（'+n+'）';
   let n=1;target='xl/worksheets/material-review.xml';while(zip.file(target))target='xl/worksheets/material-review-'+n+++'.xml';
   const rid=relationship(rels,target.slice(3),'worksheet');
   const sheet=add(book,sheetRoot,ns,'sheet',{name,sheetId:Math.max(0,...sheets.map(e=>Number(e.getAttribute('sheetId'))||0))+1});sheet.setAttributeNS(officeRel,'r:id',rid);
  }
  let styleRel=list(rels,'Relationship').find(e=>e.getAttribute('Type')===officeRel+'/styles'),stylePath;
  if(!styleRel){
   stylePath='xl/styles.xml';let n=1;while(zip.file(stylePath))stylePath='xl/material-review-styles-'+n+++'.xml';
   relationship(rels,stylePath.slice(3),'styles');
   zip.file(stylePath,`<styleSheet xmlns="${ns}"><fonts count="1"><font><sz val="11"/><name val="Calibri"/></font></fonts><fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills><borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders><cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs><cellXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/></cellXfs><cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>`);
  }else stylePath=partPath(styleRel.getAttribute('Target'));
  const styleDoc=await get(stylePath),s=styles(styleDoc);
  zip.file(stylePath,serialize(styleDoc));zip.file(target,sheetXml(rows,s,filename,scope));
  override(types,target,'application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml');override(types,stylePath,'application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml');
  zip.file('xl/workbook.xml',serialize(book));zip.file('xl/_rels/workbook.xml.rels',serialize(rels));zip.file('[Content_Types].xml',serialize(types));
  // Extended properties are descriptive; keep their sheet list in sync if present.
  if(zip.file('docProps/app.xml')){
   const app=await get('docProps/app.xml'),titles=list(app,'TitlesOfParts')[0],pairs=list(app,'HeadingPairs')[0];
   const vec=titles?.firstElementChild,pair= pairs?.firstElementChild;
   if(vec&&pair){
    const variants=Array.from(pair.children);let start=0;
    for(let i=0;i+1<variants.length;i+=2){const label=variants[i].textContent,count=Number(variants[i+1].textContent);if(label==='Worksheets'||label==='工作表'){
      const sheetNames=list(book,'sheet').map(e=>e.getAttribute('name'));
      const vt='http://schemas.openxmlformats.org/officeDocument/2006/docPropsVTypes';
      const tail=Array.from(vec.children).slice(start+count).map(e=>e.cloneNode(true));while(vec.children.length>start)vec.removeChild(vec.lastElementChild);
      for(const sheetName of sheetNames){const e=app.createElementNS(vt,'vt:lpstr');e.textContent=sheetName;vec.appendChild(e);}for(const e of tail)vec.appendChild(e);
      vec.setAttribute('size',vec.children.length);variants[i+1].firstElementChild.textContent=String(sheetNames.length);break;
     }start+=count;}
   }
   zip.file('docProps/app.xml',serialize(app));
  }
  return zip.generateAsync({type:'uint8array',compression:'DEFLATE',compressionOptions:{level:6}});
 }
 return{marker,create};
})();
