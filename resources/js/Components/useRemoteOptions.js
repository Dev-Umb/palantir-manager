import { useEffect, useMemo, useState } from 'react';

const SEARCH_DELAY_MS = 200;

export function useRemoteOptions({ searchUrl, query, context = {}, initialItems = [], enabled = true }) {
    const initialKey = useMemo(() => JSON.stringify(initialItems), [initialItems]);
    const contextKey = useMemo(() => JSON.stringify(context), [context]);
    const [items, setItems] = useState(initialItems);
    const [loading, setLoading] = useState(false);
    const [failed, setFailed] = useState(false);

    useEffect(() => {
        setItems(initialItems);
    }, [initialKey]); // eslint-disable-line react-hooks/exhaustive-deps

    useEffect(() => {
        if (!searchUrl || !enabled) {
            setLoading(false);
            setFailed(false);
            return undefined;
        }

        const controller = new AbortController();
        const timer = window.setTimeout(async () => {
            setLoading(true);
            setFailed(false);

            try {
                const response = await fetch(buildRemoteOptionsUrl(searchUrl, query, context), {
                    headers: {
                        Accept: 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    signal: controller.signal,
                });
                if (!response.ok) throw new Error('关联选项加载失败');

                const data = await response.json();
                if (!controller.signal.aborted) {
                    setItems(Array.isArray(data.items) ? data.items : []);
                }
            } catch (error) {
                if (error?.name !== 'AbortError' && !controller.signal.aborted) {
                    setFailed(true);
                }
            } finally {
                if (!controller.signal.aborted) setLoading(false);
            }
        }, SEARCH_DELAY_MS);

        return () => {
            window.clearTimeout(timer);
            controller.abort();
        };
    }, [contextKey, enabled, query, searchUrl]); // eslint-disable-line react-hooks/exhaustive-deps

    return { items, loading, failed };
}

export function buildRemoteOptionsUrl(searchUrl, query, context = {}) {
    const base = typeof window === 'undefined' ? 'http://localhost' : window.location.origin;
    const url = new URL(searchUrl, base);
    url.searchParams.set('q', String(query || '').trim());

    Object.entries(context || {}).forEach(([key, value]) => {
        if (value === null || value === undefined || value === '') return;
        url.searchParams.set(`context[${key}]`, Array.isArray(value) ? value.join(',') : String(value));
    });

    return url.origin === base ? `${url.pathname}${url.search}` : url.toString();
}
