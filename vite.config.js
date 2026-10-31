import { defineConfig, loadEnv } from 'vite'
import react from '@vitejs/plugin-react'
import { apiUrlCheck } from './vite.apiUrlCheck'

export default defineConfig(({ mode }) => ({
  // loadEnv merges .env files and the shell environment, same as what import.meta.env sees.
  plugins: [react(), apiUrlCheck(loadEnv(mode, process.cwd(), 'VITE_'))],
  test: {
    environment: 'jsdom',
    globals: true,
    setupFiles: './src/setupTests.js',
  },
}))
