import { render, screen } from '@testing-library/react'
import PriceBreakdown from './PriceBreakdown'

const quote = { subtotal: 199, shipping: 0, gst: 0, gstPercent: '5', gstMode: 'inclusive', total: 199 }

test('renders labelled rows with two-decimal amounts, zero values included', () => {
  render(<PriceBreakdown quote={quote} />)
  expect(screen.getByText('Book price').nextSibling).toHaveTextContent('₹199.00')
  expect(screen.getByText('Shipping').nextSibling).toHaveTextContent('₹0.00')
  expect(screen.getByText('GST (included)').nextSibling).toHaveTextContent('₹0.00')
  expect(screen.getByText('Total payable').nextSibling).toHaveTextContent('₹199.00')
})

test('shows the GST percent when GST is added on top', () => {
  render(<PriceBreakdown quote={{ ...quote, gstMode: 'exclusive', gst: 10, total: 209, gstPercent: '5' }} />)
  expect(screen.getByText('GST (5%)').nextSibling).toHaveTextContent('₹10.00')
  expect(screen.getByText('Total payable').nextSibling).toHaveTextContent('₹209.00')
})
