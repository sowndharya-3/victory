import { render, screen } from '@testing-library/react'
import App from './App'

test('renders home page heading', () => {
  render(<App />)
  expect(screen.getAllByText(/victory today educational enterprises/i).length).toBeGreaterThan(0)
})
