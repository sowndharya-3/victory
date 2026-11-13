import { render, screen, fireEvent } from '@testing-library/react'
import ReferralCodeInput from './ReferralCodeInput'
import * as ordersApi from '../../api/orders'

vi.mock('../../api/orders', () => ({ validateReferralCode: vi.fn() }))

afterEach(() => vi.clearAllMocks())

test('validates on blur and confirms a recognised code', async () => {
  ordersApi.validateReferralCode.mockResolvedValue({ valid: true, partnerName: 'R***' })
  render(<ReferralCodeInput value="VTP-1" onChange={() => {}} />)
  fireEvent.blur(screen.getByLabelText('Referral code (optional)'))
  expect(await screen.findByText('Code applied')).toBeInTheDocument()
  expect(ordersApi.validateReferralCode).toHaveBeenCalledWith('VTP-1')
})

test('flags an unknown code without blocking', async () => {
  ordersApi.validateReferralCode.mockResolvedValue({ valid: false })
  render(<ReferralCodeInput value="NOPE" onChange={() => {}} />)
  fireEvent.blur(screen.getByLabelText('Referral code (optional)'))
  expect(await screen.findByText('Code not recognised')).toBeInTheDocument()
})

test('does not call the API for an empty value and reports typing via onChange', () => {
  const onChange = vi.fn()
  render(<ReferralCodeInput value="" onChange={onChange} />)
  const input = screen.getByLabelText('Referral code (optional)')
  fireEvent.blur(input)
  expect(ordersApi.validateReferralCode).not.toHaveBeenCalled()
  fireEvent.change(input, { target: { value: 'abc' } })
  expect(onChange).toHaveBeenCalledWith('abc')
})
