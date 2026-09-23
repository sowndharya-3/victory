import { render, screen, fireEvent } from '@testing-library/react'
import Contact from './Contact'

test('shows success message after submitting required fields', () => {
  render(<Contact />)
  fireEvent.change(screen.getByLabelText(/name/i), { target: { value: 'Test User' } })
  fireEvent.change(screen.getByLabelText(/phone/i), { target: { value: '9999999999' } })
  fireEvent.change(screen.getByLabelText(/e-mail/i), { target: { value: 'a@b.com' } })
  fireEvent.change(screen.getByLabelText(/service you want/i), { target: { value: 'distance-education' } })
  fireEvent.click(screen.getByRole('button', { name: /submit enquiry/i }))
  expect(screen.getByText(/thank you/i)).toBeInTheDocument()
})

test('does not show success message if required field missing', () => {
  render(<Contact />)
  fireEvent.click(screen.getByRole('button', { name: /submit enquiry/i }))
  expect(screen.queryByText(/thank you/i)).not.toBeInTheDocument()
  expect(screen.getByText(/there was an error/i)).toBeInTheDocument()
})

test('facilitator radio defaults to No and can be changed to Yes', () => {
  render(<Contact />)
  const yes = screen.getByLabelText('Yes')
  const no = screen.getByLabelText('No')
  expect(no.checked).toBe(true)
  expect(yes.checked).toBe(false)
  fireEvent.click(yes)
  expect(yes.checked).toBe(true)
  expect(no.checked).toBe(false)
})

test('resets form fields after successful submission', () => {
  render(<Contact />)
  const nameInput = screen.getByLabelText(/name/i)
  fireEvent.change(nameInput, { target: { value: 'Test User' } })
  fireEvent.change(screen.getByLabelText(/phone/i), { target: { value: '9999999999' } })
  fireEvent.change(screen.getByLabelText(/e-mail/i), { target: { value: 'a@b.com' } })
  fireEvent.change(screen.getByLabelText(/service you want/i), { target: { value: 'distance-education' } })
  fireEvent.click(screen.getByRole('button', { name: /submit enquiry/i }))
  expect(nameInput.value).toBe('')
})
