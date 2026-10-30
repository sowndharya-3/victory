import { renderHook, act } from '@testing-library/react'
import { SessionProvider, useSession } from './SessionContext'

const wrapper = ({ children }) => <SessionProvider>{children}</SessionProvider>

test('LOGIN_CUSTOMER sets customerEmail', () => {
  const { result } = renderHook(() => useSession(), { wrapper })
  act(() => {
    result.current.dispatch({ type: 'LOGIN_CUSTOMER', payload: { email: 'cust@example.com' } })
  })
  expect(result.current.state.customerEmail).toBe('cust@example.com')
})

test('LOGOUT_CUSTOMER clears customerEmail', () => {
  const { result } = renderHook(() => useSession(), { wrapper })
  act(() => {
    result.current.dispatch({ type: 'LOGIN_CUSTOMER', payload: { email: 'cust@example.com' } })
  })
  act(() => {
    result.current.dispatch({ type: 'LOGOUT_CUSTOMER' })
  })
  expect(result.current.state.customerEmail).toBe(null)
})

test('LOGIN_ADMIN sets admin', () => {
  const { result } = renderHook(() => useSession(), { wrapper })
  act(() => {
    result.current.dispatch({ type: 'LOGIN_ADMIN', payload: { username: 'boss' } })
  })
  expect(result.current.state.admin).toEqual({ username: 'boss' })
})

test('LOGOUT_ADMIN clears admin', () => {
  const { result } = renderHook(() => useSession(), { wrapper })
  act(() => {
    result.current.dispatch({ type: 'LOGIN_ADMIN', payload: { username: 'boss' } })
  })
  act(() => {
    result.current.dispatch({ type: 'LOGOUT_ADMIN' })
  })
  expect(result.current.state.admin).toBe(null)
})

test('session holds auth state only; no source reads mock orders/books/referrals from it', () => {
  const { result } = renderHook(() => useSession(), { wrapper })
  expect(Object.keys(result.current.state).sort()).toEqual(['admin', 'customerEmail'])
  const sources = import.meta.glob('/src/**/*.jsx', { query: '?raw', import: 'default', eager: true })
  expect(Object.keys(sources).length).toBeGreaterThan(20)
  const offenders = Object.entries(sources)
    .filter(([path]) => !path.endsWith('.test.jsx'))
    .filter(([, code]) => /state\.(orders|books|referralPartners|commissionRate)/.test(code))
    .map(([path]) => path)
  expect(offenders).toEqual([])
})
