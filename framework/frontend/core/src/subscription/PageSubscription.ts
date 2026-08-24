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

/** The page status the auth gate owns: only its resume takes this one down. */
const UNAUTHORIZED = 401

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

  /**
   * Draw a 403 on the current page and drop what it was showing, without asking
   * the server anything (HIL-621). The client has just learned it lost the admin
   * marker while standing on an administrative surface, and privileged rows must
   * not survive one frame while the server's own verdict is on the wire.
   *
   * The page scope is re-opened rather than left alone, so the rows are GONE and
   * not merely hidden behind an error surface — a view that reads the scope
   * directly would otherwise keep rendering them.
   *
   * The server rules, not this: its answer lands a moment later and either
   * confirms the denial or replaces it with the page's data
   * ({@link ingestPageResponse} clears the error). A no-op when no page is
   * subscribed.
   */
  denyCurrentPage(): void {
    const pageKey = this.current?.pageKey
    if (pageKey === undefined) {
      return
    }
    this.refuse({
      page: pageKey,
      httpCode: 403,
      errorCode: 'forbidden',
      message: 'Access forbidden',
    })
  }

  /**
   * Put the current page back into the state a navigation leaves it in: no error,
   * no data, waiting for the subscription's answer (HIL-621, HIL-652). The client
   * has just learned that what it is displaying was decided for circumstances that
   * no longer hold — it gained the admin marker while a 403 was on screen, or it
   * lost its identity while a page for a signed-in visitor was on screen — and the
   * page it is about to be handed is not the one it is showing.
   *
   * The page scope is re-opened for the same reason {@link denyCurrentPage} does
   * it: waiting is not hiding, and a view reading the scope directly would keep
   * rendering rows decided for somebody who has gone. Its first caller, the grant
   * reaction, was standing on a refusal whose data had already been dropped, so
   * nothing changes for it.
   *
   * No frame is sent. The subscription is already live and the server re-decides
   * it on its own; asking again from here would mean the judged party initiates
   * the verdict.
   */
  awaitPageAnswer(): void {
    this.pageErrorSignal.set(null)
    this.pageLoadingSignal.set(true)
    const pageKey = this.current?.pageKey
    if (pageKey !== undefined) {
      this.scopes.openPage(pageKey)
    }
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
   * Data for the current page clears its standing error — with one exception,
   * the 401. This is the visible half of live-promotion
   * (page-access-control.md): the subscription is kept alive after a denial, so
   * the guard passing later — user #10 is created under the 404 the client is
   * looking at, or the admin grant lands — arrives as a page_response and must
   * replace the error surface with the page. It is also what makes a
   * client-drawn 403 safe: an answer with data overrules it (HIL-621).
   *
   * A 401 is not a denial waiting to be overruled by data: it is the auth gate
   * holding the page while the person identifies themselves, and it comes down
   * by {@link clearPageError} from the gate's own resume, never by an answer.
   * The gate postpones that resume while the session owes an ack (HIL-422) — the
   * sentence a finished sign-in has left to say — and a page_response landing in
   * that window would otherwise pull the surface out from under the panel and
   * show the page nobody has acknowledged yet. The data itself is still ingested,
   * which is exactly why the resume needs no round trip: the page is already in
   * the scope when the error is lifted off it.
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
    if (this.pageErrorSignal.get()?.httpCode !== UNAUTHORIZED) {
      this.pageErrorSignal.set(null)
    }
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
    this.refuse(error)

    return true
  }

  /**
   * Show a refusal for the current page and drop what the page was holding.
   *
   * One rule for both refusals — the server's ({@link handleSubscriptionError})
   * and the one the client draws ahead of it ({@link denyCurrentPage}): a page
   * that is refused holds no data. Hiding the rows behind the error surface is
   * not enough, because the page scope is the most specific layer of entity
   * resolution: a users-table row carrying `admin: true` outranks the session's
   * own `admin: false` for as long as it sits there, and a refused subscription
   * receives no fan-out to correct it — so the stale copy would never be
   * replaced, only left where the shell can still read it (HIL-621).
   *
   * Before the re-decision existed, every refusal arrived moments after a
   * subscribe, when the page scope had just been opened and was empty; this
   * re-open was a no-op then and stays one now for every one of those paths.
   *
   * @param error The page, HTTP status, code, and message to show.
   */
  private refuse(error: PageSubscriptionError): void {
    this.pageErrorSignal.set(error)
    this.pageLoadingSignal.set(false)
    this.scopes.openPage(error.page)
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
