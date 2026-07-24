import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';
import { resolve } from 'node:path';

/**
 * FitnessClub front-end build (D10).
 *
 * One Vite workspace, three entry points — user / trainer / admin. Each entry
 * emits its own bundle; code shared via `src/shared/` becomes a shared chunk, so
 * a member's browser never receives admin-specific code (hard isolation) while
 * React and the design system are downloaded once.
 *
 * WordPress serves the HTML shell (D9), not Vite, so:
 *   - build `manifest: true`  → the PHP shell reads public/ui/.vite/manifest.json
 *     and emits the hashed <script type="module"> + <link> for the resolved role.
 *   - dev: the shell detects the running Vite dev server and loads the entry +
 *     @vite/client from it for HMR (the standard Vite-for-WordPress pattern).
 */
export default defineConfig(({ command }) => {
  const isProd = command === 'build';

  return {
    plugins: [react()],

    // In prod the assets live under the plugin's public dir; the shell derives
    // this from plugin_dir_url() and must agree with it. On this dev install the
    // plugin sits at the standard path. (Finalised alongside the shell in 0.4.)
    base: isProd ? '/wp-content/plugins/fitnessclub/public/ui/' : '/',

    resolve: {
      alias: { '@shared': resolve(__dirname, 'src/shared') },
    },

    build: {
      manifest: true,
      outDir: resolve(__dirname, '../public/ui'),
      emptyOutDir: true,
      rollupOptions: {
        input: {
          user: resolve(__dirname, 'src/user/main.tsx'),
          trainer: resolve(__dirname, 'src/trainer/main.tsx'),
          admin: resolve(__dirname, 'src/admin/main.tsx'),
        },
      },
    },

    server: {
      // Allow the WordPress origin to load modules from the dev server.
      cors: true,
      host: 'localhost',
      port: 5173,
      strictPort: true,
    },
  };
});
