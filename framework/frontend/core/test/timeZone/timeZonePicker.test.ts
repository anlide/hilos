import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import {
  ActionLifecycle,
  type ActionLifecycleSource,
} from '../../src/connection/actionLifecycle.js'
import { type HilosConnection } from '../../src/connection/HilosConnection.js'
import { type HilosProfilePageContext } from '../../src/profile/profileRoot.js'
import {
  type ActionSuccessSignal,
  type ProjectSignal,
} from '../../src/protocol/parseSignal.js'
import { applyServerTime } from '../../src/session/serverClock.js'
import { ScopeManager } from '../../src/state/ScopeManager.js'
import { ingest } from '../../src/state/normalizer.js'
import { subscribeSignal } from '../../src/state/signal.js'
import {
  createHilosTimeZonePicker,
  type HilosTimeZonePicker,
} from '../../src/timeZone/timeZonePicker.js'
import {
  bindTimeZoneState,
  hilosOwnTimeZone,
  hilosTimeZone,
} from '../../src/timeZone/timeZoneState.js'

const resolved = new Intl.DateTimeFormat().resolvedOptions()
let browserZone: string | undefined
let windowEvents: EventTarget
let pageEvents: EventTarget & { visibilityState: DocumentVisibilityState }
const disposers: (() => void)[] = []

/** A session echo uses the real zone binding; actions use the real lifecycle. */
function setup(projectTimeZone = 'Asia/Tokyo') {
  const listeners = new Map<string, Set<(signal: never) => void>>()
  const source = {
    projectTimeZone,
    sendAction: vi.fn(() => true),
    on(event: string, listener: (signal: never) => void): () => void {
      let set = listeners.get(event)
      if (!set) {
        set = new Set()
        listeners.set(event, set)
      }
      set.add(listener)

      return () => {
        set.delete(listener)
      }
    },
  }
  function emit(event: string, signal: unknown): void {
    for (const listener of listeners.get(event) ?? []) listener(signal as never)
  }
  const connection = source as unknown as HilosConnection
  disposers.push(bindTimeZoneState(connection))
  const actions = new ActionLifecycle(source as ActionLifecycleSource)
  const scopes = new ScopeManager()
  const page = scopes.openPage('hilos_profile')
  const context: HilosProfilePageContext = { connection, actions, scopes }
  const picker = createHilosTimeZonePicker(context)
  disposers.push(() => picker.dispose())

  return {
    picker,
    context,
    scopes,
    page,
    source,
    catalog(zones: readonly string[]) {
      ingest(page, {
        lists: {
          profileTimeZones: {
            cleared: true,
            items: zones.map((timeZone) => ({
              itemKey: timeZone,
              slots: { timeZone },
            })),
          },
        },
      })
    },
    choose(timeZone: string | null) {
      emit('projectSignal', {
        type: 'handshake_response',
        data: { entities: { currentUser: { id: 1, timeZone } } },
      } as ProjectSignal)
    },
    success(requestId: string) {
      emit('actionSuccess', {
        kind: 'actionSuccess',
        action: 'hilos_time_zone_pick',
        requestId,
        message: undefined,
        reply: undefined,
        envelope: { type: 'action_success', data: {} },
      } satisfies ActionSuccessSignal)
    },
  }
}

function names(picker: HilosTimeZonePicker): (string | null)[] {
  return picker.rows.get().map((row) => row.timeZone)
}

beforeEach(() => {
  vi.useFakeTimers({ toFake: ['Date', 'setTimeout', 'clearTimeout'] })
  vi.setSystemTime(new Date('2026-01-01T00:00:10Z'))
  applyServerTime(Date.now())
  browserZone = 'Europe/Warsaw'
  vi.spyOn(Intl.DateTimeFormat.prototype, 'resolvedOptions').mockImplementation(
    () => ({
      ...resolved,
      timeZone: browserZone as string,
    }),
  )
  windowEvents = new EventTarget()
  pageEvents = Object.assign(new EventTarget(), {
    visibilityState: 'visible' as DocumentVisibilityState,
  })
  vi.stubGlobal(
    'addEventListener',
    windowEvents.addEventListener.bind(windowEvents),
  )
  vi.stubGlobal(
    'removeEventListener',
    windowEvents.removeEventListener.bind(windowEvents),
  )
  vi.stubGlobal('document', pageEvents)
})

