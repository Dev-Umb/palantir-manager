import { describe, expect, it, vi } from 'vitest';
import { readFileSync } from 'node:fs';
import { createContext, runInContext } from 'node:vm';
import { randomUUID } from 'node:crypto';
const read=name=>readFileSync(`resources/template-accessories/${name}`,'utf8');
const empty={progressRecords:[],progressImports:[],revision:0,updatedAt:null};
function setup(handler){
 const fetch=vi.fn(async(url,options)=>url==='/state'?{ok:true,json:async()=>({dataset:empty,preferences:{}})}:handler(url,options.body?JSON.parse(options.body):null));
 const c=createContext({fetch,structuredClone,crypto:{randomUUID},Event:class{},TEMPLATE_BOOT:{urls:{state:'/state',mutate:'/mutate',preferences:'/preferences'}}});c.window=c;c.dispatchEvent=()=>{};runInContext(read('database-adapter.js'),c);return {c,db:c.TemplateDatabase,fetch};
}
describe('template accessory database integration',()=>{
 it('does not claim private state is committed on failed writes and retains input',async()=>{
  const {db}=setup(()=>({ok:false,status:409,json:async()=>({message:'another tab changed it'})}));await db.ready;
  await db.privateState.setItem('draft','{"project":"keep"}');
  await expect(db.privateState.flush()).rejects.toThrow('another tab');
  expect(db.privateState.getItem('draft')).toContain('keep');expect(db.privateState.error).toContain('another tab');
 });
 it('uses acknowledged preference revisions and preserves mutation id after a lost response',async()=>{
  const bodies=[];let attempts=0;const {db}=setup((url,body)=>{bodies.push(body);if(url==='/mutate'&&attempts++===0)throw Error('lost');return {ok:true,json:async()=>url==='/preferences'?{revision:bodies.length}:{dataset:{...empty,revision:1},result:null}};});await db.ready;
  await db.privateState.setItem('filters','{}');await db.privateState.setItem('filters','{"kind":"台车模板"}');expect(bodies[1].expected_revision).toBe(1);
  await db.store.saveBatch([{record:{id:'one'}}],0);expect(bodies[2]).toEqual(bodies[3]);expect(bodies[2].operation).toBe('save');
 });
 it('retains original grouping, null totals, and ambiguous import boundaries',()=>{
  const c=createContext({MaterialCore:{date:()=>true}});c.window=c;runInContext(read('core.js'),c);const P=c.ProgressCore;
  expect(P.total([{shipped:null}],'shipped')).toBeNull();expect(P.total([{shipped:0}],'shipped')).toBe(0);
  expect(P.group({shipping:'已发',progress:'已做完'})).toBe(0);expect(P.group({shipping:'部分已发',progress:'未下放'})).toBe(1);
  expect(P.fields['液压配件'].map(f=>f[0])).toContain('parent');
  expect(read('ui.js')).not.toContain('localStorage');expect(read('core.js')).not.toContain('indexedDB');
  expect(read('workbench.html')).not.toContain('seed-');expect(read('workbench.html')).toContain('target="_top"');expect(read('workbench.html')).toContain('class="workspace"');expect(read('workbench.html')).not.toContain('connect-src &#39;none&#39;');
 });
});
