const money = (n) => `₹${Number(n).toFixed(2)}`

function PriceBreakdown({ quote }) {
  const gstLabel = quote.gstMode === 'inclusive' ? 'GST (included)' : `GST (${quote.gstPercent}%)`
  const rows = [
    ['Book price', quote.subtotal],
    ['Shipping', quote.shipping],
    [gstLabel, quote.gst],
    ['Total payable', quote.total],
  ]
  return (
    <dl aria-label="Price breakdown">
      {rows.map(([label, amount]) => (
        <div key={label} style={{ display: 'flex', justifyContent: 'space-between', fontWeight: label === 'Total payable' ? 700 : undefined }}>
          <dt>{label}</dt>
          <dd style={{ margin: 0 }}>{money(amount)}</dd>
        </div>
      ))}
    </dl>
  )
}

export default PriceBreakdown