afterEach(() => {
  for (const dispose of disposers.splice(0).reverse()) dispose()
  vi.restoreAllMocks()
  vi.unstubAllGlobals()
  vi.useRealTimers()
  applyServerTime(Date.now())
})

describe('normalized profile catalog and search', () => {
  it('uses only the server list, sorted and deduplicated, including UTC', () => {
    const { picker, catalog, page } = setup()
    expect(names(picker)).toEqual([])
    catalog(['UTC', 'Europe/Warsaw', 'America/New_York'])
    ingest(page, {
      lists: {
        profileTimeZones: {
          items: [{ itemKey: 'duplicate', slots: { timeZone: 'UTC' } }],
        },
      },
    })
    expect(names(picker)).toEqual(['America/New_York', 'Europe/Warsaw', 'UTC'])
    expect(picker.rows.get()[2]).toEqual({
      timeZone: 'UTC',
      label: 'UTC',
      time: '00:00',
      available: true,
      selected: false,
    })
    catalog(['Asia/Kathmandu'])
    expect(names(picker)).toEqual(['Asia/Kathmandu'])
    catalog([])
    expect(names(picker)).toEqual([])
    expect(picker.automatic.get()?.available).toBe(true)
    expect(vi.getTimerCount()).toBe(0)
  })

  it('keeps a usable backward alias once without canonicalizing it', () => {
    const { picker, catalog, choose } = setup()
    choose('US/Eastern')
    catalog(['UTC', 'America/New_York'])
    expect(names(picker)).toEqual(['America/New_York', 'US/Eastern', 'UTC'])
    expect(picker.rows.get().find((row) => row.selected)?.timeZone).toBe(
      'US/Eastern',
    )
    catalog(['US/Eastern', 'UTC'])
    expect(names(picker)).toEqual(['US/Eastern', 'UTC'])
  })

  it.each([' New York ', 'new_york', 'AMERICA/NEW_YORK', 'yOrK'])(
    'searches full IDs and city names with %s',
    (query) => {
      const { picker, catalog } = setup()
      catalog(['UTC', 'America/New_York', 'Europe/Warsaw'])
      picker.setQuery(query)
      expect(picker.query.get()).toBe(query)
      expect(names(picker)).toEqual(['America/New_York'])
      expect(picker.automatic.get()).toBeNull()
      picker.setQuery('no such city')
      expect(names(picker)).toEqual([])
      picker.setQuery('  ')
      expect(names(picker)).toHaveLength(3)
      expect(picker.automatic.get()).not.toBeNull()
      picker.setQuery('')
      expect(names(picker)).toHaveLength(3)
    },
  )

  it('ignores malformed scalar slots and old payloads without inventing a catalog', () => {
    const { picker, page, scopes, choose } = setup()
    ingest(page, { data: { profileTimeZones: ['UTC'] } })
    expect(names(picker)).toEqual([])
    ingest(page, {
      lists: {
        profileTimeZones: {
          items: [
            { itemKey: 1, slots: {} },
            { itemKey: 2, slots: { timeZone: null } },
            { itemKey: 3, slots: { timeZone: 42 } },
            { itemKey: 4, slots: { timeZone: { id: 1, timeZone: 'UTC' } } },
            { itemKey: 5, slots: { timeZone: ['UTC'] } },
            { itemKey: 6, slots: { timeZone: '' } },
            { itemKey: 7, slots: { timeZone: ' UTC ' } },
          ],
        },
      },
    })
    expect(names(picker)).toEqual([])
    choose('US/Eastern')
    expect(names(picker)).toEqual(['US/Eastern'])
    scopes.openPage('another_page')
    expect(names(picker)).toEqual(['US/Eastern'])
    choose(null)
    expect(names(picker)).toEqual([])
    expect(picker.automatic.get()?.available).toBe(true)
  })

  it('retains a server zone unavailable in this browser with no misleading clock', () => {
    const { picker, catalog } = setup()
    catalog(['Unknown/Zone', 'UTC'])
    expect(picker.rows.get()[1]).toEqual({
      timeZone: 'Unknown/Zone',
      label: 'Unknown/Zone',
      time: null,
      selected: false,
      available: false,
    })
  })
})

