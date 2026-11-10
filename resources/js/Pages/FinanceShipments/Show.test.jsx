// @vitest-environment jsdom
import { act, cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, expect, it, vi } from 'vitest';
import Show from './Show';
import { totals } from './ledger';
const sessionUser = vi.hoisted(() => ({id:42}));
vi.mock('@inertiajs/react', () => ({ usePage: () => ({props:{auth:{user:sessionUser}}}), Head: () => null, Link: ({children,href}) => <a href={href}>{children}</a>, router: { on: () => () => {} } }));
vi.mock('../../Components/Layout', () => ({ default: ({ children }) => <main>{children}</main> }));
const order = { id:'order', date:'2026-11-06', vehicle:'晋A', rows:[{id:'row',name:'模板',unit:'吨',quantity:10,price:5000},{id:'parts',name:'连接件',unit:'件',quantity:20,price:15}] };
function setup() { return render(<Show project={{title:'项目',owner:'武华晓',id:'project'}} ledger={{orders:[structuredClone(order)], revision:0}} imports={[]} urls={{index:'/finance',save:'/save',commit:'/commit'}} />); }
afterEach(() => { cleanup(); vi.useRealTimers(); vi.unstubAllGlobals(); vi.restoreAllMocks(); sessionUser.id = 42; });
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

