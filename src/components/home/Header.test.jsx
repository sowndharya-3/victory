import { render, screen, fireEvent } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'
import Header from './Header'

test('toggles nav menu open class on button click', () => {
  render(<MemoryRouter><Header /></MemoryRouter>)
  const menu = screen.getByRole('list')
  const btn = screen.getByLabelText(/toggle navigation menu/i)
  expect(menu.className).not.toMatch(/active/)
  fireEvent.click(btn)
  expect(menu.className).toMatch(/active/)
})

test('renders Books and referral nav links', () => {
  render(<MemoryRouter><Header /></MemoryRouter>)
  expect(screen.getByRole('link', { name: /books/i })).toHaveAttribute('href', '/books')
  expect(screen.getByRole('link', { name: /become a referral partner/i })).toHaveAttribute('href', '/referral/register')
})
