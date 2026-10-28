export async function request(url, method = 'GET', body) {
    const multipart = body instanceof FormData;
    const response = await fetch(url, {
        method, credentials: 'same-origin',
        headers: { Accept: 'application/json', ...(multipart ? {} : { 'Content-Type': 'application/json' }),
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '' },
        ...(body === undefined ? {} : { body: multipart ? body : JSON.stringify(body) }),
    });
    if ([401, 419].includes(response.status) || response.redirected) {
        throw new Error('登录已失效，请重新登录后重试；当前输入和草稿已保留。');
    }
    if (!response.ok) {
        const data = await response.json().catch(() => ({}));
        const error = new Error(Object.values(data.errors || {}).flat().join('；') || data.message || '请求失败，请重试。');
        error.code = data.code;
        error.status = response.status;
        error.fieldPath = data.fieldPath;
        if (data.fieldPath) error.message += `（${data.fieldPath}）`;
        throw error;
    }
    return response;
}

export function requestId() {
    const bytes = new Uint8Array(16);
    crypto.getRandomValues(bytes);
    bytes[6] = (bytes[6] & 15) | 64;
    bytes[8] = (bytes[8] & 63) | 128;
    const hex = [...bytes].map((b) => b.toString(16).padStart(2, '0')).join('');
    return `${hex.slice(0, 8)}-${hex.slice(8, 12)}-${hex.slice(12, 16)}-${hex.slice(16, 20)}-${hex.slice(20)}`;
}

export async function saveDownload({ filename, generate, report, host = window }) {
    let stage = '选择保存位置';
    let handle;
    const cancel = () => { report('已取消导出，未保存文件。'); return 'cancelled'; };
    try {
        report('请选择保存文件夹和文件名。');
        if (typeof host.showSaveFilePicker === 'function') {
            try { handle = await host.showSaveFilePicker({ suggestedName: filename }); }
            catch (error) {
                if (error.name === 'AbortError') return cancel();
                if (!['SecurityError', 'NotSupportedError'].includes(error.name)) throw error;
            }
        }
        if (!handle && !host.confirm('当前浏览器不支持网页直接选择保存位置。请在浏览器设置中开启“每次下载前询问保存位置”。继续发起下载？')) return cancel();
        filename = handle?.name || filename;
        stage = '生成文件'; report(`正在生成：${filename}…`);
        const response = await generate();
        const blob = await response.blob();
        if (handle) {
            stage = '写入文件'; report(`正在保存：${filename}…`);
            const stream = await handle.createWritable();
            try { await stream.write(blob); await stream.close(); }
            catch (error) { try { await stream.abort(); } catch {} throw error; }
            report(`导出成功：${filename}；已保存到所选文件夹。`);
            return 'saved';
        }
        stage = '发起下载';
        const url = host.URL.createObjectURL(blob);
        const anchor = host.document.createElement('a');
        anchor.href = url; anchor.download = filename;
        host.document.body.append(anchor); anchor.click(); anchor.remove();
        host.setTimeout(() => host.URL.revokeObjectURL(url), 1000);
        report(`已发起下载：${filename}；请在浏览器下载记录中确认是否保存成功。`);
        return 'downloaded';
    } catch (error) {
        const suggestions = { NotAllowedError: '请允许文件写入或换有权限的目录。', SecurityError: '请检查浏览器保存权限。',
            QuotaExceededError: '空间不足，请清理或换磁盘。', NotFoundError: '目录已不存在，请重新选择。',
            NoModificationAllowedError: '文件只读或被占用，请关闭文件或换文件名。' };
        report(`导出失败（${stage}）：${error.message} ${suggestions[error.name] || '请处理原因后重试。'}`);
        throw error;
    }
}
