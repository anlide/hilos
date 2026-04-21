import { SignalDefinition } from '@hilos/sdk/services/signals'

const isRecord = (value: unknown): value is Record<string, unknown> => {
  return typeof value === 'object' && value !== null && !Array.isArray(value)
}

/**
 * `subscription_page_hilos_user` confirms that the single-user snapshot was sent.
 * The optional user entity rides in `entities.full.users` and is applied globally
 * before this handler runs.
 */
export interface SubscriptionPageHilosUserPayload {
  userId: number
}

const parseSubscriptionPageHilosUser = (
  raw: unknown,
): SubscriptionPageHilosUserPayload | null => {
  if (!isRecord(raw) || typeof raw.userId !== 'number') return null
  return { userId: raw.userId }
}

export const subscriptionPageHilosUser = new SignalDefinition<
  'subscription_page_hilos_user',
  SubscriptionPageHilosUserPayload
>('subscription_page_hilos_user', parseSubscriptionPageHilosUser)
