import { createContext, useContext, useEffect, useReducer } from 'react'

const STORAGE_KEY = 'vtee-session'

// Auth state only: orders, books, referrals and commissions come from the PHP API.
const seedState = { customerEmail: null, admin: null }

function loadInitialState() {
  try {
    const parsed = JSON.parse(localStorage.getItem(STORAGE_KEY))
    // Older builds persisted mock orders/books here: keep only the auth keys.
    return parsed && typeof parsed === 'object'
      ? { customerEmail: parsed.customerEmail ?? null, admin: parsed.admin ?? null }
      : seedState
  } catch {
    return seedState
  }
}

function reducer(state, action) {
  switch (action.type) {
    case 'LOGIN_CUSTOMER':
      return { ...state, customerEmail: action.payload.email }
    case 'LOGOUT_CUSTOMER':
      return { ...state, customerEmail: null }
    case 'LOGIN_ADMIN':
      return { ...state, admin: { username: action.payload.username } }
    case 'LOGOUT_ADMIN':
      return { ...state, admin: null }
    default:
      return state
  }
}

const SessionCtx = createContext(null)

export function SessionProvider({ children }) {
  const [state, dispatch] = useReducer(reducer, undefined, loadInitialState)

  useEffect(() => {
    try {
      localStorage.setItem(STORAGE_KEY, JSON.stringify(state))
    } catch {
      // ponytail: private browsing / quota errors are non-fatal, just skip persisting
    }
  }, [state])

  return <SessionCtx.Provider value={{ state, dispatch }}>{children}</SessionCtx.Provider>
}

export function useSession() {
  const ctx = useContext(SessionCtx)
  if (!ctx) throw new Error('useSession must be used within SessionProvider')
  return ctx
}
