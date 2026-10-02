import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';

export default defineConfig({
    plugins: [
        laravel({
            input: [
                'resources/css/app.css',
                'resources/js/app.js',
                'resources/css/workspace-v2.css',
                'resources/js/workspace-v2/app.js',
            ],
            refresh: true,
        }),
    ],
});
