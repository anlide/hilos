// The page-subscription manager: a connection shows 0..1 pages
// (wire-protocol.md). Subscribing a page atomically replaces the previous
// subscription — navigation is one page_subscribe, never an unsubscribe+
// subscribe pair — and every `connected` transition re-sends the current
// subscription, because a new socket is a fresh protocol exchange, and sends it
// when protected mode stops holding a connection that could not send it. The
// manager owns the page scope lifecycle and the stale-signal guard: a page
// payload is ingested only while its page is still the current subscription.

import {
  dashboardSections,
  pageIdentity,
  type HilosDashboardSection,
  type HilosPageIdentity,
} from '../routing/hilosPageIdentity.js'
import {
  FIELD_PAGE,
  FIELD_PARAMS,
  FIELD_TABLE_WINDOWS,
  FIELD_TYPE,
  SIGNAL_TYPE_PAGE_SUBSCRIBE,
  SIGNAL_TYPE_PAGE_UNSUBSCRIBE,
} from '../protocol/constants.js'
import {
  type HilosConnection,
  type HilosConnectionEventMap,
  type ConnectionState,
  type TableViewportDescriptor,
  captureCurrentSocket,
  capturePreparedSession,
} from '../connection/HilosConnection.js'
import {
  PAGE_ERROR_NOT_SERVED,
  type PageSubscriptionError,
} from '../protocol/pageError.js'
import { type PageMoved } from '../protocol/pageMoved.js'
import { Scope, type ScopeManager } from '../state/ScopeManager.js'
import {
  ingestWhileCurrent,
  type NormalizerOptions,
  type ScopePayload,
} from '../state/normalizer.js'
import { createSignal, type ReadonlySignal } from '../state/signal.js'

/** The page status the auth gate owns: only its resume takes this one down. */
const UNAUTHORIZED = 401

/** The status of a page the application's view layer has not built. */
const NOT_FOUND = 404

/** The connection events the manager listens to. */
type PageSubscriptionEventMap = Pick<
  HilosConnectionEventMap,
  'state' | 'protectedMode' | 'pageResubscribe' | 'reconnectDragging'
>

/**
 * The slice of HilosConnection the manager touches; a test double only has to
 * implement these members.
 */
export interface PageSubscriptionConnection {
  readonly state: ConnectionState
  readonly reconnectDragging: boolean
  [captureCurrentSocket]?: HilosConnection[typeof captureCurrentSocket]
  send(text: string): boolean
  on<K extends keyof PageSubscriptionEventMap>(
    event: K,
    listener: (payload: PageSubscriptionEventMap[K]) => void,
  ): () => void
  forgetPageFrames(
    retainTableWindows?: boolean,
    isCurrent?: () => boolean,
  ): void
  tableWindowDescriptors(
    opening?: boolean,
  ): Record<string, TableViewportDescriptor>
  /** Older connection ports can omit the local window request lifecycle. */
  resetTableWindowRequests?(): void
  /** Older connection ports can omit the local table presentation boundary. */
  withTablePresentationUpdate?: HilosConnection['withTablePresentationUpdate']
}

/** Prepare params once per successful opening, after the session has answered. */
export type PageSubscribeParamsPreparer = (
  pageKey: string,
  params: Readonly<Record<string, unknown>>,
) => Record<string, unknown>

/** Decide whether a page payload answers the active params for its page key. */
export type PageResponseMatcher = (
  params: Readonly<Record<string, unknown>>,
  payload: Readonly<ScopePayload>,
) => boolean

/** Internal access to the active opening before connection listeners run. */
export const capturePageDelivery = Symbol('capturePageDelivery')

/** Internal response admission with the optional payload matcher. */
export const admitPageResponse = Symbol('admitPageResponse')

/** Internal listener that follows a move while the originating delivery is live. */
export const followCurrentPageMove = Symbol('followCurrentPageMove')

