import { SignalDefinition, parseEmptyPayload } from '@hilos/sdk/services/signals'
import type { ActionFailData } from '@hilos/sdk/types/websocket-messages'

/**
 * Known reason codes for hilos_user_update failures.
 *
 * Mirrors the set emitted by {@see \Demo\Chat\Pages\Hilos\Users\UserPage}.
 * Extend only when the backend starts emitting a new code.
 */
export type HilosUserUpdateFailReason =
  | 'invalid_id'
  | 'empty_name'
  | 'not_found'
  | 'rename_failed'

const isRecord = (value: unknown): value is Record<string, unknown> => {
  return typeof value === 'object' && value !== null && !Array.isArray(value)
}

const isHilosUserUpdateFailReason = (value: unknown): value is HilosUserUpdateFailReason => {
  return (
    value === 'invalid_id' ||
    value === 'empty_name' ||
    value === 'not_found' ||
    value === 'rename_failed'
  )
}

const parseHilosUserUpdateFailData = (
  raw: unknown,
): ActionFailData<HilosUserUpdateFailReason> | null => {
  if (!isRecord(raw)) return null
  if (!isHilosUserUpdateFailReason(raw.reason) || typeof raw.message !== 'string') {
    return null
  }
  return { reason: raw.reason, message: raw.message }
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
 * with a narrowed `reason` enum and a mandatory human-readable `message`
 * (backend owns the text for i18n).
 */
export const hilosUserUpdateFail = new SignalDefinition<
  'hilos_user_update_fail',
  ActionFailData<HilosUserUpdateFailReason>,
  'fail'
>('hilos_user_update_fail', parseHilosUserUpdateFailData, 'fail')
