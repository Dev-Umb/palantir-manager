// @vitest-environment node
import { readFileSync } from 'node:fs';
import vm from 'node:vm';
import { describe, expect, it } from 'vitest';
const html=readFileSync(new URL('../../production-workbench/workbench.html',import.meta.url),'utf8');
const code=html.slice(html.indexOf('(function(root){\n\'use strict\';\nconst clean='));
const source=code.slice(0,code.indexOf("})(typeof window!=='undefined'?window:globalThis);")+"})(typeof window!=='undefined'?window:globalThis);".length);
const context=vm.createContext({});vm.runInContext(source,context);const {compare}=context.MaterialComparison;
const plans=[{id:'one',records:[{id:'line',material:'中厚板',spec:'12mm',unit:'吨',quantity:20},{id:'two',material:'槽钢',spec:'10#',unit:'根',quantity:4}]}];
const car=(extra={})=>({id:'car',arrivalDate:'2026-10-01',gross:40,tare:10,items:[{material:'中板',spec:'12mm'},{material:'中板',spec:'20mm'}],...extra});
describe('document comparison rules',()=>{
 it('counts a multi-spec truck once and keeps non-ton units separate',()=>{const r=compare(plans,[car()]);expect(r.planned).toBe(20);expect(r.received).toBe(30);expect(r.difference).toBe(-10);expect(r.ratio).toBe(1.5);expect(r.excludedLines).toBe(1);expect(r.pendingWeight).toBe(0);expect(compare(plans,[car()],{dimension:'spec'}).pendingWeight).toBe(30);});
 it('excludes deleted/out-of-range trucks and does not divide by zero',()=>{expect(compare([], [car({deleted:true})]).received).toBe(0);expect(compare([], [car()],{from:'2026-11-01'}).received).toBe(0);expect(compare([], [car()]).ratio).toBe(null);expect(()=>compare(plans,[],{from:'2026-11-01',to:'2026-10-01'})).toThrow();});
 it('keeps unresolved demand counted exactly once',()=>{const p=structuredClone(plans);p[0].records[0].conflict={resolution:null,excelValue:'20mm',pdfValue:'12mm'};const r=compare(p,[],{dimension:'spec'});expect(r.planned).toBe(20);expect(r.rows.filter(x=>x.kind==='conflict')).toHaveLength(1);});
 it('ships no business seed or old date patches and keeps lazy workbook tools',()=>{expect(html).not.toContain('seed-液压配件');expect(html).toContain('window.PROGRESS_DATE_PATCHES=[]');expect(html).toContain('workbench-assets/sheetjs-');expect(html.includes('indexedDB.open(')).toBe(false);});
});
