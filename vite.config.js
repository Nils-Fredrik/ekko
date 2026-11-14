import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';
import i18n from 'laravel-vue-i18n/vite';
import vue from '@vitejs/plugin-vue';

// Each agent worktree has its own Vite port (AGENTS.md): 5174, 5175, 5176.
const port = Number(process.env.VITE_PORT ?? 5174);

export default defineConfig({
    plugins: [
        laravel({
            input: 'resources/js/app.js',
            refresh: true,
        }),
        tailwindcss(),
        // Makes lang/*/*.php (Laravel translations) available in Vue.
        i18n(),
        vue({
            template: {
                transformAssetUrls: {
                    base: null,
                    includeAbsolute: false,
                },
            },
        }),
    ],
    server: {
        host: 'localhost',
        port,
        strictPort: true,
    },
});
