// @vitest-environment node
import { readFileSync } from 'node:fs';
import vm from 'node:vm';
import { expect, it, vi } from 'vitest';
const code=readFileSync(new URL('../../production-workbench/database-adapter.js',import.meta.url),'utf8');
const data=()=>({records:[],imports:[],progressRecords:[{id:'one'}],progressImports:[],materialDemandPlans:[],revision:2});
function setup(){const fetch=vi.fn().mockImplementation(async()=>new Response(JSON.stringify(data())));const c=vm.createContext({fetch,structuredClone,crypto,PRODUCTION_BOOT:{base:'/production-workbench/data',capabilities:{'material-arrivals':{},'template-accessories':{}}},addEventListener:()=>{}});c.window=c;vm.runInContext(code,c);return {fetch,store:c.createWorkbenchStore()};}
it('rejects HTML instead of passing an empty dataset into findIndex',async()=>{const {fetch,store}=setup();await store.ready;fetch.mockResolvedValueOnce(new Response('<html>Login</html>'));const mutate=vi.fn(d=>d.progressRecords.findIndex(x=>x.id==='one'));await expect(store.transact(mutate,true)).rejects.toThrow('工程数据');expect(mutate).not.toHaveBeenCalled();expect(fetch).toHaveBeenCalledTimes(2);});
it.each([{},null,{...data(),progressRecords:null}])('rejects incomplete successful responses: %j',async bad=>{const {fetch,store}=setup();await store.ready;fetch.mockResolvedValueOnce(new Response(JSON.stringify(bad)));await expect(store.transact(()=>{},true)).rejects.toThrow('工程数据');});
it.each([401,419])('preserves input and explains expired session %s',async status=>{const {fetch,store}=setup();await store.ready;fetch.mockResolvedValueOnce(new Response('{}',{status}));await expect(store.transact(()=>{},true)).rejects.toThrow('登录');});
it('retains valid writes and server conflict errors',async()=>{const {fetch,store}=setup();await store.ready;await store.transact(d=>{d.progressRecords[0].note='saved'},true);expect(JSON.parse(fetch.mock.calls[2][1].body).dataset_json).toContain('saved');fetch.mockResolvedValueOnce(new Response(JSON.stringify({message:'工程已变化'}),{status:409}));await expect(store.transact(()=>{},true)).rejects.toThrow('工程已变化');});
