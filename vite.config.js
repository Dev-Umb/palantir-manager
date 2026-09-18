import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';
import react from '@vitejs/plugin-react';
import inertia from '@inertiajs/vite';
import { pdfAssets } from './scripts/pdf-assets.mjs';

export default defineConfig({
    plugins: [
        pdfAssets(),
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.jsx'],
            refresh: true,
        }),
        inertia(),
        react(),
        tailwindcss(),
    ],
    build: {
        rolldownOptions: {
            output: {
                assetFileNames: (asset) => asset.names?.includes('pdf.worker.min.mjs')
                    ? 'assets/[name]-[hash].js'
                    : 'assets/[name]-[hash][extname]',
            },
        },
    },
    server: {
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
