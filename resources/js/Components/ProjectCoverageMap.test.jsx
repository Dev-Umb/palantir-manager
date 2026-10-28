// @vitest-environment jsdom
import '@testing-library/jest-dom/vitest';
import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import ProjectCoverageMap, { arrangeMapPoints, zoomMapViewport, mapCanvasLayout } from './ProjectCoverageMap';
afterEach(() => { cleanup(); vi.unstubAllGlobals(); });
const point = { id: 'province-42', name: '湖北省', longitude: 114.3, latitude: 30.6, precision: '省级概略', overseas: false, projects: [{ id: 'p1', name: '武汉铁路项目', basis: '项目名称地名', factory: 'wenshui' }, { id: 'p2', name: '宜昌桥梁项目', basis: '项目名称地名', factory: null }] };
const data = { points: [point], factories: [{ id: 'wenshui', name: '山西文水 · 鑫源昌', longitude: 112.03, latitude: 37.44 }, { id: 'suqian', name: '江苏宿迁 · 晋武重工', longitude: 118.28, latitude: 33.96 }], located_count: 2, unlocated_count: 1, total_count: 3 };
describe('project coverage map', () => {
    it('shows all project names on hover, focus and tap while preserving financial-independent counts', () => {
        const { container } = render(<ProjectCoverageMap data={data} detailsUrl="/objects/project" />);
        const pointButton = screen.getByRole('button', { name: '湖北省，2 个项目' });
        fireEvent.mouseEnter(pointButton);
        expect(screen.getByRole('tooltip')).toHaveTextContent('武汉铁路项目');
        expect(screen.getByRole('tooltip')).toHaveTextContent('宜昌桥梁项目');
        expect(screen.getByRole('link', { name: '武汉铁路项目' })).toHaveAttribute('href', '/objects/project?record=p1&mode=detail');
        expect(container.querySelectorAll('.viz-map-flow')).toHaveLength(1);
        fireEvent.mouseLeave(pointButton);
        expect(screen.queryByRole('tooltip')).not.toBeInTheDocument();
        fireEvent.focus(pointButton);
        expect(screen.getByRole('tooltip')).toHaveTextContent('省级概略');
        fireEvent.keyDown(pointButton, { key: 'Escape' });
        expect(screen.queryByRole('tooltip')).not.toBeInTheDocument();
        fireEvent.click(pointButton);
        expect(screen.getByRole('tooltip')).toBeInTheDocument();
        expect(screen.getByText('已定位 2/3 · 展示 1 点 · 未定位 1')).toBeInTheDocument();
    });
    it('does not invent factory connections and preserves world points behind a world view', () => {
        const overseas = { ...point, id: 'country-nepal', name: '尼泊尔', longitude: 84.1, latitude: 28.4, overseas: true, projects: [{ ...point.projects[0], factory: null }] };
        const { container } = render(<ProjectCoverageMap data={{ ...data, points: [overseas] }} />);
        expect(screen.queryByRole('button', { name: /尼泊尔，/ })).not.toBeInTheDocument();
        fireEvent.click(screen.getByRole('button', { name: '世界' }));
        fireEvent.mouseEnter(screen.getByRole('button', { name: '尼泊尔，1 个项目' }));
        expect(screen.getByRole('tooltip')).toHaveTextContent('武汉铁路项目');
        expect(container.querySelectorAll('.viz-map-flow')).toHaveLength(0);
        fireEvent.click(screen.getByRole('button', { name: '全国' }));
        expect(screen.queryByRole('tooltip')).not.toBeInTheDocument();
    });
    it('renders factories but no invented projects in an empty scope', () => {
        render(<ProjectCoverageMap data={{ ...data, points: [], located_count: 0, total_count: 0, unlocated_count: 0 }} />);
        expect(screen.getByText('暂无可识别的项目地点')).toBeInTheDocument();
        expect(screen.getAllByText('山西文水 · 鑫源昌')).toHaveLength(2);
        expect(screen.queryByRole('button', { name: /个项目/ })).not.toBeInTheDocument();
    });
});

it('keeps actual coordinates instead of relocating overlapping project points', () => {
    const datong = { ...point, longitude: 113.295259, latitude: 40.09031 };
    const duplicates = Array.from({ length: 30 }, (_, i) => ({ ...datong, id: String(i) }));
    for (const view of ['china', 'world']) {
        const single = arrangeMapPoints([datong], view)[0].position;
        const crowded = arrangeMapPoints(duplicates, view);
        for (const item of crowded) expect(item.position).toEqual(single);
        if (view === 'world') {
            expect(single[0]).toBeCloseTo(606.590518, 5);
            expect(single[1]).toBeCloseTo(157.85268835, 5);
        }
    }
});

it('keeps static project and factory points and animates only coverage lines', () => {
    const { container } = render(<ProjectCoverageMap data={data} />);
    expect(container.querySelectorAll('.viz-map-pulse, .viz-map-factory-pulse')).toHaveLength(0);
    expect(container.querySelectorAll('.viz-map-point')).toHaveLength(1);
    expect(container.querySelector('.viz-map-point')).toHaveAttribute('r', '3.5');
    expect(container.querySelectorAll('.viz-map-factory')).toHaveLength(2);
    expect(container.querySelectorAll('.viz-map-flow')).toHaveLength(1);
    for (const line of container.querySelectorAll('.viz-map-route, .viz-map-flow')) {
        expect(line).toHaveAttribute('vector-effect', 'non-scaling-stroke');
    }
    fireEvent.mouseEnter(screen.getByRole('button', { name: '湖北省，2 个项目' }));
    expect(screen.getByRole('tooltip')).toHaveTextContent('武汉铁路项目');
});

