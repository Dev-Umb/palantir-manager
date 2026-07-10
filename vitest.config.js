import { defineConfig } from 'vitest/config';

export default defineConfig({
    test: {
        include: ['resources/js/Pages/Ai/**/*.test.{js,jsx}'],
    },
});
