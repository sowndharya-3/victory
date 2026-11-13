import { existsSync, readFileSync, writeFileSync } from 'node:fs'
import { resolve } from 'node:path'

// Production builds bake VITE_API_URL into the bundle; src/api/client.js falls back to http://localhost:8080
// when it is missing, which silently ships a site that cannot reach its API. Fail the build instead.
// Escape hatch for local verification builds only: VITE_ALLOW_LOCAL_API=1.
export function apiUrlProblem(url, allowLocal) {
  if (allowLocal === '1') return null
  if (!url) return 'VITE_API_URL is not set.'
  let host = ''
  try { host = new URL(url).hostname } catch { return `VITE_API_URL is not a valid URL: ${url}` }
  if (['localhost', '127.0.0.1', '[::1]', '0.0.0.0'].includes(host)) return `VITE_API_URL points at ${host} (${url}).`
  return null
}

export function apiUrlCheck(env) {
  return {
    name: 'vtee-api-url-check',
    config(_, { command, mode }) {
      if (command !== 'build' || mode !== 'production') return
      const problem = apiUrlProblem(env.VITE_API_URL, env.VITE_ALLOW_LOCAL_API)
      if (problem) {
        throw new Error(`${problem} Set it to the live API origin, e.g. VITE_API_URL=https://api.example.com npm run build. For a local verification build only, also set VITE_ALLOW_LOCAL_API=1.`)
      }
    },
  }
}

// public/_headers and public/.htaccess carry a CSP with this token where the API origin goes. The origin is only
// known at build time, so write it into the copies in dist/; a placeholder can then never ship.
export const API_ORIGIN_TOKEN = 'https://API_ORIGIN_PLACEHOLDER'

export function withApiOrigin(text, apiUrl) {
  return text.replaceAll(API_ORIGIN_TOKEN, new URL(apiUrl || 'http://localhost:8080').origin)
}

export function apiOriginInHeaders(env) {
  let outDir
  return {
    name: 'vtee-api-origin-in-headers',
    apply: 'build',
    configResolved(c) { outDir = resolve(c.root, c.build.outDir) },
    closeBundle() {
      for (const f of ['_headers', '.htaccess']) {
        const p = resolve(outDir, f)
        if (!existsSync(p)) continue
        writeFileSync(p, withApiOrigin(readFileSync(p, 'utf8'), env.VITE_API_URL))
      }
    },
  }
}
