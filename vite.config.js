import { defineConfig, loadEnv } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';
import i18n from 'laravel-vue-i18n/vite';
import vue from '@vitejs/plugin-vue';

export default defineConfig(({ mode }) => {
    // Each agent worktree has its own Vite port in .env (AGENTS.md): 5174, 5175, 5176.
    // loadEnv reads .env, so the port is right without exporting it in the shell.
    const env = loadEnv(mode, process.cwd(), '');
    const port = Number(env.VITE_PORT || 5174);

    return {
        plugins: [
            laravel({
                input: ['resources/js/app.js', 'resources/js/shared-report.js', 'resources/js/shared-bootstrap.js'],
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
    };
});
