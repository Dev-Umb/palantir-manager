import { ChevronLeft, ChevronRight } from 'lucide-react';
import { useEffect, useLayoutEffect, useRef, useState } from 'react';
import { getDocument, GlobalWorkerOptions, version } from 'pdfjs-dist/legacy/build/pdf.mjs';
import workerUrl from 'pdfjs-dist/legacy/build/pdf.worker.min.mjs?url';
import { usePreviewWidth, ZoomControls } from './AttachmentReader';

GlobalWorkerOptions.workerSrc = workerUrl;

export default function PdfAttachment({ data, onError }) {
    const [document, setDocument] = useState(null);
    const [page, setPage] = useState(1);
    const [zoom, setZoom] = useState(1);
    const [rendering, setRendering] = useState(true);
    const canvas = useRef(null);
    const viewport = useRef(null);
    const errorRef = useRef(onError);
    errorRef.current = onError;
    const width = usePreviewWidth(viewport);
    useEffect(() => {
        let active = true;
        const assets = `${import.meta.env.BASE_URL}pdfjs/${version}/`;
        const task = getDocument({ data: data.slice(), cMapUrl: `${assets}cmaps/`, cMapPacked: true,
            standardFontDataUrl: `${assets}standard_fonts/`, wasmUrl: `${assets}wasm/`,
            iccUrl: `${assets}iccs/`, enableXfa: false, isEvalSupported: false });
        const timeout = setTimeout(() => { if (active) errorRef.current('PDF 处理超时，请重试或下载原文件。'); }, 30000);
        task.promise.then((pdf) => { if (active) setDocument(pdf); }).catch((error) => {
            if (active) errorRef.current(error.name === 'PasswordException' ? '此 PDF 有密码保护，请下载后使用密码打开。' : 'PDF 无法显示，可以重试或下载原文件。');
        }).finally(() => clearTimeout(timeout));
        return () => { active = false; clearTimeout(timeout); void task.destroy(); };
    }, [data]);
    useLayoutEffect(() => {
        if (!document) return undefined;
        let active = true;
        let task;
        let pdfPage;
        setRendering(true);
        document.getPage(page).then((loadedPage) => {
            if (!active) return;
            pdfPage = loadedPage;
            const base = pdfPage.getViewport({ scale: 1 });
            const view = pdfPage.getViewport({ scale: width / base.width * zoom });
            const density = Math.min(window.devicePixelRatio || 1, 2, Math.sqrt(16000000 / (view.width * view.height)));
            const element = canvas.current;
            element.width = Math.floor(view.width * density);
            element.height = Math.floor(view.height * density);
            element.style.width = `${view.width}px`;
            element.style.height = `${view.height}px`;
            task = pdfPage.render({ canvasContext: element.getContext('2d'), viewport: view, transform: [density, 0, 0, density, 0, 0] });
            return task.promise;
        }).then(() => { if (active) { canvas.current.dataset.renderedPage = String(page); setRendering(false); } }).catch((error) => {
            if (active && error.name !== 'RenderingCancelledException') errorRef.current('这一页无法显示，可以重试或下载原文件。');
        });
        return () => { active = false; task?.cancel(); if (task) void task.promise.catch(() => {}).then(() => pdfPage?.cleanup()); };
    }, [document, page, width, zoom]);
    return <>
        <div className="attachment-document-toolbar">
            <div className="attachment-page-controls">
                <button type="button" aria-label="上一页" disabled={!document || page <= 1} onClick={() => setPage((value) => value - 1)}><ChevronLeft size={18} /></button>
                <span aria-live="polite">{page} / {document?.numPages || '—'}</span>
                <button type="button" aria-label="下一页" disabled={!document || page >= document.numPages} onClick={() => setPage((value) => value + 1)}><ChevronRight size={18} /></button>
            </div>
            <ZoomControls zoom={zoom} setZoom={setZoom} />
        </div>
        <div className="attachment-document-viewport" ref={viewport}>
            {rendering && <p role="status" className="attachment-rendering">正在显示 PDF…</p>}
            <canvas ref={canvas} aria-label={`PDF 第 ${page} 页`} style={{ visibility: rendering ? 'hidden' : 'visible' }} />
        </div>
    </>;
}
