import { apiUrlProblem, apiUrlCheck, withApiOrigin } from './vite.apiUrlCheck'

test('missing, localhost and invalid URLs are problems', () => {
  expect(apiUrlProblem(undefined)).toMatch(/not set/)
  expect(apiUrlProblem('')).toMatch(/not set/)
  expect(apiUrlProblem('http://localhost:8080')).toMatch(/localhost/)
  expect(apiUrlProblem('http://127.0.0.1:8080')).toMatch(/127\.0\.0\.1/)
  expect(apiUrlProblem('nonsense')).toMatch(/not a valid URL/)
})

test('a real https origin passes; the escape hatch allows anything', () => {
  expect(apiUrlProblem('https://api.example.com')).toBeNull()
  expect(apiUrlProblem('http://localhost:8080', '1')).toBeNull()
  expect(apiUrlProblem(undefined, '1')).toBeNull()
})

test('plugin only throws for a production build', () => {
  const p = apiUrlCheck({})
  expect(() => p.config({}, { command: 'build', mode: 'production' })).toThrow(/VITE_API_URL is not set/)
  expect(() => p.config({}, { command: 'serve', mode: 'development' })).not.toThrow()
  expect(() => p.config({}, { command: 'serve', mode: 'test' })).not.toThrow()
  expect(() => p.config({}, { command: 'build', mode: 'staging' })).not.toThrow()
})

test('withApiOrigin swaps the placeholder for the API origin only', () => {
  const t = "img-src 'self' https://API_ORIGIN_PLACEHOLDER; connect-src https://API_ORIGIN_PLACEHOLDER"
  expect(withApiOrigin(t, 'https://api.example.com/')).toBe("img-src 'self' https://api.example.com; connect-src https://api.example.com")
  expect(withApiOrigin(t, 'http://localhost:8080')).toContain('http://localhost:8080')
  expect(withApiOrigin(t, undefined)).not.toContain('PLACEHOLDER')
})
