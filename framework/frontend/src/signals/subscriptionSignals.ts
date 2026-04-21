import { SignalDefinition, parseEmptyPayload } from '../services/signals'

/**
 * Signal: `subscription_updated` — bookkeeping notification, no payload.
 *
 * Sent by the server when subscription params changed and the client should
 * consider the current page view stale. Currently consumed as a no-op.
 */
export const subscriptionUpdated = new SignalDefinition<'subscription_updated', undefined>(
  'subscription_updated',
  parseEmptyPayload,
)

/**
 * Prefix used by page-subscription signals (`subscription_page_<page>`).
 *
 * The framework registers a prefix handler on this value to extract
 * `tables` payloads in a uniform way for every Hilos admin page.
 */
export const SUBSCRIPTION_PAGE_PREFIX = 'subscription_page_'

/**
 * Page subscription error payload.
 *
 * Sent when page subscription encounters an error (e.g. resource not found,
 * access denied). The subscription remains active so parent updates continue.
 */
export interface PageSubscriptionError {
  page: string
  httpCode: number
  errorCode: string
  message: string
}

/**
 * Signal: `subscription_page_error` — page subscription error notification.
 *
 * Sent when AbstractPage::onSubscribe throws PageSubscriptionException.
 * The subscription stays active, allowing parent entity updates (e.g. breadcrumbs)
 * to continue. Frontend should display error state without redirecting.
 */
export const subscriptionPageError = new SignalDefinition<
  'subscription_page_error',
  PageSubscriptionError
>(
  'subscription_page_error',
  (data) => {
    if (!data || typeof data !== 'object') return undefined
    const { page, httpCode, errorCode, message } = data as any
    if (
      typeof page !== 'string' ||
      typeof httpCode !== 'number' ||
      typeof errorCode !== 'string' ||
      typeof message !== 'string'
    ) {
      return undefined
    }
    return { page, httpCode, errorCode, message }
  },
)
