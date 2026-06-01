import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'
import { resolve } from 'path'

export default defineConfig({
  plugins: [vue()],
  build: {
    outDir: resolve(__dirname, 'assets/dist'),
    emptyOutDir: false,
    rollupOptions: {
      input: resolve(__dirname, 'assets/src/main.js'),
      output: {
        entryFileNames: 'js/options.js',
        chunkFileNames: 'js/[name].js',
        assetFileNames: (assetInfo) => {
          if (assetInfo.name && assetInfo.name.endsWith('.css')) {
            return 'css/options.css'
          }
          return 'assets/[name][extname]'
        },
        format: 'iife',
        name: 'EzCacheApp'
      }
    },
    minify: true,
    cssCodeSplit: false
  },
  define: {
    '__VUE_OPTIONS_API__': true,
    '__VUE_PROD_DEVTOOLS__': false
  }
})
