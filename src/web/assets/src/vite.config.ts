import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react'
import { resolve } from 'node:path'

export default defineConfig({
  define: {
    'process.env.NODE_ENV': JSON.stringify('production'),
    'process.env': JSON.stringify({})
  },
  build: {
    target: 'es2015',
    emptyOutDir: true,
    outDir: '../dist',
    minify: false,
    lib: {
      formats: ['es'],
      entry: resolve(__dirname, 'lib/input.ts'),
      name: 'input',
      fileName: () => 'input.js',
    },
    rollupOptions: {
      output: {
        format: 'es',
        entryFileNames: '[name].js',
      },
    },
  },
  plugins: [
    react()
  ],
})
