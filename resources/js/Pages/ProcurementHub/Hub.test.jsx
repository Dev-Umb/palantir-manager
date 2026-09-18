// @vitest-environment jsdom
import { fireEvent, render, screen, cleanup } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { NoticeCard, Report, Brief, Pages, safeUrl, deadlineDate } from './shared';
import Index from './Index';
import Follows from './Follows';
import Show from './Show';
import Admin from './Admin';
const { post, get } = vi.hoisted(() => ({ post: vi.fn(), get: vi.fn() }));
vi.mock('@inertiajs/react', () => ({ Head: () => null, Link: ({ children, href, ...props }) => <a href={href} {...props}>{children}</a>, router: { post, get, delete: vi.fn() }, useForm: (data) => ({ data, setData: vi.fn(), get, post, errors: {}, processing: false }), usePoll: vi.fn(), usePage: () => ({ props: { errors: {} } }) }));
vi.mock('../../Components/Layout', () => ({ default: ({ children }) => <main>{children}</main> }));
afterEach(() => { cleanup(); vi.clearAllMocks(); });
const notice = { id: 1, title: '钢模板采购公告', kind: 'notice', facts: {}, buyer: '公开采购主体' };
describe('independent information hub', () => {
 it('localizes pagination keys and retains page URLs and filters', () => {
  const next = '/procurement-hub?kind=notice&sort=recommended&q=模板&page=2';
  const {rerender} = render(<Pages links={[
   {label:'pagination.previous',url:null},
   {label:'1',url:'/procurement-hub?page=1',active:true},
   {label:'2',url:next},
   {label:'pagination.next',url:next},
  ]} />);
  expect(screen.getByRole('link',{name:'下一页'}).getAttribute('href')).toBe(next);
  expect(screen.getByRole('link',{name:'2'}).getAttribute('href')).toBe(next);
  expect(screen.getByRole('link',{name:'1'}).className).not.toBe(screen.getByRole('link',{name:'2'}).className);
  expect(screen.queryByText('上一页')).toBeNull();
  expect(screen.queryByText(/pagination\./)).toBeNull();
  rerender(<Pages links={[
   {label:'pagination.previous',url:'/procurement-hub?page=1'},
   {label:'2',url:next,active:true},
   {label:'pagination.next',url:null},
  ]} />);
  expect(screen.getByRole('link',{name:'上一页'}).getAttribute('href')).toBe('/procurement-hub?page=1');
  expect(screen.queryByText('下一页')).toBeNull();
 });
 it('keeps legacy pagination labels readable without interpreting HTML', () => {
  render(<Pages links={[
   {label:'&laquo; Previous',url:'/procurement-hub?page=1'},
   {label:'Next &raquo;',url:'/procurement-hub?page=3'},
   {label:'<img src=x onerror=alert(1)>4',url:'/procurement-hub?page=4'},
  ]} />);
  expect(screen.getByRole('link',{name:'« 上一页'})).toBeTruthy();
  expect(screen.getByRole('link',{name:'下一页 »'})).toBeTruthy();
  expect(screen.getByRole('link',{name:'4'})).toBeTruthy();
  expect(document.querySelector('img')).toBeNull();
 });

 it('keeps new public adapters collectable and unverified sources disabled', () => {
  const source = {id:1,name:'世界银行',url:'https://projects.worldbank.org/',allowed_hosts:['projects.worldbank.org'],adapter:'worldbank',enabled:true,status:'active'};
  const {rerender} = render(<Admin sources={[source]} runs={{data:[],links:[]}} />);
  const collect = screen.getByRole('button',{name:'立即采集'});
  expect(collect.disabled).toBe(false);
  fireEvent.click(collect);
  expect(post).toHaveBeenCalledWith('/procurement-hub/sources/1/collect', {}, {preserveScroll:true});
  expect(screen.getByRole('option',{name:'世界银行公开接口'}).value).toBe('worldbank');
  rerender(<Admin sources={[{...source,adapter:'unverified'}]} runs={{data:[],links:[]}} />);
  expect(screen.getByRole('button',{name:'立即采集'}).disabled).toBe(true);
 });
 it('shows original currency and retains budget labels without calling it an award price', () => {
  render(<NoticeCard notice={{...notice,kind:'award',facts:{amount:'3000000',currency:'USD',amount_type:'budget'}}} />);
  expect(screen.getByText('USD 3000000 · 预算')).toBeTruthy();
  expect(screen.queryByText(/成交金额/)).toBeNull();
 });
 it('shows all three time fields separately while keeping notice actions', () => {
  const { rerender } = render(<NoticeCard notice={{...notice, registration:{start:'2026-09-03T10:00:00+08:00',end:'2026-09-08T10:00:00+08:00'},deadline:'2026-09-18T02:00:00Z'}} />);
  for (const label of ['报名开始','报名截止','投标截止']) expect(screen.getByText(label)).toBeTruthy();
  expect(screen.getByText('2026/09/03 10:00')).toBeTruthy();
  expect(screen.getByText('2026/09/08 10:00')).toBeTruthy();
  expect(screen.getByText('2026/09/18 10:00')).toBeTruthy();
  expect(screen.getByRole('link', {name:'查看详情'}).getAttribute('href')).toBe('/procurement-hub/notices/1');
  rerender(<NoticeCard notice={{...notice,registration:{start:'2026-09-03'},deadline:null}} />);
  expect(screen.getByText('2026-09-03（具体时刻未公开）')).toBeTruthy();
  expect(screen.getAllByText('待核实')).toHaveLength(2);
  expect(deadlineDate('invalid')).toBe('待核实');
 });
 it('keeps recommended sorting when PHP sends an empty filters array', () => {
  const {rerender} = render(<Index filters={[]} />);
  expect(screen.getByRole('combobox',{name:'排序'}).value).toBe('recommended');
  rerender(<Index filters={{sort:'latest'}} />);
  expect(screen.getByRole('combobox',{name:'排序'}).value).toBe('latest');
 });
 it('keeps unavailable amounts and evidence separate from recommendation scores', () => {
  render(<NoticeCard notice={notice} />);
  expect(screen.getByText('金额待核实')).toBeTruthy();
  expect(screen.queryByText(/跟进优先级/)).toBeNull();
  fireEvent.click(screen.getByRole('button', { name: '收藏' }));
  expect(post).toHaveBeenCalledWith('/procurement-hub/notices/1/bookmark', {}, { preserveScroll: true });
 });
 it('hides unpublished output and preserves published history with a stale warning', () => {
  const result = { analysis: { claims: [{ text: '经原文支持的结论', evidence_ids: [4] }] }, statistics: { groups: [] } };
  const { rerender } = render(<Report run={{ id: 1, status: 'running', stage: 'audit', result }} />);
  expect(screen.queryByText('经原文支持的结论')).toBeNull();
  expect(screen.getByRole('button', { name: '取消任务' })).toBeTruthy();
  rerender(<Report run={{ id: 1, status: 'published', published_at: '2026-09-01', stale_at: '2026-09-02', result, evidence: [{ id: 4, notice_id: 1 }] }} />);
  expect(screen.getByText('经原文支持的结论')).toBeTruthy();
  expect(screen.getByText('依据已更新，以下为历史分析')).toBeTruthy();
  expect(screen.getByRole('link', { name: '证据 4' }).getAttribute('href')).toBe('/procurement-hub/notices/1');
  expect(screen.getByText(/暂无口径完整/)).toBeTruthy();
 });
 it('rejects script links and never interprets text as HTML', () => {
  expect(safeUrl('javascript:alert(1)')).toBeNull();
  render(<NoticeCard notice={{ ...notice, title: '<img src=x onerror=alert(1)>' }} />);
  expect(document.querySelector('img')).toBeNull();
 });
 it('shows automatic discovery without profile configuration', () => {
  render(<Index notices={{ data: [], links: [] }} filters={{}} bookmarks={[]} hasProfile={false} />);
  expect(screen.getByText(/无需配置/)).toBeTruthy();
  expect(screen.queryByText('完善企业资料')).toBeNull();
  expect(screen.getByRole('combobox', {name:'排序'}).value).toBe('recommended');
 });
 it('renders a ready-to-read brief with source conditions and history limits', () => {
  const value = { notice_id: 1, title: notice.title, status: '原文与主档自动核对', recommendation: '已结束，纳入历史条件研究。', screening: { reasons: ['与已有项目共同涉及：模板。'] }, conditions: ['采购量约为12000吨，估算金额3973万元'], counterexamples: [{ title: '已有项目', quote: '后续未中标' }], limitations: ['预算不是成交价格'], source_url: 'https://example.com/notice', source_name: '公开原文' };
  render(<Brief value={value} />);
  expect(screen.getByText('采购核对报告')).toBeTruthy();
  expect(screen.getByText('已结束，纳入历史条件研究。')).toBeTruthy();
  expect(screen.getByText('预算不是成交价格')).toBeTruthy();
  expect(screen.getByText(/后续未中标/)).toBeTruthy();
  expect(screen.getByRole('link', {name:'公开原文 ↗'}).getAttribute('href')).toBe('https://example.com/notice');
 });
 it('shows analysis directly on an opportunity without duplicating its title', () => {
  render(<Index notices={{data:[notice],total:1}} briefings={[{notice_id:1,recommendation:'先核实原站报名窗口',conditions:[],status:'原文与主档自动核对'}]} />);
  expect(screen.getByText('先核实原站报名窗口')).toBeTruthy();
  expect(screen.getAllByText(notice.title)).toHaveLength(1);
  expect(screen.getByRole('link',{name:'查看分析报告'})).toBeTruthy();
  expect(screen.queryByText('完善企业资料')).toBeNull();
 });
 it('renders audited decisions, qualification gaps, history prices and actions with evidence', () => {
  const sections = ['decision','match','qualification','counterexample','price','window','action'];
  const recommendation = {claims: sections.map(section => ({section,text: `内容-${section}`,evidence_ids:[4]})),project_references:[{project_id:'P1',field:'remark',quote:'价格太低未中'}]};
  render(<Report run={{id:1,status:'published',published_at:'2026-09-10',result:{recommendation},evidence:[{id:4,notice_id:1}]}} />);
  for (const label of ['值得不值得跟进','与已有项目的匹配点','资格缺口与待核实条件','历史反例','历史价格参考','报名与投标时间','具体行动建议']) expect(screen.getByText(label)).toBeTruthy();
  expect(screen.getByText(/价格太低未中/)).toBeTruthy();
 });
 it('leads with the audited recommendation and folds source cross-checks without duplicate agent decisions', () => {
  const result = { recommendation: {claims:[{section:'decision',text:'当前跟进建议',evidence_ids:[]}]}, analysis:{claims:[{section:'decision',text:'重复中间判断'},{section:'price',text:'预算不能当成交价'}]} };
  render(<Show notice={notice} reports={[{id:1,status:'published',published_at:'2026-09-10',result}]} briefing={{status:'已核对',recommendation:'原始核对',screening:{reasons:[]},conditions:[],counterexamples:[],limitations:[]}} />);
  expect(screen.getByText('当前跟进建议')).toBeTruthy();
  expect(screen.getByText('预算不能当成交价')).toBeTruthy();
  expect(screen.queryByText('重复中间判断')).toBeNull();
  expect(screen.getByText('原文与主档核对').closest('details').open).toBe(false);
 });
 it('keeps personal follow empty states usable', () => {
  render(<Follows notices={[]} subscriptions={[]} />);
  expect(screen.getByText(/收藏感兴趣的公告/)).toBeTruthy();
 });
});
