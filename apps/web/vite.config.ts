/// <reference types="vitest/config" />
import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';
import { fileURLToPath, URL } from 'node:url';

// EruoFood AI — Vite configuration (build + dev server + Vitest).
export default defineConfig({
  plugins: [react()],
  resolve: {
    // Path aliases mirror tsconfig paths and the feature-sliced structure.
    alias: {
      '@': fileURLToPath(new URL('./src', import.meta.url)),
      '@app': fileURLToPath(new URL('./src/app', import.meta.url)),
      '@features': fileURLToPath(new URL('./src/features', import.meta.url)),
      '@shared': fileURLToPath(new URL('./src/shared', import.meta.url)),
      '@lib': fileURLToPath(new URL('./src/lib', import.meta.url)),
      '@config': fileURLToPath(new URL('./src/config', import.meta.url)),
    },
  },
  server: {
    host: true,
    port: 5173,
    strictPort: true,
  },
  build: {
    outDir: 'dist',
    sourcemap: true,
    target: 'es2022',
  },
  test: {
    globals: true,
    environment: 'jsdom',
    setupFiles: ['./src/test/setup.ts'],
    css: false,
    coverage: {
      provider: 'v8',
      reporter: ['text', 'lcov'],
      include: ['src/**/*.{ts,tsx}'],
      exclude: ['src/**/*.test.{ts,tsx}', 'src/test/**', 'src/main.tsx'],

      /*
       * The coverage floor (M48, F-05).
       *
       * `npm run test:coverage` is what the required `Lint · Typecheck · Test
       * · Build` job runs, and until now it measured coverage, printed it, and
       * threw the number away. Coverage could have fallen to zero without the
       * gate noticing — which is how one shared component ended up shipping a
       * live defect with no test rendering it at all.
       *
       * These numbers are the measured baseline of this branch rounded down,
       * not an aspiration: 29.87 / 78.83 / 34.83 / 29.87 across three
       * consecutive identical runs. Setting a target the repository cannot
       * currently meet would block every pull request on work nobody has done
       * yet, which is the failure mode this milestone spent its time removing
       * elsewhere. The point of a ratchet is that it only turns one way; raise
       * these as tests land.
       */
      thresholds: {
        statements: 29,
        branches: 77,
        functions: 34,
        lines: 29,
      },
    },
  },
});
