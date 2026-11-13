const SRC = 'https://checkout.razorpay.com/v1/checkout.js'

// The loader memoises its promise at module level, so load a fresh copy per test.
async function freshLoader() {
  vi.resetModules()
  return (await import('./razorpay')).loadRazorpay
}

function scripts() {
  return document.querySelectorAll(`script[src="${SRC}"]`)
}

afterEach(() => {
  document.head.innerHTML = ''
  delete window.Razorpay
})

test('injects the script once across calls and resolves with window.Razorpay on load', async () => {
  const loadRazorpay = await freshLoader()
  const first = loadRazorpay()
  const second = loadRazorpay()
  expect(scripts()).toHaveLength(1)

  const Ctor = function Razorpay() {}
  window.Razorpay = Ctor
  scripts()[0].onload()

  await expect(first).resolves.toBe(Ctor)
  await expect(second).resolves.toBe(Ctor)
})

test('rejects when the script errors', async () => {
  const loadRazorpay = await freshLoader()
  const p = loadRazorpay()
  scripts()[0].onerror()
  await expect(p).rejects.toThrow()
})

test('a failed load can be retried', async () => {
  const loadRazorpay = await freshLoader()
  const p = loadRazorpay()
  scripts()[0].onerror()
  await expect(p).rejects.toThrow()

  const retry = loadRazorpay()
  expect(scripts()).toHaveLength(1) // failed tag removed, fresh one added
  window.Razorpay = function Razorpay() {}
  scripts()[0].onload()
  await expect(retry).resolves.toBe(window.Razorpay)
})
