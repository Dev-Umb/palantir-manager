import { Head } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import './ProductionWorkbench.css';

const materialViews = [['ledger', '进场台账'], ['entry', '快捷录入'], ['summary', '月度汇总'], ['report', '原表报表']];
const templateViews = [['台车模板', '台车模板'], ['开挖台架', '开挖台架'], ['液压配件', '液压配件']];
const moduleOf = (view) => materialViews.some(([key]) => key === view) ? 'material' : 'template';

export default function ProductionWorkbench({ workbenches, initialModule, panelUrl }) {
    const [selected, setSelected] = useState(initialModule === 'template' ? '台车模板' : 'ledger');
    const [loaded, setLoaded] = useState([initialModule]);
    const [states, setStates] = useState({});
    const frames = useRef({});
    const pendingSelection = useRef(true);
    const active = moduleOf(selected);
    const activeRef = useRef(active);
    activeRef.current = active;
    const ready = !!states[active]?.ready;

    useEffect(() => {
        function receive(event) {
            if (event.origin !== window.location.origin || event.data?.type !== 'production-workbench-state') return;
            const module = Object.keys(frames.current).find((key) => frames.current[key]?.contentWindow === event.source);
            if (!module) return;
            setStates((old) => ({ ...old, [module]: event.data }));
            const current = event.data.nav?.find((item) => item.active)?.key;
            if (module === activeRef.current && !pendingSelection.current && current) setSelected(current);
        }
        window.addEventListener('message', receive);
        return () => window.removeEventListener('message', receive);
    }, []);

    useEffect(() => {
        if (!ready) return;
        const selector = active === 'material' ? `.nav[data-view="${selected}"]` : `.progress-nav[data-kind="${selected}"]`;
        frames.current[active]?.contentDocument?.querySelector(selector)?.click();
        pendingSelection.current = false;
    }, [selected, active, ready]);

    function choose(view) {
        const module = moduleOf(view);
        pendingSelection.current = true;
        setLoaded((old) => old.includes(module) ? old : [...old, module]);
        setSelected(view);
    }

    function action(id) {
        frames.current[active]?.contentDocument?.getElementById(id)?.click();
    }

    function navigation(module, views) {
        if (!workbenches[module]) return null;
        return <nav aria-label={module === 'material' ? '原材进场' : '模板与配件'}>{views.filter(([key]) => key !== 'entry' || workbenches.material.manage).map(([key, label]) => (
            <button key={key} className={selected === key ? 'active' : ''} aria-current={selected === key ? 'page' : undefined} onClick={() => choose(key)}>{label}<b>{states[module]?.nav?.find((item) => item.key === key)?.count || ''}</b></button>
        ))}</nav>;
    }

    return <div className="production-workbench">
        <Head title="生产管理工作台" />
        <aside className="production-sidebar">
            <a className="production-return" href={panelUrl}>← 返回 Palantir 面板</a>
            <div className="production-brand"><span>鑫</span><div>鑫源昌<small>生产管理工作台</small></div></div>
            {workbenches.material && <div className="production-nav-label">原材进场</div>}
            {navigation('material', materialViews)}
            {workbenches.template && <div className="production-nav-label">模板与配件</div>}
            {navigation('template', templateViews)}
            <div className="production-tools">
                <small>共享版 · 数据库保存</small>
                <small>{active === 'material' ? '原材进场' : '模板与配件'}工程备份</small>
                {workbenches[active]?.export && <button disabled={!ready} onClick={() => action('backup')}>保存工程备份 ↗</button>}
                {workbenches[active]?.recover && <><button disabled={!ready} onClick={() => action('restore')}>恢复工程备份 ↗</button><button disabled={!ready} onClick={() => action('undo-restore')}>撤销上次恢复</button></>}
                <button disabled={!ready} onClick={() => action('help')}>使用说明</button>
                <small role="status">{states[active]?.backupStatus}</small>
            </div>
        </aside>
        <main className="production-content">
            {!ready && <p role="status" className="production-loading">{states[active]?.error || '正在载入工作台…'}</p>}
            {loaded.map((module) => <iframe key={module} ref={(node) => { frames.current[module] = node; }} title={module === 'material' ? '原材进场内容' : '模板与配件内容'} src={workbenches[module].url} hidden={active !== module} />)}
        </main>
    </div>;
}
