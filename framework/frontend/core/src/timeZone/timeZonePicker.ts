// One open window's search and clock, over the existing normalized page list
// and account-zone signals. This projection belongs to one browser instance;
// Hilos::$rt would replicate UI work no other worker or node needs to read
// (framework-first.md, runtime/rt-context.md, architecture/truth-source.md).
import { type ActionHandle } from '../connection/actionLifecycle.js'
import { formatTimeZoneChoiceMoment } from '../format/moment.js'
import { type HilosProfilePageContext } from '../profile/profileRoot.js'
import { offsetMs } from '../session/serverClock.js'
import {
  computedSignal,
  createSignal,
  type ReadonlySignal,
} from '../state/signal.js'
import { usableTimeZone } from './timeZoneRule.js'
import { hilosBrowserTimeZone, hilosOwnTimeZone } from './timeZoneState.js'

const PROFILE_TIME_ZONES_LIST = 'profileTimeZones'
const TIME_ZONE_PICK_ACTION = 'hilos_time_zone_pick'
/** Row payload key of the exact IANA name, kept scalar by the normalizer. */
const TIME_ZONE_FIELD = 'timeZone'
const MINUTE_MS = 60000

/** A named choice, or the separate Automatic reset choice. */
export interface HilosTimeZonePickerRow {
  /** Exact account value to pick; null resets to Automatic. */
  readonly timeZone: string | null
  /** Exact IANA name; for Automatic, its browser/project/UTC fallback name. */
  readonly label: string
  /** HH:mm, or null when this browser cannot show this zone's clock. */
  readonly time: string | null
  /** Follows the confirmed account choice, never a pending action. */
  readonly selected: boolean
  /** Named choices require a usable clock; Automatic always permits reset. */
  readonly available: boolean
}

/** Headless state of one Time zone window; the view owns tracked actions. */
export interface HilosTimeZonePicker {
  /** The input as typed; normalization is confined to the search copy. */
  readonly query: ReadonlySignal<string>
  /** Sorted named choices matching the search, without Automatic. */
  readonly rows: ReadonlySignal<readonly HilosTimeZonePickerRow[]>
  /** The reset choice, hidden while the normalized search is nonempty. */
  readonly automatic: ReadonlySignal<HilosTimeZonePickerRow | null>
  setQuery(query: string): void
  /** Reset the search, refresh now and run one clock until stop/dispose. */
  start(): void
  stop(): void
  dispose(): void
  /** Dispatch through the existing lifecycle; the session echo moves the mark. */
  pick(timeZone: string | null): ActionHandle
}

/** Search alone equates spaces and underscores, without changing saved names. */
function searchCopy(value: string): string {
  return value.replaceAll('_', ' ').trim().toLowerCase()
}

/**
 * Create one profile window over its normalized server catalog.
 *
 * @param context The profile's existing connection, page scope and action lifecycle.
 */
export function createHilosTimeZonePicker(
  context: HilosProfilePageContext,
): HilosTimeZonePicker {
  const items = context.scopes.pageListSignal(PROFILE_TIME_ZONES_LIST)
  const query = createSignal('')
  const search = computedSignal(() => searchCopy(query.get()))
  const now = createSignal(Date.now() + offsetMs())
  let disposed = false
  let stopClock: (() => void) | null = null

  const names = computedSignal(() => {
    const zones = new Set<string>()
    for (const item of items.get()) {
      const zone = item.slots[TIME_ZONE_FIELD]
      if (typeof zone === 'string' && zone !== '' && zone.trim() === zone) {
        zones.add(zone)
      }
    }
    const own = hilosOwnTimeZone.get()
    if (own !== null) zones.add(own)

    return [...zones].sort()
  })

  const rows = computedSignal(() => {
    const current = now.get()
    const own = hilosOwnTimeZone.get()
    const needle = search.get()

    return names
      .get()
      .filter((zone) => searchCopy(zone).includes(needle))
      .map((zone): HilosTimeZonePickerRow => {
        const time = formatTimeZoneChoiceMoment(current, zone)

        return {
          timeZone: zone,
          label: zone,
          time,
          selected: zone === own,
          available: time !== null,
        }
      })
  })

  const automatic = computedSignal((): HilosTimeZonePickerRow | null => {
    if (search.get() !== '') return null
    const zone =
      hilosBrowserTimeZone.get() ??
      usableTimeZone(context.connection.projectTimeZone) ??
      'UTC'

    return {
      timeZone: null,
      label: zone,
      time: formatTimeZoneChoiceMoment(now.get(), zone),
      selected: hilosOwnTimeZone.get() === null,
      available: true,
    }
  })

  const stop = (): void => {
    stopClock?.()
    stopClock = null
  }

  return {
    query,
    rows,
    automatic,
    setQuery(value) {
      if (!disposed) query.set(value)
    },
    start() {
      if (disposed) return
      stop()
      query.set('')
      let active = true
      let timer: ReturnType<typeof setTimeout> | undefined
      const refresh = (): void => {
        if (!active) return
        clearTimeout(timer)
        const current = Date.now() + offsetMs()
        now.set(current)
        if (!active) return
        timer = setTimeout(
          refresh,
          MINUTE_MS - (((current % MINUTE_MS) + MINUTE_MS) % MINUTE_MS),
        )
      }
      const page = typeof document === 'undefined' ? null : document
      const onVisibility = (): void => {
        if (page?.visibilityState === 'visible') refresh()
      }
      globalThis.addEventListener?.('focus', refresh)
      page?.addEventListener?.('visibilitychange', onVisibility)
      stopClock = () => {
        active = false
        clearTimeout(timer)
        globalThis.removeEventListener?.('focus', refresh)
        page?.removeEventListener?.('visibilitychange', onVisibility)
      }
      refresh()
    },
    stop,
    dispose() {
      stop()
      disposed = true
    },
    pick: (timeZone) =>
      context.actions.dispatch(TIME_ZONE_PICK_ACTION, { timeZone }),
  }
}
