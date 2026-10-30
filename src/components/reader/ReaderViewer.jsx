import { useEffect, useRef, useState } from 'react'
import { fetchPageBlobUrl } from '../../api/reader'

const SWIPE_MIN_PX = 50
const WATERMARK_REPEAT = 24

function errorMessage(err) {
  if (err.status === 403) return 'Access revoked. This book is no longer available on your account.'
  if (err.status === 429) return 'You are turning pages too fast. Please slow down and try again in a minute.'
  return 'This page could not be loaded. Please try again.'
}

function ReaderViewer({ token, bookId, pageCount, book, email, orderNumber }) {
  const [page, setPage] = useState(1)
  const [jump, setJump] = useState('')
  const [error, setError] = useState(null)
  const [showIndex, setShowIndex] = useState(false)
  const [, rerender] = useState(0)
  // ponytail: holds at most the current and next page's blob URLs, everything else is revoked.
  const cache = useRef({})
  const touch = useRef(null)

  const go = (p) => setPage(Math.min(pageCount, Math.max(1, p)))

  useEffect(() => {
    let cancelled = false
    setError(null)
    const keep = [page, page + 1].filter(p => p <= pageCount)
    for (const p of Object.keys(cache.current)) {
      if (!keep.includes(Number(p))) {
        URL.revokeObjectURL(cache.current[p])
        delete cache.current[p]
      }
    }
    keep.filter(p => !cache.current[p]).forEach(p => {
      fetchPageBlobUrl(token, bookId, p)
        .then(url => {
          if (cancelled) { URL.revokeObjectURL(url); return }
          cache.current[p] = url
          rerender(n => n + 1)
        })
        .catch(err => { if (!cancelled && p === page) setError(err) }) // prefetch failures are retried on arrival
    })
    return () => { cancelled = true }
  }, [token, bookId, page, pageCount])

  useEffect(() => () => {
    Object.values(cache.current).forEach(url => URL.revokeObjectURL(url))
    cache.current = {}
  }, [])

  useEffect(() => {
    const onKey = (e) => {
      if (e.target instanceof HTMLInputElement) return
      if (e.key === 'ArrowRight') setPage(p => Math.min(pageCount, p + 1))
      if (e.key === 'ArrowLeft') setPage(p => Math.max(1, p - 1))
    }
    window.addEventListener('keydown', onKey)
    return () => window.removeEventListener('keydown', onKey)
  }, [pageCount])

  const onTouchStart = (e) => {
    const t = e.touches[0]
    touch.current = { x: t.clientX, y: t.clientY }
  }
  const onTouchEnd = (e) => {
    if (!touch.current) return
    const t = e.changedTouches[0]
    const dx = t.clientX - touch.current.x
    const dy = t.clientY - touch.current.y
    touch.current = null
    if (Math.abs(dx) > SWIPE_MIN_PX && Math.abs(dx) > Math.abs(dy)) go(dx < 0 ? page + 1 : page - 1)
  }

  const submitJump = (e) => {
    e.preventDefault()
    const n = parseInt(jump, 10)
    if (Number.isNaN(n)) return
    go(n)
    setJump('')
  }

  const block = (e) => e.preventDefault()
  const label = `Page ${page} of ${pageCount}`
  const mark = `Licensed to ${email || 'you'} · ${orderNumber || bookId}`
  const indexUrl = book?.indexUrl
  const indexIsPdf = indexUrl && /\.pdf($|\?)/i.test(indexUrl)
  const src = cache.current[page]

  return (
    <div className="reader" onContextMenu={block} onDragStart={block}>
      <div className="reader-toolbar">
        <button type="button" className="btn btn-outline" onClick={() => go(page - 1)} disabled={page <= 1} aria-label="Previous page">‹ Prev</button>
        <p className="reader-status" aria-live="polite">{label}</p>
        <button type="button" className="btn btn-outline" onClick={() => go(page + 1)} disabled={page >= pageCount} aria-label="Next page">Next ›</button>
        <form className="reader-jump" onSubmit={submitJump}>
          <label htmlFor="reader-jump">Go to page</label>
          <input id="reader-jump" type="number" value={jump} onChange={e => setJump(e.target.value)} />
          <button type="submit" className="btn btn-outline">Go</button>
        </form>
        {indexUrl && (
          <button type="button" className="btn btn-outline" aria-expanded={showIndex} onClick={() => setShowIndex(s => !s)}>
            {showIndex ? 'Hide index' : 'Show index'}
          </button>
        )}
      </div>

      <div className="reader-body">
        <div className="reader-page" onTouchStart={onTouchStart} onTouchEnd={onTouchEnd}>
          {error ? (
            <p role="alert" className="form-error">{errorMessage(error)}</p>
          ) : src ? (
            <img src={src} alt={label} draggable="false" />
          ) : (
            <p aria-live="polite">Loading page…</p>
          )}
          <div className="reader-watermark" aria-hidden="true">
            {Array.from({ length: WATERMARK_REPEAT }, (_, i) => <span key={i}>{mark}</span>)}
          </div>
        </div>

        {showIndex && indexUrl && (
          <aside className="reader-index" aria-label="Book index">
            {indexIsPdf ? (
              <>
                <iframe src={indexUrl} title="Book index" />
                <a href={indexUrl} target="_blank" rel="noopener noreferrer">Open index</a>
              </>
            ) : (
              <img src={indexUrl} alt="Book index" />
            )}
          </aside>
        )}
      </div>

      <p className="reader-notice">Screenshots and screen recording cannot be fully prevented; this book is licensed to you only.</p>
    </div>
  )
}

export default ReaderViewer
