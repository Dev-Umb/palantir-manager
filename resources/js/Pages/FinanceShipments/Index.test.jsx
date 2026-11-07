// @vitest-environment jsdom
import { cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { afterEach, expect, it, vi } from 'vitest';
import Index from './Index';
import { router } from '@inertiajs/react';
vi.mock('@inertiajs/react', () => ({ Head: () => null, router:{reload:vi.fn()}, Link: ({children,href}) => <a href={href}>{children}</a> }));
vi.mock('../../Components/Layout', () => ({ default: ({children}) => <main>{children}</main> }));
const projects=[{id:'a',title:'北京01项目',code:'XYC-001',owner_id:1,owner:'武华晓',url:'/a',tonnes:12},{id:'b',title:'兰合五标',code:'XYC-002',owner_id:1,owner:'武华晓',url:'/b',tonnes:20},{id:'c',title:'北京其他业务员项目',code:'XYC-003',owner_id:2,owner:'业务员乙',url:'/c',tonnes:30}];
afterEach(() => {cleanup();vi.unstubAllGlobals();vi.clearAllMocks();});
it('matches partial names and codes, and clears without crossing salesperson scope',()=>{
    render(<Index projects={projects}/>);fireEvent.click(screen.getAllByRole('button',{name:'查看项目 →'})[0]);
    const search=screen.getByRole('searchbox',{name:'快速查找项目'});
    fireEvent.change(search,{target:{value:' 北京 '}});expect(screen.getByRole('status').textContent).toBe('找到 1 / 2 个项目');
    expect(screen.getByText('北京01项目')).toBeTruthy();expect(screen.queryByText('兰合五标')).toBeNull();expect(screen.queryByText('北京其他业务员项目')).toBeNull();
    fireEvent.change(search,{target:{value:'xyc-002'}});expect(screen.getByText('兰合五标')).toBeTruthy();expect(screen.getByRole('link',{name:'查看明细 →'}).getAttribute('href')).toBe('/b');
    fireEvent.change(search,{target:{value:'不存在'}});expect(screen.getByText('没有匹配的项目，请换个关键词或清空搜索。')).toBeTruthy();
    fireEvent.click(screen.getByRole('button',{name:'清空搜索'}));expect(screen.getByRole('status').textContent).toBe('2 个项目');expect(screen.getAllByRole('link',{name:'查看明细 →'})).toHaveLength(2);
});

const unlinked = {id:'ledger-1',ledger_id:1,title:'原文件.xls',owner_id:'unlinked',owner:'待关联资料',url:'/ledgers/1',association_url:'/ledgers/1/association',project_id:null,revision:3};
it('links unassigned data from the overview through a searchable existing project picker', async()=>{
    const fetch=vi.fn().mockResolvedValue({ok:true,json:async()=>({revision:4})});vi.stubGlobal('fetch',fetch);
    render(<Index projects={[unlinked,...projects]} canUnlink projectOptions={[{value:'existing',label:'宁盐一标 · 建筑七局 · XYC-136'}]} />);
    fireEvent.click(within(screen.getByRole('heading',{name:'待关联资料'}).closest('section')).getByRole('button',{name:'查看项目 →'}));
    expect(screen.getByText('未关联')).toBeTruthy();
    fireEvent.click(screen.getByRole('button',{name:'修改关联'}));
    fireEvent.click(screen.getByRole('button',{name:'暂不关联项目'}));
    fireEvent.change(screen.getByPlaceholderText('输入关键字搜索'),{target:{value:'建筑七局'}});
    fireEvent.click(screen.getByRole('button',{name:'宁盐一标 · 建筑七局 · XYC-136'}));
    fireEvent.click(screen.getByRole('button',{name:'保存关联'}));
    await waitFor(()=>expect(router.reload).toHaveBeenCalled());
    expect(JSON.parse(fetch.mock.calls[0][1].body)).toEqual({project_id:'existing',revision:3});
    expect(screen.getByRole('link',{name:'查看明细 →'}).getAttribute('href')).toBe('/ledgers/1');
});
it('keeps a failed association selection and detail entry visible without refreshing', async()=>{
    vi.stubGlobal('fetch',vi.fn().mockResolvedValue({ok:false,status:409,json:async()=>({message:'台账已更新'})}));
    render(<Index projects={[unlinked]} canUnlink />);
    fireEvent.click(screen.getByRole('button',{name:'查看项目 →'}));
    fireEvent.click(screen.getByRole('button',{name:'修改关联'}));
    fireEvent.click(screen.getByRole('button',{name:'保存关联'}));
    await waitFor(()=>expect(screen.getByRole('alert').textContent).toContain('台账已更新'));
    expect(router.reload).not.toHaveBeenCalled();
    expect(screen.getByRole('button',{name:'保存关联'}).disabled).toBe(false);
    expect(screen.getByRole('link',{name:'查看明细 →'})).toBeTruthy();
});
it('edits the finance name inline and preserves association and detail entry', async()=>{
    const fetch=vi.fn().mockResolvedValue({ok:true,json:async()=>({display_title:'财务新名称',revision:4})});vi.stubGlobal('fetch',fetch);
    render(<Index projects={[{...unlinked,rename_url:'/ledgers/1/title'}]} />);fireEvent.click(screen.getByRole('button',{name:'查看项目 →'}));
    fireEvent.click(screen.getByRole('button',{name:'原文件.xls'}));
    fireEvent.change(screen.getByRole('textbox',{name:'财务项目名称'}),{target:{value:'财务新名称'}});
    fireEvent.blur(screen.getByRole('textbox',{name:'财务项目名称'}));
    await waitFor(()=>expect(screen.getByRole('button',{name:'财务新名称'})).toBeTruthy());
    expect(JSON.parse(fetch.mock.calls[0][1].body)).toEqual({display_title:'财务新名称',revision:3});
    expect(screen.getByRole('button',{name:'修改关联'})).toBeTruthy();
    expect(screen.getByRole('link',{name:'查看明细 →'}).getAttribute('href')).toBe('/ledgers/1');
});
it('cancels with Escape and retains invalid or conflicting name input', async()=>{
    const fetch=vi.fn().mockResolvedValue({ok:false,status:409,json:async()=>({message:'台账已更新'})});vi.stubGlobal('fetch',fetch);
    render(<Index projects={[{...unlinked,rename_url:'/ledgers/1/title'}]} />);fireEvent.click(screen.getByRole('button',{name:'查看项目 →'}));
    fireEvent.click(screen.getByRole('button',{name:'原文件.xls'}));fireEvent.change(screen.getByRole('textbox',{name:'财务项目名称'}),{target:{value:'取消'}});
    fireEvent.keyDown(screen.getByRole('textbox',{name:'财务项目名称'}),{key:'Escape'});
    expect(fetch).not.toHaveBeenCalled();expect(screen.getByRole('button',{name:'原文件.xls'})).toBeTruthy();
    fireEvent.click(screen.getByRole('button',{name:'原文件.xls'}));fireEvent.change(screen.getByRole('textbox',{name:'财务项目名称'}),{target:{value:' '}});fireEvent.blur(screen.getByRole('textbox',{name:'财务项目名称'}));
    expect(screen.getByRole('alert').textContent).toBe('名称不能为空');expect(fetch).not.toHaveBeenCalled();
    fireEvent.change(screen.getByRole('textbox',{name:'财务项目名称'}),{target:{value:'保留输入'}});fireEvent.blur(screen.getByRole('textbox',{name:'财务项目名称'}));
    await waitFor(()=>expect(screen.getByRole('alert').textContent).toBe('台账已更新'));
    expect(screen.getByRole('textbox',{name:'财务项目名称'}).value).toBe('保留输入');expect(router.reload).not.toHaveBeenCalled();
});
it('searches renamed and original project names while read-only names remain uneditable',()=>{
    render(<Index projects={[{...projects[0],title:'财务名称',project_title:'业务原名'}]} />);fireEvent.click(screen.getByRole('button',{name:'查看项目 →'}));
    fireEvent.change(screen.getByRole('searchbox'),{target:{value:'业务原名'}});
    expect(screen.getByText('财务名称')).toBeTruthy();expect(screen.queryByRole('button',{name:'财务名称'})).toBeNull();
});
