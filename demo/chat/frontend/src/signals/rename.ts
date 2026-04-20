import { SignalDefinition, parseEmptyPayload } from '@hilos/sdk/services/signals'
import type { ActionFailData } from '@hilos/sdk/types/websocket-messages'

/**
 * Known reason codes for rename failures.
 *
 * Initially only `empty` is enforced by the backend
 * (see ProfilePage::handleRename). Add `too_long`, `name_taken`,
 * `rate_limited`, etc. only when the backend actually emits them.
 */
export type RenameFailReason = 'empty'

const isRecord = (value: unknown): value is Record<string, unknown> => {
  return typeof value === 'object' && value !== null && !Array.isArray(value)
}

const isRenameFailReason = (value: unknown): value is RenameFailReason => {
  return value === 'empty'
}

const parseRenameFailData = (raw: unknown): ActionFailData<RenameFailReason> | null => {
  if (!isRecord(raw)) return null
  if (!isRenameFailReason(raw.reason) || typeof raw.message !== 'string') {
    return null
  }
  return { reason: raw.reason, message: raw.message }
}

/**
 * Ack signal sent only to the initiator of `{action: 'rename'}` on success.
 * Envelope carries `outcome: 'success'`; no data body.
 */
export const renameSuccess = new SignalDefinition<'rename_success', undefined, 'success'>(
  'rename_success',
  parseEmptyPayload,
  'success',
)

/**
 * Ack signal sent only to the initiator of `{action: 'rename'}` on failure.
 *
 * Envelope carries `outcome: 'fail'`; data is a standard
 * {@link ActionFailData} with a narrowed `reason` enum and a mandatory
 * human-readable `message` (backend owns the text for i18n).
 */
export const renameFail = new SignalDefinition<
  'rename_fail',
  ActionFailData<RenameFailReason>,
  'fail'
>('rename_fail', parseRenameFailData, 'fail')
