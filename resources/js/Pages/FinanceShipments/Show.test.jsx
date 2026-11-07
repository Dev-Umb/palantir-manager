// @vitest-environment jsdom
import { act, cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, expect, it, vi } from 'vitest';
import Show from './Show';
import { totals } from './ledger';
vi.mock('@inertiajs/react', () => ({ Head: () => null, Link: ({children,href}) => <a href={href}>{children}</a>, router: { on: () => () => {} } }));
vi.mock('../../Components/Layout', () => ({ default: ({ children }) => <main>{children}</main> }));
const order = { id:'order', date:'2026-11-06', vehicle:'晋A', rows:[{id:'row',name:'模板',unit:'吨',quantity:10,price:5000},{id:'parts',name:'连接件',unit:'件',quantity:20,price:15}] };
function setup() { return render(<Show project={{title:'项目',owner:'武华晓',id:'project'}} ledger={{orders:[structuredClone(order)], revision:0}} imports={[]} urls={{index:'/finance',save:'/save',commit:'/commit'}} />); }
afterEach(() => { cleanup(); vi.useRealTimers(); vi.unstubAllGlobals(); });
it('edits inline, saves and preserves adjacent non-ton rows', async () => {
    vi.useFakeTimers(); const fetch=vi.fn().mockResolvedValue({ok:true,json:async()=>({revision:1})});vi.stubGlobal('fetch',fetch);setup();
    fireEvent.change(screen.getByLabelText('第1行数量'),{target:{value:'12'}});
    await act(async()=>vi.advanceTimersByTimeAsync(650));
    expect(JSON.parse(fetch.mock.calls[0][1].body).orders[0].rows[0].quantity).toBe('12');
    expect(screen.getByLabelText('第2行货物名称').value).toBe('连接件');
    expect(screen.getByRole('status').textContent).toBe('已自动保存');
    expect(screen.queryByRole('button',{name:'增加明细'})).toBeNull();
});
it('inserts under the right clicked row and leaves a continuous blank row', () => {
    setup();fireEvent.contextMenu(screen.getByLabelText('第1行货物名称'),{clientX:30,clientY:100});
    fireEvent.click(screen.getByRole('button',{name:'在下方插入明细'}));
    expect(screen.getByLabelText('第2行货物名称').value).toBe('');
    expect(screen.getByLabelText('第3行货物名称').value).toBe('连接件');
    fireEvent.change(screen.getByLabelText('第4行货物名称'),{target:{value:'新明细'}});
    expect(screen.getByLabelText('第5行货物名称').value).toBe('');
});
it('serializes edits during a request without overwriting the newer input',async()=>{
    vi.useFakeTimers();let resolve;
    const fetch=vi.fn().mockImplementationOnce(()=>new Promise(r=>{resolve=r;})).mockResolvedValue({ok:true,json:async()=>({revision:2})});vi.stubGlobal('fetch',fetch);setup();
    fireEvent.change(screen.getByLabelText('第1行数量'),{target:{value:'11'}});await act(async()=>vi.advanceTimersByTimeAsync(650));
    fireEvent.change(screen.getByLabelText('第1行数量'),{target:{value:'12'}});
    await act(async()=>{resolve({ok:true,json:async()=>({revision:1})});});
    await act(async()=>vi.advanceTimersByTimeAsync(650));
    expect(fetch).toHaveBeenCalledTimes(2);expect(JSON.parse(fetch.mock.calls[1][1].body).revision).toBe(1);
    expect(screen.getByLabelText('第1行数量').value).toBe('12');
});
it('keeps input on failure and retries, while conflicts do not silently overwrite',async()=>{
    vi.useFakeTimers();const fetch=vi.fn().mockResolvedValue({ok:false,status:409,json:async()=>({message:'台账冲突'})});vi.stubGlobal('fetch',fetch);setup();
    fireEvent.change(screen.getByLabelText('第1行数量'),{target:{value:'13'}});await act(async()=>vi.advanceTimersByTimeAsync(650));
    expect(screen.getByRole('alert').textContent).toContain('台账冲突');expect(screen.getByLabelText('第1行数量').value).toBe('13');
    fireEvent.change(screen.getByLabelText('第1行数量'),{target:{value:'14'}});await act(async()=>vi.advanceTimersByTimeAsync(1000));expect(fetch).toHaveBeenCalledTimes(1);
});
it('counts only complete tonne rows; zero and missing are not shipments',()=>{
    const result=totals([order,{...order,rows:[{name:'草稿',unit:'吨',quantity:null},{name:'零',unit:'吨',quantity:0}]}]);
    expect(result.tonnes).toBe(10);expect(result.amount).toBe(50300);expect(result.pending).toBe(3);
});

