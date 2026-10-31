import { useState } from 'react'
import { fetchAdminOrders, formatRupees } from '../../api/admin'
import { useLoad, LoadState, Table, label } from './adminUi'
import AdminOrderDetail from './AdminOrderDetail'

const STATUSES = ['pending', 'paid', 'failed', 'cancelled', 'refunded']
const FULFILMENT = ['confirmed', 'processing', 'shipped', 'out_for_delivery', 'delivered', 'cancelled', 'not_applicable']

// A full access code would end up in the GET query string (and server logs): send admins to Verify instead.
const looksLikeFullCode = (v) => /^[A-Z0-9]{4,}(-[A-Z0-9]{4,}){2,}$/i.test(v) || /^[A-Z0-9]{16,}$/i.test(v)

function AdminOrdersPage() {
  const [search, setSearch] = useState('')
  const [searchHint, setSearchHint] = useState('')
  const [filters, setFilters] = useState({ q: '', type: '', status: '', fulfilment: '', page: 1 })
  const [selectedId, setSelectedId] = useState(null)
  const { data, error, loading, reload } = useLoad(() => fetchAdminOrders(filters), [filters])

  const setFilter = (key) => (e) => setFilters(f => ({ ...f, [key]: e.target.value, page: 1 }))
  const items = data?.items ?? []
  const lastPage = data ? Math.max(1, Math.ceil(data.total / data.perPage)) : 1

  return (
    <div>
      <h2>Orders</h2>
      <form className="admin-filters" onSubmit={e => {
        e.preventDefault()
        const q = search.trim()
        if (looksLikeFullCode(q)) {
          setSearchHint('That looks like a full access code. Open the order and use the code panel to verify it; search by the last 4 characters instead.')
          return
        }
        setSearchHint('')
        setFilters(f => ({ ...f, q, page: 1 }))
      }}>
        <label htmlFor="order-search">Search</label>
        <input id="order-search" className="form-control" placeholder="Order no., name, email, phone, tracking code or code last 4"
          value={search} onChange={e => setSearch(e.target.value)} maxLength={100} autoComplete="off" />
        <button type="submit" className="btn btn-primary">Search</button>
        <label htmlFor="type-filter">Type</label>
        <select id="type-filter" value={filters.type} onChange={setFilter('type')}>
          <option value="">All</option>
          <option value="digital">Digital</option>
          <option value="hardcopy">Hard copy</option>
        </select>
        <label htmlFor="status-filter">Payment</label>
        <select id="status-filter" value={filters.status} onChange={setFilter('status')}>
          <option value="">All</option>
          {STATUSES.map(s => <option key={s} value={s}>{s}</option>)}
        </select>
        <label htmlFor="fulfilment-filter">Fulfilment</label>
        <select id="fulfilment-filter" value={filters.fulfilment} onChange={setFilter('fulfilment')}>
          <option value="">All</option>
          {FULFILMENT.map(s => <option key={s} value={s}>{label(s)}</option>)}
        </select>
      </form>
      {searchHint && <p role="alert" className="form-error">{searchHint}</p>}

      <LoadState loading={loading} error={error} empty={!items.length} emptyText="No orders match." />
      {items.length > 0 && (
        <Table head={['Order', 'Customer', 'Book', 'Type', 'Payment', 'Fulfilment', 'Code', 'Amount', 'Created', '']}>
          {items.map(o => (
            <tr key={o.id} className={o.id === selectedId ? 'is-selected' : undefined}>
              <td>{o.orderNumber}{o.adminFlag && <span className="admin-badge">{label(o.adminFlag)}</span>}</td>
              <td>{o.customer?.name}<br /><small>{o.customer?.email}</small></td>
              <td>{o.book?.title}</td>
              <td>{o.purchaseType === 'hardcopy' ? 'Hard copy' : 'Digital'}</td>
              <td>{o.status}</td>
              <td>{o.purchaseType === 'hardcopy' ? label(o.fulfilmentStatus) : '—'}</td>
              <td>{o.digitalCode ? `${o.digitalCode.status} ····${o.digitalCode.last4}` : '—'}</td>
              <td>{formatRupees(o.amount)}</td>
              <td>{o.createdAt}</td>
              <td><button type="button" onClick={() => setSelectedId(o.id)}>View</button></td>
            </tr>
          ))}
        </Table>
      )}
      {data && data.total > data.perPage && (
        <p className="admin-pager">
          <button type="button" disabled={filters.page <= 1} onClick={() => setFilters(f => ({ ...f, page: f.page - 1 }))}>Previous</button>
          <span> Page {filters.page} of {lastPage} </span>
          <button type="button" disabled={filters.page >= lastPage} onClick={() => setFilters(f => ({ ...f, page: f.page + 1 }))}>Next</button>
        </p>
      )}

      {selectedId && (
        <AdminOrderDetail key={selectedId} orderId={selectedId} onClose={() => setSelectedId(null)} onChanged={reload} />
      )}
    </div>
  )
}

export default AdminOrdersPage