it('retains location counts and removes the requested explanations in the compact map card', () => {
    render(<ProjectCoverageMap data={data} />);
    expect(screen.getByText('已定位 2/3 · 展示 1 点 · 未定位 1')).toBeVisible();
    expect(screen.queryByText(/仅合同／加工函已回项目/)).not.toBeInTheDocument();
    expect(screen.getByRole('img', { name: /全国项目覆盖地图/ })).toBeVisible();
});


it('zooms around the cursor with bounded scale and translation', () => {
    expect(zoomMapViewport({ scale: 1, x: 0, y: 0 }, 2, [100, 80])).toEqual({ scale: 2, x: -100, y: -80 });
    expect(zoomMapViewport({ scale: 2, x: -100, y: -80 }, 0, [100, 80])).toEqual({ scale: 1, x: 0, y: 0 });
    expect(zoomMapViewport({ scale: 1, x: 0, y: 0 }, 9, [760, 420])).toEqual({ scale: 4, x: -2280, y: -1260 });
});

it('moves hit targets with wheel zoom and preserves hover, drag and reset', () => {
    vi.stubGlobal('PointerEvent', MouseEvent);
    const { container } = render(<ProjectCoverageMap data={data} />);
    const stage = screen.getByLabelText('地图缩放区域');
    stage.getBoundingClientRect = () => ({ left: 0, top: 0, width: 760, height: 420 });
    const button = screen.getByRole('button', { name: '湖北省，2 个项目' });
    const originalLeft = button.style.left;
    fireEvent.wheel(stage, { deltaY: -350, clientX: 380, clientY: 210 });
    expect(Number(stage.dataset.zoom)).toBeGreaterThan(1);
    const zoom = Number(stage.dataset.zoom);
    for (const marker of container.querySelectorAll('.viz-map-point, .viz-map-factory')) {
        const inverse = Number(marker.parentElement.getAttribute('transform').match(/scale\(([^)]+)\)/)[1]);
        expect(zoom * inverse).toBeCloseTo(1);
    }
    expect(button.style.left).not.toBe(originalLeft);
    fireEvent.mouseEnter(button);
    expect(screen.getByRole('tooltip')).toHaveTextContent('武汉铁路项目');
    const beforeDrag = container.querySelector('.viz-map-viewport').getAttribute('transform');
    fireEvent.pointerDown(stage, { button: 0, clientX: 300, clientY: 200 });
    fireEvent.pointerMove(stage, { clientX: 350, clientY: 230 });
    fireEvent.pointerUp(stage, { clientX: 350, clientY: 230 });
    expect(container.querySelector('.viz-map-viewport').getAttribute('transform')).not.toBe(beforeDrag);
    expect(screen.queryByRole('tooltip')).not.toBeInTheDocument();
    fireEvent.doubleClick(stage);
    expect(stage).toHaveAttribute('data-zoom', '1');
    expect(button.style.left).toBe(originalLeft);
    fireEvent.wheel(stage, { deltaY: -10000, clientX: 380, clientY: 210 });
    fireEvent.wheel(stage, { deltaY: -10000, clientX: 380, clientY: 210 });
    expect(stage).toHaveAttribute('data-zoom', '4');
    fireEvent.keyDown(stage, { key: '0' });
    expect(stage).toHaveAttribute('data-zoom', '1');
});

it('resets zoom when switching views and leaves tooltip scrolling independent', () => {
    const overseas = { ...point, id: 'nepal', name: '尼泊尔', overseas: true };
    render(<ProjectCoverageMap data={{ ...data, points: [point, overseas] }} />);
    const stage = screen.getByLabelText('地图缩放区域');
    stage.getBoundingClientRect = () => ({ left: 0, top: 0, width: 760, height: 420 });
    fireEvent.wheel(stage, { deltaY: -100, clientX: 380, clientY: 210 });
    fireEvent.mouseEnter(screen.getByRole('button', { name: '湖北省，2 个项目' }));
    const zoom = stage.dataset.zoom;
    fireEvent.wheel(screen.getByRole('tooltip'), { deltaY: -100, clientX: 380, clientY: 210 });
    expect(stage.dataset.zoom).toBe(zoom);
    fireEvent.click(screen.getByRole('button', { name: '世界' }));
    expect(stage).toHaveAttribute('data-zoom', '1');
    expect(screen.queryByRole('tooltip')).not.toBeInTheDocument();
});

it('maps pointer positions into a full-card canvas without distorting geography', () => {
    expect(mapCanvasLayout(1000, 420)).toEqual({ scale: 1, offsetX: 120, offsetY: 0 });
    expect(mapCanvasLayout(380, 300)).toEqual({ scale: 0.5, offsetX: 0, offsetY: 45 });
});