it('shows imported unknown units as editable pending rows without double counting', () => {
    const draft = { ...structuredClone(order), rows:[{id:'pending',name:'盖板',unit:null,quantity:16.94,price:5000}] };
    const {container} = render(<Show project={{title:'项目',owner:'业务员'}} ledger={{orders:[draft],revision:1}} imports={[{id:'i',name:'历史.xls',imported_at:'2026-11-07',counts:{ready:0,pending:1,excluded:0},pending_outside_ledger:0,records:[]}]} urls={{}} />);
    expect(screen.getByLabelText('第1行单位').value).toBe('');
    expect(screen.getByLabelText('第1行数量').value).toBe('16.94');
    expect(container.querySelector('tfoot').textContent).toContain('1 行待定');
    expect(totals([draft]).tonnes).toBe(0);
});

it('renders one Excel grid with independent date and vehicle cells and no explanation cards', async () => {
    vi.useFakeTimers();const fetch=vi.fn().mockResolvedValue({ok:true,json:async()=>({revision:1})});vi.stubGlobal('fetch',fetch);
    const second={...structuredClone(order),id:'other',vehicle:'晋B',rows:[{id:'other-row',name:'盖板',unit:'吨',quantity:2,price:5000}]};
    render(<Show project={{title:'项目',owner:'业务员'}} ledger={{orders:[structuredClone(order),second],revision:0}} imports={[]} urls={{save:'/save'}} />);
    expect(screen.getAllByRole('table')).toHaveLength(1);
    expect(screen.getByRole('columnheader',{name:'车牌号'})).toBeTruthy();
    expect(screen.queryByText('发货明细记录')).toBeNull();expect(screen.queryByRole('button',{name:'新建发货单'})).toBeNull();
    fireEvent.change(screen.getByLabelText('第1行车牌号'),{target:{value:'晋C'}});
    fireEvent.change(screen.getByLabelText('第1行发货日期'),{target:{value:'2026-11-05'}});
    await act(async()=>vi.advanceTimersByTimeAsync(650));
    const saved=JSON.parse(fetch.mock.calls[0][1].body).orders;
    expect(saved[0].rows[0].vehicle).toBe('晋C');expect(saved[0].rows[0].date).toBe('2026-11-05');
    expect(screen.getByLabelText('第2行车牌号').value).toBe('晋A');expect(screen.getByLabelText('第3行车牌号').value).toBe('晋B');
});
it('allows typing the first detail directly into an empty project', async()=>{
    vi.useFakeTimers();const fetch=vi.fn().mockResolvedValue({ok:true,json:async()=>({revision:1})});vi.stubGlobal('fetch',fetch);
    render(<Show project={{title:'项目'}} ledger={{orders:[],revision:0}} imports={[]} urls={{save:'/save'}}/>);
    fireEvent.change(screen.getByLabelText('第1行货物名称'),{target:{value:'盖板'}});
    expect(screen.getByLabelText('第2行货物名称').value).toBe('');
    await act(async()=>vi.advanceTimersByTimeAsync(650));expect(JSON.parse(fetch.mock.calls[0][1].body).orders[0].rows[0].name).toBe('盖板');
});

it('opens the hidden file picker through the import button and blocks it during unsaved edits', () => {
    setup();
    const input = screen.getByLabelText('导入发货数据');
    const click = vi.spyOn(input, 'click');
    expect(input.hidden).toBe(true);
    expect(input.accept).toBe('.xls,.xlsx,.csv');
    fireEvent.click(screen.getByRole('button', {name:'导入Excel/CSV'}));
    expect(click).toHaveBeenCalledTimes(1);
    fireEvent.change(screen.getByLabelText('第1行数量'), {target:{value:'12'}});
    expect(screen.getByRole('button', {name:'导入Excel/CSV'}).disabled).toBe(true);
    expect(input.disabled).toBe(true);
});
