// Customer-facing labels; mirror the server's TrackController::LABELS.
export const FULFILMENT_LABELS = {
  confirmed: 'Order Confirmed',
  processing: 'Processing',
  shipped: 'Shipped',
  out_for_delivery: 'Out for Delivery',
  delivered: 'Delivered',
  cancelled: 'Cancelled',
}

export const fulfilmentLabel = (status) => FULFILMENT_LABELS[status] || status

export const money = (n) => `₹${Number(n).toFixed(2)}`

// Server sends 'YYYY-MM-DD HH:MM:SS' (no zone); show just the date.
export const shortDate = (s) => (s ? String(s).slice(0, 10) : '')
