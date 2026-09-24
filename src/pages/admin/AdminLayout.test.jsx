import { render, screen, fireEvent } from '@testing-library/react'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { SessionProvider } from '../../context/SessionContext'
import AdminLayout from './AdminLayout'

test('shows login form, then nav after valid hardcoded login', () => {
  render(
    <SessionProvider>
      <MemoryRouter initialEntries={['/admin']}>
        <Routes>
          <Route path="/admin" element={<AdminLayout />}>
            <Route index element={<div>dashboard content</div>} />
          </Route>
        </Routes>
      </MemoryRouter>
    </SessionProvider>
  )
  expect(screen.getByLabelText(/username/i)).toBeInTheDocument()
  fireEvent.change(screen.getByLabelText(/username/i), { target: { value: 'admin' } })
  fireEvent.change(screen.getByLabelText(/password/i), { target: { value: 'admin123' } })
  fireEvent.click(screen.getByRole('button', { name: /log in/i }))
  expect(screen.getByText(/dashboard content/i)).toBeInTheDocument()
})
