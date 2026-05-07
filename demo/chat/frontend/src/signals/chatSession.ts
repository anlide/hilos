import { SignalDefinition } from '@hilos/sdk/services/signals'
import { parseSelfConnection, type SelfConnectionPayload } from '@/entities/frontendStateParsers'

const isRecord = (value: unknown): value is Record<string, unknown> => {
  return typeof value === 'object' && value !== null && !Array.isArray(value)
}

/**
 * Chat session fields merged by ChatEventSignalDTO when
 * the main page includes a connection-local session summary.
 */
export interface ChatSessionFields {
  selfConnection?: SelfConnectionPayload
  /** Retain the raw payload for forwarding to the legacy prefix/table handlers. */
  raw: Record<string, unknown>
}

const parseChatSessionFields = (raw: unknown): ChatSessionFields | null => {
  if (!isRecord(raw)) return null
  const result: ChatSessionFields = { raw }
  if ('selfConnection' in raw) {
    const selfConnection = parseSelfConnection(raw.selfConnection)
    if (selfConnection === null) return null
    result.selfConnection = selfConnection
  }
  return result
}

/**
 * `subscription_page_main` delivers the user's own chat page state,
 * including outbound moderation, draft attachments, and ongoing binary upload progress.
 */
export const subscriptionPageMain = new SignalDefinition<
  'subscription_page_main',
  ChatSessionFields
>('subscription_page_main', parseChatSessionFields)

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
