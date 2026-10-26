// One mounted screen owns this memory: public projection, requests and timers.
// Hilos::$rt was considered (framework-first.md, runtime/rt-context.md and
// truth-source.md): replicated server state cannot own a browser's private read
// lifecycle. No user data, persistent storage or connection-wide cache lives here.
import { type HilosConnection } from '../connection/HilosConnection.js'
import { STATUS_FILES_CHANGED_SIGNAL } from '../protocol/statusSignals.js'
import {
  computedSignal,
  createSignal,
  subscribeSignal,
  type ReadonlySignal,
} from '../state/signal.js'
import { calendarDays } from '../timeZone/calendarPeriods.js'
import { hilosTimeZone } from '../timeZone/timeZoneState.js'
import {
  publicStatusBanner,
  publicStatusDayStrips,
  publicStatusHistory,
  publicStatusOutageViews,
  publicStatusOverall,
  type PublicStatusBanner,
  type PublicStatusDayStrip,
  type PublicStatusHistoryEntry,
  type PublicStatusOutageView,
} from './derivedStatus.js'
import {
  readPublicStatusFiles,
  type PublicStatusComponent,
  type PublicStatusFetch,
  type PublicStatusFile,
  type PublicStatusPair,
  type PublicStatusState,
} from './statusFiles.js'

const DEFAULT_POLL_SECONDS = 30
const MILLISECONDS_PER_SECOND = 1000
const CLOCK_TICK_MS = 1000
const HISTORY_DAYS = 90
const MAX_PAIR_ATTEMPTS = 3
// Browser timeouts clamp larger delays to 1 ms. Long publisher configurations
// must instead be revisited at the largest supported delay.
const MAX_TIMEOUT_MS = 2_147_483_647

/** Unknown has no since; only an accepted heartbeat can supply one. */
export type PublicStatusFreshness =
  | { readonly kind: 'unknown'; readonly aliveAt: null; readonly since: null }
  | { readonly kind: 'fresh'; readonly aliveAt: number; readonly since: null }
  | { readonly kind: 'stale'; readonly aliveAt: number; readonly since: number }

/** Transport and clock seams for deterministic browser-controller tests. */
export interface PublicStatusOptions {
  readonly fetch?: PublicStatusFetch
  readonly now?: () => number
}

/** File-derived, read-only state for any SDK view of public /status. */
export interface PublicStatus {
  readonly projection: ReadonlySignal<PublicStatusFile | null>
  readonly freshness: ReadonlySignal<PublicStatusFreshness>
  readonly liveConnection: ReadonlySignal<boolean>
  readonly connectionMessage: ReadonlySignal<string | null>
  readonly components: ReadonlySignal<readonly PublicStatusComponent[]>
  readonly overall: ReadonlySignal<PublicStatusState>
  readonly banner: ReadonlySignal<PublicStatusBanner>
  readonly currentOutages: ReadonlySignal<readonly PublicStatusOutageView[]>
  readonly history: ReadonlySignal<readonly PublicStatusHistoryEntry[]>
  readonly dayStrips: ReadonlySignal<readonly PublicStatusDayStrip[]>
  /** Read immediately on mount. Idempotent; never opens or subscribes a socket. */
  start(): void
  /** Terminal, idempotent teardown. Late responses cannot publish afterwards. */
  dispose(): void
}

/**
 * Read a coherent nginx pair independently of handshake, identity and page state.
 * Only one pass runs at once; hints during it reserve one subsequent pass.
 */
