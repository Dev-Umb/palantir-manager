import { defineConfig } from 'vitest/config';

export default defineConfig({
    test: {
        include: [
            'resources/js/**/*.test.{js,jsx}',
            'scripts/**/*.test.mjs',
        ],
    },
});
