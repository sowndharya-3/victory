import { useSession } from '../../context/SessionContext'

function AdminReferralsPage() {
  const { state, dispatch } = useSession()

  return (
    <div>
      <h2>Referral Partners</h2>
      <table>
        <thead>
          <tr><th>Name</th><th>Code</th><th>Status</th><th>Active</th><th>Referrals</th><th>Actions</th></tr>
        </thead>
        <tbody>
          {state.referralPartners.map(p => {
            const referralCount = state.orders.filter(o => o.referralPartnerId === p.id).length
            return (
              <tr key={p.id}>
                <td>{p.name}</td>
                <td>{p.referralCode}</td>
                <td>{p.approved ? 'Approved' : 'Pending'}</td>
                <td>{p.active ? 'Active' : 'Inactive'}</td>
                <td>{referralCount}</td>
                <td>
                  {!p.approved && (
                    <>
                      <button onClick={() => dispatch({ type: 'APPROVE_REFERRAL', payload: { id: p.id } })}>Approve</button>
                      <button onClick={() => dispatch({ type: 'REJECT_REFERRAL', payload: { id: p.id } })}>Reject</button>
                    </>
                  )}
                  {p.approved && (
                    <button onClick={() => dispatch({ type: 'TOGGLE_REFERRAL_ACTIVE', payload: { id: p.id } })}>
                      {p.active ? 'Deactivate' : 'Activate'}
                    </button>
                  )}
                </td>
              </tr>
            )
          })}
        </tbody>
      </table>
    </div>
  )
}

export default AdminReferralsPage
