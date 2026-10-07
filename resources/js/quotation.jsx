import React from 'react';
import { createRoot } from 'react-dom/client';
import { createInertiaApp, router } from '@inertiajs/react';
import Quotations from './Pages/Quotations/Index';
import './Pages/Quotations/quotation.css';
router.on('before', (event) => {
    const visit = event.detail.visit;
    if (visit.method === 'get' && visit.url.pathname !== '/quotations') {
        event.preventDefault();
        window.location.assign(visit.url.href);
    }
});
createInertiaApp({
    resolve: (name) => {
        if (name !== 'Quotations/Index') { window.location.reload(); throw new Error('正在返回主平台'); }
        return Quotations;
    },
    setup({ el, App, props }) { createRoot(el).render(<App {...props} />); },
});
