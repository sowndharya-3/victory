import { render, screen, fireEvent, waitFor } from '@testing-library/react'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { vi } from 'vitest'
import { SessionProvider } from '../../context/SessionContext'
import AdminLayout from './AdminLayout'
import * as authApi from '../../api/auth'

test('shows login form, then nav after real admin login succeeds', async () => {
  vi.spyOn(authApi, 'loginAdmin').mockResolvedValue({ token: 'admin-token', admin: { id: 1, username: 'admin' } })

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
  fireEvent.change(screen.getByLabelText(/password/i), { target: { value: 'adminpass123' } })
  fireEvent.click(screen.getByRole('button', { name: /log in/i }))

  await waitFor(() => {
    expect(screen.getByText(/dashboard content/i)).toBeInTheDocument()
  })
})