describe('Automatic and authoritative choice', () => {
  it('previews the browser zone even with a different own and project zone', () => {
    const { picker, catalog, choose } = setup()
    catalog(['UTC', 'Asia/Tokyo'])
    choose('Asia/Tokyo')
    expect(hilosTimeZone.get()).toBe('Asia/Tokyo')
    expect(picker.automatic.get()).toEqual({
      timeZone: null,
      label: 'Europe/Warsaw',
      time: '01:00',
      selected: false,
      available: true,
    })
    picker.setQuery('tokyo')
    choose('UTC')
    expect(picker.query.get()).toBe('tokyo')
    expect(picker.rows.get()[0]?.selected).toBe(false)
    choose('Asia/Tokyo')
    expect(picker.rows.get()[0]?.selected).toBe(true)
    choose(null)
    picker.setQuery('')
    expect(picker.automatic.get()?.selected).toBe(true)
    expect(picker.rows.get().some((row) => row.selected)).toBe(false)
  })

  it('falls back from an absent browser to a usable project, then UTC', () => {
    browserZone = undefined
    expect(setup('Asia/Kathmandu').picker.automatic.get()).toMatchObject({
      label: 'Asia/Kathmandu',
      time: '05:45',
    })
    expect(setup('Unknown/Zone').picker.automatic.get()).toMatchObject({
      label: 'UTC',
      time: '00:00',
    })
  })

  it('leaves Automatic reset available even without Intl', () => {
    vi.stubGlobal('Intl', undefined)
    const { picker, catalog } = setup()
    catalog(['UTC'])
    expect(picker.automatic.get()).toEqual({
      timeZone: null,
      label: 'UTC',
      time: null,
      selected: true,
      available: true,
    })
    expect(picker.rows.get()[0]).toMatchObject({ time: null, available: false })
  })

  it('returns the exact ActionHandle and sends only timeZone; success waits for session echo', async () => {
    const { picker, catalog, source, context, success, choose } = setup()
    catalog(['UTC'])
    const dispatch = vi.spyOn(context.actions, 'dispatch')
    const handle = picker.pick('UTC')
    expect(dispatch).toHaveBeenCalledExactlyOnceWith('hilos_time_zone_pick', {
      timeZone: 'UTC',
    })
    expect(handle).toBe(dispatch.mock.results[0]?.value)
    expect(source.sendAction).toHaveBeenLastCalledWith(
      'hilos_time_zone_pick',
      { timeZone: 'UTC' },
      handle.requestId,
    )
    expect(hilosOwnTimeZone.get()).toBeNull()
    expect(picker.rows.get()[0]?.selected).toBe(false)
    success(handle.requestId)
    await handle.done
    expect(hilosOwnTimeZone.get()).toBeNull()
    expect(picker.rows.get()[0]?.selected).toBe(false)
    choose('UTC')
    expect(picker.rows.get()[0]?.selected).toBe(true)
    const reset = picker.pick(null)
    expect(source.sendAction).toHaveBeenLastCalledWith(
      'hilos_time_zone_pick',
      { timeZone: null },
      reset.requestId,
    )
    success(reset.requestId)
    await reset.done
    expect(hilosOwnTimeZone.get()).toBe('UTC')
    choose(null)
    expect(picker.automatic.get()?.selected).toBe(true)
  })
})

