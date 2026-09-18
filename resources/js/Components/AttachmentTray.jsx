import { Download, Eye, File, FileImage, FileText, Files, X, ArrowLeft } from 'lucide-react';
import { useCallback, useEffect, useId, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import { useDialogFocus } from './useDialogFocus';
import { beginAttachmentPreview } from '../attachmentPreviewHistory';

import AttachmentReader from './AttachmentReader';

function FileIcon({ kind }) {
    const Icon = kind === 'pdf' ? FileText : kind === 'image' ? FileImage : File;
    return <span className={`attachment-file-icon ${kind || 'file'}`}><Icon size={22} aria-hidden="true" /></span>;
}

function Cards({ files, onPreview }) {
    return <span className="attachment-cards" role="list">
        {files.map((file) => <span className="attachment-card" role="listitem" key={file.info_url}>
            <button type="button" className="attachment-card-open" onClick={() => onPreview(file)} aria-label={`查看 ${file.name}`} title={file.name}>
                <FileIcon kind={file.kind} /><span className="attachment-file-name">{file.name}</span><Eye className="attachment-eye" size={17} aria-hidden="true" />
            </button>
            <a className="attachment-card-download" href={file.download_url} target="_blank" rel="noreferrer" aria-label={`下载 ${file.name}`} title="下载"><Download size={17} /></a>
        </span>)}
    </span>;
}

export default function AttachmentTray({ files = [], label = '附件', compact = false }) {
    const [opened, setOpened] = useState(false);
    const [selected, setSelected] = useState(null);
    const close = useCallback(() => setOpened(false), []);
    if (!files.length) return null;
    const open = (file = null) => { setSelected(file); setOpened(true); };

    return <span className={`attachment-tray ${compact ? 'compact' : ''}`} onClick={(event) => event.stopPropagation()}>
        {compact ? <button type="button" className="attachment-tray-trigger" onClick={() => open(files.length === 1 ? files[0] : null)} aria-label={`查看${label}，共 ${files.length} 个`}>
            {files.length === 1 ? <FileIcon kind={files[0].kind} /> : <Files size={17} aria-hidden="true" />}
            <span>{files.length === 1 ? files[0].name : `${files.length} 个附件`}</span><Eye size={15} aria-hidden="true" />
        </button> : <Cards files={files} onPreview={open} />}
        {opened && <AttachmentDialog title={selected?.name || label} reading={!!selected} onClose={close}>
            {selected ? <>
                {files.length > 1 && <button type="button" className="attachment-back" onClick={() => setSelected(null)}><ArrowLeft size={16} /> 返回附件列表（{files.length}）</button>}
                <AttachmentReader key={selected.info_url} file={selected} />
            </> : <div className="attachment-tray-body"><p className="attachment-tray-hint">共 {files.length} 个文件，点开即可查看</p><Cards files={files} onPreview={setSelected} /></div>}
        </AttachmentDialog>}
    </span>;
}

function AttachmentDialog({ title, reading, children, onClose }) {
    const ref = useRef(null);
    const titleId = useId();
    const requestClose = useRef(onClose);
    const closeRef = useRef(onClose);
    closeRef.current = onClose;
    useDialogFocus(true, ref);
    useEffect(() => {
        const previousOverflow = document.body.style.overflow;
        document.body.style.overflow = 'hidden';
        const history = beginAttachmentPreview(() => closeRef.current());
        requestClose.current = history.close;
        return () => {
            document.body.style.overflow = previousOverflow;
            history.dispose();
        };
    }, [titleId]);

    return createPortal(<div className="attachment-preview-backdrop" onClick={(event) => event.stopPropagation()} onKeyDown={(event) => {
        event.stopPropagation();
        if (event.key === 'Escape') { event.preventDefault(); requestClose.current(); }
    }}>
        <section className={`attachment-preview-panel${reading ? '' : ' attachment-list-panel'}`} ref={ref} role="dialog" aria-modal="true" aria-labelledby={titleId} tabIndex={-1}>
            <header className="attachment-preview-head"><h2 id={titleId} title={title}>{title}</h2><button type="button" onClick={() => requestClose.current()} aria-label="关闭附件预览"><X size={21} /></button></header>
            {children}
        </section>
    </div>, document.body);
}
