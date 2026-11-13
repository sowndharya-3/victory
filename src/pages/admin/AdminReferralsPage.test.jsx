import { render, screen, fireEvent, waitFor, within } from '@testing-library/react'
import { vi } from 'vitest'
import * as adminApi from '../../api/admin'
import AdminReferralsPage from './AdminReferralsPage'

afterEach(() => vi.restoreAllMocks())

test('renders applications and partners from the API and approves a pending one', async () => {
  vi.spyOn(adminApi, 'fetchReferrals').mockResolvedValue({
    applications: { items: [{ id: 5, name: 'Meena K', status: 'Student', phone: '9000000001', gmail: 'meena@gmail.com', applicationStatus: 'pending', createdAt: '2026-10-29' }], page: 1, perPage: 25, total: 1 },
    partners: [{ id: 2, name: 'Ravi', referralCode: 'VTP-ABC123', phone: '9000000002', gmail: 'ravi@gmail.com', active: true }],
  })
  const approve = vi.spyOn(adminApi, 'approveReferral').mockResolvedValue({})
  render(<AdminReferralsPage />)
  const tr = (await screen.findByText('Meena K')).closest('tr')
  expect(screen.getByText('VTP-ABC123')).toBeInTheDocument()
  fireEvent.click(within(tr).getByRole('button', { name: /approve/i }))
  await waitFor(() => expect(approve).toHaveBeenCalledWith(5))
})
