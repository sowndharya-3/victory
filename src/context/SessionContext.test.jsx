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
