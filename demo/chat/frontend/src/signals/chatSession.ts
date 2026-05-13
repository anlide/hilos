import { SignalDefinition } from '@hilos/sdk/services/signals'
import { extractBrowserPagePayload, type BrowserPagePayload } from '@hilos/sdk/types'
import { parseSelfConnection, type SelfConnectionPayload } from '@/entities/frontendStateParsers'

const isRecord = (value: unknown): value is Record<string, unknown> => {
  return typeof value === 'object' && value !== null && !Array.isArray(value)
}

/**
 * `subscription_page_main` delivers main chat page state as browser tables.
 */
export const subscriptionPageMain = new SignalDefinition<
  'subscription_page_main',
  BrowserPagePayload
>('subscription_page_main', extractBrowserPagePayload)

/**
 * `self_connection_update` — current connection-local chat session state.
 */
export interface SelfConnectionUpdatePayload {
  selfConnection?: SelfConnectionPayload
}

export const selfConnectionUpdate = new SignalDefinition<
  'self_connection_update',
  SelfConnectionUpdatePayload
>('self_connection_update', (raw: unknown): SelfConnectionUpdatePayload | null => {
  if (!isRecord(raw)) return null
  if (!('selfConnection' in raw)) return 'frontend' in raw ? {} : null
  const selfConnection = parseSelfConnection(raw.selfConnection)
  return selfConnection === null ? null : { selfConnection }
})
