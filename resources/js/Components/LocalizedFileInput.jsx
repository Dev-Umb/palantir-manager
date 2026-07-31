import { useEffect, useRef } from 'react';

export default function LocalizedFileInput({
    accept,
    autoFocus = false,
    emptyLabel = '未选择文件',
    file = null,
    name,
    onChange,
    required = false,
    selectLabel = '选择文件',
}) {
    const inputRef = useRef(null);
    const selectedFilename = typeof File !== 'undefined' && file instanceof File ? file.name : '';

    useEffect(() => {
        if (! file && inputRef.current) {
            inputRef.current.value = '';
        }
    }, [file]);

    function clearFile() {
        if (inputRef.current) {
            inputRef.current.value = '';
        }
        onChange(null);
    }

    return (
        <div className="localized-file-input">
            <input
                ref={inputRef}
                accept={accept}
                aria-label={selectLabel}
                autoFocus={autoFocus}
                className="sr-only"
                name={name}
                required={required && !file}
                type="file"
                onChange={(event) => onChange(event.target.files?.[0] || null)}
            />
            <div className="localized-file-actions">
                <button type="button" onClick={() => inputRef.current?.click()}>{selectLabel}</button>
                {selectedFilename && (
                    <button className="ghost-button" type="button" onClick={clearFile}>清除</button>
                )}
            </div>
            <span aria-live="polite" className={selectedFilename ? 'localized-file-name' : 'localized-file-name empty'}>
                {selectedFilename || emptyLabel}
            </span>
        </div>
    );
}
