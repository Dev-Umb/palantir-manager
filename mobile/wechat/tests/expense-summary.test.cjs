const test=require('node:test'),assert=require('node:assert/strict'),fs=require('node:fs'),vm=require('node:vm');
const m={exports:{}};
vm.runInNewContext(fs.readFileSync(require('node:path').join(__dirname,'../pages/reimbursements/common.js'),'utf8'),{module:m,require:()=>({onClear(){}})});
const summarize=items=>JSON.parse(JSON.stringify(m.exports.expenseSummary(items)));
test('groups clear categories with exact cents without changing original expenses',()=>{
 const file={id:'a',mime:'application/pdf',name:'杂费邮费住宿.pdf'};
 const items=[{description:'快递费\n凭证号码123',cents:32,attachments:[file]},{description:'邮寄',cents:101,attachments:[file]},{description:'住宿费',cents:10000,attachments:[]}];
 const before=JSON.stringify(items),r=summarize(items);
 assert.deepEqual(r.groups.map(g=>[g.description,g.total,g.count]),[['邮费','1.33',2],['住宿费','100.00',1]]);
 assert.equal(JSON.stringify(items),before);assert.equal(r.files.length,1);assert.equal(r.files[0].i,0);assert.equal(r.files[0].j,0);
});
test('ambiguous and unknown expenses remain separate and unicode labels fit twenty characters',()=>{
 const r=summarize([{description:'住宿和快递',cents:1,attachments:[]},{description:'住宿和快递',cents:2,attachments:[]},{description:'😀'.repeat(25),cents:3,attachments:[]}]);
 assert.equal(r.groups.length,3);assert.equal(Array.from(r.groups[2].description).length,20);assert.equal(r.groups.reduce((s,g)=>s+g.cents,0),6);
});
test('forty expenses reduce to compact rows without losing invoice references or totals',()=>{
 const items=Array.from({length:40},(_,i)=>({description:i%2?'汽油':'住宿费',cents:10001,attachments:[{id:'f'+i,mime:'application/pdf'}]}));
 const r=summarize(items);assert.equal(r.groups.length,2);assert.equal(r.files.length,40);assert.equal(r.groups.reduce((s,g)=>s+g.cents,0),400040);
 assert.equal(r.files[39].i,39);assert.deepEqual(summarize([]),{groups:[],files:[]});
});
