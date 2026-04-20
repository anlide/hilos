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
