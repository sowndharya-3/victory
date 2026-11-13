import { captureReferralFromUrl, getStoredReferral } from './referral'

afterEach(() => {
  vi.restoreAllMocks()
})

test('stores the ref param uppercased', () => {
  captureReferralFromUrl('?ref=vtp-abc')
  expect(getStoredReferral()).toBe('VTP-ABC')
})

test('caps the code at 30 characters', () => {
  captureReferralFromUrl(`?ref=${'a'.repeat(50)}`)
  expect(getStoredReferral()).toBe('A'.repeat(30))
})

test('ignores a missing or empty ref and keeps any earlier code', () => {
  expect(getStoredReferral()).toBe('')
  captureReferralFromUrl('?x=1')
  captureReferralFromUrl('?ref=')
  expect(getStoredReferral()).toBe('')
  captureReferralFromUrl('?ref=vtp-1')
  captureReferralFromUrl('?foo=bar')
  expect(getStoredReferral()).toBe('VTP-1')
})

test('ignores garbage characters', () => {
  captureReferralFromUrl('?ref=<script>')
  captureReferralFromUrl('?ref=a b')
  expect(getStoredReferral()).toBe('')
})

test('survives a throwing localStorage', () => {
  vi.spyOn(Storage.prototype, 'setItem').mockImplementation(() => { throw new Error('quota') })
  vi.spyOn(Storage.prototype, 'getItem').mockImplementation(() => { throw new Error('denied') })
  expect(() => captureReferralFromUrl('?ref=vtp-1')).not.toThrow()
  expect(getStoredReferral()).toBe('')
})
