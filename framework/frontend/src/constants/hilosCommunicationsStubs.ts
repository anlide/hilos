/**
 * Stub data for Hilos Communications admin section (demo).
 */

export type HilosCommunicationsChannelId =
  | 'email'
  | 'sms'
  | 'push'
  | 'telegram'
  | 'slack'

export type HilosCommunicationsChannelRow = {
  channelId: HilosCommunicationsChannelId
  label: string
  providerName: string
  status: 'healthy' | 'degraded' | 'disabled'
  lastDeliveryAt: string
  queueDepth: number
  templatesCount: number
}

export const hilosCommunicationsChannelsStub: HilosCommunicationsChannelRow[] = [
  {
    channelId: 'email',
    label: 'Email',
    providerName: 'SMTP / SES',
    status: 'healthy',
    lastDeliveryAt: '2025-03-22 14:32:01',
    queueDepth: 3,
    templatesCount: 42,
  },
  {
    channelId: 'sms',
    label: 'SMS',
    providerName: 'Twilio',
    status: 'degraded',
    lastDeliveryAt: '2025-03-22 13:58:44',
    queueDepth: 12,
    templatesCount: 8,
  },
  {
    channelId: 'push',
    label: 'Push',
    providerName: 'FCM',
    status: 'healthy',
    lastDeliveryAt: '2025-03-22 14:29:00',
    queueDepth: 0,
    templatesCount: 15,
  },
  {
    channelId: 'telegram',
    label: 'Telegram',
    providerName: 'Bot API',
    status: 'healthy',
    lastDeliveryAt: '2025-03-22 12:10:00',
    queueDepth: 1,
    templatesCount: 4,
  },
  {
    channelId: 'slack',
    label: 'Slack',
    providerName: 'Slack Web API',
    status: 'disabled',
    lastDeliveryAt: '—',
    queueDepth: 0,
    templatesCount: 0,
  },
]

export type HilosCommunicationsDeliveryRow = {
  id: string
  at: string
  recipient: string
  template: string
  status: 'sent' | 'failed' | 'queued'
}

export const hilosCommunicationsDeliveriesByChannelStub: Record<
  HilosCommunicationsChannelId,
  HilosCommunicationsDeliveryRow[]
> = {
  email: [
    { id: 'd-1001', at: '2025-03-22 14:30', recipient: 'user@example.com', template: 'welcome', status: 'sent' },
    { id: 'd-1002', at: '2025-03-22 14:15', recipient: 'ops@example.com', template: 'alert', status: 'sent' },
    { id: 'd-1003', at: '2025-03-22 14:00', recipient: 'bounce@invalid', template: 'digest', status: 'failed' },
  ],
  sms: [
    { id: 'd-2001', at: '2025-03-22 13:55', recipient: '+1***0001', template: 'otp', status: 'sent' },
    { id: 'd-2002', at: '2025-03-22 13:40', recipient: '+1***0002', template: 'otp', status: 'queued' },
  ],
  push: [
    { id: 'd-3001', at: '2025-03-22 14:28', recipient: 'device:abc…', template: 'mention', status: 'sent' },
  ],
  telegram: [
    { id: 'd-4001', at: '2025-03-22 12:05', recipient: 'chat:-100…', template: 'deploy', status: 'sent' },
  ],
  slack: [],
}
