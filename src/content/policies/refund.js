import { CONTACT_SECTION } from './shared'

export default {
  path: '/refund-policy',
  title: 'Cancellation and Refund Policy',
  sections: [
    { heading: 'All purchases are final', paras: ['There are no general refunds or cancellations once a purchase is made. This applies to Digital and Hard Copy books. Please check the book, the purchase type, the address and the price breakdown before you pay.'] },
    { heading: 'Exceptions', paras: ['The following cases are handled individually. Contact us and we will review your case and respond.'], items: [
      'You were charged more than once for the same order (duplicate payment).',
      'Money was deducted from your account but your order failed or was not created.',
      'A refund is required by law.',
    ] },
    { heading: 'How to ask', paras: ['Use the contact form on our home page or email us, and include your order number or the Razorpay payment id, the email on your account, and what went wrong. We will review and respond.'] },
    CONTACT_SECTION,
  ],
}
