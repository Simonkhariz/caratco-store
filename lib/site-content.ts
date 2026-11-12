import { SHOP, option1Word, option2Word } from '@/lib/shop';
/**
 * Editable copy for the storefront information pages (/about, /faq, /shipping, /returns,
 * /privacy, /terms, /contact).
 *
 * STORE OWNER: please read and edit this file before launch. Everything written here describes
 * how this store's software actually works (payments, payment windows, delivery fees, tracking,
 * sign-in, emails, refunds). Anything that is a business decision — return windows, who pays
 * return delivery, how long data is kept, your legal entity — is left neutral and marked with
 * `TODO(store owner)`. Do not publish numbers or promises you cannot keep.
 *
 * Contact details (email, phone, WhatsApp, dispatch address) are NOT written here: they come
 * live from admin → Store settings, so they stay correct in one place.
 */

export interface ContentSection {
  heading: string;
  /** Paragraphs. Keep them short; plain text only. */
  body: string[];
}

export interface FaqItem {
  question: string;
  answer: string;
}

/** How long an unpaid order holds its items (ORDER_PAYMENT_WINDOW_MINUTES / ORDER_MANUAL_PAYMENT_WINDOW_HOURS). */
export interface PaymentWindows {
  gatewayMinutes: number;
  manualHours: number;
}

// The backend's defaults, used only when its values could not be loaded.
export const DEFAULT_PAYMENT_WINDOWS: PaymentWindows = { gatewayMinutes: 60, manualHours: 24 };

/** The windows from GET /checkout/payment-methods, or the defaults. */
export function paymentWindowsFrom(res: { payment_window_minutes?: number; manual_payment_window_hours?: number } | null | undefined): PaymentWindows {
  const minutes = Number(res?.payment_window_minutes);
  const hours = Number(res?.manual_payment_window_hours);
  return {
    gatewayMinutes: Number.isFinite(minutes) && minutes > 0 ? minutes : DEFAULT_PAYMENT_WINDOWS.gatewayMinutes,
    manualHours: Number.isFinite(hours) && hours > 0 ? hours : DEFAULT_PAYMENT_WINDOWS.manualHours,
  };
}

const plural = (n: number, unit: string) => `${n} ${unit}${n === 1 ? '' : 's'}`;

export const ABOUT: { intro: string; sections: ContentSection[] } = {
  // TODO(store owner): replace the intro with your own story (who you are, where you are based).
  intro: `${SHOP.name} is an ${SHOP.kind} delivering across Nigeria.`,
  sections: [
    {
      heading: 'How shopping here works',
      body: [
        `Browse the catalogue, choose your ${option1Word} and ${option2Word}, and check out as a guest or with your account. Prices and stock you see are checked again by our server when you place the order.`,
        'You can pay by card or bank transfer through our payment partners Kora and Monnify, or arrange a bank transfer with us on WhatsApp.',
        'Delivery fees depend on the state you are delivering to (and, for some areas, how many items you order) and are shown before you pay.',
      ],
    },
    {
      heading: 'After you order',
      body: [
        'We email you when your order is received, when payment is confirmed, when it ships (with the courier and tracking number) and if it is refunded.',
        'You can follow your order at any time on the Track order page with your order number and the email you checked out with.',
      ],
    },
  ],
};

export const SHIPPING: { intro: string; sections: ContentSection[] } = {
  intro: 'We deliver to the states listed below. The fee for your state is added at checkout before you pay.',
  sections: [
    {
      heading: 'Delivery fees',
      body: [
        'The table on this page is live: it shows the delivery zones and fees our checkout uses right now. Where a fee covers a set number of items, each extra item adds the amount shown.',
        // Only a shop that charges more for large items says so (features.heavyDelivery).
        ...(SHOP.features.heavyDelivery ? ['Large items cost more to deliver. Where a zone shows a fee for large items, it is added for each one in your order.'] : []),
        'If your state is not listed, checkout will tell you that we do not deliver there yet.',
      ],
    },
    {
      heading: 'When your order ships',
      body: [
        'We start preparing your order once payment is confirmed. When it ships we email you the courier name and tracking number, and the same details appear on the Track order page.',
        'Delivery estimates are given per zone and are estimates, not guarantees.',
        // TODO(store owner): add your packing / dispatch times if you want to promise them (e.g. "orders paid before 2pm ship the same day").
      ],
    },
  ],
};

export const RETURNS: { intro: string; sections: ContentSection[] } = {
  intro: 'If something is wrong with your order, contact us with your order number and we will help.',
  sections: [
    {
      heading: 'Starting a return',
      body: [
        // TODO(store owner): state how many days after delivery customers can request a return, and which items cannot be returned (e.g. underwear, items marked final sale).
        'Contact us by email or WhatsApp with your order number, the items you want to return and the reason. We will confirm whether the item can be returned and how to send it back.',
        // TODO(store owner): state who pays for return delivery and the condition items must be in.
      ],
    },
    {
      heading: 'Refunds',
      body: [
        'Once a refund is approved we send the money back through the original payment route or by bank transfer, and email you when it has been sent.',
        'Depending on your bank, a refund can take a few business days to appear in your account.',
        'If an order is cancelled after you have paid, you are refunded in full.',
      ],
    },
  ],
};

