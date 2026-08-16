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

  /** True from a page's subscribe until that page's first answer of either kind. */
  private readonly pageLoadingSignal = createSignal(false)

  /**
   * Whether the session has answered ON THE CURRENT SOCKET. A page_subscribe
   * sent before it says who this connection is asks a question the backend
   * cannot answer yet: the connection's identity is established by the handshake
   * and reaches the other workers on its own, so a subscribe that overtakes it is
   * read as anonymous and refused — a false 401 the client then has to live with.
   * Holding the frame until the answer lands costs one round trip the page is
   * waiting on anyway, and removes the question rather than racing it.
   *
   * A dropped socket takes its identity with it, so the hold goes back up on any
   * non-connected transition: the next socket is a stranger to the backend until
   * its own handshake answers, however long the visitor has been signed in. Since
   * HIL-582 that case is routine rather than exotic — every login reconnects to
   * trade its rotation ticket for the new cookie.
   *
   * TODO(HIL-599): the server now holds such a frame and judges it once the
   * identity lands, so this hold is a second lock rather than the only one. It
   * stays until the server side has been in the wild long enough to trust alone -
   * revisit after 2027-12-28, and only on the owner's word, as a leaf of its own.
   */
  private sessionAnswered = false

  constructor(
    private readonly connection: PageSubscriptionConnection,
    private readonly scopes: ScopeManager,
  ) {
    connection.on('state', (state) => {
      if (state !== 'connected') {
        this.sessionAnswered = false
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
   * True while the subscribed page has not answered yet, so the routed view can
   * hold the page back instead of showing it and taking it away again when a
   * denial lands one round trip later.
   *
   * It is raised by {@link subscribe} — a navigation — and lowered by the first
   * answer for that page, a page_response or a subscription_page_error alike.
   * A reconnect's re-subscribe does NOT raise it: the page is already on screen
   * and its content is being refreshed, not awaited, and blanking it on every
   * socket flap would be worse than showing slightly stale rows.
   */
  get pageLoading(): ReadonlySignal<boolean> {
    return this.pageLoadingSignal
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
    this.pageLoadingSignal.set(true)
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
   *
   * It does not raise {@link pageLoading} again: this subscription has already
   * answered once, and the page is un-gated in place rather than awaited afresh.
   * Raising it here would blank a page the user is looking at for as long as the
   * re-delivery takes.
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
    this.pageLoadingSignal.set(false)
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
    this.pageLoadingSignal.set(false)

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
    this.pageLoadingSignal.set(false)

    return true
  }

  /**
   * Release the held subscription: the session has answered, so the backend
   * knows who this connection is and a page subscribe can be judged. Called by
   * the boot sequence on every handshake response, which is once per socket —
   * the second call for the same socket is a no-op, and the first after a
   * reconnect is what sends the page's re-subscribe.
   */
  releaseOnSession(): void {
    if (this.sessionAnswered) {
      return
    }
    this.sessionAnswered = true
    this.sendSubscribe()
  }

  private sendSubscribe(): void {
    if (!this.current || !this.sessionAnswered) {
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
