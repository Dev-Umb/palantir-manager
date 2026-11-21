// @vitest-environment jsdom
import { fireEvent, render, screen } from '@testing-library/react';
import { expect, it, vi } from 'vitest';
import Demos from './Demos';
vi.mock('@inertiajs/react', () => ({
    Head: () => null, Link: ({children, href}) => <a href={href}>{children}</a>,
    Form: ({action,children}) => <form action={action}>{children({processing:false,errors:{}})}</form>,
}));
vi.mock('../../Components/Layout', () => ({ default: ({ children }) => <div>{children}</div> }));
vi.mock('../../Components/SteelGirderNavigation', () => ({ default: () => <nav /> }));
it('keeps the active site, failed attempts, and original ZIP upload together', () => {
    render(<Demos site={{id:'s',name:'吊装演示',current_id:'1'}} share="https://example.test/s/s" deployments={[
        {id:'2', version:2,name:'错误.zip',status:'failed',failure:'ZIP 无效'},
        {id:'1',version:1,name:'吊装.zip',status:'ready',url:'https://site.umb.ink/d/1/demo.html'},
    ]}/>);
    expect(screen.getByRole('link',{name:'打开站点'}).getAttribute('href')).toBe('https://example.test/s/s');
    expect(screen.getAllByRole('link',{name:'预览'})).toHaveLength(1);
    fireEvent.change(screen.getByLabelText('筛选部署状态'), {target:{value:'failed'}});
    expect(screen.queryByRole('link',{name:'预览'})).toBeNull();
    fireEvent.click(screen.getByRole('button',{name:'上传新版本'}));
    const file=screen.getByLabelText(/选择 HTML 离线包/);
    expect(file.accept).toBe('.zip');
    expect(file.form.getAttribute('action')).toBe('/steel-girder-projects/demos');
});
