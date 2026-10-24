// @vitest-environment jsdom
import { fireEvent, render, screen, cleanup } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import Index from './Index';
import Show from './Show';
import Batch from './Batch';
const {get,post}=vi.hoisted(()=>({get:vi.fn(),post:vi.fn()}));
vi.mock('@inertiajs/react',()=>({Head:()=>null,Link:({children,href,...p})=><a href={href} {...p}>{children}</a>,router:{get,post}}));
vi.mock('../../Components/Layout',()=>({default:({children,title,eyebrow,hideHeader})=><main>{!hideHeader&&<header><p>{eyebrow}</p><h1>{title}</h1></header>}{children}</main>}));
afterEach(()=>{cleanup();vi.clearAllMocks();});
const payload={title:'测试采购',unit:'测试单位',code:'TEST-1',nature:'公开询比',category:'钢模板',quantity:'18套（暂估）',published:'2026-10-24（无时分）',registration:'文件获取 2026-10-30（报名未单列）',deadline:'2026-11-01 10:00',priority:'P2',issues:'尺寸待核实',judgment:'采集端建议核对',url:'https://example.com/list',platform:'测试平台',evidence:['未上传.txt'],terms:'交货条款待核实'};
const notice={id:1,title:payload.title,method:payload.nature,priority:'P2',has_issues:true,payload,created_at:'2026-10-24T02:00:00Z'};
const props={notices:{data:[notice],links:[]},methods:['公开询比','公开竞争性谈判'],totals:{count:37,priority:{P2:29}}};
describe('JSON procurement platform',()=>{
 it('removes redundant overview headings while retaining actions and statistics',()=>{
  render(<Index {...props}/>);
  for(const text of ['招采信息中心','招采信息','采购机会 · PC CODEX 采集','招采总览','最新公告、采购规模与截止时间，一览掌握。']) expect(screen.queryByText(text)).toBeNull();
  expect(screen.queryByRole('heading')).toBeNull();
  expect(screen.getByRole('navigation',{name:'招采操作'})).toBeTruthy();
  expect(screen.getByRole('link',{name:'上传批次'}).getAttribute('href')).toBe('/procurement-hub/batches');
  expect(screen.getByRole('link',{name:/进入平台 AI/}).getAttribute('href')).toBe('/ai');
  expect(screen.getByText('匹配采购信息')).toBeTruthy();
  expect(screen.getByRole('button',{name:'筛选'})).toBeTruthy();
 });
 it('shows full matching counts, independent issues and method filters',()=>{
  render(<Index {...props}/>);
  expect(screen.getByText('37')).toBeTruthy();expect(screen.getByText('29')).toBeTruthy();
  expect(screen.getByText('核实提示')).toBeTruthy();
  fireEvent.change(screen.getByRole('combobox',{name:'采购方式'}),{target:{value:'公开竞争性谈判'}});
  fireEvent.click(screen.getByRole('button',{name:'筛选'}));
  expect(get).toHaveBeenCalledWith('/procurement-hub',expect.objectContaining({method:'公开竞争性谈判',time:'published',page:1}),expect.any(Object));
  expect(screen.queryByText('立即采集')).toBeNull();expect(screen.getByRole('link',{name:/进入平台 AI/})).toBeTruthy();
 });
 it('preserves raw dates, terms, issues and explicitly unuploaded evidence in details',()=>{
  render(<Show notice={notice} batch={{id:'test-batch'}} warnings={['日期精度未核实']}/>);
  expect(screen.getByText('招采信息中心')).toBeTruthy();
  for(const text of [payload.registration,payload.published,payload.quantity,payload.terms,payload.issues,'未上传.txt']) expect(screen.getByText(text)).toBeTruthy();
  expect(screen.getByRole('link',{name:/查看原始来源/}).getAttribute('href')).toBe(payload.url);
  expect(screen.getByText('来源文件引用（未上传）')).toBeTruthy();
 });
 it('renders untrusted text inert and prevents script links',()=>{
  render(<Show notice={{...notice,payload:{...payload,title:'<img src=x onerror=alert(1)>',url:'javascript:alert(1)'}}} batch={{id:'b'}}/>);
  expect(document.querySelector('img')).toBeNull();expect(screen.queryByRole('link',{name:/查看原始来源/})).toBeNull();
 });
 it('has an empty state and visible validation feedback',()=>{
  render(<Index {...props} notices={{data:[],links:[]}} errors={{start:'日期范围无效'}}/>);
  expect(screen.getByText(/暂无匹配数据/)).toBeTruthy();expect(screen.getByRole('alert').textContent).toBe('日期范围无效');
 });
 it('keeps upload-only users from accepting updates and renders coverage metadata',()=>{
  render(<Batch batch={{payload:{date:'2026-10-24',title:'简报',window:'当天',scope:'钢模板',coverage:[['平台','范围','1条','限制']],exclusions:[['项目','已截止']],limitations:['未验证资格']}}} observations={[{id:1,status:'pending_update',payload,warnings:[]}]} canManage={false}/>);
  expect(screen.getByText('未验证资格')).toBeTruthy();expect(screen.queryByRole('button',{name:'核对后采纳更新'})).toBeNull();
 });
});
