import { render, screen, fireEvent, waitFor } from '@testing-library/react'
import { vi } from 'vitest'
import Contact from './Contact'

const reply = (status, body, headers = {}) =>
  vi.stubGlobal('fetch', vi.fn().mockResolvedValue({ ok: status < 400, status, headers: { get: k => headers[k] ?? null }, json: async () => body }))
afterEach(() => vi.unstubAllGlobals())

function fillValid(over = {}) {
  const v = { name: 'Test User', phone: '9999999999', email: 'a@b.com', details: 'I want to know about admission.', ...over }
  fireEvent.change(screen.getByLabelText(/^name/i), { target: { value: v.name } })
  fireEvent.change(screen.getByLabelText(/phone/i), { target: { value: v.phone } })
  fireEvent.change(screen.getByLabelText(/e-mail/i), { target: { value: v.email } })
  fireEvent.change(screen.getByLabelText(/service you want/i), { target: { value: 'distance-education' } })
  fireEvent.change(screen.getByLabelText(/details/i), { target: { value: v.details } })
}
const submit = () => fireEvent.click(screen.getByRole('button', { name: /submit enquiry|sending/i }))

test('success only after backend ok:true; sends honeypot and resets the form', async () => {
  reply(200, { ok: true })
  const { container } = render(<Contact />)
  fillValid()
  submit()
  expect(await screen.findByText(/your enquiry has been sent/i)).toBeInTheDocument()
  const body = JSON.parse(fetch.mock.calls[0][1].body)
  expect(body).toMatchObject({ name: 'Test User', email: 'a@b.com', phone: '9999999999', website: '' })
  expect(body.message).toMatch(/admission/)
  expect(screen.getByLabelText(/^name/i).value).toBe('')
  const hp = container.querySelector('input[name="website"]')
  expect(hp).toHaveAttribute('tabindex', '-1')
  expect(hp).toHaveAttribute('autocomplete', 'off')
  expect(hp.closest('[aria-hidden="true"]')).not.toBeNull()
})

test('honeypot value is sent as typed', async () => {
  reply(200, { ok: true })
  const { container } = render(<Contact />)
  fillValid()
  fireEvent.change(container.querySelector('input[name="website"]'), { target: { value: 'spam.example' } })
  submit()
  await waitFor(() => expect(fetch).toHaveBeenCalled())
  expect(JSON.parse(fetch.mock.calls[0][1].body).website).toBe('spam.example')
})

test('client validation blocks the request and shows inline errors', () => {
  vi.stubGlobal('fetch', vi.fn())
  render(<Contact />)
  fillValid({ name: 'A', email: 'nope', phone: 'abc', details: 'short' })
  submit()
  expect(fetch).not.toHaveBeenCalled()
  expect(screen.getByText(/enter your name/i)).toBeInTheDocument()
  expect(screen.getByText(/valid email/i)).toBeInTheDocument()
  expect(screen.getByText(/valid phone/i)).toBeInTheDocument()
  expect(screen.queryByText(/has been sent/i)).toBeNull()
})

test('400 field errors show inline and no success', async () => {
  reply(400, { error: 'validation_error', fields: { email: 'Email looks wrong', message: 'Message too short' } })
  render(<Contact />)
  fillValid()
  submit()
  expect(await screen.findByText('Email looks wrong')).toBeInTheDocument()
  expect(screen.getByText('Message too short')).toBeInTheDocument()
  expect(screen.queryByText(/has been sent/i)).toBeNull()
})

test('502 email_failed shows an error, never success, and keeps the form values', async () => {
  reply(502, { error: 'email_failed', message: 'x' })
  render(<Contact />)
  fillValid()
  submit()
  expect(await screen.findByText(/could not send your enquiry/i)).toBeInTheDocument()
  expect(screen.queryByText(/has been sent/i)).toBeNull()
  expect(screen.getByLabelText(/^name/i).value).toBe('Test User')
})

test('network failure is an error, not success', async () => {
  vi.stubGlobal('fetch', vi.fn().mockRejectedValue(new TypeError('offline')))
  render(<Contact />)
  fillValid()
  submit()
  expect(await screen.findByText(/could not send your enquiry/i)).toBeInTheDocument()
  expect(screen.queryByText(/has been sent/i)).toBeNull()
})

test('429 shows a friendly wait message using Retry-After', async () => {
  reply(429, {}, { 'Retry-After': '45' })
  render(<Contact />)
  fillValid()
  submit()
  expect(await screen.findByText(/please wait 45 seconds/i)).toBeInTheDocument()
  expect(screen.queryByText(/has been sent/i)).toBeNull()
})

test('submit is disabled while sending', async () => {
  vi.stubGlobal('fetch', vi.fn(() => new Promise(() => {})))
  render(<Contact />)
  fillValid()
  submit()
  expect(await screen.findByRole('button', { name: /sending/i })).toBeDisabled()
})

test('facilitator radio defaults to No and can be changed to Yes', () => {
  render(<Contact />)
  const yes = screen.getByLabelText('Yes')
  const no = screen.getByLabelText('No')
  expect(no.checked).toBe(true)
  fireEvent.click(yes)
  expect(yes.checked).toBe(true)
  expect(no.checked).toBe(false)
})
