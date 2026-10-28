import { useEffect, useId, useRef, useState } from 'react';
import geometry from '../projectMapGeometry.json';

function projectCoordinate(location, view) {
    const { scale, translate, type } = geometry[view];
    const longitude = location.longitude * Math.PI / 180;
    const latitude = location.latitude * Math.PI / 180;
    return [translate[0] + scale * longitude, translate[1] - scale * (type === 'mercator' ? Math.log(Math.tan(Math.PI / 4 + latitude / 2)) : latitude)];
}

export function arrangeMapPoints(points, view) {
    return points.map((point) => {
        const position = projectCoordinate(point, view);
        return { ...point, position };
    });
}

export function mapCanvasLayout(width, height) {
    const scale = Math.min(width / 760, height / 420);
    return { scale, offsetX: (width - 760 * scale) / 2, offsetY: (height - 420 * scale) / 2 };
}

export function zoomMapViewport(current, scale, anchor) {
    scale = Math.max(1, Math.min(4, scale));
    const ratio = scale / current.scale;
    return {
        scale,
        x: Math.max(760 * (1 - scale), Math.min(0, anchor[0] - (anchor[0] - current.x) * ratio)),
        y: Math.max(420 * (1 - scale), Math.min(0, anchor[1] - (anchor[1] - current.y) * ratio)),
    };
}

