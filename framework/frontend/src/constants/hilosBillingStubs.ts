/**
 * Stub data for Hilos Billing admin section (demo).
 */

export type HilosBillingProviderId = 'stripe' | 'paypal' | 'yookassa' | 'cloudpayments'

export type HilosBillingProviderRow = {
  providerId: HilosBillingProviderId
  label: string
  mode: 'live' | 'test'
  status: 'connected' | 'error' | 'disabled'
  lastWebhookAt: string
}

export const hilosBillingProvidersStub: HilosBillingProviderRow[] = [
  {
    providerId: 'stripe',
    label: 'Stripe',
    mode: 'test',
    status: 'connected',
    lastWebhookAt: '2025-03-22 14:31:00',
  },
  {
    providerId: 'paypal',
    label: 'PayPal',
    mode: 'test',
    status: 'connected',
    lastWebhookAt: '2025-03-22 14:28:00',
  },
  {
    providerId: 'yookassa',
    label: 'YooKassa',
    mode: 'live',
    status: 'error',
    lastWebhookAt: '2025-03-21 18:00:00',
  },
  {
    providerId: 'cloudpayments',
    label: 'CloudPayments',
    mode: 'disabled',
    status: 'disabled',
    lastWebhookAt: '—',
  },
]

export type HilosBillingProviderConfigStub = {
  providerId: HilosBillingProviderId
  apiKeyHint: string
  webhookSecretHint: string
  currency: string
  statementDescriptor: string
  notes: string
}

export const hilosBillingProviderConfigById: Record<HilosBillingProviderId, HilosBillingProviderConfigStub> = {
  stripe: {
    providerId: 'stripe',
    apiKeyHint: 'sk_test_…4a2f',
    webhookSecretHint: 'whsec_…9c1b',
    currency: 'USD',
    statementDescriptor: 'HILOS DEMO',
    notes: 'Use test cards in dashboard. Webhook endpoint must return 200 within 10s.',
  },
  paypal: {
    providerId: 'paypal',
    apiKeyHint: 'client_id: AY…sandbox',
    webhookSecretHint: 'webhook_id: WH-…',
    currency: 'USD',
    statementDescriptor: 'HILOS DEMO',
    notes: 'Sandbox only for demo.',
  },
  yookassa: {
    providerId: 'yookassa',
    apiKeyHint: 'shopId: 123… / secret_key: live_…',
    webhookSecretHint: '—',
    currency: 'RUB',
    statementDescriptor: 'HILOS',
    notes: 'Last error: invalid credentials (stub).',
  },
  cloudpayments: {
    providerId: 'cloudpayments',
    apiKeyHint: '—',
    webhookSecretHint: '—',
    currency: '—',
    statementDescriptor: '—',
    notes: 'Not configured.',
  },
}

export type HilosBillingPaymentRow = {
  id: string
  at: string
  amount: string
  currency: string
  status: 'succeeded' | 'pending' | 'failed'
  customerHint: string
}

export const hilosBillingPaymentsByProviderStub: Record<HilosBillingProviderId, HilosBillingPaymentRow[]> = {
  stripe: [
    { id: 'pi_abc123', at: '2025-03-22 14:20', amount: '49.00', currency: 'USD', status: 'succeeded', customerHint: 'cus_…1' },
    { id: 'pi_def456', at: '2025-03-22 11:05', amount: '9.99', currency: 'USD', status: 'pending', customerHint: 'cus_…2' },
  ],
  paypal: [
    { id: 'PAY-7K…', at: '2025-03-22 10:00', amount: '19.00', currency: 'USD', status: 'succeeded', customerHint: 'payer@…' },
  ],
  yookassa: [
    { id: '2d7f…', at: '2025-03-21 17:55', amount: '1500.00', currency: 'RUB', status: 'failed', customerHint: '—' },
  ],
  cloudpayments: [],
}

export type HilosBillingRefundRow = {
  id: string
  at: string
  paymentId: string
  amount: string
  currency: string
  status: 'succeeded' | 'pending' | 'failed'
}

export const hilosBillingRefundsByProviderStub: Record<HilosBillingProviderId, HilosBillingRefundRow[]> = {
  stripe: [
    { id: 're_001', at: '2025-03-21 09:00', paymentId: 'pi_old001', amount: '49.00', currency: 'USD', status: 'succeeded' },
  ],
  paypal: [
    { id: 'REF-88…', at: '2025-03-20 16:30', paymentId: 'PAY-7K…', amount: '19.00', currency: 'USD', status: 'pending' },
  ],
  yookassa: [],
  cloudpayments: [],
}
