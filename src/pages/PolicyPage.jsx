import Header from '../components/home/Header'
import Footer from '../components/home/Footer'
import { LAST_UPDATED } from '../content/policies/shared'

function PolicyPage({ policy }) {
  return (
    <>
      <Header />
      <section className="container" style={{ paddingTop: '2rem', paddingBottom: '2rem' }}>
        <h1>{policy.title}</h1>
        <p role="note" className="form-error"><strong>Draft for business review</strong> - not final until approved by the business owner.</p>
        <p>Last updated: {LAST_UPDATED}</p>
        {policy.sections.map(s => (
          <section key={s.heading}>
            <h2>{s.heading}</h2>
            {s.paras?.map(p => <p key={p}>{p}</p>)}
            {s.items && <ul>{s.items.map(i => <li key={i}>{i}</li>)}</ul>}
          </section>
        ))}
      </section>
      <Footer />
    </>
  )
}

export default PolicyPage
