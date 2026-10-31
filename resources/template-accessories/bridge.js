(async function(){
'use strict';
const $=id=>document.getElementById(id),D=TemplateDatabase,store=D.store;
let data,busy=false;
function toast(message){$('toast').textContent=message;$('toast').hidden=false;setTimeout(()=>$('toast').hidden=true,5000);}
function error(e){$('save-status').textContent=e.message;toast(e.message);}
function updateSaveStatus(){
 const privateStatus=D.privateState.error?'个人草稿/筛选未保存：'+D.privateState.error:D.privateState.pending?'个人草稿/筛选正在保存…':'数据库已同步';
 $('save-status').textContent=privateStatus+(window.ProgressUI?.pendingCount()?` · ${ProgressUI.pendingCount()}项修改尚未提交`:'');
 if($('p-editor-draft')&&D.privateState.getItem('draft'))$('p-editor-draft').textContent=D.privateState.error?'草稿未保存，请勿关闭页面':D.privateState.pending?'草稿正在保存…':'草稿已存数据库 · 尚未提交';
 const mark=JSON.parse(D.privateState.getItem('backup')||'null');$('backup-status').textContent=mark?`最近备份：${mark.at}${mark.revision===data?.revision?'':' · 数据已变化，请重新备份'}`:'尚无备份导出记录，请保存工程备份。';
}
function confirmBox(title,message,label='确认'){
 return new Promise(resolve=>{const d=$('confirm-dialog');$('confirm-title').textContent=title;$('confirm-body').textContent=message;$('confirm-body').style.whiteSpace='pre-wrap';$('confirm-yes').textContent=label;const finish=v=>{d.close();resolve(v);};$('confirm-no').onclick=()=>finish(false);$('confirm-yes').onclick=()=>finish(true);d.oncancel=e=>{e.preventDefault();finish(false);};d.showModal();});
}
async function run(fn){if(busy)return;busy=true;try{await fn();}catch(e){error(e);}finally{busy=false;}}
async function refresh(){data=await store.read();window.dispatchEvent(new Event('dataset-updated'));updateSaveStatus();}
async function refreshExport(){data=await store.export();window.dispatchEvent(new Event('dataset-updated'));}
async function ensureSaved(action){if(window.ProgressUI?.hasDraft()){if(!await confirmBox(action,'有未保存修改，是否保存后继续？','保存后继续'))return false;await ProgressUI.savePending();}await D.privateState.flush();return true;}
function download(blob,name){const url=URL.createObjectURL(blob),a=document.createElement('a');a.href=url;a.download=name;a.click();setTimeout(()=>URL.revokeObjectURL(url),1000);}
try{
 await D.ready;data=await store.read();
 window.MaterialBridge={getData:()=>data,store,run,error,toast,confirmBox,refresh,refreshExport,ensureSaved,download,updateSaveStatus};
 $('backup').hidden=!TEMPLATE_BOOT.capabilities.export;for(const id of ['restore','undo-restore'])$(id).hidden=!TEMPLATE_BOOT.capabilities.recover;
 $('backup').onclick=()=>run(async()=>{if(!await ensureSaved('保存工程备份'))return;const dataset=await store.export(),checksum=await MaterialHash.hash(JSON.stringify(dataset));download(new Blob([JSON.stringify({format:'xyc-template-accessory-project',version:1,checksum,dataset},null,2)],{type:'application/json'}),'模板与配件工程.json');D.privateState.setItem('backup',JSON.stringify({at:new Date().toISOString(),revision:dataset.revision}));await D.privateState.flush();toast('备份已生成，请确认下载完成。');});
 $('restore').onclick=()=>$('project-file').click();
 $('project-file').onchange=e=>run(async()=>{try{const file=e.target.files[0];if(!file)return;if(file.size>50*1024*1024)throw Error('备份不能超过50MB。');if(!await ensureSaved('恢复工程'))return;const p=JSON.parse(await file.text());if(p.format!=='xyc-template-accessory-project'||p.version!==1||await MaterialHash.hash(JSON.stringify(p.dataset))!==p.checksum)throw Error('请选择本工作台导出的有效备份。');ProgressCore.validate(p.dataset);const current=await store.read();if(!await confirmBox('恢复模板与配件工程',`将替换本工作台全部正式记录及回收站，共${p.dataset.progressRecords.length}项。原材台账不受影响，可撤销本次恢复。`,'恢复'))return;await store.replace(p.dataset,current.revision);await refresh();toast('恢复完成。');}finally{e.target.value='';}});
 $('undo-restore').onclick=()=>run(async()=>{if(!await ensureSaved('撤销恢复'))return;const current=await store.read();if(!await confirmBox('撤销上次恢复','回到恢复前的模板与配件工程；恢复后新增或修改的数据也会被替换。','撤销'))return;await store.recover(current.revision);await refresh();});
 $('help').onclick=()=>confirmBox('使用说明','台车模板、开挖台架、液压配件独立分类。编辑完成后点击保存修改，整批校验并保存；失败保留输入。正式数据共享，个人草稿和筛选按账号存数据库。Excel导入先预览，同名项目无法匹配时停止；未在文件中的项目保留。发货吨位是项目累计数，月份为最后发货月份，不代表当月实际发货量。备份只含本工作台正式数据与回收站，不含个人草稿。','知道了');
 window.addEventListener('private-state-updated',updateSaveStatus);
 window.addEventListener('focus',()=>{if(!busy&&!D.privateState.pending&&!window.ProgressUI?.hasDraft())run(refresh);});
 window.addEventListener('beforeprint',()=>ProgressUI?.preparePrint());
 window.addEventListener('beforeunload',e=>{if(D.privateState.pending||D.privateState.error||window.ProgressUI?.hasDraft()){e.preventDefault();e.returnValue='';}});
 window.dispatchEvent(new Event('workbench-ready'));updateSaveStatus();
}catch(e){error(e);}
})();
