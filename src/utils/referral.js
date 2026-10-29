const STORAGE_KEY = 'vtee-referral'

// ponytail: charset mirrors codes like "VTP-ABC"; anything else is dropped rather than sanitised.
export function captureReferralFromUrl(search = window.location.search) {
  try {
    const code = new URLSearchParams(search).get('ref')?.trim().toUpperCase().slice(0, 30)
    if (code && /^[A-Z0-9_-]+$/.test(code)) localStorage.setItem(STORAGE_KEY, code)
  } catch {
    // private browsing / quota errors are non-fatal, just skip persisting
  }
}

export function getStoredReferral() {
  try {
    return localStorage.getItem(STORAGE_KEY) || ''
  } catch {
    return ''
  }
}
