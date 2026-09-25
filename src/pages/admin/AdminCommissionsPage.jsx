import { useState } from 'react'
import { useSession } from '../../context/SessionContext'

function AdminCommissionsPage() {
  const { state, dispatch } = useSession()
  const [digitalRate, setDigitalRate] = useState(state.commissionRate.digital)
  const [hardcopyRate, setHardcopyRate] = useState(state.commissionRate.hardcopy)

  const commissionEntries = state.orders
    .filter(o => o.paymentStatus === 'paid' && o.referralPartnerId)
    .map(o => {
      const partner = state.referralPartners.find(p => p.id === o.referralPartnerId)
      const rate = o.purchaseType === 'digital' ? state.commissionRate.digital : state.commissionRate.hardcopy
      return { orderId: o.id, partnerName: partner?.name, rate }
    })

  const total = commissionEntries.reduce((s, e) => s + e.rate, 0)

  const handleSave = (e) => {
    e.preventDefault()
    dispatch({ type: 'SET_COMMISSION_RATE', payload: { digital: Number(digitalRate), hardcopy: Number(hardcopyRate) } })
  }

  return (
    <div>
      <h2>Commissions</h2>
      <form onSubmit={handleSave}>
        <label htmlFor="digital-rate">Digital Commission (₹)</label>
        <input id="digital-rate" type="number" value={digitalRate} onChange={e => setDigitalRate(e.target.value)} />
        <label htmlFor="hardcopy-rate">Hard Copy Commission (₹)</label>
        <input id="hardcopy-rate" type="number" value={hardcopyRate} onChange={e => setHardcopyRate(e.target.value)} />
        <button type="submit" className="btn btn-primary">Save Rates</button>
      </form>

      <p>Total Commission: ₹{total}</p>
      <table>
        <thead><tr><th>Order</th><th>Partner</th><th>Commission</th></tr></thead>
        <tbody>
          {commissionEntries.map(e => (
            <tr key={e.orderId}><td>{e.orderId}</td><td>{e.partnerName}</td><td>₹{e.rate}</td></tr>
          ))}
        </tbody>
      </table>
    </div>
  )
}

export default AdminCommissionsPage
