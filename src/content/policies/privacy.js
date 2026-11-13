import { CONTACT_SECTION } from './shared'

export default {
  path: '/privacy',
  title: 'Privacy Policy',
  sections: [
    { heading: 'What we collect', items: [
      'Account details: name, email address, phone number (if given) and a password, which we store only in hashed form.',
      'Hard copy orders: the delivery name, phone, address, city, district, state and pincode you enter.',
      'Orders and payments: your order details and the order and payment identifiers returned by Razorpay.',
      'Referral partner applications: name, qualification, place, district, phone and WhatsApp numbers, GPay / PhonePe details (optional) and Gmail ID.',
      'Referral codes: if you arrive through a referral link or enter a code, we record which partner it belongs to for that order.',
      'Contact form messages: the name, email, phone (optional), subject (optional) and message you send. These are emailed to our admin.',
      'Technical data: your IP address, used to limit repeated requests (rate limiting) and protect the service.',
    ] },
    { heading: 'Payments', paras: ['Payments are processed by Razorpay. Your card, UPI or bank details are entered with Razorpay and are not stored by us.'] },
    { heading: 'How we use it', items: [
      'To create and run your account, take payment, give you access to digital books and deliver and track hard copies.',
      'To account for referral commission.',
      'To reply to your messages.',
      'To keep the service secure and prevent misuse.',
    ] },
    { heading: 'Sharing', paras: ['We share data only as needed to run the service, for example with Razorpay for payments and with the courier or delivery partner for hard copy delivery. [OWNER TO CONFIRM ANY OTHER SERVICE PROVIDERS, E.G. EMAIL / HOSTING]'] },
    { heading: 'Your choices', paras: ['To ask about, correct or delete your data, contact us using the details below. [OWNER TO CONFIRM RETENTION PERIOD AND ANY LEGAL REQUIREMENTS]'] },
    CONTACT_SECTION,
  ],
}
