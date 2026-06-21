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
import { type Scope, type ScopeManager } from '../state/ScopeManager.js'
import {
  ingest,
  type NormalizerOptions,
  type ScopePayload,
} from '../state/normalizer.js'

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
    const scope = this.scopes.openPage(pageKey)
    this.sendSubscribe()

    return scope
  }

  /** Leave to no page: drops the page scope and tells the backend. */
  unsubscribe(): void {
    if (!this.current) {
      return
    }
    const left = this.current.pageKey
    this.current = null
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
