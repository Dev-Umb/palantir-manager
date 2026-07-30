import '../css/app.css';
import { createInertiaApp } from '@inertiajs/react';
import { createRoot } from 'react-dom/client';

createInertiaApp({
    title: (title) => title ? `${title} - 鑫源昌智造中枢` : '鑫源昌智造中枢',
    resolve: (name) => {
        const pages = import.meta.glob(['./Pages/**/*.jsx', '!./Pages/**/*.test.jsx']);
        return pages[`./Pages/${name}.jsx`]();
    },
    setup({ el, App, props }) {
        createRoot(el).render(<App {...props} />);
    },
});
