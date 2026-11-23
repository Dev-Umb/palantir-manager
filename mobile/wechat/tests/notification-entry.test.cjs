const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');
const root = path.join(__dirname, '..');
const pages = require('../app.json').pages;

for (const [notice, destination] of [['approval','pages/reimbursements/index?approval=1'],['result','pages/reimbursements/index']]) {
 test(notice+' opens its registered page after cold login and reuses a warm session', async () => {
  for (const warm of [false,true]) {
   let page, logins=0, url;
   const api={user:()=>warm, wechatLogin:async()=>{logins++;}};
   vm.runInNewContext(fs.readFileSync(path.join(root,'pages/login/login.js'),'utf8'),{
    Page:p=>{page=p;}, require:()=>api, wx:{redirectTo:o=>{url=o.url;}}
   });
   await page.onLoad({notice});
   assert.equal(logins,warm?0:1);
   assert.equal(url,'/'+destination);
   assert.ok(pages.includes(destination.split('?')[0]));
  }
 });
}
