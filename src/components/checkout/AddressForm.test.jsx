import { render, screen, fireEvent } from '@testing-library/react'
import AddressForm from './AddressForm'

const empty = { fullName: '', phone: '', addressLine: '', city: '', district: '', state: '', pincode: '' }

test('renders a labelled input for every address field', () => {
  render(<AddressForm value={empty} onChange={() => {}} errors={{}} />)
  for (const label of ['Full name', 'Phone', 'Address', 'City', 'District', 'State', 'Pincode']) {
    expect(screen.getByLabelText(label)).toBeInTheDocument()
  }
  expect(screen.getByLabelText('Pincode')).toHaveAttribute('id', 'addr-pincode')
})

test('typing calls onChange with the updated address', () => {
  const onChange = vi.fn()
  render(<AddressForm value={empty} onChange={onChange} errors={{}} />)
  fireEvent.change(screen.getByLabelText('City'), { target: { value: 'Chennai' } })
  expect(onChange).toHaveBeenCalledWith({ ...empty, city: 'Chennai' })
})

test('shows field errors and marks the input invalid', () => {
  render(<AddressForm value={empty} onChange={() => {}} errors={{ pincode: 'Must be 6 digits' }} />)
  expect(screen.getByText('Must be 6 digits')).toBeInTheDocument()
  expect(screen.getByLabelText('Pincode')).toBeInvalid()
})
