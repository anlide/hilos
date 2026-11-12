import {
  ActionLifecycle,
  applyServerTime,
  bindAccessLoss,
  bindAccountBlocked,
  bindAccountStanding,
  bindAdminAccess,
  bindImpersonation,
  bindLegalReconsent,
  bindSessionScope,
  bindSignOut,
  HilosPages,
  hilosLegalReconsent,
  hilosToasts,
  offsetMs,
  ScopeManager,
  SIGNAL_HANDSHAKE_RESPONSE,
  type ConnectionState,
  type HilosConnection,
  type HilosConnectionEventMap,
  type HilosRouter,
  type ProtectedModeStatus,
} from '@hilos/core/base'
import type * as Vitest from 'vitest'
import { layoutChromeFeed, layoutChromeRouter } from './layoutChromeFixtures.js'

export interface LayoutPublicStatusInput {
  connection: HilosConnection
  actions: ActionLifecycle
  router: HilosRouter
  brand?: string
  allow?: boolean
  modalOpen?: boolean
  onSave?: () => void
  /** Register disposal before native setup can throw or start an async action. */
  retain(view: LayoutPublicStatusView): void
}

/** Native commits and disposal only; gestures and their outcomes stay common. */
export interface LayoutPublicStatusView {
  readonly root: HTMLElement
  update(props: Pick<LayoutPublicStatusInput, 'allow' | 'modalOpen'>): void
  flush(run?: () => void | Promise<void>): Promise<void>
  unmount(): Promise<void>
}

export type MountLayoutPublicStatus = (
  input: LayoutPublicStatusInput,
) => Promise<LayoutPublicStatusView>

export interface LayoutPublicStatusOptions {
  state?: ConnectionState
  firstFrameHeld?: boolean
  protectedMode?: ProtectedModeStatus
  brand?: string
  allow?: boolean
  modalOpen?: boolean
  onSave?: () => void
  /** Build raw session input under the owned clock, before mounting the view. */
  initialHandshake?: () => Record<string, unknown>
}

/** The actual request arguments, without URL normalization or inferred options. */
export interface LayoutPublicStatusRequest {
  readonly input: RequestInfo | URL
  readonly init: RequestInit | undefined
}

/** Successful immediate HTTP answers; parsing and availability belong to core. */
function publicFiles(now: number) {
  const status = {
    schemaVersion: 2,
    revision: 'layout-public-status',
    generatedAt: now,
    config: { pollIntervalSeconds: 30, staleAfterSeconds: 120 },
    components: [
      {
        componentKey: 'site',
        name: 'Site',
        description: null,
        state: 'operational',
      },
    ],
    candidates: [],
    outages: [],
    scheduledWorks: [],
    maintenancePeriods: [],
  }
  const heartbeat = {
    schemaVersion: 2,
    revision: status.revision,
    aliveAt: now,
  }
  const requests: LayoutPublicStatusRequest[] = []
  const fetch = async (input: RequestInfo | URL, init?: RequestInit) => {
    requests.push({ input, init })
    const value =
      input === '/status-data/status.json'
        ? status
        : input === '/status-data/heartbeat.json'
          ? heartbeat
          : undefined
    return { ok: value !== undefined, json: async () => value }
  }
  return { requests, fetch }
}

/** Full boot session binding on a source independent of shell availability. */
async function publicSession(router: HilosRouter) {
  const listeners = new Map<
    keyof HilosConnectionEventMap,
    Set<(payload: never) => void>
  >()
  const sent: { action: string; data: unknown; requestId?: string }[] = []
  const releases: (() => void)[] = []
  let state: ConnectionState = 'connected'
  let released = false
  let ownsLegal = false
  function emit<E extends keyof HilosConnectionEventMap>(
    event: E,
    payload: HilosConnectionEventMap[E],
  ): void {
    const errors: unknown[] = []
    for (const listener of listeners.get(event) ?? []) {
      try {
        listener(payload as never)
      } catch (error) {
        errors.push(error)
      }
    }
    if (errors.length) throw new AggregateError(errors)
  }
  const source = {
    get state() {
      return state
    },
    sendAction(action, data, requestId) {
      if (released || state !== 'connected') return false
      sent.push({ action, data, requestId })
      return true
    },
    on(event, listener) {
      if (released) return () => {}
      const subscriptions = listeners.get(event) ?? new Set()
      listeners.set(event, subscriptions)
      const notify = (payload: never): void => listener(payload)
      subscriptions.add(notify)
      return () => {
        subscriptions.delete(notify)
      }
    },
  } satisfies Pick<HilosConnection, 'state' | 'sendAction' | 'on'>
  function disconnect(): void {
    state = 'disconnected'
    emit('state', state)
  }
  async function unbind(): Promise<void> {
    if (released) return
    released = true
    const errors: unknown[] = []
    for (const release of releases.splice(0).reverse()) {
      try {
        release()
      } catch (error) {
        errors.push(error)
      }
    }
    listeners.clear()
    await Promise.resolve()
    if (ownsLegal) {
      try {
        hilosLegalReconsent.reset()
      } catch (error) {
        errors.push(error)
      }
    }
    if (errors.length) throw new AggregateError(errors)
  }
  try {
    const scopes = new ScopeManager()
    bindSessionScope(source as unknown as HilosConnection, scopes)
    const actions = new ActionLifecycle(source)
    releases.push(bindAdminAccess(scopes))
    releases.push(bindAccountBlocked(scopes, actions, source))
    releases.push(bindAccountStanding(scopes, actions))
    releases.push(bindSignOut(scopes, actions))
    ownsLegal = true
    releases.push(bindLegalReconsent(scopes, actions))
    releases.push(bindImpersonation(scopes, actions))
    releases.push(bindAccessLoss(scopes, router))
    return {
      actions,
      source: { sent, disconnect },
      handshake(payload: Record<string, unknown>): void {
        emit('projectSignal', {
          kind: 'project',
          type: SIGNAL_HANDSHAKE_RESPONSE,
          data: payload,
          envelope: { type: SIGNAL_HANDSHAKE_RESPONSE, data: payload },
        })
      },
      unbind,
    }
  } catch (error) {
    const errors = [error]
    try {
      disconnect()
    } catch (cleanupError) {
      errors.push(cleanupError)
    }
    try {
      await unbind()
    } catch (cleanupError) {
      errors.push(cleanupError)
    }
    throw new AggregateError(errors, 'Public Status session setup failed', {
      cause: error,
    })
  }
}

