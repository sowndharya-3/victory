import { Link } from 'react-router-dom'

function NoRefundNotice() {
  return (
    <p role="note">
      All purchases are final. No general refunds or cancellations - see <Link to="/refund-policy">Cancellation &amp; Refund Policy</Link>.
    </p>
  )
}

export default NoRefundNotice
