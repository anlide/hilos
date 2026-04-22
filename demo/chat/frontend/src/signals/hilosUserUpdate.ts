import { SignalDefinition, parseEmptyPayload } from '@hilos/sdk/services/signals'
import type { ActionFailData } from '@hilos/sdk/types/websocket-messages'

const isRecord = (value: unknown): value is Record<string, unknown> => {
  return typeof value === 'object' && value !== null && !Array.isArray(value)
}

const parseHilosUserUpdateFailData = (raw: unknown): ActionFailData | null => {
  if (!isRecord(raw)) return null
  if (typeof raw.reason !== 'string') {
    return null
  }
  return { reason: raw.reason }
}

/**
 * Ack signal sent only to the initiator of `{action: 'hilos_user_update'}` on success.
 * Envelope carries `outcome: 'success'`; no data body.
 */
export const hilosUserUpdateSuccess = new SignalDefinition<
  'hilos_user_update_success',
  undefined,
  'success'
>('hilos_user_update_success', parseEmptyPayload, 'success')

/**
 * Ack signal sent only to the initiator of `{action: 'hilos_user_update'}` on failure.
 *
 * Envelope carries `outcome: 'fail'`; data is a standard {@link ActionFailData}
 * with a mandatory human-readable `reason` (backend owns the text for i18n).
 */
export const hilosUserUpdateFail = new SignalDefinition<
  'hilos_user_update_fail',
  ActionFailData,
  'fail'
>('hilos_user_update_fail', parseHilosUserUpdateFailData, 'fail')
