import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js'],
            refresh: true
        })
    ],
    build: {
        rollupOptions: {
            output: {
                manualChunks: {
                    chart: ['chart.js/auto', 'chartjs-plugin-datalabels'],
                    tiptap: [
                        '@tiptap/core',
                        '@tiptap/starter-kit',
                        '@tiptap/extension-image',
                        '@tiptap/extension-link',
                        '@tiptap/extension-placeholder'
                    ]
                }
            }
        }
    }
});
