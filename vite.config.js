import { defineConfig, loadEnv } from 'vite'
import react from '@vitejs/plugin-react'
import { apiUrlCheck, apiOriginInHeaders } from './vite.apiUrlCheck'

export default defineConfig(({ mode }) => {
  // loadEnv merges .env files and the shell environment, same as what import.meta.env sees.
  const env = loadEnv(mode, process.cwd(), 'VITE_')
  return {
    plugins: [react(), apiUrlCheck(env), apiOriginInHeaders(env)],
    test: {
      environment: 'jsdom',
      globals: true,
      setupFiles: './src/setupTests.js',
    },
  }
})