export interface LayoutPublicStatusCase {
  readonly view: LayoutPublicStatusView
  readonly feed: ReturnType<typeof layoutChromeFeed>
  readonly route: ReturnType<typeof layoutChromeRouter>['route']
  readonly session: Awaited<ReturnType<typeof publicSession>>
  readonly requests: readonly LayoutPublicStatusRequest[]
  commit(run?: () => void): Promise<void>
}

/** Own globals before setup; release every resource even after partial failure. */
export async function withLayoutPublicStatus(
  vi: typeof Vitest.vi,
  mount: MountLayoutPublicStatus,
  options: LayoutPublicStatusOptions,
  run: (scope: LayoutPublicStatusCase) => Promise<void>,
): Promise<void> {
  const entryOffset = offsetMs()
  const now = Date.now()
  const entryHref = window.location.href
  const entryState: unknown = window.history.state
  const fetchDescriptor = Object.getOwnPropertyDescriptor(globalThis, 'fetch')
  let session: Awaited<ReturnType<typeof publicSession>> | undefined
  let view: LayoutPublicStatusView | undefined
  const errors: unknown[] = []
  let ownsClock = false
  try {
    vi.useFakeTimers({
      toFake: [
        'Date',
        'setTimeout',
        'clearTimeout',
        'setInterval',
        'clearInterval',
      ],
    })
    ownsClock = true
    vi.setSystemTime(now)
    window.history.replaceState(null, '', '/status')
    const files = publicFiles(now)
    Object.defineProperty(globalThis, 'fetch', {
      configurable: true,
      writable: true,
      value: files.fetch,
    })
    const feed = layoutChromeFeed(options.protectedMode)
    if (options.state !== undefined) feed.state(options.state)
    if (options.firstFrameHeld !== undefined)
      feed.injectFirstFrameHold(options.firstFrameHeld)
    const { router, route } = layoutChromeRouter()
    route.set({ page: HilosPages.PUBLIC_STATUS, params: {}, admin: false })
    router.resolvePath = (page) =>
      page === HilosPages.PUBLIC_STATUS ? '/status' : '/'
    session = await publicSession(router)
    if (options.initialHandshake) session.handshake(options.initialHandshake())
    view = await mount({
      connection: feed.connection,
      actions: session.actions,
      router,
      brand: options.brand,
      allow: options.allow,
      modalOpen: options.modalOpen,
      onSave: options.onSave,
      retain(partialView) {
        view = partialView
      },
    })
    const mounted = view
    const commit = (step?: () => void) =>
      mounted.flush(async () => {
        step?.()
        // Deliver this pass only, without advancing polling or heartbeat age.
        await vi.advanceTimersByTimeAsync(0)
      })
    await commit()
    await run({ view, feed, route, session, requests: files.requests, commit })
  } catch (error) {
    errors.push(error)
  } finally {
    const cleanup = async (release: () => void | Promise<void>) => {
      try {
        await release()
      } catch (error) {
        errors.push(error)
      }
    }
    // Reject actions and deliver their continuations while the view is alive.
    await cleanup(async () => {
      if (view) await view.flush(() => session?.source.disconnect())
      else {
        session?.source.disconnect()
        await Promise.resolve()
      }
    })
    await cleanup(async () => {
      if (view) await view.flush(() => session?.unbind())
      else await session?.unbind()
    })
    await cleanup(() => view?.unmount())
    await cleanup(() => hilosToasts.clear())
    await cleanup(() => {
      if (fetchDescriptor)
        Object.defineProperty(globalThis, 'fetch', fetchDescriptor)
      else Reflect.deleteProperty(globalThis, 'fetch')
    })
    await cleanup(() => window.history.replaceState(entryState, '', entryHref))
    await cleanup(() => applyServerTime(Date.now() + entryOffset))
    await cleanup(() => {
      if (ownsClock) vi.useRealTimers()
    })
  }
  if (errors.length === 1) throw errors[0]
  if (errors.length > 1)
    throw new AggregateError(errors, 'Public Status case and cleanup failed')
}
