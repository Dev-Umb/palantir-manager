(function(){
'use strict';const $=id=>document.getElementById(id),P=ProgressCore,F=ProgressFiles,esc=v=>String(v??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const fmt=v=>v==null?'':Number(v).toLocaleString('zh-CN',{maximumFractionDigits:3});
const uid=()=>crypto.randomUUID?crypto.randomUUID():'p-'+Date.now()+'-'+Math.random().toString(36).slice(2);
let B,kind='台车模板',editing=null,dirty=false,saving=false,filter={search:'',progress:'',shipping:'',month:'',trash:false};
const draftKey='draft',filterKey='filters';
const inlineKey='inline',inlineDrafts=new Map(),savedRows=new Set();
let inlineStorageError='',editorInitial='',batchPromise=null;
function pendingInline(){return [...inlineDrafts.values()].some(d=>d.dirty);}
function modalDraft(){try{const v=TemplateDatabase.privateState.getItem(draftKey);return v?JSON.parse(v):null;}catch{return null;}}
function pendingCount(){return [...inlineDrafts.values()].filter(d=>d.dirty).length+(dirty||modalDraft()?1:0);}
function requireSavedInline(){if(pendingCount())throw Error('有未保存的修改，请先保存再执行此操作。');}
function updateSaveButton(){const n=pendingCount(),btn=$('p-save-changes');if(btn){btn.disabled=!n||!!batchPromise;btn.textContent=batchPromise?'正在保存…':n?`保存修改（${n}项）`:'保存修改';}if($('p-view-changes')){$('p-view-changes').disabled=!n;$('p-view-changes').textContent=`查看未保存（${n}）`;}B?.updateSaveStatus?.();}
function persistInline(){try{TemplateDatabase.privateState.setItem(inlineKey,JSON.stringify([...inlineDrafts].filter(([,d])=>d.dirty).map(([id,d])=>({id,record:d.record,expected:d.expected,editRevision:d.editRevision,databaseRevision:d.databaseRevision,error:d.error||'',invalid:d.invalid||{}}))));inlineStorageError='';}catch{inlineStorageError='未能保存草稿，请保留页面并重试。';}updateSaveButton();}
function locate(id,field){const d=inlineDrafts.get(id),r=d?.record||data().progressRecords.find(x=>x.id===id);if(!r)return;filter={search:'',progress:'',shipping:'',month:'',trash:false};activate(r.kind);const row=[...$('view-progress').querySelectorAll('[data-row-id]')].find(e=>e.dataset.rowId===id),el=row?.querySelector(`[data-field="${field||d?.errorField||'project'}"]`);if(el){el.focus({preventScroll:true});el.scrollIntoView({block:'center',inline:'center',behavior:'instant'});row.classList.add('located-row');setTimeout(()=>row.classList.remove('located-row'),2500);}}
async function discardInline(id){if(batchPromise)throw Error('正在保存，请稍后重试。');const list=[...inlineDrafts].filter(([k,d])=>d.dirty&&(!id||k===id));if(!list.length)return;if(!await B.confirmBox('放弃未保存修改',`放弃${list.length}项未保存的修改，恢复最近保存的数据？`,'放弃修改'))return;for(const [k]of list)inlineDrafts.delete(k);persistInline();render();}
function showPending(){const dialog=$('progress-pending');const list=[...inlineDrafts].filter(([,d])=>d.dirty);dialog.innerHTML='<div class="dialog-head"><h2>未保存的修改</h2><button id="pending-close" aria-label="关闭">×</button></div><div class="dialog-body pending-list">'+list.map(([id,d])=>`<div><span><strong>${esc(d.record.project||'未填项目名称')}</strong><small>${esc(d.record.kind+' '+(d.record.subproject||''))}</small>${d.error?`<small class="inline-error">${esc(d.error)}</small>`:''}</span><button data-locate="${esc(id)}">定位</button><button data-discard-pending="${esc(id)}">放弃修改</button></div>`).join('')+(modalDraft()?'<div><span>未保存的新增 / 编辑草稿</span><button id="pending-resume">继续编辑</button></div>':'')+'</div><div class="dialog-foot"><button id="pending-discard-all" '+(!list.length?'disabled':'')+'>放弃全部行内修改</button><button id="pending-save-all" class="primary">保存修改</button></div>';
dialog.querySelector('#pending-close').onclick=()=>dialog.close();dialog.querySelectorAll('[data-locate]').forEach(b=>b.onclick=()=>{dialog.close();locate(b.dataset.locate);});dialog.querySelectorAll('[data-discard-pending]').forEach(b=>b.onclick=()=>B.run(async()=>{dialog.close();await discardInline(b.dataset.discardPending);}));if($('pending-resume'))$('pending-resume').onclick=()=>{dialog.close();resumeDraft();};$('pending-discard-all').onclick=()=>B.run(async()=>{dialog.close();await discardInline();});$('pending-save-all').onclick=()=>B.run(async()=>{dialog.close();await flushInline();B.toast('修改已保存。');});dialog.showModal();}
function inlineRecord(r){return inlineDrafts.get(r.id)?.record||r;}
function statusSelect(field,value,prefix,attrs=''){const list=field==='progress'?P.stages:P.shipping;return `<select name="${esc(prefix+'-'+field)}" ${attrs}>`+list.map(v=>`<option value="${esc(v)}" ${v===value?'selected':''}>${esc(v)}</option>`).join('')+'</select>';}
function inlineCell(r,k,label,type){const a=`data-inline="${esc(r.id)}" data-field="${k}" aria-label="${esc(r.project+' '+label)}"`,v=inlineRecord(r)[k];if(type==='stage'||type==='shipping')return statusSelect(k,v,'row-'+r.id,a);if(['note','receipts'].includes(k))return `<textarea ${a} rows="2" maxlength="5000">${esc(v)}</textarea>`;return `<input ${a} type="${type==='number'?'number':type==='date'?'date':'text'}" ${type==='number'?'min="0" step="any"':''} value="${esc(v)}" ${['project','type'].includes(k)?'required':''}>`;}
function inlineStatus(id){const d=inlineDrafts.get(id);return d?.error?`<span class="inline-error">${esc(d.error)}</span><button data-retry="${esc(id)}">重试</button><button data-revert="${esc(id)}">放弃本行修改</button>`:d?.saving?'<span>正在保存…</span>':d?.dirty?`<span>未保存修改</span><button data-revert="${esc(id)}">放弃修改</button>`:savedRows.has(id)?'<span class="inline-saved">已保存</span>':'<span class="muted">可直接编辑</span>';}
function updateInlineStatus(id){updateSaveButton();const row=[...$('view-progress').querySelectorAll('[data-row-id]')].find(r=>r.dataset.rowId===id);if(!row)return;const el=row.querySelector('.inline-state');if(el)el.innerHTML=inlineStatus(id);bindInlineActions(row);}
function startInline(r){let d=inlineDrafts.get(r.id);if(!d){d={record:P.copy(r),expected:r.version,dirty:false,editRevision:0,databaseRevision:data().revision,error:'',saving:false,timer:null};inlineDrafts.set(r.id,d);}return d;}
function errorField(r,message){if(!r.project.trim())return 'project';if(!r.type.trim())return 'type';if(message.includes('进度')||message.includes('发货情况'))return 'progress';return P.fields[r.kind].find(([,label])=>message.includes(label))?.[0]||'project';}
async function flushInline(extraMutator){
 if(batchPromise)return batchPromise;
 const entries=[...inlineDrafts].filter(([,d])=>d.dirty).map(([id,d])=>({id,d,record:P.copy(d.record),expected:d.expected,revision:d.editRevision}));
 const md=modalDraft();if(!entries.length&&!md&&!extraMutator)return;
 const faults=[];for(const entry of entries){try{const invalid=Object.keys(entry.d.invalid||{}).find(k=>entry.d.invalid[k]);if(invalid){entry.d.errorField=invalid;throw Error('输入尚未完整，请检查该栏。');}P.valid(entry.record);entry.d.error='';}catch(e){entry.d.error=e.message;entry.d.errorField=entry.d.errorField||errorField(entry.record,e.message);faults.push(entry);}}
 if(faults.length){persistInline();locate(faults[0].id,faults[0].d.errorField);throw Error(`有${faults.length}项未通过校验，本批修改均未保存。已定位到：${faults[0].record.project||'未填项目名称'}。`);}
 if(md){try{P.valid(md.record);}catch(e){resumeDraft();$('p-editor-error').textContent=e.message;throw Error('新增 / 编辑草稿尚未填完整，本批修改均未保存。');}}
 for(const entry of entries){entry.d.saving=true;updateInlineStatus(entry.id);}
 $('view-progress').inert=true;batchPromise=(async()=>{let failedId=null;try{
  const changes=entries.map(entry=>({record:entry.record,version:entry.expected}));
  if(md){if(entries.some(x=>x.id===md.record.id))throw Error('同一项目存在两份修改。');const r={...md.record};delete r.newRecord;changes.push({record:r,version:md.newRecord?null:md.expected});}
  const bases=[...entries.map(e=>e.d.databaseRevision),...(md?[md.record.databaseRevision]:[])];if(new Set(bases).size>1)throw Error('草稿来自不同版本，请分别核对后保存。');await B.store.saveBatch(changes,bases[0]);
  for(const entry of entries){const d=entry.d;d.expected=entry.expected+1;d.record.version=d.expected;if(d.editRevision===entry.revision){d.dirty=false;inlineDrafts.delete(entry.id);savedRows.add(entry.id);}}
  if(md){clearDraft();if($('progress-editor').open)$('progress-editor').close();}persistInline();await B.refresh();
 }catch(e){const target=failedId?entries.find(x=>x.id===failedId):entries[0];if(target){target.d.error=e.message;persistInline();locate(target.id);}throw Error('本批修改未保存：'+e.message);
 }finally{for(const entry of entries){entry.d.saving=false;updateInlineStatus(entry.id);}}})();
 updateSaveButton();try{await batchPromise;}finally{batchPromise=null;$('view-progress').inert=false;updateSaveButton();}
}
function bindInlineActions(root){root.querySelectorAll('[data-retry]').forEach(b=>b.onclick=()=>B.run(()=>flushInline()));root.querySelectorAll('[data-revert]').forEach(b=>b.onclick=()=>B.run(()=>discardInline(b.dataset.revert)));}
function bindInline(records){const snapshots=new Map(records.map(r=>[r.id,P.copy(r)]));$('view-progress').querySelectorAll('[data-inline]').forEach(el=>{
 el.onfocus=()=>startInline(snapshots.get(el.dataset.inline));
 const change=()=>{const r=snapshots.get(el.dataset.inline),d=startInline(r),key=el.dataset.field,type=P.fields[r.kind].find(f=>f[0]===key)[2];d.record[key]=type==='number'?(el.value===''?null:Number(el.value)):el.value;d.dirty=true;d.editRevision++;d.error='';d.errorField='';d.invalid=d.invalid||{};d.invalid[key]=!!el.validity?.badInput;clearTimeout(d.timer);
  if(key==='shipping'&&el.value==='已发'){d.record.progress='已做完';const row=el.closest('tr');row.querySelector('[data-field="progress"]').value='已做完';}
  if(el.validity?.badInput){d.error='数字尚未输入完整，请继续填写。';persistInline();updateInlineStatus(r.id);return;}
  d.dirty=P.content(d.record)!==P.content(data().progressRecords.find(x=>x.id===r.id));savedRows.delete(r.id);persistInline();if(inlineStorageError)d.error=inlineStorageError;updateInlineStatus(r.id);
 };
 if(el.tagName==='SELECT')el.onchange=change;else{el.oninput=change;if(el.type==='date')el.onchange=change;}el.onblur=()=>{const d=inlineDrafts.get(el.dataset.inline);if(d&&!d.dirty&&!d.saving)inlineDrafts.delete(el.dataset.inline);};
 });bindInlineActions($('view-progress'));
}
function data(){return B.getData();}
function rows(){return P.sorted(data().progressRecords.filter(r=>r.kind===kind&&!!r.deleted===filter.trash&&(!filter.progress||r.progress===filter.progress)&&(!filter.shipping||r.shipping===filter.shipping)&&(!filter.month||r.shipDate.startsWith(filter.month))&&(!filter.search||P.fields[kind].some(([k])=>String(r[k]??'').toLowerCase().includes(filter.search.toLowerCase())))));}
function remember(){try{TemplateDatabase.privateState.setItem(filterKey,JSON.stringify({kind,filter}));}catch{B.toast('筛选未能保存，下次打开需重新选择。');}}
function options(list,selected,all){return (all?'<option value="">'+all+'</option>':'')+list.map(v=>`<option value="${esc(v)}" ${v===selected?'selected':''}>${esc(v)}</option>`).join('');}
function scope(){return kind+' · '+(filter.progress||'全部进度')+' · '+(filter.shipping||'全部发货状态')+(filter.month?' · 最后发货月份 '+filter.month:'')+(filter.search?' · 搜索 '+filter.search:'');}
function table(records,print=false){const fields=P.fields[kind].filter(([k])=>!print||!['receipts','parent'].includes(k));
 let html='<table class="progress-table"><thead><tr>'+fields.map(([k,label,t])=>`<th class="${t==='number'?'num':''}">${esc(label)}</th>`).join('')+(print?'':'<th class="progress-actions">操作</th>')+'</tr></thead><tbody>';
 const colCount=fields.length+(print?0:1);
 function totalLine(list,label,cls){return `<tr class="${cls}">`+fields.map(([k,,t],i)=>`<${print?'td':'td'} class="${t==='number'?'num':''}">${i===0?esc(label):t==='number'?fmt(P.total(list,k)):''}</td>`).join('')+(print?'':'<td></td>')+'</tr>';}
 for(let g=0;g<3;g++){
  const list=records.filter(r=>P.group(r)===g);if(!list.length)continue;
  html+=`<tr class="progress-group"><th colspan="${colCount}">${esc(P.groups[g])}<small>${list.length} 项</small></th></tr>`;
  for(const r of list)html+=`<tr data-row-id="${esc(r.id)}">`+fields.map(([k,label,t])=>`<td class="${t==='number'?'num':''} ${k==='project'?'project-cell':''} ${['date','stage','shipping'].includes(t)?'progress-short':''}" title="${esc(r[k])}">${!print&&!filter.trash&&TEMPLATE_BOOT.capabilities.manage?inlineCell(r,k,label,t):t==='number'?fmt(r[k]):esc(r[k])||'—'}</td>`).join('')+(print?'':`<td class="progress-actions">${filter.trash?`<button data-restore="${esc(r.id)}">恢复</button>`:`<div class="inline-state">${inlineStatus(r.id)}</div><button data-delete="${esc(r.id)}" class="text-button">删除</button>`}</td>`)+'</tr>';
  if(!filter.trash)html+=totalLine(list,'小计','progress-subtotal');
 }
 if(!records.length)html+=`<tr><td class="empty" colspan="${colCount}">没有符合条件的项目。可清除筛选或新增项目。</td></tr>`;
 if(records.length&&!filter.trash)html+=totalLine(records,'合计','progress-total');return html+'</tbody></table>';
}
function render(){
 if(!B)return;document.querySelectorAll('.progress-nav').forEach(btn=>{const n=data().progressRecords.filter(r=>!r.deleted&&r.kind===btn.dataset.kind).length;btn.querySelector('b').textContent=n;});
 if(!document.body.classList.contains('progress-active'))return;
 const active=document.activeElement,focus=active?.dataset?.inline?{id:active.dataset.inline,field:active.dataset.field,value:active.type==='radio'?active.value:null,start:active.selectionStart,end:active.selectionEnd}:null;
 const scroll=$('view-progress').querySelector('.progress-wrap'),left=scroll?.scrollLeft||0,scrollTop=scroll?.scrollTop||0,top=window.scrollY;
 const all=rows(),fields=P.fields[kind];
 $('crumb').textContent=kind;
 $('view-progress').innerHTML=`<div class="progress-heading"><div><div class="eyebrow">鑫源昌 · 模板与配件</div><h1>${esc(kind)}</h1><p>按项目维护进度和发货情况，自动分组汇总。</p></div><button class="primary" id="p-add">＋ 新增项目</button></div>
 <div class="progress-tools"><label>模板进度<select id="p-progress">${options(P.stages,filter.progress,'全部进度')}</select></label><label>是否发货<select id="p-shipping">${options(P.shipping,filter.shipping,'全部状态')}</select></label><label>最后发货月份<input id="p-month" type="month" value="${filter.month}"></label><label class="progress-search">搜索项目或备注<input type="search" id="p-search" value="${esc(filter.search)}" placeholder="项目、隧道、类型、备注"></label><button id="p-reset">清除筛选</button></div>
 <div class="progress-toolbar"><div><button class="primary" id="p-save-changes" ${pendingCount()?'':'disabled'}>${pendingCount()?`保存修改（${pendingCount()}项）`:'保存修改'}</button><button id="p-view-changes" ${pendingCount()?'':'disabled'}>查看未保存（${pendingCount()}）</button><button id="p-import">导入本类 Excel</button><button id="p-export" ${!all.length||filter.trash?'disabled':''}>导出当前报表</button><button id="p-print" ${!all.length||filter.trash?'disabled':''}>打印 / PDF</button></div><label class="trash-toggle"><input id="p-trash" type="checkbox" ${filter.trash?'checked':''}>回收站</label></div>
 <div class="progress-summary">${filter.trash?'回收站记录不计入汇总':`当前 ${all.length} 项 <span>已发 ${all.filter(r=>r.shipping==='已发').length} 项</span><span>累计发货 <strong>${fmt(P.total(all,'shipped'))||'—'}</strong> 吨</span>`}<span class="progress-draft-message" id="p-draft"></span></div>
 <p class="progress-footnote">发货吨位为项目累计数，日期为最后一批日期。${filter.month?'当前月份筛选的是项目，不代表该月实际发货量。':''}${kind==='台车模板'?'智能部分单独统计，不计入发货吨位。':''}</p>
 <div class="table-wrap progress-wrap">${table(all)}</div><p class="progress-footnote">直接编辑各栏，完成后点击“保存修改”（Ctrl+S）。关闭或刷新网页时，有未保存修改会提示；选择留在页面后保存。保存后更新分组、小计与合计。</p>`;
 $('p-view-changes').onclick=showPending;$('p-save-changes').onclick=()=>B.run(async()=>{await flushInline();B.toast('修改已保存，分组、小计和合计已更新。');});$('p-add').onclick=()=>openEditor();$('p-import').onclick=()=>$('progress-file').click();$('p-export').onclick=()=>B.run(exportExcel,$('p-export'));$('p-print').onclick=()=>B.run(async()=>{if(!await B.ensureSaved('打印'))return;await B.refreshExport();preparePrint();window.print();});
 for(const [id,key]of [['p-progress','progress'],['p-shipping','shipping'],['p-month','month'],['p-trash','trash']])$(id).onchange=()=>{filter[key]=key==='trash'?$(id).checked:$(id).value;remember();render();};
 $('p-search').oninput=e=>{filter.search=e.target.value;remember();const pos=e.target.selectionStart;render();$('p-search').focus();$('p-search').setSelectionRange(pos,pos);};
 $('p-reset').onclick=()=>{filter={search:'',progress:'',shipping:'',month:'',trash:false};remember();render();};
 bindInline(all);
 $('view-progress').querySelectorAll('[data-delete],[data-restore]').forEach(b=>b.onclick=()=>B.run(async()=>{const deleting=!!b.dataset.delete,id=b.dataset.delete||b.dataset.restore,r=data().progressRecords.find(r=>r.id===id);if(inlineDrafts.get(id)?.dirty)throw Error('本行尚未保存，请先完成修改或放弃本行修改。');if(!await B.confirmBox(deleting?'移入回收站':'恢复项目',r.project+' '+(r.subproject||'')+(deleting?'\n可从回收站恢复。':''),deleting?'删除':'恢复'))return;await B.store.remove(id,r.version,deleting);await B.refresh();B.toast(deleting?'项目已移入回收站。':'项目已恢复。');}));
 try{if(TemplateDatabase.privateState.getItem(draftKey))$('p-draft').innerHTML='<button id="p-resume" class="text-button">继续未保存草稿</button>';if($('p-resume'))$('p-resume').onclick=resumeDraft;}catch{}
 if(focus){const el=[...$('view-progress').querySelectorAll('[data-inline]')].find(x=>x.dataset.inline===focus.id&&x.dataset.field===focus.field&&(focus.value===null||x.value===focus.value));if(el){el.focus({preventScroll:true});if(focus.start!=null&&['text','search','textarea'].includes(el.type))el.setSelectionRange(focus.start,focus.end);}}
 $('view-progress').querySelector('.progress-wrap').scrollLeft=left;$('view-progress').querySelector('.progress-wrap').scrollTop=scrollTop;for(const el of $('view-progress').querySelectorAll('[data-delete],[data-restore],#p-add,#p-import,#p-save-changes,#p-view-changes,#p-resume'))el.hidden=!TEMPLATE_BOOT.capabilities.manage;for(const id of ['p-export','p-print'])$(id).hidden=!TEMPLATE_BOOT.capabilities.export;updateSaveButton();window.scrollTo({top,behavior:'instant'});
}
function activate(k){kind=k;document.body.classList.add('progress-active');document.querySelectorAll('.view').forEach(v=>v.hidden=v.id!=='view-progress');document.querySelectorAll('.nav').forEach(v=>v.classList.remove('active'));document.querySelectorAll('.progress-nav').forEach(v=>v.classList.toggle('active',v.dataset.kind===kind));remember();render();}
function rawForm(){const r={...editing};for(const [k,,t]of P.fields[editing.kind]){const v=$('pf-'+k).value;r[k]=t==='number'?(v===''?null:Number(v)):v;}return r;}
function preserveDraft(){try{preserveEditorDraft();}finally{updateSaveButton();}}
function preserveEditorDraft(){dirty=P.content(rawForm())!==editorInitial;if(!dirty){try{TemplateDatabase.privateState.removeItem(draftKey);}catch{}$('p-editor-draft').textContent='尚无修改';return;}try{TemplateDatabase.privateState.setItem(draftKey,JSON.stringify({record:rawForm(),expected:editing.version,newRecord:!!editing.newRecord,baseline:editorInitial}));$('p-editor-draft').textContent='草稿等待数据库确认 · 尚未提交';}catch{$('p-editor-draft').textContent='草稿未能保存，请勿关闭页面';}}
function clearDraft(){dirty=false;try{TemplateDatabase.privateState.removeItem(draftKey);}catch{}updateSaveButton();}
async function openEditor(record,resume=false){
 if(!resume){let has=false;try{has=!!TemplateDatabase.privateState.getItem(draftKey);}catch{}if(has&&!await B.confirmBox('替换未提交草稿','已有未提交的进度草稿，打开此项目会替换该草稿。可取消并点击“继续未保存草稿”。','替换草稿'))return;clearDraft();}
 editing=record?{...P.copy(record),databaseRevision:record.databaseRevision??data().revision}:{id:uid(),kind,version:1,seq:1,sourceRow:null,newRecord:true,deleted:false,databaseRevision:data().revision};
 if(!record)for(const [k,,t]of P.fields[kind])editing[k]=t==='number'?null:k==='progress'?'未下放':k==='shipping'?'未发':k==='type'?(kind==='液压配件'?'液压及配件':kind==='开挖台架'?'开挖台架':'二衬台车'):'';
 editorInitial=resume?(modalDraft()?.baseline||P.content(data().progressRecords.find(r=>r.id===editing.id)||{...editing,project:''})):P.content(editing);const fields=P.fields[editing.kind];
 $('progress-editor-content').innerHTML=`<form id="p-form"><div class="dialog-head"><div><h2>${editing.newRecord?'新增':'修改'}${esc(editing.kind)}</h2><small id="p-editor-draft">模板进度和是否发货为下拉单选</small></div><button type="button" id="p-close" aria-label="关闭">×</button></div><div class="dialog-body progress-form">`+fields.map(([k,label,t])=>`<div class="p-form-field ${['note','parent','project'].includes(k)?'span2':''}"><label for="pf-${k}">${esc(label)}</label>${t==='stage'||t==='shipping'?statusSelect(k,editing[k],'editor',`id="pf-${k}"`):k==='note'?`<textarea id="pf-${k}" rows="2" maxlength="5000">${esc(editing[k])}</textarea>`:`<input id="pf-${k}" type="${t==='number'?'number':t==='date'?'date':'text'}" ${t==='number'?'min="0" step="any"':''} value="${esc(editing[k])}" ${['project','type'].includes(k)?'required':''}>`}</div>`).join('')+`</div><p id="p-editor-error" class="form-error" role="alert"></p><div class="dialog-foot"><button type="button" id="p-discard">放弃草稿</button><button type="button" id="p-cancel">关闭并保留草稿</button><button type="submit" class="primary" id="p-save">保存项目</button></div></form>`;
 $('p-form').oninput=preserveDraft;$('p-form').onchange=preserveDraft;
 $('p-form').querySelectorAll('[name="editor-shipping"]').forEach(el=>el.addEventListener('change',()=>{if(el.value==='已发')$('pf-progress').value='已做完';preserveDraft();}));
 $('p-close').onclick=$('p-cancel').onclick=()=>{if(dirty)preserveDraft();$('progress-editor').close();render();};
 $('p-discard').onclick=()=>B.run(async()=>{if(dirty&&!await B.confirmBox('放弃草稿','只清除未保存输入，已保存项目不受影响。','放弃'))return;clearDraft();$('progress-editor').close();render();});
 $('p-form').onsubmit=async e=>{e.preventDefault();if(saving)return;saving=true;$('p-form').inert=true;$('p-save').disabled=true;try{const r=rawForm();delete r.newRecord;P.valid(r);await B.store.saveBatch([{record:r,version:editing.newRecord?null:editing.version}],editing.databaseRevision);clearDraft();$('progress-editor').close();filter={search:'',progress:'',shipping:'',month:'',trash:false};kind=r.kind;remember();await B.refresh();activate(kind);B.toast('项目已保存，分组和小计已更新。');}catch(err){$('p-editor-error').textContent=err.message;preserveDraft();}finally{saving=false;if($('p-form'))$('p-form').inert=false;if($('p-save'))$('p-save').disabled=false;}};
 if(!$('progress-editor').open)$('progress-editor').showModal();$('pf-project').focus();
}
function resumeDraft(){try{const p=JSON.parse(TemplateDatabase.privateState.getItem(draftKey));if(!p?.record||!P.kinds.includes(p.record.kind))throw Error('草稿格式异常');const r={...p.record,newRecord:p.newRecord,version:p.expected};openEditor(r,true);dirty=true;updateSaveButton();}catch(e){B.error(e);}}
async function exportExcel(){if(!await B.ensureSaved('导出报表'))return;await B.refreshExport();const list=rows();if(filter.trash||!list.length)throw Error('没有可导出的正常项目。');await WorkbenchExcel.write();const book=await F.workbook(kind,list);B.download(new Blob([await book.xlsx.writeBuffer()],{type:'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'}),`山西路桥模板进度一览表-${kind}.xlsx`);B.toast('已导出当前筛选项目，含自动小计和合计。');}
async function importExcel(file){requireSavedInline();await WorkbenchExcel.read();
 if(file.size>30*1024*1024)throw Error('Excel文件不能超过30MB。');const importingKind=kind,bytes=await file.arrayBuffer(),hash=await MaterialHash.hash(bytes),parsed=F.parse(bytes,importingKind),current=await B.store.read(),plan=P.plan(current,parsed,hash,importingKind);
 if(plan.sameFile){B.toast('这份Excel已经导入，没有重复添加。');return;}
 if(plan.ambiguous.length)throw Error('存在无法区分的同名项目，本次未导入：'+plan.ambiguous.slice(0,5).map(r=>r.project).join('、')+'。请核对项目类型及数量。');
 const changes=plan.update.slice(0,12).map(({old,record:r})=>`${r.project} ${r.subproject||''}：`+P.fields[importingKind].filter(([k])=>(old[k]??null)!==(r[k]??null)).map(([k,label])=>`${label} ${old[k]??'空'} → ${r[k]??'空'}`).join('；')).join('\n');
 const msg=`${file.name}\n识别 ${parsed.length} 项：新增 ${plan.add.length}，相同 ${plan.same}，有变化 ${plan.update.length}。\n`+changes+(plan.update.length>12?'\n其余变化项目共 '+(plan.update.length-12)+' 项。':'')+'\n未出现在文件中的现有项目会保留。';
 if(!await B.confirmBox('预览进度表导入',msg,'继续'))return;
 let replace=true;if(plan.update.length)replace=await B.confirmBox('处理有变化的项目',`共${plan.update.length}项与现有记录不同。选择“采用Excel更新”会更新这些项目；选择“取消”仅导入新增项，保留现有项目。`,'采用Excel更新');
 const result=await B.store.import(parsed,hash,file.name,importingKind,current.revision,replace);await B.refresh();filter={search:'',progress:'',shipping:'',month:'',trash:false};activate(importingKind);B.toast(`导入完成：新增${result.add.length}项，更新${replace?result.update.length:0}项。`);
}
function preparePrint(){if(!B||!document.body.classList.contains('progress-active'))return;$('print-area').innerHTML=filter.trash?'<p>回收站不生成报表。</p>':`<h1>${esc(PROGRESS_CONFIG[kind].title)}</h1><p>${esc(scope())} · 发货吨位为项目累计数</p>`+table(rows(),true);}
function init(){B=window.MaterialBridge;if(!B)return;try{const s=JSON.parse(TemplateDatabase.privateState.getItem(filterKey));if(s&&P.kinds.includes(s.kind)&&s.filter&&['search','progress','shipping','month'].every(k=>typeof s.filter[k]==='string')){kind=s.kind;filter={...filter,...s.filter,trash:false};}}catch{}
 document.querySelectorAll('.progress-nav').forEach(btn=>btn.onclick=()=>activate(btn.dataset.kind));
 $('progress-file').onchange=e=>B.run(async()=>{try{const file=e.target.files[0];if(file)await importExcel(file);}finally{e.target.value='';}});
 $('progress-editor').addEventListener('cancel',()=>{if(dirty)preserveDraft();});
 window.addEventListener('dataset-updated',render);
 document.addEventListener('keydown',e=>{if(document.body.classList.contains('progress-active')&&(e.ctrlKey||e.metaKey)&&e.key.toLowerCase()==='s'){e.preventDefault();if($('progress-editor').open)$('p-form').requestSubmit();else B.run(async()=>{await flushInline();B.toast('修改已保存。');});}});
 try{const pending=JSON.parse(TemplateDatabase.privateState.getItem(inlineKey)||'[]');if(!Array.isArray(pending)||pending.length>50000)throw Error('行内草稿格式异常');for(const d of pending){if(!d||typeof d.id!=='string'||!d.record||d.record.id!==d.id||!P.kinds.includes(d.record.kind)||!Number.isInteger(d.expected))throw Error('行内草稿格式异常');inlineDrafts.set(d.id,{...d,dirty:true,saving:false,timer:null,error:d.error||'已恢复未保存修改，请核对后点击“保存修改”。'});}}catch(e){B.error(e);}
 window.ProgressUI={activate,render,preparePrint,flushPending:requireSavedInline,savePending:flushInline,pendingCount,showPending,hasDraft:()=>{try{return dirty||[...inlineDrafts.values()].some(d=>d.dirty)||!!TemplateDatabase.privateState.getItem(draftKey);}catch{return dirty;}},openEditor};activate(kind);
}
window.addEventListener('workbench-ready',init);if(window.MaterialBridge)init();
})();
