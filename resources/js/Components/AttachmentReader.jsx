import { Download, RotateCcw, ZoomIn, ZoomOut } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';


function previewError(message, denied = false) {
    return Object.assign(new Error(message), { previewMessage: message, denied });
}

async function checkedFetch(url, signal) {
    const response = await fetch(url, { signal, credentials: 'same-origin', headers: { Accept: 'application/json, application/pdf, image/jpeg, image/png', 'X-Requested-With': 'XMLHttpRequest' } });
    if (response.status === 401 || response.status === 403 || response.redirected) {
        throw previewError('登录已失效或没有查看权限，请重新登录后再试。', true);
    }
    if (!response.ok) throw previewError(response.status === 404 ? '附件不存在或当前文件不支持预览。' : '附件读取失败，请稍后重试。');
    return response;
}

export default function AttachmentReader({ file }) {
    const [attempt, setAttempt] = useState(0);
    const [state, setState] = useState({ loading: true });
    useEffect(() => {
        const controller = new AbortController();
        let active = true;
        let objectUrl;
        let timedOut = false;
        const timeout = setTimeout(() => { timedOut = true; controller.abort(); if (active) setState({ error: '读取超时，请检查网络后重试。' }); }, 30000);
        setState({ loading: true });
        (async () => {
            const info = await (await checkedFetch(file.info_url, controller.signal)).json();
            if (!['application/pdf', 'image/jpeg', 'image/png'].includes(info.mime_type)) throw previewError('此文件暂不支持在线预览。');
            const response = await checkedFetch(file.content_url, controller.signal);
            if (response.headers.get('content-type')?.split(';')[0] !== info.mime_type) throw previewError('附件内容已改变，请关闭后重新打开。');
            const blob = await response.blob();
            if (info.mime_type === 'application/pdf') {
                const data = new Uint8Array(await blob.arrayBuffer());
                const { default: Pdf } = await import('./PdfAttachment');
                if (active && !timedOut) setState({ data, Pdf });
            } else if (active && !timedOut) {
                objectUrl = URL.createObjectURL(blob);
                setState({ image: objectUrl });
            }
        })().catch((error) => {
            if (active) setState({ error: timedOut ? '读取超时，请检查网络后重试。' : error.previewMessage || '预览加载失败，请检查网络后重试，或下载原文件。', denied: !!error.denied });
        }).finally(() => clearTimeout(timeout));
        return () => { active = false; controller.abort(); clearTimeout(timeout); if (objectUrl) URL.revokeObjectURL(objectUrl); };
    }, [file.info_url, file.content_url, attempt]);

    const Pdf = state.Pdf;
    return <div className="attachment-reader">
        <div className="attachment-reader-actions"><span>在线预览</span>{!state.denied && <a href={file.download_url} target="_blank" rel="noreferrer"><Download size={16} /> 下载原文件</a>}</div>
        {state.loading && <p className="attachment-status" role="status">正在读取附件…</p>}
        {state.error && <div className="attachment-status" role="alert"><p>{state.error}</p><button type="button" onClick={() => setAttempt((value) => value + 1)}>重新加载</button></div>}
        {state.data && Pdf && <Pdf data={state.data} onError={(error) => setState({ error })} />}
        {state.image && <ImageAttachment src={state.image} name={file.name} onError={() => setState({ error: '图片无法显示，可以重试或下载原文件。' })} />}
    </div>;
}

export function ZoomControls({ zoom, setZoom }) {
    return <div className="attachment-zoom-controls">
        <button type="button" aria-label="缩小" disabled={zoom <= 0.5} onClick={() => setZoom(Math.max(0.5, zoom - 0.25))}><ZoomOut size={17} /></button>
        <span>{Math.round(zoom * 100)}%</span>
        <button type="button" aria-label="放大" disabled={zoom >= 3} onClick={() => setZoom(Math.min(3, zoom + 0.25))}><ZoomIn size={17} /></button>
        <button type="button" aria-label="适合宽度" onClick={() => setZoom(1)}><RotateCcw size={16} /><span>适宽</span></button>
    </div>;
}

export function usePreviewWidth(ref) {
    const [width, setWidth] = useState(640);
    useEffect(() => {
        const measure = () => setWidth(Math.max(160, (ref.current?.clientWidth || 680) - 32));
        measure();
        if (typeof ResizeObserver === 'undefined') return undefined;
        const observer = new ResizeObserver(measure);
        if (ref.current) observer.observe(ref.current);
        return () => observer.disconnect();
    }, [ref]);
    return width;
}

function ImageAttachment({ src, name, onError }) {
    const [zoom, setZoom] = useState(1);
    const [naturalWidth, setNaturalWidth] = useState(Infinity);
    const [ready, setReady] = useState(false);
    const viewport = useRef(null);
    const drag = useRef(null);
    const width = usePreviewWidth(viewport);
    return <>
        <div className="attachment-document-toolbar"><ZoomControls zoom={zoom} setZoom={setZoom} /></div>
        {!ready && <p role="status" className="attachment-image-loading">正在显示图片…</p>}
        <div className="attachment-document-viewport image-viewport" ref={viewport} style={{ touchAction: zoom > 1 ? 'none' : 'auto' }} onPointerDown={(event) => {
            if (zoom <= 1) return;
            drag.current = { x: event.clientX, y: event.clientY, left: viewport.current.scrollLeft, top: viewport.current.scrollTop };
            viewport.current.setPointerCapture(event.pointerId);
        }} onPointerMove={(event) => {
            if (!drag.current) return;
            viewport.current.scrollLeft = drag.current.left + drag.current.x - event.clientX;
            viewport.current.scrollTop = drag.current.top + drag.current.y - event.clientY;
        }} onPointerUp={() => { drag.current = null; }} onPointerCancel={() => { drag.current = null; }}>
            <img src={src} alt={name} draggable={false} style={{ width: Math.min(width, naturalWidth) * zoom }} onLoad={(event) => { setReady(true); setNaturalWidth(event.currentTarget.naturalWidth); }} onError={onError} />
        </div>
    </>;
}