describe('one local minute clock', () => {
  it('starts at server now, schedules one minute boundary and never reloads the catalog', () => {
    const { picker, catalog, scopes } = setup()
    catalog(['UTC', 'Asia/Kathmandu'])
    const readList = vi.spyOn(scopes, 'pageListSignal')
    applyServerTime(Date.now() + 25000)
    picker.setQuery('tokyo')
    picker.start()
    expect(picker.query.get()).toBe('')
    expect(picker.rows.get().map((row) => row.time)).toEqual(['05:45', '00:00'])
    expect(vi.getTimerCount()).toBe(1)
    vi.advanceTimersByTime(24999)
    expect(picker.rows.get()[1]?.time).toBe('00:00')
    vi.advanceTimersByTime(1)
    expect(picker.rows.get().map((row) => row.time)).toEqual(['05:46', '00:01'])
    vi.advanceTimersByTime(60000)
    expect(picker.rows.get()[1]?.time).toBe('00:02')
    expect(vi.getTimerCount()).toBe(1)
    expect(readList).not.toHaveBeenCalled()
  })

  it('samples Date.now once for all rows, even near a minute edge', () => {
    const { picker, catalog } = setup()
    catalog(['UTC', 'Asia/Kathmandu', 'Europe/Warsaw'])
    const now = vi
      .spyOn(Date, 'now')
      .mockReturnValue(Date.parse('2026-01-01T00:00:59.999Z'))
    picker.start()
    expect(picker.rows.get().map((row) => row.time)).toEqual([
      '05:45',
      '01:00',
      '00:00',
    ])
    expect(picker.automatic.get()?.time).toBe('01:00')
    expect(now).toHaveBeenCalledTimes(1)
  })

  it('refreshes on focus and visible, ignores hidden, and reschedules the minute', () => {
    const { picker, catalog } = setup()
    catalog(['UTC'])
    picker.start()
    vi.setSystemTime(new Date('2026-01-01T01:23:42Z'))
    windowEvents.dispatchEvent(new Event('focus'))
    expect(picker.rows.get()[0]?.time).toBe('01:23')
    vi.setSystemTime(new Date('2026-01-01T02:34:55Z'))
    pageEvents.visibilityState = 'hidden'
    pageEvents.dispatchEvent(new Event('visibilitychange'))
    expect(picker.rows.get()[0]?.time).toBe('01:23')
    pageEvents.visibilityState = 'visible'
    pageEvents.dispatchEvent(new Event('visibilitychange'))
    expect(picker.rows.get()[0]?.time).toBe('02:34')
    expect(vi.getTimerCount()).toBe(1)
    vi.advanceTimersByTime(5000)
    expect(picker.rows.get()[0]?.time).toBe('02:35')
  })

  it.each([
    ['2026-03-08T06:59:50Z', '01:59', '03:00'],
    ['2026-11-01T05:59:50Z', '01:59', '01:00'],
  ])('keeps DST and non-hour clocks coherent at %s', (at, before, after) => {
    const { picker, catalog } = setup()
    catalog(['America/New_York', 'Asia/Kathmandu'])
    vi.setSystemTime(new Date(at))
    picker.start()
    expect(picker.rows.get()[0]?.time).toBe(before)
    const kathmandu = picker.rows.get()[1]?.time
    vi.advanceTimersByTime(10000)
    expect(picker.rows.get()[0]?.time).toBe(after)
    expect(picker.rows.get()[1]?.time).not.toBe(kathmandu)
  })

  it('stops and disposes idempotently, detaches listeners and rejects stale callbacks', () => {
    const { picker, catalog } = setup()
    catalog(['UTC'])
    const addWindow = vi.spyOn(globalThis, 'addEventListener')
    const removeWindow = vi.spyOn(globalThis, 'removeEventListener')
    const addPage = vi.spyOn(pageEvents, 'addEventListener')
    const removePage = vi.spyOn(pageEvents, 'removeEventListener')
    const timeout = vi.spyOn(globalThis, 'setTimeout')
    picker.start()
    const stale = timeout.mock.calls[0]?.[0] as () => void
    picker.start()
    picker.stop()
    picker.stop()
    expect(vi.getTimerCount()).toBe(0)
    const rows = picker.rows.get()
    vi.advanceTimersByTime(300000)
    windowEvents.dispatchEvent(new Event('focus'))
    pageEvents.dispatchEvent(new Event('visibilitychange'))
    stale()
    expect(picker.rows.get()).toBe(rows)
    picker.setQuery('utc')
    picker.start()
    expect(picker.query.get()).toBe('')
    expect(picker.rows.get()[0]?.time).toBe('00:05')
    stale()
    expect(vi.getTimerCount()).toBe(1)
    const changed = vi.fn()
    const release = subscribeSignal(picker.rows, changed)
    picker.dispose()
    picker.dispose()
    picker.start()
    picker.setQuery('ignored')
    vi.advanceTimersByTime(300000)
    expect(changed).not.toHaveBeenCalled()
    expect(picker.query.get()).toBe('')
    expect(vi.getTimerCount()).toBe(0)
    expect(removeWindow).toHaveBeenCalledTimes(addWindow.mock.calls.length)
    expect(removePage).toHaveBeenCalledTimes(addPage.mock.calls.length)
    release()
  })
})
