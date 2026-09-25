import { createContext, useContext, useEffect, useReducer } from 'react'
import { seedBooks } from '../data/books'
import { seedReferralPartners } from '../data/referralPartners'

const STORAGE_KEY = 'vtee-session'

const seedState = {
  customerEmail: null,
  admin: null,
  books: seedBooks,
  referralPartners: seedReferralPartners,
  orders: [],
  commissionRate: { digital: 25, hardcopy: 25 },
}

// ponytail: single-user mock demo — persist the whole reducer state as-is,
// no merge with fresh seed data needed.
function loadInitialState() {
  try {
    const raw = localStorage.getItem(STORAGE_KEY)
    if (!raw) return seedState
    const parsed = JSON.parse(raw)
    return parsed && typeof parsed === 'object' ? parsed : seedState
  } catch {
    return seedState
  }
}

let nextOrderId = 1
let nextPartnerId = seedReferralPartners.length + 1
let nextBookId = seedBooks.length + 1

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

    case 'REGISTER_REFERRAL': {
      const partner = {
        ...action.payload,
        id: `r${nextPartnerId++}`,
        referralCode: `VTP-R${nextPartnerId - 1}`,
        approved: false,
        active: false,
      }
      return { ...state, referralPartners: [...state.referralPartners, partner] }
    }
    case 'APPROVE_REFERRAL':
      return {
        ...state,
        referralPartners: state.referralPartners.map(p =>
          p.id === action.payload.id ? { ...p, approved: true, active: true } : p
        ),
      }
    case 'REJECT_REFERRAL':
      return {
        ...state,
        referralPartners: state.referralPartners.map(p =>
          p.id === action.payload.id ? { ...p, approved: false, active: false } : p
        ),
      }
    case 'TOGGLE_REFERRAL_ACTIVE':
      return {
        ...state,
        referralPartners: state.referralPartners.map(p =>
          p.id === action.payload.id ? { ...p, active: !p.active } : p
        ),
      }

    case 'ADD_BOOK': {
      const book = { ...action.payload, id: `b${nextBookId++}`, published: false }
      return { ...state, books: [...state.books, book] }
    }
    case 'UPDATE_BOOK':
      return {
        ...state,
        books: state.books.map(b => (b.id === action.payload.id ? { ...b, ...action.payload.changes } : b)),
      }
    case 'DELETE_BOOK':
      return { ...state, books: state.books.filter(b => b.id !== action.payload.id) }
    case 'TOGGLE_PUBLISH':
      return {
        ...state,
        books: state.books.map(b => (b.id === action.payload.id ? { ...b, published: !b.published } : b)),
      }

    case 'CREATE_ORDER': {
      const order = {
        ...action.payload,
        id: `o${nextOrderId++}`,
        paymentStatus: 'pending',
        date: new Date().toISOString(),
      }
      return { ...state, orders: [...state.orders, order] }
    }
    case 'MARK_ORDER_PAID':
      return {
        ...state,
        orders: state.orders.map(o => (o.id === action.payload.orderId ? { ...o, paymentStatus: 'paid' } : o)),
      }

    case 'SET_COMMISSION_RATE':
      return { ...state, commissionRate: { ...state.commissionRate, ...action.payload } }

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
