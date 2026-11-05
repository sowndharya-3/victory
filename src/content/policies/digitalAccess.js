import { CONTACT_SECTION } from './shared'

export default {
  path: '/digital-access',
  title: 'Digital Book Delivery / Access Policy',
  sections: [
    { heading: 'How digital books are delivered', items: [
      'After payment succeeds you are shown a one-time access code for the book.',
      'Redeem the code in My Books to add the book to your account.',
      'You read the book online in our secure reader. Digital books are not downloadable.',
    ] },
    { heading: 'Access rules', items: [
      'Access is tied to your account and cannot be transferred or shared.',
      'A code can be redeemed only once. Keep it private.',
      'The reader shows a watermark overlay on pages.',
      'The reader limits how many pages can be opened within a time window. If you go too fast, wait a short while and try again.',
    ] },
    { heading: 'Hard copy books', paras: ['Hard copies are ordered with a delivery address and tracked with the tracking code shown for your order, on the Track Order page.'] },
    { heading: 'Misuse', paras: ['We may revoke access to a book if the access rules or Terms and Conditions are broken.'] },
    { heading: 'Problems with access', paras: ['If you paid but cannot get or redeem your code, contact us. See the Cancellation and Refund Policy for payment problems.'] },
    CONTACT_SECTION,
  ],
}
