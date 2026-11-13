const SRC = 'https://checkout.razorpay.com/v1/checkout.js'

let promise

// Injects Razorpay Checkout once; resolves with the `window.Razorpay` constructor.
export function loadRazorpay() {
  if (promise) return promise
  promise = new Promise((resolve, reject) => {
    const script = document.createElement('script')
    script.src = SRC
    script.onload = () => (window.Razorpay ? resolve(window.Razorpay) : fail(new Error('Razorpay unavailable')))
    script.onerror = () => fail(new Error('Failed to load Razorpay'))
    // Drop the memo and the dead tag on failure so a later call can retry.
    function fail(err) {
      promise = undefined
      script.remove()
      reject(err)
    }
    document.head.appendChild(script)
  })
  return promise
}
