// @vitest-environment jsdom
import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, expect, it, vi } from 'vitest';
import Index from './Index';
vi.mock('@inertiajs/react', () => ({ Head: () => null, Link: ({children,href}) => <a href={href}>{children}</a> }));
vi.mock('../../Components/Layout', () => ({ default: ({children}) => <main>{children}</main> }));
const projects=[{id:'a',title:'北京01项目',code:'XYC-001',owner_id:1,owner:'武华晓',url:'/a',tonnes:12},{id:'b',title:'兰合五标',code:'XYC-002',owner_id:1,owner:'武华晓',url:'/b',tonnes:20},{id:'c',title:'北京其他业务员项目',code:'XYC-003',owner_id:2,owner:'业务员乙',url:'/c',tonnes:30}];
afterEach(cleanup);
it('matches partial names and codes, and clears without crossing salesperson scope',()=>{
    render(<Index projects={projects}/>);fireEvent.click(screen.getAllByRole('button',{name:'查看项目 →'})[0]);
    const search=screen.getByRole('searchbox',{name:'快速查找项目'});
    fireEvent.change(search,{target:{value:' 北京 '}});expect(screen.getByRole('status').textContent).toBe('找到 1 / 2 个项目');
    expect(screen.getByText('北京01项目')).toBeTruthy();expect(screen.queryByText('兰合五标')).toBeNull();expect(screen.queryByText('北京其他业务员项目')).toBeNull();
    fireEvent.change(search,{target:{value:'xyc-002'}});expect(screen.getByText('兰合五标')).toBeTruthy();expect(screen.getByRole('link',{name:'查看明细 →'}).getAttribute('href')).toBe('/b');
    fireEvent.change(search,{target:{value:'不存在'}});expect(screen.getByText('没有匹配的项目，请换个关键词或清空搜索。')).toBeTruthy();
    fireEvent.click(screen.getByRole('button',{name:'清空搜索'}));expect(screen.getByRole('status').textContent).toBe('2 个项目');expect(screen.getAllByRole('link',{name:'查看明细 →'})).toHaveLength(2);
});
