// The page-scope binder opens the page-subscription manager over the app's scopes
// and routes page-response project signals to the current subscription. The
// PageSubscription manager and the normalizer do the work; this binds them to a
// connection so projects share the projectSignal plumbing.

import {
  type HilosConnection,
  admittedPageDeliveries,
  hasCurrentSessionAnswer,
  isProjectSignalFromCurrentSocket,
  pageDeliveryAdmissions,
} from '../connection/HilosConnection.js'
import {
  SIGNAL_TYPE_PAGE_MOVED,
  SIGNAL_TYPE_PAGE_RESPONSE,
  SIGNAL_TYPE_PAGE_SUBSCRIPTION_ERROR,
} from '../protocol/constants.js'
import {
  pageSubscriptionErrorSchema,
  type PageSubscriptionError,
} from '../protocol/pageError.js'
import { pageMovedSchema, type PageMoved } from '../protocol/pageMoved.js'
import {
  pageResponseSchema,
  type PageResponseWire,
} from '../protocol/scopePayload.js'
import { type NormalizerOptions } from '../state/normalizer.js'
import { type ScopeManager } from '../state/ScopeManager.js'
import {
  PageSubscription,
  admitPageResponse,
  capturePageDelivery,
  detachPageSubscription,
  ingestAdmittedPageResponse,
  type PageResponseMatcher,
} from './PageSubscription.js'
import { prepareHilosPageSubscribeParams } from './hilosPageSubscribeParams.js'

/** Locate the owner of a connection's current page scope. */
const pageSubscriptions = new WeakMap<
  HilosConnection,
  { pages: PageSubscription; stop: () => void }
>()

/**
 * The page_response schema keyed for a connection's `projectSchemas`. A project
 * spreads it into its own schema map so the parse boundary validates the page
 * payloads {@link bindPageScope} ingests. The framework owns this schema, so a
 * project never restates the `{ page_response: pageResponseSchema }` pair.
 */
export const PAGE_SIGNAL_SCHEMAS = {
  [SIGNAL_TYPE_PAGE_RESPONSE]: pageResponseSchema,
  [SIGNAL_TYPE_PAGE_SUBSCRIPTION_ERROR]: pageSubscriptionErrorSchema,
  [SIGNAL_TYPE_PAGE_MOVED]: pageMovedSchema,
}

export interface BindPageScopeOptions extends NormalizerOptions {
  /** Optional checks for pages whose replies carry their subscribed identity. */
  pageResponseMatchers?: ReadonlyMap<string, PageResponseMatcher>
}

/**
 * Open the page-subscription manager and route page responses to it. The
 * manager checks whether a response belongs to the current subscription.
 * The navigator uses the returned manager to subscribe to the URL's page.
 *
 * @param connection The application's Hilos connection.
 * @param scopes The application's scope-partitioned stores.
 * @param options Entity types for payload slots and optional response matchers.
 */
export function bindPageScope(
  connection: HilosConnection,
  scopes: ScopeManager,
  options: BindPageScopeOptions = {},
): PageSubscription {
  const pages = new PageSubscription(
    connection,
    scopes,
    prepareHilosPageSubscribeParams,
  )
  const previous = pageSubscriptions.get(connection)
  previous?.stop()

  pageDeliveryAdmissions.set(connection, {
    response: ({ page, payload }) => {
      const matcher = options.pageResponseMatchers?.get(page)
      const isCurrent = pages[admitPageResponse](page, payload ?? {}, matcher)

      if (matcher && isCurrent === false) return false

      // Ordinary responses enter the table presentation boundary even when their
      // page differs. Only the current opening can apply or remember the answer.
      return {
        isCurrent: isCurrent || (() => false),
        matched: matcher !== undefined,
      }
    },
    refusal: (error) =>
      pages[capturePageDelivery](error.page, { kind: 'refusal', error }),
    address: (page, params) =>
      pages[capturePageDelivery](page, { kind: 'address', params }),
  })
  const stop = connection.on('projectSignal', (signal) => {
    if (pageSubscriptions.get(connection)?.pages !== pages) return
    const isLive = admittedPageDeliveries.get(signal)?.isLive
    if (signal.type === SIGNAL_TYPE_PAGE_RESPONSE) {
      // Validated against pageResponseSchema at the parse boundary; this cast
      // is the declared typed selector for that schema's output.
      const response = signal.data as PageResponseWire
      pages[ingestAdmittedPageResponse](
        response.page,
        response.payload ?? {},
        options,
        isLive,
      )
    } else if (signal.type === SIGNAL_TYPE_PAGE_SUBSCRIPTION_ERROR) {
      // Validated against pageSubscriptionErrorSchema at the parse boundary;
      // the typed selector for that schema's output.
      pages.handleSubscriptionError(
        signal.data as PageSubscriptionError,
        isLive,
      )
    } else if (signal.type === SIGNAL_TYPE_PAGE_MOVED) {
      // Validated against pageMovedSchema at the parse boundary; the typed
      // selector for that schema's output.
      if (isLive?.() === false) return
      pages.handlePageMoved(
        signal.data as PageMoved,
        isLive
          ? () =>
              pageSubscriptions.get(connection)?.pages === pages &&
              isProjectSignalFromCurrentSocket(signal)
          : undefined,
      )
    }
  })
  pageSubscriptions.set(connection, { pages, stop })
  if (previous) {
    connection.forgetPageFrames()
    previous.pages[detachPageSubscription]()
  }
  if (
    pageSubscriptions.get(connection)?.pages === pages &&
    hasCurrentSessionAnswer(connection)
  ) {
    pages.releaseOnSession()
  }

  return pages
}

/**
 * Find the page subscription bound at boot; an unbound connection cannot dispatch
 * an action that needs its current subscription.
 *
 * Internal to the SDK.
 *
 * @param connection The application's Hilos connection.
 * @throws When boot has not bound a page subscription to this connection.
 */
export function pageSubscriptionFor(
  connection: HilosConnection,
): PageSubscription {
  const pages = pageSubscriptions.get(connection)?.pages
  if (!pages) {
    throw new Error('No page subscription is bound to this connection')
  }

  return pages
}
