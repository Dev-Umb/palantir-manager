import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import react from '@vitejs/plugin-react';
export default defineConfig({
    resolve: { alias: process.env.QUOTATION_PLATFORM_LAYOUT ? { '../../Components/Layout': process.env.QUOTATION_PLATFORM_LAYOUT } : {} },
    plugins: [laravel({ input: ['resources/js/quotation.jsx'], buildDirectory: 'quotation-build', refresh: false }), react()],
    build: { target: ['chrome86', 'edge86', 'firefox114', 'safari16.2', 'ios16.2'], outDir: 'public/quotation-build', emptyOutDir: true },
});
