import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'
import { VitePWA } from 'vite-plugin-pwa'

// Ziel des Symfony-Backends im Dev-Modus. Per Umgebungsvariable ueberschreibbar:
//   VITE_API_TARGET=http://127.0.0.1:8000 npm run dev
const API_TARGET = process.env.VITE_API_TARGET || 'http://127.0.0.1:8000'

// command === 'build'  -> relative Pfade ('./'), damit die App unter JEDEM
//                         Unterordner laeuft (z.B. /mobile/) ohne Neubau.
// command === 'serve'  -> fester Basis-Pfad '/mobile/' fuer den Dev-Server.
export default defineConfig(({ command }) => ({
  base: command === 'build' ? './' : '/mobile/',
  server: {
    proxy: {
      '/api': {
        target: API_TARGET,
        changeOrigin: false,
        cookieDomainRewrite: '',
      },
    },
  },
  plugins: [
    vue(),
    VitePWA({
      registerType: 'autoUpdate',
      includeAssets: ['icons/favicon.ico'],
      manifest: {
        name: 'FlyRes – Reservierung',
        short_name: 'FlyRes',
        description: 'Flugzeug- und Fluglehrer-Reservierung',
        lang: 'de',
        // relativ -> funktioniert unter jedem Unterordner
        start_url: '.',
        scope: '.',
        display: 'standalone',
        background_color: '#f2f2f7',
        theme_color: '#0a84ff',
        icons: [
          { src: 'icons/icon-192.png', sizes: '192x192', type: 'image/png' },
          { src: 'icons/icon-512.png', sizes: '512x512', type: 'image/png' },
          { src: 'icons/icon-512-maskable.png', sizes: '512x512', type: 'image/png', purpose: 'maskable' },
        ],
      },
      workbox: {
        // API nie cachen (Pfad kann je nach Unterordner variieren -> per Suffix matchen)
        runtimeCaching: [
          {
            urlPattern: ({ url }) => url.pathname.includes('/api/'),
            handler: 'NetworkOnly',
          },
        ],
      },
    }),
  ],
  build: {
    outDir: '../public/mobile',
    emptyOutDir: true,
  },
}))
