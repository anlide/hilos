import {
  hilosToasts,
  PROTECTED_MODE_INACTIVE,
  RT_STALENESS_FRESH,
  SIGNAL_HANDSHAKE_RESPONSE,
  type ConnectionState,
  type HilosConnection,
  type HilosConnectionEventMap,
  type ProjectSignal,
  type ProtectedModeStatus,
} from '@hilos/core/base'
import { layoutChromeRouter } from './layoutChromeFixtures.js'
import {
  bindLayoutLifetimeSession,
  reconsentHandshake,
} from './layoutLifetimeSessionFixtures.js'

export interface LayoutLifetimeOptions {
  firstFrameHeld?: boolean
}

/** Raw native component callbacks, never a prediction of the active branch. */
export interface LayoutLifetimeEvent {
  phase: 'start' | 'stop'
  instance: object
}

/** One source for the whole attempt; every notification follows its snapshot. */
function layoutLifetimeFeed(firstFrameHeld: boolean) {
  const snapshot = {
    state: 'connected' as ConnectionState,
    protectedMode: PROTECTED_MODE_INACTIVE,
    firstFrameHeld,
  }
  const listeners = new Map<
    keyof HilosConnectionEventMap,
    Set<(payload: never) => void>
  >()
  function emit<E extends keyof HilosConnectionEventMap>(
    event: E,
    payload: HilosConnectionEventMap[E],
  ): void {
    for (const listener of listeners.get(event) ?? [])
      listener(payload as never)
  }
  const source = {
    get state() {
      return snapshot.state
    },
    get protectedMode() {
      return snapshot.protectedMode
    },
    get firstFrameHeld() {
      return snapshot.firstFrameHeld
    },
    rtStaleness: RT_STALENESS_FRESH,
    reconnectDragging: false,
    build: undefined,
    sendAction() {
      return snapshot.state === 'connected'
    },
    on(event, listener) {
      const subscriptions = listeners.get(event) ?? new Set()
      listeners.set(event, subscriptions)
      const notify = (payload: never): void => listener(payload)
      subscriptions.add(notify)
      return () => {
        subscriptions.delete(notify)
      }
    },
  } satisfies Pick<
    HilosConnection,
    | 'state'
    | 'protectedMode'
    | 'firstFrameHeld'
    | 'rtStaleness'
    | 'reconnectDragging'
    | 'build'
    | 'sendAction'
    | 'on'
  >
  return {
    connection: source as unknown as HilosConnection,
    state(value: ConnectionState): void {
      snapshot.state = value
      emit('state', value)
    },
    protectedMode(value: ProtectedModeStatus): void {
      snapshot.protectedMode = value
      emit('protectedMode', value)
    },
    releaseFirstFrame(): void {
      snapshot.firstFrameHeld = false
      emit('firstFrameHold', false)
    },
    handshake(payload: Record<string, unknown>): void {
      emit('projectSignal', {
        kind: 'project',
        type: SIGNAL_HANDSHAKE_RESPONSE,
        data: payload,
        envelope: {},
      } as ProjectSignal)
    },
    clear(): void {
      listeners.clear()
    },
  }
}

/** Own the external session before any native component can subscribe. */
export function layoutLifetimeFixture(options: LayoutLifetimeOptions) {
  const feed = layoutLifetimeFeed(options.firstFrameHeld ?? false)
  let release: (() => void) | undefined
  try {
    release = bindLayoutLifetimeSession(feed.connection)
    feed.handshake({
      ...reconsentHandshake(null),
      data: { accountBlocked: null, accountStanding: null },
    })
    const events: LayoutLifetimeEvent[] = []
    return {
      feed,
      router: layoutChromeRouter().router,
      events,
      async dispose(): Promise<void> {
        // Settle pending legal reads through the actual lifecycle's drop path.
        try {
          feed.state('disconnected')
          release?.()
          await Promise.resolve()
        } finally {
          feed.clear()
          hilosToasts.clear()
        }
      },
    }
  } catch (error) {
    feed.state('disconnected')
    release?.()
    feed.clear()
    hilosToasts.clear()
    throw error
  }
}

export interface LayoutLifetimeView {
  readonly root: HTMLElement
  readonly events: readonly LayoutLifetimeEvent[]
  readonly feed: ReturnType<typeof layoutLifetimeFixture>['feed']
  flush(run?: () => void): Promise<void>
  unmount(): Promise<void>
}

export type LayoutLifetimeCheckpoint =
  | 'L01-created'
  | 'L01-edited'
  | 'L01-replaced'
  | 'L01-restored'
  | 'L02-created'
  | 'L02-replaced'
  | 'L02-restored'
  | 'L03-held'
  | 'L03-released'
  | 'L04-created'
  | 'L04-disconnected'
  | 'L04-connected'
