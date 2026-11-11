'use strict';
window.MaterialProjectStore = (() => {
    const config = window.SteelGirderConfig;
    const revisions = new Map(), materials = new Map(), buffers = new Map();
    async function request(path = '', options = {}, raw = false) {
        const headers = { Accept: 'application/json', 'X-CSRF-TOKEN': config.csrf, ...options.headers };
        if (options.body && !(options.body instanceof FormData)) { headers['Content-Type'] = 'application/json'; options.body = JSON.stringify(options.body); }
        const response = await fetch(config.base + path, { ...options, headers, credentials: 'same-origin' });
        if (!response.ok || response.redirected) {
            const error = await response.json().catch(() => ({}));
            throw Error(Object.values(error.errors || {}).flat().join('；') || error.message || (response.status === 401 || response.redirected ? '登录已失效，请重新登录。' : '请求失败，请重试。'));
        }
        return raw ? response : response.json();
    }
    function remember(meta) { revisions.set(meta.id, meta.revision); return meta; }
    function expected(id) { return { expected_revision: revisions.get(id) }; }
    return {
        list: async () => (await request()).map(remember),
        get: async id => { const record = await request('/' + id); remember(record.meta); materials.set(id, record.data?.material_id); buffers.delete(id); return record; },
        create: async meta => remember(await request('', { method: 'POST', body: { id: meta.id, name: meta.name } })),
        save: async (meta, payload) => {
            if (!payload) return meta;
            const data = new FormData();
            data.set('expected_revision', revisions.get(meta.id));
            data.set('unit', payload.unit);
            if (payload.buffer && buffers.get(meta.id) !== payload.buffer) data.set('file', new File([payload.buffer], payload.filename));
            if (materials.get(meta.id)) data.set('material_id', materials.get(meta.id));
            // JSON reviews travel as a single string and are decoded by the request before validation.
            data.set('reviews_json', JSON.stringify(payload.reviews));
            const saved = await request('/' + meta.id + '/material', { method: 'POST', body: data });
            remember(saved.meta); materials.set(meta.id, saved.material_id); buffers.set(meta.id, payload.buffer);
            return saved.meta;
        },
        listDrawings: id => request('/' + id + '/drawings'),
        addDrawings: async (id, items) => {
            const result = { added: 0, skipped: 0 };
            for (const item of items) {
                const data = new FormData(); data.set('expected_revision', revisions.get(id)); data.set('file', item.blob, item.name);
                const saved = await request('/' + id + '/drawings', { method: 'POST', body: data });
                remember(saved.meta); result.added += saved.added; result.skipped += saved.skipped;
            }
            return result;
        },
        removeDrawing: async (id, drawing) => remember(await request('/' + id + '/drawings/' + drawing, { method: 'DELETE', body: expected(id) })),
        remove: async id => request('/' + id, { method: 'DELETE', body: expected(id) }),
        original: async id => (await request('/' + id + '/original?material_id=' + encodeURIComponent(materials.get(id)), {}, true)).arrayBuffer(),
        downloadDrawings: (id, drawing) => request('/' + id + (drawing ? '/drawings/' + drawing : '/drawings.zip'), {}, true),
    };
})();