/** Internal access reactions against the refusal of the active opening. */
export const reactToPageRefusal = Symbol('reactToPageRefusal')

type PageRefusalEffect = 'clear' | 'await'

export interface PageRefusalReaction {
  [reactToPageRefusal]?(httpCode: number, effect: PageRefusalEffect): boolean
}

/** Internal ingest of a response that has already passed admission. */
export const ingestAdmittedPageResponse = Symbol('ingestAdmittedPageResponse')

/** Internal release after boot has prepared a new or repeated session response. */
export const releasePreparedSession = Symbol('releasePreparedSession')

/** Internal release of the callbacks owned by a replaced binding. */
export const detachPageSubscription = Symbol('detachPageSubscription')

type PageMoveListener = (
  target: PageMoveTarget,
  isCurrent: () => boolean,
) => void

/** The address a page_moved names: the page and route params it lives at now. */
export interface PageMoveTarget {
  readonly page: string
  readonly params: Readonly<Record<string, string>>
}

type PageDelivery =
  | { kind: 'response' }
  | { kind: 'refusal'; error: PageSubscriptionError }
  | { kind: 'address'; params: Record<string, unknown> }

export class PageSubscription {
  private current: {
    pageKey: string
    params: Record<string, unknown>
    scope?: Scope
    /** The refusal that advanced this opening's delivery ownership. */
    refusal?: {
      error: PageSubscriptionError
      revision: number
      scope: Scope | undefined
    }
    /** Survives reconnects; only a new opening or departure discards this context. */
    prepared: boolean
    /** An in-place move uses the current window controls even before preparation. */
    followingMove?: boolean
  } | null = null

  private detached = false

  /** The scope can be re-answered in place under a new address or access verdict. */
  private responseRevision = 0

  /** An applied answer completes a wait without invalidating its delivery. */
  private answeredRevision: number | undefined

  private readonly stopTransportListeners: ReadonlyArray<() => void>

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

  /**
   * Whether the current page's page_subscribe reached the server ON THE CURRENT
   * SOCKET. The connection refuses every outbound frame while protected mode holds
   * it, and refuses without dropping the socket: neither a `connected` transition
   * nor a session answer is coming to send the frame again. A tab loaded under the
   * freeze would then stand on its page, unanswered, for the whole window after it
   * was let in — the server re-answers only the pages it holds a subscription for
   * (HIL-912). So the refusal is remembered here, and the frame goes out when the
   * mode stops holding this connection.
   */
  private subscribeDelivered = false

  /** Waits owned by this page subscription, forgotten on departure or dispatch. */
  private readonly subscriptionWaiters = new Set<() => void>()

  /** Who turns an accepted page_moved into an address; bootHilos binds one. */
  private readonly moveListeners = new Map<PageMoveListener, PageMoveListener>()

  /** The current page's catalog identity, read off the page scope it arrives in. */
  private readonly pageIdentitySignal: ReadonlySignal<
    HilosPageIdentity | undefined
  >

  /** The dashboard's section grouping, read off the same page scope. */
  private readonly dashboardSectionsSignal: ReadonlySignal<
    HilosDashboardSection[] | undefined
  >

  constructor(
    private readonly connection: PageSubscriptionConnection,
    private readonly scopes: ScopeManager,
    private readonly prepareParams: PageSubscribeParamsPreparer = (
      _pageKey,
      params,
    ) => params,
    private readonly pageResponseMatchers?: ReadonlyMap<
      string,
      PageResponseMatcher
    >,
  ) {
    // Both selectors are derived once and held, not rebuilt per read: they are
    // computed over the page-scope signal, so one instance already answers for
    // every page this subscription will ever open.
    this.pageIdentitySignal = pageIdentity(scopes)
    this.dashboardSectionsSignal = dashboardSections(scopes)
    this.stopTransportListeners = [
      connection.on('state', (state) => {
        if (state !== 'connected') {
          this.sessionAnswered = false
          this.subscribeDelivered = false
        }
      }),
      connection.on('protectedMode', (status) => {
        if (!status.active && !this.subscribeDelivered) {
          this.sendSubscribe()
        }
      }),
      connection.on('pageResubscribe', ({ data }) => {
        if (
          this.current?.pageKey !== data.page ||
          !sameParams(this.current.params, data.params)
        ) {
          return
        }
        this.subscribeDelivered = false
        this.sendSubscribe()
      }),
    ]
  }

