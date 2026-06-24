// The page-scope binder: the one-call wiring an end project would otherwise
// repeat verbatim — open the page-subscription manager over the app's scopes
// and route every page_response project signal into the current page scope. The
// PageSubscription manager and the normalizer do the work; this binds them to a
// connection so a project never re-implements the projectSignal plumbing. The
// only project input is the per-slot entity types for the page payloads.

import { type HilosConnection } from '../connection/HilosConnection.js'
import {
  SIGNAL_TYPE_PAGE_RESPONSE,
  SIGNAL_TYPE_PAGE_SUBSCRIPTION_ERROR,
} from '../protocol/constants.js'
import {
  pageSubscriptionErrorSchema,
  type PageSubscriptionError,
} from '../protocol/pageError.js'
import {
  pageResponseSchema,
  type PageResponseWire,
} from '../protocol/scopePayload.js'
import { type NormalizerOptions } from '../state/normalizer.js'
import { type ScopeManager } from '../state/ScopeManager.js'
import { PageSubscription } from './PageSubscription.js'

/**
 * The page_response schema keyed for a connection's `projectSchemas`. A project
 * spreads it into its own schema map so the parse boundary validates the page
 * payloads {@link bindPageScope} ingests. The framework owns this schema, so a
 * project never restates the `{ page_response: pageResponseSchema }` pair.
 */
export const PAGE_SIGNAL_SCHEMAS = {
  [SIGNAL_TYPE_PAGE_RESPONSE]: pageResponseSchema,
  [SIGNAL_TYPE_PAGE_SUBSCRIPTION_ERROR]: pageSubscriptionErrorSchema,
}

/**
 * Open the page-subscription manager and route every page_response into the
 * current page scope, dropping a late one for a page already left (the
 * stale-signal guard is the manager's). The returned manager is what the
 * navigator subscribes the URL's page through.
 *
 * @param connection The application's Hilos connection.
 * @param scopes The application's scope-partitioned stores.
 * @param options Per-slot entity types applied to every page payload's slots.
 */
export function bindPageScope(
  connection: HilosConnection,
  scopes: ScopeManager,
  options: NormalizerOptions = {},
): PageSubscription {
  const pages = new PageSubscription(connection, scopes)
  connection.on('projectSignal', (signal) => {
    if (signal.type === SIGNAL_TYPE_PAGE_RESPONSE) {
      // Validated against pageResponseSchema at the parse boundary; this cast
      // is the declared typed selector for that schema's output.
      const response = signal.data as PageResponseWire
      pages.ingestPageResponse(response.page, response.payload, options)
    } else if (signal.type === SIGNAL_TYPE_PAGE_SUBSCRIPTION_ERROR) {
      // Validated against pageSubscriptionErrorSchema at the parse boundary;
      // the typed selector for that schema's output.
      pages.handleSubscriptionError(signal.data as PageSubscriptionError)
    }
  })

  return pages
}
