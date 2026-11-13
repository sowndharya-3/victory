import { useState } from 'react'
import { fetchAdminCustomers, fetchAdminCustomer, formatRupees } from '../../api/admin'
import { useLoad, LoadState, Table, label } from './adminUi'

function CustomerDetail({ id, onClose }) {
  const { data: c, error, loading } = useLoad(() => fetchAdminCustomer(id), [id])
  if (!c) return <section className="admin-detail"><LoadState loading={loading} error={error} /></section>
  return (
    <section className="admin-detail" aria-label={`Customer ${c.name}`}>
      <button type="button" onClick={onClose} className="admin-detail-close">Close</button>
      <h3>{c.name}</h3>
      <p>{c.email} · {c.phone ?? '—'} · joined {c.createdAt}</p>
      {c.orders?.length ? (
        <Table head={['Order', 'Book', 'Type', 'Payment', 'Fulfilment', 'Amount', 'Created']}>
          {c.orders.map(o => (
            <tr key={o.id}>
              <td>{o.orderNumber}</td><td>{o.book?.title}</td><td>{o.purchaseType}</td><td>{o.status}</td>
              <td>{o.purchaseType === 'hardcopy' ? label(o.fulfilmentStatus) : '—'}</td><td>{formatRupees(o.amount)}</td><td>{o.createdAt}</td>
            </tr>
          ))}
        </Table>
      ) : <p>No orders.</p>}
    </section>
  )
}

function AdminCustomersPage() {
  const [search, setSearch] = useState('')
  const [params, setParams] = useState({ q: '', page: 1 })
  const [selectedId, setSelectedId] = useState(null)
  const { data, error, loading } = useLoad(() => fetchAdminCustomers(params), [params])
  const items = data?.items ?? []
  const lastPage = data ? Math.max(1, Math.ceil(data.total / data.perPage)) : 1

  return (
    <div>
      <h2>Customers</h2>
      <form className="admin-filters" onSubmit={e => { e.preventDefault(); setParams({ q: search.trim(), page: 1 }) }}>
        <label htmlFor="customer-search">Search</label>
        <input id="customer-search" className="form-control" placeholder="Name, email or phone" maxLength={100}
          value={search} onChange={e => setSearch(e.target.value)} />
        <button type="submit" className="btn btn-primary">Search</button>
      </form>
      <LoadState loading={loading} error={error} empty={!items.length} emptyText="No customers found." />
      {items.length > 0 && (
        <Table head={['Name', 'Email', 'Phone', 'Orders', 'Paid total', 'Joined', '']}>
          {items.map(c => (
            <tr key={c.id}>
              <td>{c.name}</td><td>{c.email}</td><td>{c.phone ?? '—'}</td><td>{c.orderCount}</td>
              <td>{formatRupees(c.paidTotal)}</td><td>{c.createdAt}</td>
              <td><button type="button" onClick={() => setSelectedId(c.id)}>View</button></td>
            </tr>
          ))}
        </Table>
      )}
      {data && data.total > data.perPage && (
        <p className="admin-pager">
          <button type="button" disabled={params.page <= 1} onClick={() => setParams(p => ({ ...p, page: p.page - 1 }))}>Previous</button>
          <span> Page {params.page} of {lastPage} </span>
          <button type="button" disabled={params.page >= lastPage} onClick={() => setParams(p => ({ ...p, page: p.page + 1 }))}>Next</button>
        </p>
      )}
      {selectedId && <CustomerDetail key={selectedId} id={selectedId} onClose={() => setSelectedId(null)} />}
    </div>
  )
}

export default AdminCustomersPage
