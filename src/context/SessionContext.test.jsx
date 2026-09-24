import { renderHook, act } from '@testing-library/react'
import { SessionProvider, useSession } from './SessionContext'

const wrapper = ({ children }) => <SessionProvider>{children}</SessionProvider>

test('CREATE_ORDER then MARK_ORDER_PAID grants access', () => {
  const { result } = renderHook(() => useSession(), { wrapper })

  act(() => {
    result.current.dispatch({
      type: 'CREATE_ORDER',
      payload: { bookId: 'b1', customerEmail: 'a@b.com', purchaseType: 'digital', amount: 199, referralPartnerId: 'r1' },
    })
  })
  const created = result.current.state.orders[0]
  expect(created.paymentStatus).toBe('pending')

  act(() => {
    result.current.dispatch({ type: 'MARK_ORDER_PAID', payload: { orderId: created.id } })
  })
  expect(result.current.state.orders[0].paymentStatus).toBe('paid')
})

test('REGISTER_REFERRAL adds a pending, unapproved partner', () => {
  const { result } = renderHook(() => useSession(), { wrapper })
  act(() => {
    result.current.dispatch({
      type: 'REGISTER_REFERRAL',
      payload: { name: 'New Partner', qualification: 'B.Sc', place: 'Erode', district: 'Erode', status: 'Student', phone: '900', whatsapp: '900', upiId: 'x@y', gmail: 'x@y.com' },
    })
  })
  const added = result.current.state.referralPartners.at(-1)
  expect(added.approved).toBe(false)
  expect(added.referralCode).toMatch(/^VTP-/)
})

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

test('APPROVE_REFERRAL approves and activates only the targeted partner', () => {
  const { result } = renderHook(() => useSession(), { wrapper })
  act(() => {
    result.current.dispatch({ type: 'APPROVE_REFERRAL', payload: { id: 'r2' } })
  })
  const r2 = result.current.state.referralPartners.find(p => p.id === 'r2')
  const r1 = result.current.state.referralPartners.find(p => p.id === 'r1')
  expect(r2.approved).toBe(true)
  expect(r2.active).toBe(true)
  expect(r1.approved).toBe(true) // unchanged (was already approved in seed)
  expect(r1.active).toBe(true)
})

test('REJECT_REFERRAL rejects only the targeted partner', () => {
  const { result } = renderHook(() => useSession(), { wrapper })
  act(() => {
    result.current.dispatch({ type: 'REJECT_REFERRAL', payload: { id: 'r1' } })
  })
  const r1 = result.current.state.referralPartners.find(p => p.id === 'r1')
  const r2 = result.current.state.referralPartners.find(p => p.id === 'r2')
  expect(r1.approved).toBe(false)
  expect(r1.active).toBe(false)
  expect(r2.approved).toBe(false) // untouched, unchanged from seed
})

test('TOGGLE_REFERRAL_ACTIVE flips only the targeted partner active flag', () => {
  const { result } = renderHook(() => useSession(), { wrapper })
  act(() => {
    result.current.dispatch({ type: 'TOGGLE_REFERRAL_ACTIVE', payload: { id: 'r1' } })
  })
  const r1 = result.current.state.referralPartners.find(p => p.id === 'r1')
  const r2 = result.current.state.referralPartners.find(p => p.id === 'r2')
  expect(r1.active).toBe(false) // seed was true
  expect(r2.active).toBe(false) // untouched, seed was false
})

test('ADD_BOOK appends an unpublished book', () => {
  const { result } = renderHook(() => useSession(), { wrapper })
  const before = result.current.state.books.length
  act(() => {
    result.current.dispatch({ type: 'ADD_BOOK', payload: { title: 'New Book', onlinePrice: 99, hardCopyPrice: 199 } })
  })
  expect(result.current.state.books.length).toBe(before + 1)
  const added = result.current.state.books.at(-1)
  expect(added.title).toBe('New Book')
  expect(added.published).toBe(false)
})

test('UPDATE_BOOK merges changes into only the targeted book', () => {
  const { result } = renderHook(() => useSession(), { wrapper })
  act(() => {
    result.current.dispatch({ type: 'UPDATE_BOOK', payload: { id: 'b1', changes: { title: 'Updated Title' } } })
  })
  const b1 = result.current.state.books.find(b => b.id === 'b1')
  const b2 = result.current.state.books.find(b => b.id === 'b2')
  expect(b1.title).toBe('Updated Title')
  expect(b2.title).not.toBe('Updated Title')
})

test('DELETE_BOOK removes only the targeted book', () => {
  const { result } = renderHook(() => useSession(), { wrapper })
  const before = result.current.state.books.length
  act(() => {
    result.current.dispatch({ type: 'DELETE_BOOK', payload: { id: 'b1' } })
  })
  expect(result.current.state.books.length).toBe(before - 1)
  expect(result.current.state.books.find(b => b.id === 'b1')).toBeUndefined()
  expect(result.current.state.books.find(b => b.id === 'b2')).toBeDefined()
})

test('TOGGLE_PUBLISH flips only the targeted book published flag', () => {
  const { result } = renderHook(() => useSession(), { wrapper })
  act(() => {
    result.current.dispatch({ type: 'TOGGLE_PUBLISH', payload: { id: 'b1' } })
  })
  const b1 = result.current.state.books.find(b => b.id === 'b1')
  const b2 = result.current.state.books.find(b => b.id === 'b2')
  expect(b1.published).toBe(false) // seed was true
  expect(b2.published).toBe(true) // untouched, seed was true
})

test('SET_COMMISSION_RATE merges into commissionRate without dropping other keys', () => {
  const { result } = renderHook(() => useSession(), { wrapper })
  act(() => {
    result.current.dispatch({ type: 'SET_COMMISSION_RATE', payload: { digital: 30 } })
  })
  expect(result.current.state.commissionRate.digital).toBe(30)
  expect(result.current.state.commissionRate.hardcopy).toBe(25) // untouched
})
