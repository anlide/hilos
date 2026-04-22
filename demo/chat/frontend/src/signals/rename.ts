import { SignalDefinition, parseEmptyPayload } from '@hilos/sdk/services/signals'
import type { ActionFailData } from '@hilos/sdk/types/websocket-messages'

const isRecord = (value: unknown): value is Record<string, unknown> => {
  return typeof value === 'object' && value !== null && !Array.isArray(value)
}

const parseRenameFailData = (raw: unknown): ActionFailData | null => {
  if (!isRecord(raw)) return null
  if (typeof raw.reason !== 'string') {
    return null
  }
  return { reason: raw.reason }
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
 * {@link ActionFailData} with a mandatory human-readable `reason`
 * (backend owns the text for i18n).
 */
export const renameFail = new SignalDefinition<
  'rename_fail',
  ActionFailData,
  'fail'
>('rename_fail', parseRenameFailData, 'fail')