it('defaults historical unspecified units to tonnes without double counting', () => {
    const draft = { ...structuredClone(order), rows:[{id:'pending',name:'盖板',unit:null,quantity:16.94,price:5000}] };
    const {container} = render(<Show project={{title:'项目',owner:'业务员'}} ledger={{orders:[draft],revision:1}} imports={[{id:'i',name:'历史.xls',imported_at:'2026-11-07',counts:{ready:0,pending:1,excluded:0},pending_outside_ledger:0,records:[]}]} urls={{}} />);
    expect(screen.getByLabelText('第1行单位').value).toBe('吨');
    expect(screen.getByLabelText('第1行数量').value).toBe('16.94');
    expect(container.querySelector('tfoot').textContent).not.toContain('行待定');
    expect(totals([draft]).tonnes).toBe(16.94);
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

it('defaults unspecified units to tonnes and sums without dates while excluding pieces',()=>{
    const quantities=[19.58,17.46,25.12,24.52,26.18,27.48,26.16,31.14,31.5];
    const rows=quantities.map((quantity,i)=>({id:String(i),name:'箱梁',quantity,unit:null,price:7350,date:null}));
    const sample={id:'shipment',date:'2026-11-06',rows};
    const {container}=render(<Show project={{title:'项目'}} ledger={{orders:[sample],revision:0}} imports={[]} urls={{}}/>);
    expect(container.querySelector('tfoot').textContent).toContain('229.14 吨');
    fireEvent.change(screen.getByLabelText('第1行单位'),{target:{value:'件'}});
    expect(container.querySelector('tfoot').textContent).toContain('209.56 吨');
    fireEvent.change(screen.getByLabelText('第1行单位'),{target:{value:'吨'}});
    expect(container.querySelector('tfoot').textContent).toContain('229.14 吨');
    expect(container.querySelector('.finance-sheet-subtotal').textContent).toContain('229.14 吨');
    expect(screen.getByLabelText('第1行发货日期').value).toBe('');
    const result=totals([{...sample,rows:[...rows.map(row=>({...row,unit:'吨'})),{name:'配件',unit:'件',quantity:100},{name:'未知',unit:null,quantity:20,date:null}]}]);
    expect(result.tonnes).toBe(249.14);
    expect(result.last_date).toBeNull();
    expect(result.amount).toBe(1684179);
});

it('drags from editable cells to select whole rows, saves range metadata and removes only the subtotal', async () => {
    vi.useFakeTimers(); const fetch = vi.fn().mockResolvedValue({ok:true,json:async()=>({revision:1})}); vi.stubGlobal('fetch',fetch);
    const {container} = setup();
    const before = container.querySelector('tfoot').textContent;
    fireEvent.mouseDown(screen.getByLabelText('第1行数量'), {button:0,buttons:1});
    fireEvent.mouseEnter(screen.getByLabelText('第2行货物名称').closest('tr'), {buttons:1});
    fireEvent.mouseUp(document);
    expect(container.querySelectorAll('tr[aria-selected="true"]')).toHaveLength(2);
    fireEvent.contextMenu(screen.getByLabelText('第2行备注'));
    expect(screen.getByText('已选中 2 行')).toBeTruthy();
    fireEvent.click(screen.getByRole('button',{name:'在选区下方插入小计'}));
    expect(screen.getByText('第 1–2 行小计')).toBeTruthy();
    expect(container.querySelector('tfoot').textContent).toBe(before);
    await act(async()=>vi.advanceTimersByTimeAsync(650));
    expect(JSON.parse(fetch.mock.calls[0][1].body).orders[0].rows[1].subtotal_starts).toEqual(['row']);
    fireEvent.click(screen.getByRole('button',{name:'删除小计'}));
    expect(screen.queryByText('第 1–2 行小计')).toBeNull();
    expect(screen.getByLabelText('第2行货物名称').value).toBe('连接件');
});
it('restores subtotals and recalculates edits and inserted detail without counting them twice', () => {
    const savedOrder = structuredClone(order); savedOrder.rows[1].subtotal_starts = ['row'];
    render(<Show project={{title:'项目'}} ledger={{orders:[savedOrder],revision:1}} imports={[]} urls={{}} />);
    expect(screen.getByText('第 1–2 行小计').closest('tr').textContent).toContain('50,300');
    fireEvent.change(screen.getByLabelText('第1行数量'),{target:{value:'12'}});
    expect(screen.getByText('第 1–2 行小计').closest('tr').textContent).toContain('60,300');
    fireEvent.contextMenu(screen.getByLabelText('第1行货物名称'));
    fireEvent.click(screen.getByRole('button',{name:'在下方插入明细'}));
    expect(screen.getByText('第 1–3 行小计')).toBeTruthy();
    fireEvent.change(screen.getByLabelText('第2行货物名称'),{target:{value:'新明细'}});
    fireEvent.change(screen.getByLabelText('第2行数量'),{target:{value:'2'}});
    expect(screen.getByText('第 1–3 行小计').closest('tr').textContent).toContain('14 吨');
});
it('supports reverse dragging and does not select blank rows or another source sheet', () => {
    const {container} = setup();
    fireEvent.mouseDown(screen.getByLabelText('第2行备注'),{button:0,buttons:1});
    fireEvent.mouseEnter(screen.getByLabelText('第1行车牌号').closest('tr'),{buttons:1});
    fireEvent.mouseEnter(screen.getByLabelText('第3行货物名称').closest('tr'),{buttons:1});
    fireEvent.mouseUp(document);
    expect(container.querySelectorAll('tr[aria-selected="true"]')).toHaveLength(2);
    fireEvent.mouseDown(screen.getByLabelText('第1行数量'),{button:0});
    fireEvent.mouseUp(document);
    expect(container.querySelectorAll('tr[aria-selected="true"]')).toHaveLength(0);
});
it('keeps selection within a source sheet and offers keyboard range selection', () => {
    const other = {...structuredClone(order), id:'other', source:'other.xls / Sheet1',rows:[{id:'other-row',name:'其他',quantity:1,unit:'吨'}]};
    const {container} = render(<Show project={{title:'项目'}} ledger={{orders:[order,other],revision:0}} imports={[]} urls={{}} />);
    fireEvent.mouseDown(screen.getByLabelText('第1行数量'),{button:0,buttons:1});
    fireEvent.mouseEnter(screen.getByLabelText('第3行数量').closest('tr'),{buttons:1});
    fireEvent.mouseUp(document);
    expect(container.querySelectorAll('tr[aria-selected="true"]')).toHaveLength(0);
    fireEvent.keyDown(screen.getByLabelText('选择第1行'),{key:' '});
    fireEvent.keyDown(screen.getByLabelText('选择第1行'),{key:'ArrowDown',shiftKey:true});
    expect(container.querySelectorAll('tr[aria-selected="true"]')).toHaveLength(2);
    fireEvent.keyDown(screen.getByLabelText('选择第2行'),{key:'ContextMenu'});
    expect(screen.getByRole('button',{name:'在选区下方插入小计'}).disabled).toBe(false);
});
it('opens the unit list after a click but not after a drag', () => {
    setup();
    const select = screen.getByLabelText('第1行单位');
    fireEvent.mouseDown(select,{button:0,buttons:1}); fireEvent.mouseUp(select); fireEvent.click(select);
    expect(select.size).toBe(4);
    fireEvent.change(select,{target:{value:'吨'}});
    expect(select.size).not.toBe(4);
    fireEvent.mouseDown(select,{button:0,buttons:1});
    fireEvent.mouseEnter(screen.getByLabelText('第2行单位').closest('tr'),{buttons:1});
    fireEvent.mouseUp(select); fireEvent.click(select);
    expect(select.size).not.toBe(4);
});
it('autosaves independent reference prices and leaves all totals unchanged', async () => {
    vi.useFakeTimers(); const fetch=vi.fn().mockResolvedValue({ok:true,json:async()=>({revision:1})});vi.stubGlobal('fetch',fetch);
    const savedOrder = structuredClone(order); savedOrder.rows[1].subtotal_starts=['row'];
    const {container}=render(<Show project={{title:'项目'}} ledger={{orders:[savedOrder],revision:0}} imports={[]} urls={{save:'/save'}} />);
    const total = container.querySelector('tfoot').textContent;
    const subtotal = screen.getByText('第 1–2 行小计').closest('tr').textContent;
    expect(screen.getByLabelText('第1行网价').value).toBe('');
    fireEvent.change(screen.getByLabelText('第1行网价'),{target:{value:'80'}});
    fireEvent.change(screen.getByLabelText('第1行加工费'),{target:{value:'30'}});
    expect(container.querySelector('tfoot').textContent).toBe(total);
    expect(screen.getByText('第 1–2 行小计').closest('tr').textContent).toBe(subtotal);
    await act(async()=>vi.advanceTimersByTimeAsync(650));
    const saved = JSON.parse(fetch.mock.calls[0][1].body).orders;
    expect(saved[0].rows[0]).toMatchObject({net_price:'80',processing_fee:'30',price:5000,quantity:10});
    cleanup();render(<Show project={{title:'项目'}} ledger={{orders:saved,revision:1}} imports={[]} urls={{}} />);
    expect(screen.getByLabelText('第1行加工费').value).toBe('30');
    fireEvent.change(screen.getByLabelText('第1行网价'),{target:{value:''}});
    fireEvent.change(screen.getByLabelText('第1行加工费'),{target:{value:'0'}});
    await act(async()=>vi.advanceTimersByTimeAsync(650));
    expect(JSON.parse(fetch.mock.calls[1][1].body).orders[0].rows[0]).toMatchObject({net_price:null,processing_fee:'0'});
});
it('drags headers, aligns totals by field, and restores only the current user preference', () => {
    const values = new Map();vi.spyOn(window,'localStorage','get').mockReturnValue({getItem:key=>values.get(key)??null,setItem:(key,value)=>values.set(key,value)});
    let view = setup();
    const from=screen.getByRole('columnheader',{name:'金额（元）'}), to=screen.getByRole('columnheader',{name:'发货日期'});
    fireEvent.dragStart(from,{dataTransfer:{setData:vi.fn()}});fireEvent.dragOver(to);fireEvent.drop(to);
    expect(screen.getAllByRole('columnheader')[1].textContent).toBe('金额（元）');
    expect(view.container.querySelector('tfoot tr').children[1].textContent).toBe('50,300');
    fireEvent.mouseDown(screen.getByLabelText('第1行网价'),{button:0,buttons:1});
    fireEvent.mouseEnter(screen.getByLabelText('第2行网价').closest('tr'),{buttons:1});
    fireEvent.mouseUp(document);
    expect(view.container.querySelectorAll('tr[aria-selected="true"]')).toHaveLength(2);
    expect(screen.getByLabelText('第1行数量').value).toBe('10');
    cleanup();view=setup();
    expect(screen.getAllByRole('columnheader')[1].textContent).toBe('金额（元）');
    fireEvent.keyDown(screen.getByRole('columnheader',{name:'金额（元）'}),{altKey:true,key:'ArrowRight'});
    expect(screen.getAllByRole('columnheader')[2].textContent).toBe('金额（元）');
    cleanup();sessionUser.id=73;setup();
    expect(screen.getAllByRole('columnheader')[1].textContent).toBe('发货日期');
});
it('deletes only the context row, removes dangling subtotal references and autosaves recalculated totals', async()=>{
    vi.useFakeTimers();vi.spyOn(window,'confirm').mockReturnValue(true);
    const fetch=vi.fn().mockResolvedValue({ok:true,json:async()=>({revision:1})});vi.stubGlobal('fetch',fetch);
    const savedOrder=structuredClone(order);savedOrder.rows[1].subtotal_starts=['row'];
    const {container}=render(<Show project={{title:'项目'}} ledger={{orders:[savedOrder],revision:0}} imports={[]} urls={{save:'/save'}} />);
    fireEvent.contextMenu(screen.getByLabelText('第1行货物名称'));
    fireEvent.click(screen.getByRole('button',{name:'删除此行'}));
    expect(screen.getByLabelText('第1行货物名称').value).toBe('连接件');
    expect(screen.queryByText('第 1–2 行小计')).toBeNull();
    expect(container.querySelector('tfoot').textContent).toContain('300');
    await act(async()=>vi.advanceTimersByTimeAsync(650));
    const rows=JSON.parse(fetch.mock.calls[0][1].body).orders[0].rows;
    expect(rows).toHaveLength(1);expect(rows[0].subtotal_starts).toEqual([]);
});
it('keeps the row when deletion is cancelled and disables deletion for the trailing blank row',()=>{
    vi.spyOn(window,'confirm').mockReturnValue(false);setup();
    fireEvent.contextMenu(screen.getByLabelText('第1行货物名称'));fireEvent.click(screen.getByRole('button',{name:'删除此行'}));
    expect(screen.getByLabelText('第1行货物名称').value).toBe('模板');
    fireEvent.contextMenu(screen.getByLabelText('第3行货物名称'));
    expect(screen.getByRole('button',{name:'删除此行'}).disabled).toBe(true);
});
