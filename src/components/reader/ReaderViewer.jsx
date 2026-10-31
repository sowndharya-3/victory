import { useEffect, useRef, useState } from 'react'
import { fetchPageBlobUrl } from '../../api/reader'

const SWIPE_MIN_PX = 50
// ponytail: the rotated box is 2x the page each way; ~200 short spans keep diagonal rows ~150px apart on a full page.
const WATERMARK_REPEAT = 200

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
  const [retries, setRetries] = useState(0)
  const [, rerender] = useState(0)
  // page -> { controller, url? } for the current + next page only (in flight or loaded).
  // An entry is reused while its page stays in the window; leaving the window aborts it and
  // revokes its blob. A response whose entry is gone (left window / unmounted) is revoked on arrival.
  const entries = useRef(new Map())
  const pageRef = useRef(page)
  pageRef.current = page
  const touch = useRef(null)

  const go = (p) => setPage(Math.min(pageCount, Math.max(1, p)))

  useEffect(() => {
    setError(null)
    const window_ = new Set([page, page + 1].filter(p => p <= pageCount))
    for (const [p, entry] of entries.current) {
      if (!window_.has(p)) {
        entry.controller.abort()
        if (entry.url) URL.revokeObjectURL(entry.url)
        entries.current.delete(p)
      }
    }
    const load = (p) => {
      if (entries.current.has(p)) return
      const entry = { controller: new AbortController() }
      entries.current.set(p, entry)
      fetchPageBlobUrl(token, bookId, p, entry.controller.signal)
        .then(url => {
          if (entries.current.get(p) !== entry) { URL.revokeObjectURL(url); return }
          entry.url = url
          rerender(n => n + 1)
        })
        .catch(err => {
          if (entries.current.get(p) !== entry) return
          entries.current.delete(p) // so arriving at / retrying this page fetches again
          if (p === pageRef.current) setError(err)
        })
    }
    // ponytail: 150 ms debounce so a held arrow key doesn't fetch every skipped page;
    // pages already in the map render immediately regardless.
    const timer = setTimeout(() => window_.forEach(load), 150)
    return () => clearTimeout(timer)
  }, [token, bookId, page, pageCount, retries])

  useEffect(() => {
    const map = entries.current
    return () => {
      for (const entry of map.values()) {
        entry.controller.abort()
        if (entry.url) URL.revokeObjectURL(entry.url)
      }
      map.clear()
    }
  }, [])

  useEffect(() => {
    const onKey = (e) => {
      const t = e.target
      if (e.altKey || e.metaKey || e.ctrlKey || e.shiftKey) return
      if (t instanceof HTMLElement && (t.isContentEditable || /^(INPUT|TEXTAREA|SELECT)$/.test(t.tagName))) return
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
  const src = entries.current.get(page)?.url

  return (
    <div className="reader" onContextMenu={block} onDragStart={block}>
      <div className="reader-toolbar">
        <button type="button" className="btn btn-outline" onClick={() => go(page - 1)} disabled={page <= 1}>Previous page</button>
        <p className="reader-status" aria-live="polite">{label}</p>
        <button type="button" className="btn btn-outline" onClick={() => go(page + 1)} disabled={page >= pageCount}>Next page</button>
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
            <div role="alert">
              <p className="form-error">{errorMessage(error)}</p>
              {error.status !== 403 && <button type="button" className="btn btn-outline" onClick={() => setRetries(n => n + 1)}>Retry</button>}
            </div>
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
