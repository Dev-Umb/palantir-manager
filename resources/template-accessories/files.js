(function(root){
'use strict';const P=root.ProgressCore,configs=root.PROGRESS_CONFIG;
const normal=v=>String(v??'').replace(/\s/g,'').replace(/[（]/g,'(').replace(/[）]/g,')');
function day(v,wb){
 if(v==null||v==='')return '';
 if(typeof v==='number'){const x=root.XLSX.SSF.parse_date_code(v,{date1904:!!wb.Workbook?.WBProps?.date1904});if(!x)throw Error('日期序列无效');return `${x.y}-${String(x.m).padStart(2,'0')}-${String(x.d).padStart(2,'0')}`;}
 const s=String(v).trim().replace(/[年/.]/g,'-').replace('月','-').replace('日','');return s.replace(/^(\d{4})-(\d{1,2})-(\d{1,2})$/,(_,y,m,d)=>`${y}-${m.padStart(2,'0')}-${d.padStart(2,'0')}`);
}
function parse(bytes,kind){
 const config=configs[kind],X=root.XLSX,wb=X.read(bytes,{type:'array',cellDates:false}),sheet=wb.Sheets[config.sheet];
 if(!sheet)throw Error('没有找到工作表“'+config.sheet+'”，请在对应分类导入原表格式。');
 const range=X.utils.decode_range(sheet['!ref']||'A1');if(range.e.r>50000)throw Error('记录过多，请分批导入。');
 const get=(r,c)=>{const cell=sheet[X.utils.encode_cell({r,c})];if(cell?.t==='e'||cell?.f&&cell.v==null)throw Error(`第${r+1}行公式无有效结果，请用Excel计算保存后再导入。`);return cell?.v??null;};
 const legacy=kind==='液压配件'&&normal(get(1,12))==='项目回单',headers=legacy?config.legacyHeaders:config.headers,keys=legacy?config.legacyKeys:config.keys;
 if(headers.some((h,c)=>normal(get(1,c))!==normal(h)))throw Error('表头与当前分类不符，请使用最新原表或本模块导出的Excel。');
 const rows=[],dates=new Set(['shipDate','orderDate']),numbers=new Set(P.fields[kind].filter(f=>f[2]==='number').map(f=>f[0]));
 for(let r=2;r<=range.e.r;r++){
  const raw=keys.map((k,c)=>get(r,c));
  if(['小计','合计','总计'].includes(String(raw[0]??'').trim())||raw.every(v=>v==null||v===''))continue;
  for(let c=0;c<2&&kind!=='液压配件';c++){const merge=(sheet['!merges']||[]).find(m=>m.s.c===c&&m.e.c===c&&m.s.r<=r&&m.e.r>=r);if(merge)raw[c]=get(merge.s.r,c);}
  const record={id:'parsed-'+kind+'-'+r,kind,seq:r+1,sourceRow:r+1,version:1,deleted:false};
  keys.forEach((k,c)=>{const v=raw[c];if(numbers.has(k)){if(v==null||v==='')record[k]=null;else if(typeof v==='number'&&Number.isFinite(v))record[k]=v;else throw Error(`第${r+1}行“${headers[c]}”应为数字。`);}else record[k]=dates.has(k)?day(v,wb):String(v??'');});
  P.valid(record);rows.push(record);
 }
 if(!rows.length)throw Error('没有找到有效项目记录。');return rows;
}
const clone=o=>JSON.parse(JSON.stringify(o??{}));
async function workbook(kind,records){
 const cfg=configs[kind],book=new root.ExcelJS.Workbook();await book.xlsx.load(Uint8Array.from(atob(cfg.template),c=>c.charCodeAt(0)));
 const ws=book.getWorksheet(cfg.sheet),cols=cfg.keys.length;
 if(kind==='液压配件')ws.spliceColumns(13,1);
 const printEnd=String(ws.pageSetup.printArea||'').match(/:\$?([A-Z]+)\$?\d+$/)?.[1]||root.XLSX.utils.encode_col(cols-1);
 const styles=new Map(),heights=new Map();
 for(let r=3;r<=ws.rowCount;r++){styles.set(r,Array.from({length:cols},(_,i)=>clone(ws.getCell(r,i+1).style)));heights.set(r,ws.getRow(r).height);}
 for(const address of [...ws.model.merges]){const bounds=root.XLSX.utils.decode_range(address);if(bounds.s.r>=2)ws.unMergeCells(address);}
 // ExcelJS can retain cells when spliceRows deletes the entire remaining tail.
 // Clear every old body row explicitly before rebuilding the grouped report.
 const oldLastRow=ws.rowCount;
 for(let r=3;r<=oldLastRow;r++)ws.getRow(r).values=[];
 ws.spliceRows(3,oldLastRow-2);ws.dataValidations.model={};
 const nums=P.fields[kind].filter(f=>f[2]==='number').map(f=>f[0]),sumRows=[],dataRanges=[];
 let cursor=3;
 function styleRow(r,source){const base=styles.get(source)||styles.get(cfg.firstRow);ws.getRow(r).height=heights.get(source)||heights.get(cfg.firstRow)||24;for(let c=1;c<=cols;c++)ws.getCell(r,c).style=clone(base[c-1]);}
 const sets=[0,1,2].map(g=>P.sorted(records).filter(r=>P.group(r)===g));
 for(let g=0;g<3;g++){
  const start=cursor,items=sets[g];
  for(const record of items){styleRow(cursor,record.sourceRow);cfg.keys.forEach((key,i)=>{const cell=ws.getCell(cursor,i+1),v=record[key];cell.value=(key==='shipDate'||key==='orderDate')&&v?new Date(v+'T00:00:00Z'):v===''?null:v??null;if((key==='shipDate'||key==='orderDate')&&v&&(!cell.numFmt||cell.numFmt==='General'))cell.numFmt='yyyy-mm-dd';});
   for(const [field,options]of [['progress',P.stages],['shipping',P.shipping]])ws.getCell(cursor,cfg.keys.indexOf(field)+1).dataValidation={type:'list',allowBlank:false,formulae:['"'+options.join(',')+'"'],showErrorMessage:true,error:'请选择列表中的状态。'};
   cursor++;
  }
  if(kind!=='液压配件')for(const col of [0,1]){let a=0;for(let i=1;i<=items.length;i++){if(i===items.length||items[i][cfg.keys[col]]!==items[a][cfg.keys[col]]||items[i].project!==items[a].project){if(i-a>1&&items[a][cfg.keys[col]])ws.mergeCells(start+a,col+1,start+i-1,col+1);a=i;}}}
  styleRow(cursor,cfg.subtotalRows[g]);ws.getCell(cursor,1).value='小计';
  cfg.keys.forEach((key,i)=>{if(nums.includes(key)){const letter=root.XLSX.utils.encode_col(i),value=P.total(items,key);ws.getCell(cursor,i+1).value={formula:items.length?`IF(COUNT(${letter}${start}:${letter}${cursor-1})=0,"",SUM(${letter}${start}:${letter}${cursor-1}))`:'""',result:value??''};}else if(i>0)ws.getCell(cursor,i+1).value=null;});
  sumRows.push(cursor);dataRanges.push([start,cursor-1]);cursor++;
 }
 styleRow(cursor,cfg.totalRow);ws.getCell(cursor,1).value='合计';
 cfg.keys.forEach((key,i)=>{if(nums.includes(key)){const letter=root.XLSX.utils.encode_col(i),refs=sumRows.map(r=>letter+r).join(',');ws.getCell(cursor,i+1).value={formula:`IF(COUNT(${refs})=0,"",SUM(${refs}))`,result:P.total(records,key)??''};}else if(i>0)ws.getCell(cursor,i+1).value=null;});
 ws.pageSetup.printArea=`A1:${printEnd}${cursor}`;ws.pageSetup.printTitlesRow='1:2';ws.pageSetup.orientation='landscape';ws.pageSetup.fitToPage=true;ws.pageSetup.fitToWidth=1;ws.pageSetup.fitToHeight=0;
 book.calcProperties.fullCalcOnLoad=true;return book;
}
root.ProgressFiles={parse,workbook,day};
})(typeof window!=='undefined'?window:globalThis);
