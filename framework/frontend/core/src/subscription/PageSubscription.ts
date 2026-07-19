// The page-subscription manager: a connection shows 0..1 pages
// (wire-protocol.md). Subscribing a page atomically replaces the previous
// subscription — navigation is one page_subscribe, never an unsubscribe+
// subscribe pair — and every `connected` transition re-sends the current
// subscription, because a new socket is a fresh protocol exchange. The
// manager owns the page scope lifecycle and the stale-signal guard: a page
// payload is ingested only while its page is still the current subscription.

import {
  FIELD_PAGE,
  FIELD_PARAMS,
  FIELD_TYPE,
  SIGNAL_TYPE_PAGE_SUBSCRIBE,
  SIGNAL_TYPE_PAGE_UNSUBSCRIBE,
} from '../protocol/constants.js'
import { type ConnectionState } from '../connection/HilosConnection.js'
import { type PageSubscriptionError } from '../protocol/pageError.js'
import { type Scope, type ScopeManager } from '../state/ScopeManager.js'
import {
  ingest,
  type NormalizerOptions,
  type ScopePayload,
} from '../state/normalizer.js'
import { createSignal, type ReadonlySignal } from '../state/signal.js'

/**
 * The slice of HilosConnection the manager touches; a test double only has to
 * implement these three members.
 */
export interface PageSubscriptionConnection {
  readonly state: ConnectionState
  send(text: string): boolean
  on(event: 'state', listener: (state: ConnectionState) => void): () => void
}

export class PageSubscription {
  private current: {
    pageKey: string
    params: Record<string, unknown>
  } | null = null

  /** The current page's subscription error; null while the page loads cleanly. */
  private readonly pageErrorSignal = createSignal<PageSubscriptionError | null>(
    null,
  )

  constructor(
    private readonly connection: PageSubscriptionConnection,
    private readonly scopes: ScopeManager,
  ) {
    connection.on('state', (state) => {
      if (state === 'connected') {
        this.sendSubscribe()
      }
    })
  }

  /** The currently subscribed page key; `undefined` between subscriptions. */
  pageKey(): string | undefined {
    return this.current?.pageKey
  }

  /**
   * The current page's subscription error, or null while it loads cleanly. Set
   * from a `subscription_page_error` for the current page and cleared on any
   * page change; the routed view shows an error surface while it is set.
   */
  get pageError(): ReadonlySignal<PageSubscriptionError | null> {
    return this.pageErrorSignal
  }

  /**
   * Subscribe the page, atomically replacing the previous subscription: the
   * old page scope drops, a fresh one opens, and one page_subscribe frame
   * goes out (immediately when connected, on the next `connected` transition
   * otherwise).
   *
   * @param pageKey The page to subscribe.
   * @param params Route params for the subscription.
   */
  subscribe(pageKey: string, params: Record<string, unknown> = {}): Scope {
    this.current = { pageKey, params }
    this.pageErrorSignal.set(null)
    const scope = this.scopes.openPage(pageKey)
    this.sendSubscribe()

    return scope
  }

  /**
   * Clear the current page's subscription error without leaving the page. The
   * subscription is already live and the backend re-delivers the page payload
   * the instant its guard passes (page-access-control.md live-promotion), so
   * clearing a 401 the moment the session authenticates un-gates the page with
   * no re-navigation — the resume half of the auth gate (HIL-165). A no-op when
   * no error is set.
   */
  clearPageError(): void {
    this.pageErrorSignal.set(null)
  }

  /** Leave to no page: drops the page scope and tells the backend. */
  unsubscribe(): void {
    if (!this.current) {
      return
    }
    const left = this.current.pageKey
    this.current = null
    this.pageErrorSignal.set(null)
    this.scopes.dropPage()
    this.connection.send(
      JSON.stringify({
        [FIELD_TYPE]: SIGNAL_TYPE_PAGE_UNSUBSCRIBE,
        [FIELD_PAGE]: left,
      }),
    )
  }

  /**
   * Ingest a page's scope payload into the current page scope. A payload for
   * any other page is dropped — the late-signal guard of wire-protocol.md —
   * and the return value reports which happened.
   *
   * @param pageKey The page key the signal carried.
   * @param payload The page's scope-shaped payload.
   * @param options Binding-local entity-type overrides for this page's slots.
   */
  ingestPageResponse(
    pageKey: string,
    payload: ScopePayload,
    options: NormalizerOptions = {},
  ): boolean {
    const scope = this.scopes.page()
    if (!scope || pageKey !== this.current?.pageKey) {
      return false
    }
    ingest(scope, payload, options)

    return true
  }

  /**
   * Record a `subscription_page_error` for the current page. A late error for a
   * page the client has already left is dropped — the same guard as
   * {@link ingestPageResponse} — and the return value reports which happened.
   * The subscription stays active; the error is cleared on the next page change.
   *
   * @param error The page, HTTP status, code, and message the server reported.
   */
  handleSubscriptionError(error: PageSubscriptionError): boolean {
    if (error.page !== this.current?.pageKey) {
      return false
    }
    this.pageErrorSignal.set(error)

    return true
  }

  private sendSubscribe(): void {
    if (!this.current) {
      return
    }
    this.connection.send(
      JSON.stringify({
        [FIELD_TYPE]: SIGNAL_TYPE_PAGE_SUBSCRIBE,
        [FIELD_PAGE]: this.current.pageKey,
        [FIELD_PARAMS]: this.current.params,
      }),
    )
  }
}
