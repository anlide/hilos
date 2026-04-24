import { SignalDefinition } from '../services/signals'

const isRecord = (value: unknown): value is Record<string, unknown> => {
  return typeof value === 'object' && value !== null && !Array.isArray(value)
}

export interface PageActionErrorPayload {
  action: string
  reason: string
}

/**
 * Signal: `action_error` - default failure report for page actions.
 *
 * Emitted by AbstractPage::onActionException() when a page action throws and
 * the page does not override the exception hook with a more specific contract.
 */
export const actionError = new SignalDefinition<
  'action_error',
  PageActionErrorPayload,
  'fail'
>(
  'action_error',
  (raw: unknown): PageActionErrorPayload | null => {
    if (!isRecord(raw)) {
      return null
    }
    const { action, reason } = raw
    if (typeof action !== 'string' || typeof reason !== 'string') {
      return null
    }
    return { action, reason }
  },
  'fail',
)
