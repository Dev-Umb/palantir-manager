const api=require('./api');
const files=new Set();
api.onClear(()=>{files.forEach(path=>wx.getFileSystemManager().unlink({filePath:path,fail(){}}));files.clear();});
async function open(file){
 if(!file||!file.content_url)throw new Error('附件地址无效');
 const epoch=api.epoch(),data=await api.request(file.content_url,'GET',undefined,false,true);
 if(epoch!==api.epoch())throw new Error('请重新登录');
 const extension=file.kind==='pdf'?'pdf':file.kind==='image'?'jpg':(file.name.split('.').pop()||'bin').replace(/[^a-zA-Z0-9]/g,'');
 const path=wx.env.USER_DATA_PATH+'/attachment-'+Date.now()+'.'+extension;
 await new Promise((resolve,reject)=>wx.getFileSystemManager().writeFile({filePath:path,data,success:resolve,fail:()=>reject(new Error('附件保存失败'))}));
 if(epoch!==api.epoch()){wx.getFileSystemManager().unlink({filePath:path,fail(){}});throw new Error('请重新登录');}
 files.add(path);
 await new Promise((resolve,reject)=>{const options={success:resolve,fail:()=>reject(new Error('无法预览此附件，请在网页打开'))};if(file.kind==='image')wx.previewImage({...options,urls:[path]});else wx.openDocument({...options,filePath:path,showMenu:false});});
}
module.exports={open};
