import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import vue from '@vitejs/plugin-vue';

export default defineConfig({
    plugins: [
        laravel({
            input: 'resources/js/app.js',
            refresh: true,
        }),
        vue({
            template: {
                transformAssetUrls: {
                    base: null,
                    includeAbsolute: false,
                },
            },
        }),
    ],
    build: {
        // Лимит чанков увеличен — Dashboard крупный, но дробится на под-чанки.
        chunkSizeWarningLimit: 1500,
        // Без полифилла modulePreload браузер не делает <link rel="preload"> для ВСЕХ
        // CSS-чанков (включая те, что нужны другой странице) — пропадает шум в консоли
        // «preloaded but not used within a few seconds».
        modulePreload: { polyfill: false },
        rollupOptions: {
            output: {
                // Разбиваем тяжёлые сторонние пакеты в отдельные чанки.
                // Браузер кэширует их между навигациями + грузит параллельно.
                manualChunks(id) {
                    if (!id.includes('node_modules')) return;
                    if (id.includes('ag-grid')) return 'ag-grid';
                    if (id.includes('hyperformula')) return 'hyperformula';
                    if (id.includes('exceljs') || id.includes('file-saver')) return 'exceljs';
                    if (id.includes('@inertiajs')) return 'inertia';
                    if (id.includes('lodash')) return 'lodash';
                },
            },
        },
    },
});
