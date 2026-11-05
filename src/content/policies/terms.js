import { CONTACT_SECTION } from './shared'

export default {
  path: '/terms',
  title: 'Terms and Conditions',
  sections: [
    { heading: 'About these terms', paras: ['These terms apply when you use this website, create an account, or buy books from Victory Today Educational Enterprises. By creating an account or placing an order you agree to them.'] },
    { heading: 'Accounts', items: [
      'You sign up with your name, email and password and log in to buy and read books.',
      'Keep your login details to yourself. Digital book access is tied to your account.',
    ] },
    { heading: 'Books and orders', items: [
      'Books are sold as Digital (read online) or Hard Copy (delivered to the address you give).',
      'The price, including any shipping and tax shown, is displayed as a breakdown at checkout before you pay.',
      'An order is confirmed once payment succeeds.',
      'Hard copy orders get a tracking code, which you can use on the Track Order page.',
    ] },
    { heading: 'Payments', paras: ['Payments are processed by Razorpay using the methods Razorpay offers at checkout, such as card, UPI and netbanking where available. We do not receive or store your card details.'] },
    { heading: 'Digital books', paras: ['Digital books are read online in our secure reader. They cannot be downloaded. Access is personal to your account and is governed by the Digital Book Delivery / Access Policy.'] },
    { heading: 'Cancellations and refunds', paras: ['All purchases are final. Please read the Cancellation and Refund Policy before buying.'] },
    { heading: 'Acceptable use', items: [
      'Do not copy, record, share or resell book content or access codes.',
      'Do not try to bypass the reader protections, watermark or page limits.',
      'We may suspend or revoke access if these terms are broken.',
    ] },
    { heading: 'Referral partners', paras: ['Approved referral partners earn a commission of Rs 25 for each online (digital) sale and Rs 25 for each hard copy sale made through their referral code.'] },
    { heading: 'Changes', paras: ['We may update these terms and the other policies from time to time. The date at the top of each policy shows when it was last updated.'] },
    CONTACT_SECTION,
  ],
}