export default function ProjectCoverageMap({ data, detailsUrl }) {
    const [view, setView] = useState('china');
    const [viewport, setViewport] = useState({ scale: 1, x: 0, y: 0 });
    const [stageSize, setStageSize] = useState({ width: 760, height: 420 });
    const canvas = mapCanvasLayout(stageSize.width, stageSize.height);
    const stageRef = useRef(null);
    const dragRef = useRef(null);
    function resetViewport() { setViewport({ scale: 1, x: 0, y: 0 }); }
    useEffect(() => {
        const stage = stageRef.current;
        function measure() {
            const rect = stage.getBoundingClientRect();
            if (rect.width && rect.height) setStageSize({ width: rect.width, height: rect.height });
        }
        measure();
        const observer = typeof ResizeObserver === 'undefined' ? null : new ResizeObserver(measure);
        observer?.observe(stage);
        function wheel(event) {
            if (event.target.closest('.viz-map-tooltip')) return;
            const rect = stage.getBoundingClientRect();
            if (!rect.width || !rect.height) return;
            event.preventDefault();
            const layout = mapCanvasLayout(rect.width, rect.height);
            const anchor = [(event.clientX - rect.left - layout.offsetX) / layout.scale, (event.clientY - rect.top - layout.offsetY) / layout.scale];
            const delta = event.deltaY * (event.deltaMode === 1 ? 16 : event.deltaMode === 2 ? rect.height : 1);
            setViewport(current => zoomMapViewport(current, current.scale * Math.exp(-Math.max(-500, Math.min(500, delta)) * 0.002), anchor));
        }
        stage.addEventListener('wheel', wheel, { passive: false });
        return () => { stage.removeEventListener('wheel', wheel); observer?.disconnect(); };
    }, []);
    function startDrag(event) {
        if (event.button !== 0 || event.target.closest('button, a, .viz-map-tooltip')) return;
        dragRef.current = { clientX: event.clientX, clientY: event.clientY, viewport };
        event.currentTarget.setPointerCapture?.(event.pointerId);
        setActive(null); setPinned(null);
    }
    function moveDrag(event) {
        const drag = dragRef.current;
        if (!drag) return;
        const rect = event.currentTarget.getBoundingClientRect();
        if (!rect.width || !rect.height) return;
        const layout = mapCanvasLayout(rect.width, rect.height);
        const next = { ...drag.viewport, x: drag.viewport.x + (event.clientX - drag.clientX) / layout.scale, y: drag.viewport.y + (event.clientY - drag.clientY) / layout.scale };
        setViewport(zoomMapViewport(next, next.scale, [0, 0]));
    }
    function endDrag(event) {
        dragRef.current = null;
        if (event.currentTarget.hasPointerCapture?.(event.pointerId)) event.currentTarget.releasePointerCapture(event.pointerId);
    }
    const [active, setActive] = useState(null);
    const [pinned, setPinned] = useState(null);
    const id = useId().replaceAll(':', '');
    const hasOverseas = data.points.some((point) => point.overseas);
    const factories = data.factories.map((factory) => ({ ...factory, position: projectCoordinate(factory, view) }));
    const points = arrangeMapPoints(data.points.filter((point) => view === 'world' || !point.overseas), view);
    function nearestPoint(event, fallback) {
        const rect = stageRef.current.getBoundingClientRect();
        if (!rect.width || !rect.height) return fallback;
        const cursor = [(event.clientX - rect.left - canvas.offsetX) / canvas.scale, (event.clientY - rect.top - canvas.offsetY) / canvas.scale];
        let nearest = fallback;
        let distance = Infinity;
        for (const point of points) {
            const x = point.position[0] * viewport.scale + viewport.x;
            const y = point.position[1] * viewport.scale + viewport.y;
            const screenX = x * canvas.scale + canvas.offsetX;
            const screenY = y * canvas.scale + canvas.offsetY;
            if (screenX < 0 || screenX > stageSize.width || screenY < 0 || screenY > stageSize.height) continue;
            const next = Math.hypot(x - cursor[0], y - cursor[1]);
            if (next < distance) { nearest = point.id; distance = next; }
        }
        return nearest;
    }
    const activePoint = points.find((point) => point.id === active);
    const routes = points.flatMap((point) => {
        const knownFactories = [...new Set(point.projects.map((project) => project.factory).filter(Boolean))];
        return knownFactories.map((factoryId) => {
            const factory = factories.find((item) => item.id === factoryId);
            if (!factory) return null;
            const [x, y] = factory.position;
            const [endX, endY] = projectCoordinate(point, view);
            return { id: `${point.id}-${factoryId}`, path: `M ${x} ${y} Q ${(x + endX) / 2} ${Math.min(y, endY) - Math.min(85, Math.abs(x - endX) * 0.25 + 24)} ${endX} ${endY}` };
        }).filter(Boolean);
    });
    return <section className="viz-card viz-map-card" aria-label="项目覆盖版图">
        <div className="viz-card-heading"><h2>项目覆盖版图</h2>{hasOverseas && <div className="viz-map-views" aria-label="地图视野"><button type="button" aria-pressed={view === 'china'} onClick={() => { setView('china'); resetViewport(); setActive(null); setPinned(null); }}>全国</button><button type="button" aria-pressed={view === 'world'} onClick={() => { setView('world'); resetViewport(); setActive(null); setPinned(null); }}>世界</button></div>}</div>
        <div className="viz-map-stage" ref={stageRef} tabIndex={0} aria-label="地图缩放区域" title="滚轮缩放，拖动平移，双击复位" data-zoom={viewport.scale} onPointerDown={startDrag} onPointerMove={moveDrag} onPointerUp={endDrag} onPointerCancel={endDrag} onDoubleClick={(event) => { if (!event.target.closest('button, a, .viz-map-tooltip')) resetViewport(); }} onKeyDown={(event) => {
            if (event.target !== event.currentTarget) return;
            if (['+', '=', '-', '0'].includes(event.key)) {
                event.preventDefault();
                if (event.key === '0') resetViewport();
                else setViewport(current => zoomMapViewport(current, current.scale * (event.key === '-' ? 0.8 : 1.25), [380, 210]));
            }
        }}>
            <svg className="viz-map-svg" viewBox="0 0 760 420" role="img" aria-label={`${view === 'china' ? '全国' : '世界'}项目覆盖地图，${points.length} 个概略地域`}>
                <defs><radialGradient id={`${id}-glow`}><stop offset="0" stopColor="#d5f2fc" stopOpacity="0.65" /><stop offset="1" stopColor="#f8fcff" stopOpacity="0" /></radialGradient></defs>
                <rect width="760" height="420" fill={`url(#${id}-glow)`} />
                <g className="viz-map-viewport" transform={`translate(${viewport.x},${viewport.y}) scale(${viewport.scale})`}><g className="viz-map-geography">{geometry[view].paths.map((region, index) => <path key={index} d={region.path}><title>{region.name}</title></path>)}</g>
                <g aria-hidden="true">{routes.map((route, index) => <g key={route.id}><path className="viz-map-route" vectorEffect="non-scaling-stroke" d={route.path} /><path className="viz-map-flow" vectorEffect="non-scaling-stroke" d={route.path} pathLength="100" style={{ animationDelay: `${-index * 0.6}s` }} /></g>)}</g>
                {points.map((point) => {
                    const [x, y] = point.position;
                    return <g key={point.id}><g transform={`translate(${x},${y}) scale(${1 / viewport.scale})`} aria-hidden="true"><circle className={`viz-map-point ${active === point.id ? 'is-active' : ''}`} r={active === point.id ? 5 : 3.5} /></g></g>;
                })}
                {factories.map((factory, index) => <g key={factory.id} transform={`translate(${factory.position}) scale(${1 / viewport.scale})`}><circle className="viz-map-factory" r="6" /><text className="viz-map-factory-label" x={index === 0 ? -12 : 12} y={index === 0 ? -14 : 24} textAnchor={index === 0 ? 'end' : 'start'}>{factory.name}</text></g>)}
                </g>
            </svg>
            <div className="viz-map-hit-layer">{points.map((point) => {
                const x = (point.position[0] * viewport.scale + viewport.x) * canvas.scale + canvas.offsetX;
                const y = (point.position[1] * viewport.scale + viewport.y) * canvas.scale + canvas.offsetY;
                const outside = x < 0 || x > stageSize.width || y < 0 || y > stageSize.height;
                return <button key={point.id} type="button" className="viz-map-hit" hidden={outside} style={{ left: `${x / stageSize.width * 100}%`, top: `${y / stageSize.height * 100}%`, display: outside ? 'none' : undefined }} aria-label={`${point.name}，${point.projects.length} 个项目`} aria-expanded={active === point.id} aria-controls={`${id}-projects`} onMouseEnter={(event) => setActive(nearestPoint(event, point.id))} onMouseMove={(event) => setActive(nearestPoint(event, point.id))} onMouseLeave={() => { if (!pinned) setActive(null); }} onFocus={() => setActive(point.id)} onBlur={() => { if (!pinned) setActive(null); }} onClick={(event) => { const selected = event.detail === 0 ? point.id : nearestPoint(event, point.id); setActive(selected); setPinned(selected); }} onKeyDown={(event) => { if (event.key === 'Escape') { setActive(null); setPinned(null); } }} />;
            })}</div>
            {activePoint && <div className="viz-map-tooltip" role="tooltip" id={`${id}-projects`} onMouseEnter={() => setActive(activePoint.id)} onMouseLeave={() => { if (!pinned) setActive(null); }}>
                <strong>{activePoint.name} · {activePoint.projects.length} 个项目</strong><small>{activePoint.precision} · 非精确施工坐标</small>
                <ul>{activePoint.projects.map((project) => <li key={project.id}>{detailsUrl ? <a href={`${detailsUrl}?record=${encodeURIComponent(project.id)}&mode=detail`}>{project.name}</a> : <span>{project.name}</span>}<small>{project.basis}</small><small>合同金额：{project.contract_amount == null ? '未维护' : `${(project.contract_amount / 10000).toLocaleString('zh-CN', { maximumFractionDigits: 2 })} 万元`}</small></li>)}</ul>
                <button type="button" onClick={() => { setActive(null); setPinned(null); }} aria-label="关闭项目清单">关闭</button>
            </div>}
            {data.located_count === 0 && <div className="viz-map-empty">暂无可识别的项目地点</div>}
        </div>
        <div className="viz-map-factory-list">{data.factories.map((factory) => <span key={factory.id}>{factory.name}</span>)}</div>
        <div className="viz-map-caption"><span><i className="viz-factory-key" />工厂标定 <i className="viz-project-key" />概略项目点</span><span>已定位 {data.located_count}/{data.total_count} · 展示 {data.representative_count ?? points.length} 点 · 未定位 {data.unlocated_count}</span></div>
    </section>;
}
