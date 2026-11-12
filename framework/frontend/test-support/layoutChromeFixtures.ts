// External inputs for real Layout chrome, without session or restore machinery.
import {
  createSignal,
  PROTECTED_MODE_INACTIVE,
  RT_STALENESS_FRESH,
  type ConnectionState,
  type HilosConnection,
  type HilosConnectionEventMap,
  type HilosRouter,
  type PageRouteMatch,
  type ProtectedModeStatus,
  type RtStalenessStatus,
} from '@hilos/core/base'

export const FROZEN: ProtectedModeStatus = {
  active: true,
  operation: 'restore',
  title: 'Restoring a backup',
  message: 'The application will be back in a few minutes.',
  bannerMessage: undefined,
  acceptsPass: false,
  passIssued: false,
  passRejected: false,
}

const VERIFYING: ProtectedModeStatus = { ...FROZEN, acceptsPass: true }

/** The verification window seen by a browser already admitted to the shell. */
export const ADMITTED: ProtectedModeStatus = {
  ...VERIFYING,
  active: false,
  title: undefined,
  message: undefined,
  bannerMessage: 'The restore is being verified.',
}

/** Complete initial chrome snapshot; subscriptions detach through native disposal. */
export function layoutChromeConnection(
  protectedMode: ProtectedModeStatus = PROTECTED_MODE_INACTIVE,
): HilosConnection {
  return layoutChromeFeed(protectedMode).connection
}

/** Controlled chrome inputs publish their backing snapshot before notification. */
export function layoutChromeFeed(
  protectedMode: ProtectedModeStatus = PROTECTED_MODE_INACTIVE,
  initialBuild?: string,
) {
  const snapshot = {
    protectedMode,
    firstFrameHeld: false,
    build: initialBuild,
    state: 'connected' as ConnectionState,
    rtStaleness: RT_STALENESS_FRESH,
    reconnectDragging: false,
  }
  const presented: string[] = []
  const listeners = new Map<
    keyof HilosConnectionEventMap,
    Set<(payload: never) => void>
  >()
  const source = {
    get state() {
      return snapshot.state
    },
    get protectedMode() {
      return snapshot.protectedMode
    },
    get rtStaleness() {
      return snapshot.rtStaleness
    },
    get reconnectDragging() {
      return snapshot.reconnectDragging
    },
    get firstFrameHeld() {
      return snapshot.firstFrameHeld
    },
    get build() {
      return snapshot.build
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
    presentProtectedModePass(rawCode) {
      presented.push(rawCode)
    },
  } satisfies Pick<
    HilosConnection,
    | 'state'
    | 'protectedMode'
    | 'rtStaleness'
    | 'reconnectDragging'
    | 'firstFrameHeld'
    | 'build'
    | 'on'
    | 'presentProtectedModePass'
  >
  return {
    connection: source as unknown as HilosConnection,
    presented,
    listenerCount(event: keyof HilosConnectionEventMap): number {
      return listeners.get(event)?.size ?? 0
    },
    listenerCounts(): readonly number[] {
      return [...listeners.values()].map((subscriptions) => subscriptions.size)
    },
    // Snapshot integration only, not a parsed transport welcome.
    handshake(build: string | undefined): void {
      snapshot.build = build
      for (const listener of listeners.get('handshake') ?? [])
        listener({} as never)
    },
    state(value: ConnectionState): void {
      snapshot.state = value
      for (const listener of listeners.get('state') ?? [])
        listener(value as never)
    },
    reconnectDragging(value: boolean): void {
      snapshot.reconnectDragging = value
      for (const listener of listeners.get('reconnectDragging') ?? [])
        listener(value as never)
    },
    protectedMode(value: ProtectedModeStatus): void {
      snapshot.protectedMode = value
      for (const listener of listeners.get('protectedMode') ?? [])
        listener(value as never)
    },
    rtStaleness(value: RtStalenessStatus): void {
      snapshot.rtStaleness = value
      for (const listener of listeners.get('rtStaleness') ?? [])
        listener(value as never)
    },
    // Defensive synthetic input only: a real connection emits release (false).
    injectFirstFrameHold(value: boolean): void {
      snapshot.firstFrameHeld = value
      for (const listener of listeners.get('firstFrameHold') ?? [])
        listener(value as never)
    },
  }
}

/** A writable route input, separate from the readonly router given to Layout. */
export function layoutChromeRouter() {
  const route = createSignal<PageRouteMatch>({
    page: null,
    params: {},
    admin: false,
  })
  const router: HilosRouter = {
    currentRoute: route,
    currentPath: createSignal(''),
    currentTitle: createSignal(''),
    pageError: createSignal(null),
    pageLoading: createSignal(false),
    pageIdentity: createSignal(undefined),
    dashboardSections: createSignal(undefined),
    resolvePath: () => undefined,
    clearPageError: () => {},
    denyCurrentPage: () => {},
    awaitPageAnswer: () => {},
    onLeave: () => () => {},
    navigate: () => {},
    replacePath: () => {},
    start: () => {},
    stop: () => {},
  }
  return { router, route }
}

/** Native caller content only; the SDK owns every shell region and strip. */
export interface LayoutChromeInput {
  connection: HilosConnection
  banner?: { dataId: string; text: string }
  user?: { dataId: string; text: string }
  projectUserControls?: boolean
  nav?: boolean
  pageAction?: boolean
  router?: HilosRouter
}

/** Native lifecycle and external inputs; no disclosure decisions live here. */
export interface LayoutChromeView {
  readonly root: HTMLElement
  update(input: Partial<Omit<LayoutChromeInput, 'router'>>): Promise<void>
  flush(run?: () => void): Promise<void>
  unmount(): Promise<void>
}

export type MountLayoutChrome = (
  input: LayoutChromeInput,
  retain?: (view: LayoutChromeView) => void,
) => Promise<LayoutChromeView>