export function createPublicStatus(
  connection: HilosConnection,
  options: PublicStatusOptions = {},
): PublicStatus {
  const readNow = options.now ?? Date.now
  const pair = createSignal<PublicStatusPair | null>(null)
  const now = createSignal(readNow())
  const calendarAnchor = createSignal(now.get())
  const days = computedSignal(() =>
    calendarDays(calendarAnchor.get(), hilosTimeZone.get(), HISTORY_DAYS),
  )
  const usable = (): boolean =>
    connection.state === 'connected' && !connection.protectedMode.active
  const liveConnection = createSignal(usable())
  const projection = computedSignal(() => pair.get()?.status ?? null)
  const freshness = computedSignal<PublicStatusFreshness>(() => {
    const accepted = pair.get()
    if (accepted === null)
      return { kind: 'unknown', aliveAt: null, since: null }
    const aliveAt = accepted.heartbeat.aliveAt

    return now.get() - aliveAt >
      accepted.status.config.staleAfterSeconds * MILLISECONDS_PER_SECOND
      ? { kind: 'stale', aliveAt, since: aliveAt }
      : { kind: 'fresh', aliveAt, since: null }
  })
  const components = computedSignal<readonly PublicStatusComponent[]>(() => {
    const current = projection.get()?.components ?? []

    return freshness.get().kind === 'stale'
      ? current.map((component) => ({ ...component, state: 'unknown' }))
      : current
  })
  const overall = computedSignal<PublicStatusState>(() =>
    freshness.get().kind === 'fresh'
      ? publicStatusOverall(components.get(), projection.get())
      : 'unknown',
  )
  const banner = computedSignal(() =>
    publicStatusBanner(overall.get(), freshness.get().since),
  )
  const connectionMessage = computedSignal(() =>
    freshness.get().kind === 'fresh' && !liveConnection.get()
      ? 'Your live connection to the site is lost — the system is up'
      : null,
  )
  const currentOutages = computedSignal(() =>
    publicStatusOutageViews(
      projection
        .get()
        ?.outages.filter((outage) => outage.resolvedAt === null) ?? [],
      now.get(),
    ),
  )
  const history = computedSignal(() =>
    publicStatusHistory(projection.get(), days.get()),
  )
  const dayStrips = computedSignal(() => {
    const status = projection.get()

    return status === null
      ? []
      : publicStatusDayStrips(status, days.get(), now.get())
  })

  let started = false
  let disposed = false
  let reading = false
  let pending = false
  let abort: AbortController | null = null
  let pollTimer: ReturnType<typeof setTimeout> | undefined
  let clockTimer: ReturnType<typeof setTimeout> | undefined
  const teardown: Array<() => void> = []

  const clearPoll = (): void => {
    clearTimeout(pollTimer)
    pollTimer = undefined
  }
  const armPoll = (): void => {
    clearPoll()
    if (!started || disposed || liveConnection.get()) return
    const seconds =
      projection.get()?.config.pollIntervalSeconds ?? DEFAULT_POLL_SECONDS
    pollTimer = setTimeout(
      () => {
        requestRead()
      },
      Math.min(MAX_TIMEOUT_MS, seconds * MILLISECONDS_PER_SECOND),
    )
  }
  const tick = (): void => {
    if (disposed) return
    now.set(readNow())
    const window = days.get()
    const last = window[window.length - 1]
    if (
      last !== undefined &&
      (now.get() < last.startTs || now.get() >= last.endTs)
    ) {
      calendarAnchor.set(now.get())
    }
    clearTimeout(clockTimer)
    const accepted = pair.get()
    const untilStale =
      accepted === null
        ? CLOCK_TICK_MS
        : accepted.heartbeat.aliveAt +
          accepted.status.config.staleAfterSeconds * MILLISECONDS_PER_SECOND -
          now.get() +
          1
    clockTimer = setTimeout(
      tick,
      Math.min(
        CLOCK_TICK_MS,
        untilStale > 0 ? untilStale : CLOCK_TICK_MS,
        (days.get().at(-1)?.endTs ?? now.get() + CLOCK_TICK_MS) - now.get(),
      ),
    )
  }
  const read = async (): Promise<void> => {
    reading = true
    const request = new AbortController()
    abort = request
    try {
      for (let attempt = 0; attempt < MAX_PAIR_ATTEMPTS; attempt++) {
        const result = await readPublicStatusFiles(
          request.signal,
          options.fetch,
        )
        if (disposed || request.signal.aborted) return
        if (result.kind === 'valid') {
          now.set(readNow())
          pair.set(result.pair)
          tick()
          break
        }
        if (result.kind !== 'revision-mismatch') break
      }
    } finally {
      reading = false
      abort = null
      if (!disposed) {
        if (pending) {
          pending = false
          requestRead()
        } else armPoll()
      }
    }
  }
  const requestRead = (): void => {
    if (!started || disposed) return
    if (reading) {
      pending = true
      return
    }
    clearPoll()
    void read()
  }
  const syncConnection = (): void => {
    const previous = liveConnection.get()
    liveConnection.set(usable())
    armPoll()
    if (!previous && liveConnection.get()) requestRead()
  }

  return {
    projection,
    freshness,
    liveConnection,
    connectionMessage,
    components,
    overall,
    banner,
    currentOutages,
    history,
    dayStrips,
    start() {
      if (started || disposed) return
      started = true
      liveConnection.set(usable())
      calendarAnchor.set(readNow())
      teardown.push(
        connection.on('state', syncConnection),
        connection.on('protectedMode', syncConnection),
        connection.on('projectSignal', (signal) => {
          if (signal.type === STATUS_FILES_CHANGED_SIGNAL) requestRead()
        }),
        subscribeSignal(hilosTimeZone, () => calendarAnchor.set(readNow())),
      )
      tick()
      requestRead()
    },
    dispose() {
      if (disposed) return
      disposed = true
      clearPoll()
      clearTimeout(clockTimer)
      abort?.abort()
      for (const off of teardown.splice(0)) off()
    },
  }
}
