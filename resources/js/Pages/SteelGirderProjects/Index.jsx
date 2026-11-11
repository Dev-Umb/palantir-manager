import { useEffect, useRef } from 'react';
import { Head, router } from '@inertiajs/react';
import Layout from '../../Components/Layout';
import SteelGirderNavigation from '../../Components/SteelGirderNavigation';

export default function Index({ workspaceUrl }) {
    const frame = useRef(null);
    useEffect(() => router.on('before', (event) => {
        if (frame.current?.contentWindow?.SteelGirderHasUnsavedChanges?.() && !window.confirm('项目有尚未保存的修改。确定离开并放弃这些修改吗？')) {
            event.preventDefault();
        }
    }), []);
    return <Layout hideHeader>
        <Head title="项目管理 · 钢箱梁工作台" />
        <SteelGirderNavigation />
        <iframe ref={frame} title="钢箱梁项目管理" src={workspaceUrl} style={{ width: '100%', height: 'calc(100dvh - 150px)', minHeight: 580, border: 0, borderRadius: 12 }} />
    </Layout>;
}