export const PRIVACY: { intro: string; sections: ContentSection[] } = {
  // TODO(store owner): have this reviewed for compliance with the Nigeria Data Protection Act (NDPA) and name the data controller (your business name and address).
  intro: 'This page explains what personal information the store collects and why.',
  sections: [
    {
      heading: 'What we collect',
      body: [
        'When you order: your name, email address, phone number and delivery address, and the items you bought.',
        'When you create an account: your name and email address. You can sign in with a password or with a one-time link sent to your email; passwords are stored only as secure hashes.',
        'When you subscribe to the newsletter: your email address.',
      ],
    },
    {
      heading: 'Payments',
      body: [
        'Card and bank-transfer payments are handled by Kora and Monnify. Your card details are entered on their pages, not ours, and are not stored by this store. We keep the payment reference and amount so we can match payments to orders.',
      ],
    },
    {
      heading: 'How we use it',
      body: [
        'To process and deliver your order, send you order emails, answer your questions and handle refunds.',
        'Newsletters go to people who subscribed and to customers with a confirmed email address or a paid order. Every newsletter has a one-click unsubscribe link, and once you unsubscribe you are not added back.',
      ],
    },
    {
      heading: 'Your choices',
      body: [
        'You can unsubscribe from the newsletter at any time using the link in any newsletter email.',
        'To ask what information we hold about you, or to have it corrected or deleted, contact us using the details on the Contact page.',
        // TODO(store owner): state how long you keep order and account records (and why, e.g. tax records).
      ],
    },
  ],
};

export const termsContent = (w: PaymentWindows = DEFAULT_PAYMENT_WINDOWS): { intro: string; sections: ContentSection[] } => ({
  // TODO(store owner): have these terms reviewed by a lawyer and add your legal business name and registered address.
  intro: 'These terms describe how ordering from this store works.',
  sections: [
    {
      heading: 'Prices and payment',
      body: [
        'Prices are in Nigerian naira (NGN). The total you pay — items, any discount and the delivery fee for your state — is shown at checkout before you pay.',
        `An order is confirmed only when payment is verified. Unpaid orders reserve their items for ${plural(w.gatewayMinutes, 'minute')} when paying online, or ${plural(w.manualHours, 'hour')} when paying by bank transfer arranged on WhatsApp; after that the order is cancelled and the items are released.`,
      ],
    },
    {
      heading: 'Stock and availability',
      body: [
        'Stock is checked when you place your order. If an item sells out before you check out, checkout will tell you and you will not be charged for it.',
      ],
    },
    {
      heading: 'Promo codes',
      body: ['Promo codes are subject to their dates and usage limits and are checked when you place your order.'],
    },
    {
      heading: 'Cancellations, returns and refunds',
      body: ['See the Returns page for how returns and refunds work.'],
    },
  ],
});

export const faqContent = (w: PaymentWindows = DEFAULT_PAYMENT_WINDOWS): FaqItem[] => [
  {
    question: 'How do I pay?',
    answer:
      'By card or bank transfer through Kora or Monnify at checkout, or by bank transfer arranged with us on WhatsApp. Your order is confirmed as soon as the payment is verified.',
  },
  {
    question: 'How long do I have to pay?',
    answer: `Your items are reserved for ${plural(w.gatewayMinutes, 'minute')} when you pay online, or ${plural(w.manualHours, 'hour')} if you arrange a bank transfer on WhatsApp. If payment isn't completed in time the order is cancelled and the items are released.`,
  },
  {
    question: 'My payment went through but the order still says "awaiting payment".',
    answer:
      'Bank transfers can take a few minutes to be confirmed. Open the Track order page and refresh. If it still has not updated after some time, contact us with your order number and payment reference.',
  },
  {
    question: 'How much is delivery?',
    answer: 'It depends on your state. See the Shipping page for the current fees; the exact fee is shown at checkout before you pay.',
  },
  {
    question: 'How do I track my order?',
    answer:
      'Use the Track order page with your order number and the email you used at checkout. When your order ships we also email you the courier and tracking number.',
  },
  {
    question: 'Do I need an account?',
    answer:
      'No, you can check out as a guest. If you sign in with the same email later (we email you a one-time sign-in link), your earlier orders appear under My orders.',
  },
  {
    question: 'Can I return something?',
    answer: 'Contact us with your order number and the item. See the Returns page for how returns and refunds work.',
  },
];