  [detachPageSubscription](): void {
    const scope = this.current?.scope
    this.detached = true
    this.current = null
    this.subscribeDelivered = false
    for (const stop of this.stopTransportListeners) stop()
    this.notifySubscriptionWaiters()
    this.moveListeners.clear()
    if (scope && this.scopes.page() === scope) this.scopes.dropPage()
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
   * The current page's catalog identity — heading, lead, breadcrumb, subsection
   * cards — as the page's own answer delivered it, or `undefined` while that
   * answer is on the wire and for a page the catalog carries no entry for.
   *
   * It reads the page scope rather than a store of its own: navigation drops
   * that scope, so the previous screen's name cannot outlive the screen, and the
   * value returns on its own when the next answer lands.
   */
  get pageIdentity(): ReadonlySignal<HilosPageIdentity | undefined> {
    return this.pageIdentitySignal
  }

  /**
   * The admin dashboard's section grouping, or `undefined` until the dashboard's
   * own answer lands. Only the dashboard is sent it.
   */
  get dashboardSections(): ReadonlySignal<HilosDashboardSection[] | undefined> {
    return this.dashboardSectionsSignal
  }

  /**
   * Subscribe the page, atomically replacing the previous subscription: the
   * old page scope drops, a fresh one opens, and one page_subscribe frame
   * goes out (immediately when connected, on the next `connected` transition
   * otherwise).
   *
   * The frames the connection is holding are dropped before the frame goes out:
   * what the previous page said describes a page nobody is standing on.
   *
   * @param pageKey The page to subscribe.
   * @param params Route params for the subscription.
   */
  subscribe(pageKey: string, params: Record<string, unknown> = {}): Scope {
    if (this.detached) return new Scope('page', pageKey)

    this.subscribeDelivered = false
    const current = (this.current = {
      pageKey,
      params: { ...params },
      prepared: false,
    })
    this.notifySubscriptionWaiters()
    this.pageErrorSignal.set(null)
    if (this.current !== current) return new Scope('page', pageKey)

    this.pageLoadingSignal.set(true)
    if (this.current !== current) return new Scope('page', pageKey)

    const scope = this.openCurrentScope(pageKey)
    if (this.current === current && this.scopes.page() === scope) {
      this.connection.forgetPageFrames()
      this.sendSubscribe()
    }

    return scope
  }

  /**
   * Run in the same stack that proves this page's subscription was sent on the
   * current socket. A reconnect may keep the wait alive; leaving the captured
   * subscription, aborting, or an unreachable connection rejects it. Once run
   * starts, its own lifecycle owns the result.
   *
   * @param run Start the action synchronously and return its result promise.
   * @param signal Cancel the wait before the action starts.
   * @returns The action's result, or a rejection if the wait cannot complete.
   */
  runWhenSubscribed<T>(
    run: () => Promise<T>,
    signal?: AbortSignal,
  ): Promise<T> {
    const current = this.current

    return new Promise<T>((resolve, reject) => {
      const cleanup = (): void => {
        this.subscriptionWaiters.delete(check)
        stopState()
        stopDragging()
        signal?.removeEventListener('abort', check)
      }
      const check = (): void => {
        if (!this.subscriptionWaiters.has(check)) {
          return
        }
        if (signal?.aborted) {
          cleanup()
          reject(signal.reason)
        } else if (!current || this.current !== current) {
          cleanup()
          reject(new DOMException('Page subscription changed', 'AbortError'))
        } else if (
          this.connection.reconnectDragging ||
          this.connection.state === 'disconnected'
        ) {
          cleanup()
          reject(new Error('Connection is unavailable'))
        } else if (
          this.connection.state === 'connected' &&
          this.sessionAnswered &&
          this.subscribeDelivered
        ) {
          cleanup()
          try {
            resolve(run())
          } catch (error) {
            reject(error)
          }
        }
      }
      const stopState = this.connection.on('state', (state) => {
        if (state === 'disconnected') {
          check()
        }
      })
      const stopDragging = this.connection.on(
        'reconnectDragging',
        (dragging) => {
          if (dragging) {
            check()
          }
        },
      )
      this.subscriptionWaiters.add(check)
      signal?.addEventListener('abort', check)
      check()
    })
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
   * Apply an access reaction to the refusal still held by this opening.
   *
   * @param httpCode The refusal status the access event resolves.
   * @param effect Whether to clear the refusal or wait for a new verdict.
   * @returns Whether the refusal matched and the reaction ran.
   */
  [reactToPageRefusal](httpCode: number, effect: PageRefusalEffect): boolean {
    const error = this.current?.refusal?.error
    if (
      this.detached ||
      error?.httpCode !== httpCode ||
      this.pageErrorSignal.get() !== error ||
      (effect === 'await' && error.errorCode === PAGE_ERROR_NOT_SERVED)
    ) {
      return false
    }
    if (effect === 'clear') {
      this.clearPageError()
    } else {
      this.awaitPageAnswer()
    }
    return true
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
      // TODO(i18n): Remove temporary client text when backend delivery supplies this refusal.
      title: 'Forbidden',
      message: 'You do not have access to this page.',
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
   * nothing changes for it. The frames the connection holds are dropped with it,
   * for the same reason: they too were decided for circumstances that are gone.
   *
   * A known table keeps only its window controls in the existing connection
   * registration, so a replacement view can consume the server's held window.
   * Capture them before publishing loading: a view adapter may unmount at once.
   *
   * No frame is sent. The subscription is already live and the server re-decides
   * it on its own; asking again from here would mean the judged party initiates
   * the verdict.
   */
  awaitPageAnswer(): void {
    if (this.detached) return

    const current = this.current
    const revision = ++this.responseRevision
    const isCurrent = () =>
      !this.detached &&
      this.current === current &&
      this.responseRevision === revision &&
      this.answeredRevision !== revision
    this.connection.forgetPageFrames(true, isCurrent)
    if (!isCurrent()) return

    this.pageErrorSignal.set(null)
    if (!isCurrent()) return
    this.pageLoadingSignal.set(true)
    if (current && isCurrent()) {
      this.openCurrentScope(current.pageKey)
    }
  }

  /**
   * Leave to no page: drops the page scope and the frames held for it, and
   * tells the backend.
   */
  unsubscribe(): void {
    if (!this.current) {
      return
    }
    const left = this.current.pageKey
    this.subscribeDelivered = false
    this.current = null
    this.notifySubscriptionWaiters()
    this.pageErrorSignal.set(null)
    this.pageLoadingSignal.set(false)
    this.scopes.dropPage()
    this.connection.forgetPageFrames()
    this.connection.send(
      JSON.stringify({
        [FIELD_TYPE]: SIGNAL_TYPE_PAGE_UNSUBSCRIBE,
        [FIELD_PAGE]: left,
      }),
    )
  }

  /**
   * Refuse a page the application's view layer has not built, without asking
   * the server. The surface is the same as a server not_served refusal (HIL-1090).
   * Leaving the current subscription also drops its scope and held frames.
   *
   * @param pageKey The page the application cannot render.
   */
  refuseUnbuilt(pageKey: string): void {
    this.unsubscribe()
    this.pageErrorSignal.set({
      page: pageKey,
      httpCode: NOT_FOUND,
      errorCode: PAGE_ERROR_NOT_SERVED,
      // TODO(i18n): Remove temporary client text when backend delivery supplies this refusal.
      title: 'Page Not Found',
      message: 'The page you are looking for does not exist.',
    })
    this.pageLoadingSignal.set(false)
  }

  /**
   * Capture the opening and address before any delivery listener is called.
   *
   * @param pageKey The page key the frame carried.
   * @param delivery The effect the frame may apply at the captured address.
   */
  [capturePageDelivery](
    pageKey: string,
    delivery: PageDelivery,
  ): (() => boolean) | false {
    const current = this.current
    if (current?.pageKey !== pageKey) return false

    const params = current.params
    const revision = this.responseRevision
    const scope = this.scopes.page()
    if (delivery.kind === 'response' && !scope) return false
    if (delivery.kind === 'address' && !sameParams(params, delivery.params))
      return false

    return () =>
      this.current === current &&
      current.params === params &&
      ((this.responseRevision === revision && this.scopes.page() === scope) ||
        (delivery.kind === 'refusal' &&
          current.refusal?.error === delivery.error &&
          current.refusal.revision === revision + 1 &&
          current.refusal.revision === this.responseRevision &&
          current.refusal.scope === this.scopes.page()))
  }

  [admitPageResponse](
    pageKey: string,
    payload: ScopePayload,
    matcher?: PageResponseMatcher,
  ): (() => boolean) | false {
    const current = this.current
    const isCurrent = this[capturePageDelivery](pageKey, { kind: 'response' })
    if (!current || isCurrent === false) return false
    if (
      matcher &&
      (current.scope !== this.scopes.page() ||
        !matcher(current.params, payload))
    ) {
      return false
    }

    return isCurrent() ? isCurrent : false
  }

  /**
   * Ingest a page's scope payload into the current page scope. Drop payloads
   * for another page key or rejected by the current page's response matcher.
   * The return value reports whether the payload was ingested.
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
    const matcher = this.pageResponseMatchers?.get(pageKey)
    const admission = matcher
      ? this[admitPageResponse](pageKey, payload, matcher)
      : undefined

    return (
      admission !== false &&
      this[ingestAdmittedPageResponse](pageKey, payload, options, admission)
    )
  }

  /**
   * @param pageKey The page key the signal carried.
   * @param payload The page's scope-shaped payload.
   * @param options Binding-local entity-type overrides for this page's slots.
   * @param isCurrent Whether the admitted opening still owns the delivery.
   */
  [ingestAdmittedPageResponse](
    pageKey: string,
    payload: ScopePayload,
    options: NormalizerOptions,
    isCurrent?: () => boolean,
  ): boolean {
    const scope = this.scopes.page()
    if (!scope || pageKey !== this.current?.pageKey) return false

    const update = (): boolean => {
      if (isCurrent && !isCurrent()) return false
      const revision = this.responseRevision

      ingestWhileCurrent(scope, payload, options, isCurrent)
      if (isCurrent && !isCurrent()) return false
      if (this.responseRevision === revision && this.scopes.page() === scope) {
        this.answeredRevision = revision
      }
      if (this.pageErrorSignal.get()?.httpCode !== UNAUTHORIZED) {
        this.pageErrorSignal.set(null)
      }
      if (isCurrent && !isCurrent()) return false

      this.pageLoadingSignal.set(false)

      return true
    }

    return this.connection.withTablePresentationUpdate
      ? this.connection.withTablePresentationUpdate({ kind: 'window' }, update)
      : update()
  }

  /**
   * Record a `subscription_page_error` for the current page. A late error for a
   * page the client has already left is dropped — the same guard as
   * {@link ingestPageResponse} — and the return value reports which happened.
   * The subscription stays active; the error is cleared on the next page change.
   *
   * @param error The page, HTTP status, code, and message the server reported.
   * @param isCurrent Whether the original delivery still owns the refusal.
   */
  handleSubscriptionError(
    error: PageSubscriptionError,
    isCurrent?: () => boolean,
  ): boolean {
    if (error.page !== this.current?.pageKey || isCurrent?.() === false) {
      return false
    }
    this.refuse(error, isCurrent)

    return true
  }

  /**
   * Hand a `page_moved` for the current subscription to the listeners of
   * {@link onPageMoved}: the record the address named lives at another address
   * now. Only a move for the current page with the params it is subscribed
   * with is accepted — the same guard as the server's page_resubscribe — so one
   * that arrives after the page or its params changed is dropped, and the
   * return value reports which happened.
   *
   * Nothing on screen changes here: the page keeps its scope, its loading state
   * and its error until the answer for the new address lands.
   *
   * @param move The address subscribed and the address the record lives at now.
   * @param isLive Whether the original transport still owns the move.
   */
  handlePageMoved(move: PageMoved, isLive?: () => boolean): boolean {
    const ownsOpening = this[capturePageDelivery](move.page, {
      kind: 'address',
      params: move.params,
    })
    if (ownsOpening === false || isLive?.() === false) return false

    const isCurrent = () => ownsOpening() && (isLive?.() ?? true)
    const target: PageMoveTarget = {
      page: move.targetPage,
      params: move.targetParams,
    }
    for (const listener of [...this.moveListeners.values()]) {
      // Public observers report the accepted move. The navigator still checks
      // that the original opening owns any rewrite or repeat subscription.
      if (isLive?.() === false) break
      listener(target, isCurrent)
    }

    return true
  }

  /**
   * Listen for an accepted `page_moved`. The listener receives the address the
   * record lives at now and decides how to reach it; {@link followMove} is how
   * it stays on the same page.
   *
   * @param listener Called with the target of every accepted move.
   * @returns A function that removes this listener.
   */
  onPageMoved(listener: (target: PageMoveTarget) => void): () => void {
    return this[followCurrentPageMove](listener, (target) => listener(target))
  }

  [followCurrentPageMove](
    listener: PageMoveListener,
    notify = listener,
  ): () => void {
    this.moveListeners.set(listener, notify)

    return () => {
      this.moveListeners.delete(listener)
    }
  }

  /**
   * Repeat the current page's subscription in place under new route params: the
   * page the server moved is the same page at another address. The params pass
   * the same preparer as a navigation's, and the frame reports the windows the
   * page's tables hold now — their filters, page and view stay, as on a
   * page_resubscribe. The page scope, {@link pageLoading}, {@link pageError}
   * and the held frames are left alone: the answer for the new address lands
   * over them. A no-op with no current page.
   *
   * @param params The route params of the new address.
   */
  followMove(params: Record<string, string>): void {
    const current = this.current
    if (!current) {
      return
    }
    this.responseRevision += 1
    current.params = { ...params }
    current.prepared = false
    current.followingMove = current.scope !== undefined
    this.subscribeDelivered = false
    this.sendSubscribe()
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
   * The frames the connection is holding go the same way as the scope, and for
   * the same reason: a held frame is that same content in another pocket.
   *
   * @param error The page, HTTP status, code, and message to show.
   * @param isLive Whether a transport refusal is still being delivered.
   */
  private refuse(error: PageSubscriptionError, isLive?: () => boolean): void {
    const current = this.current
    if (!current) return
    const revision = ++this.responseRevision
    const before = this.scopes.page()
    const refusal = (current.refusal = { error, revision, scope: before })
    const ownsRevision = () =>
      this.current === current && this.responseRevision === revision
    const isCurrent = () => ownsRevision() && (isLive?.() ?? true)
    this.pageErrorSignal.set(error)
    if (!isCurrent() || this.scopes.page() !== before) return
    this.pageLoadingSignal.set(false)
    if (!isCurrent() || this.scopes.page() !== before) return
    const scope = this.openCurrentScope(error.page)
    if (!ownsRevision() || this.scopes.page() !== scope) return

    refusal.scope = scope
    if (isCurrent()) this.connection.forgetPageFrames()
  }

  /**
   * Publish a fresh scope and attach it only if the same opening is still current.
   *
   * @param pageKey The page the scope belongs to.
   */
  private openCurrentScope(pageKey: string): Scope {
    const current = this.current
    const scope = this.scopes.openPage(pageKey)
    if (this.scopes.page() === scope) {
      if (current && this.current === current) {
        current.scope = scope
      } else if (this.detached) {
        this.scopes.dropPage()
      }
    }

    return scope
  }

  /**
   * Release the held subscription: the session has answered, so the backend
   * knows who this connection is and a page subscribe can be judged. Boot
   * calls this on a session answer, and a replacement binding can use the
   * answer already received on the same socket. The first call after a
   * reconnect sends the page's re-subscribe.
   */
  releaseOnSession(): void {
    if (this.sessionAnswered) {
      return
    }
    this[releasePreparedSession]()
  }

  [releasePreparedSession](): void {
    this.sessionAnswered = true
    if (!this.subscribeDelivered) this.sendSubscribe()
  }

  private sendSubscribe(): void {
    const current = this.current
    const isSessionReady = capturePreparedSession(this.connection)
    if (
      !current?.scope ||
      !this.sessionAnswered ||
      isSessionReady?.() === false
    ) {
      return
    }
    const isCurrentSocket = this.connection[captureCurrentSocket]?.()
    const revision = this.responseRevision
    const isCurrent = () =>
      this.current === current &&
      this.responseRevision === revision &&
      (isCurrentSocket?.() ?? true) &&
      (isSessionReady?.() ?? true)
    const params = current.prepared
      ? current.params
      : this.prepareParams(current.pageKey, current.params)
    if (!isCurrent()) return

    this.connection.resetTableWindowRequests?.()
    if (!isCurrent()) return
    const frame: Record<string, unknown> = {
      [FIELD_TYPE]: SIGNAL_TYPE_PAGE_SUBSCRIBE,
      [FIELD_PAGE]: current.pageKey,
      [FIELD_PARAMS]: params,
    }
    // A new opening will mount new controllers. Tables report their opening controls
    // instead of a departing controller's search and address.
    const windows = this.connection.tableWindowDescriptors(
      !current.prepared && !current.followingMove,
    )
    if (Object.keys(windows).length > 0) {
      frame[FIELD_TABLE_WINDOWS] = windows
    }
    const text = JSON.stringify(frame)
    if (!isCurrent()) return

    const delivered = this.connection.send(text)
    if (!isCurrent()) return

    this.subscribeDelivered = delivered
    if (delivered) {
      if (current.params !== params) current.params = { ...params }
      current.prepared = true
    }
    this.notifySubscriptionWaiters()
  }

  /** Each callback rechecks readiness: the previous one may have closed the socket. */
  private notifySubscriptionWaiters(): void {
    for (const check of [...this.subscriptionWaiters]) {
      check()
    }
  }
}

/** Compare JSON param values, preserving array order while ignoring map key order. */
function sameParams(left: unknown, right: unknown): boolean {
  if (left === right) return true
  if (
    left === null ||
    right === null ||
    typeof left !== 'object' ||
    typeof right !== 'object' ||
    Array.isArray(left) !== Array.isArray(right)
  ) {
    return false
  }
  const leftMap = left as Record<string, unknown>
  const rightMap = right as Record<string, unknown>
  const keys = Object.keys(leftMap)
  return (
    keys.length === Object.keys(rightMap).length &&
    keys.every(
      (key) =>
        Object.hasOwn(rightMap, key) && sameParams(leftMap[key], rightMap[key]),
    )
  )
}
