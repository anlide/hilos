import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createHilosConnection } from '../../src/connection/createHilosConnection.js'
import { STATUS_FILES_CHANGED_SIGNAL } from '../../src/protocol/statusSignals.js'
import { applyServerTime } from '../../src/session/serverClock.js'
import { subscribeSignal } from '../../src/state/signal.js'
import {
  createPublicStatus,
  type PublicStatusFetch,
  type PublicStatusOptions,
} from '../../src/status/index.js'
import {
  bindTimeZoneState,
  hilosTimeZone,
} from '../../src/timeZone/timeZoneState.js'
import {
  deferred,
  heartbeat,
  NOW,
  outage,
  response,
  Socket,
  statusFile,
} from './fixtures.js'

const cleanup: Array<() => void> = []

beforeEach(() => {
  vi.useFakeTimers()
  vi.setSystemTime(NOW)
})
afterEach(() => {
  for (const off of cleanup.splice(0).reverse()) off()
  vi.useRealTimers()
  vi.restoreAllMocks()
  vi.unstubAllGlobals()
  applyServerTime(Date.now())
})

function mount(live = false, options: PublicStatusOptions = {}) {
  const socket = new Socket()
  const factory = vi.fn(() => socket)
  const { connection } = createHilosConnection({
    url: 'ws://test/ws',
    webSocketFactory: factory,
  })
  cleanup.push(() => connection.close())
  if (live) {
    connection.connect()
    socket.emit('open')
  }
  let status = statusFile()
  let beat = heartbeat()
  let ok = true
  const fetchFile = vi.fn<PublicStatusFetch>(async (url) =>
    response(url.endsWith('/status.json') ? status : beat, ok),
  )
  const reader = createPublicStatus(connection, {
    fetch: fetchFile,
    ...options,
  })
  cleanup.push(() => reader.dispose())

  return {
    reader,
    socket,
    connection,
    factory,
    fetchFile,
    hint: () => socket.push(STATUS_FILES_CHANGED_SIGNAL),
    publish: (nextStatus = statusFile(), nextBeat = heartbeat()) => {
      status = nextStatus
      beat = nextBeat
    },
    fail: () => {
      ok = false
    },
    start: async () => {
      reader.start()
      await vi.advanceTimersByTimeAsync(0)
    },
  }
}

async function settle(): Promise<void> {
  await vi.advanceTimersByTimeAsync(0)
}

describe('public reader lifecycle and scheduling', () => {
  it('cold reads before any socket or handshake; start is idempotent and does not send', async () => {
    const h = mount()
    expect(h.reader.freshness.get()).toEqual({
      kind: 'unknown',
      aliveAt: null,
      since: null,
    })
    expect(h.reader.banner.get().kind).toBe('unknown')
    expect(h.reader.dayStrips.get()).toEqual([])
    await h.start()
    h.reader.start()
    expect(h.factory).not.toHaveBeenCalled()
    expect(h.socket.send).not.toHaveBeenCalled()
    expect(h.fetchFile).toHaveBeenCalledTimes(2)
    expect(h.reader.overall.get()).toBe('operational')
    expect(h.reader.connectionMessage.get()).toBe(
      'Your live connection to the site is lost — the system is up',
    )
  })

  it('uses 30 seconds until the first pair, then its accepted poll interval', async () => {
    const fetchFile = vi.fn<PublicStatusFetch>(async () =>
      response(null, false),
    )
    const h = mount(false, { fetch: fetchFile })
    await h.start()
    await vi.advanceTimersByTimeAsync(29999)
    expect(fetchFile).toHaveBeenCalledTimes(2)
    fetchFile.mockImplementation(async (url) =>
      response(
        url.endsWith('/status.json')
          ? statusFile({
              config: { pollIntervalSeconds: 7, staleAfterSeconds: 120 },
            })
          : heartbeat(),
      ),
    )
    await vi.advanceTimersByTimeAsync(1)
    expect(fetchFile).toHaveBeenCalledTimes(4)
    await vi.advanceTimersByTimeAsync(6999)
    expect(fetchFile).toHaveBeenCalledTimes(4)
    await vi.advanceTimersByTimeAsync(1)
    expect(fetchFile).toHaveBeenCalledTimes(6)
  })

  it('reads hints, remains event-driven when live, and rereads immediately on recovery', async () => {
    const h = mount(true)
    await h.start()
    await vi.advanceTimersByTimeAsync(31000)
    expect(h.fetchFile).toHaveBeenCalledTimes(2)
    h.hint()
    await settle()
    expect(h.fetchFile).toHaveBeenCalledTimes(4)
    h.connection.close()
    expect(h.reader.liveConnection.get()).toBe(false)
    await vi.advanceTimersByTimeAsync(30000)
    expect(h.fetchFile).toHaveBeenCalledTimes(6)
    h.connection.connect()
    h.socket.emit('open')
    await settle()
    expect(h.fetchFile).toHaveBeenCalledTimes(8)
    expect(h.reader.liveConnection.get()).toBe(true)
    expect(h.reader.connectionMessage.get()).toBeNull()
  })

  it('polls while freeze holds an open socket; admitted recovery reads immediately', async () => {
    const h = mount(true)
    await h.start()
    h.socket.push('protected_mode', { active: true })
    expect(h.socket.readyState).toBe(1)
    expect(h.reader.liveConnection.get()).toBe(false)
    await vi.advanceTimersByTimeAsync(30000)
    expect(h.fetchFile).toHaveBeenCalledTimes(4)
    h.socket.push('protected_mode', { active: false, acceptsPass: true })
    await settle()
    expect(h.reader.liveConnection.get()).toBe(true)
    expect(h.fetchFile).toHaveBeenCalledTimes(6)
    await vi.advanceTimersByTimeAsync(30000)
    expect(h.fetchFile).toHaveBeenCalledTimes(6)
  })

  it('coalesces a hint storm and recovery into one later pass, never parallel passes', async () => {
    const blocked = deferred<Pick<Response, 'ok' | 'json'>>()
    const fetchFile = vi.fn<PublicStatusFetch>(async (url) =>
      url.endsWith('/status.json') ? blocked.promise : response(heartbeat()),
    )
    const h = mount(true, { fetch: fetchFile })
    h.reader.start()
    for (let i = 0; i < 20; i++) h.hint()
    h.socket.push('protected_mode', { active: true })
    h.socket.push('protected_mode', { active: false, acceptsPass: true })
    expect(fetchFile).toHaveBeenCalledTimes(2)
    blocked.resolve(response(statusFile()))
    await settle()
    expect(fetchFile).toHaveBeenCalledTimes(4)
    await settle()
    expect(fetchFile).toHaveBeenCalledTimes(4)
  })

  it('bounds revision races to three attempts, then waits for another hint', async () => {
    const h = mount(true)
    h.publish(statusFile(), heartbeat({ revision: 'new generation' }))
    await h.start()
    expect(h.fetchFile).toHaveBeenCalledTimes(6)
    expect(h.reader.projection.get()).toBeNull()
    await vi.advanceTimersByTimeAsync(30000)
    expect(h.fetchFile).toHaveBeenCalledTimes(6)
    h.publish(
      statusFile({ revision: 'new generation' }),
      heartbeat({ revision: 'new generation' }),
    )
    h.hint()
    await settle()
    expect(h.reader.projection.get()?.revision).toBe('new generation')
  })

  it('rereads both files on a race and atomically replaces the whole prior projection', async () => {
    const h = mount(true)
    await h.start()
    let attempt = 0
    h.fetchFile.mockImplementation(async (url) => {
      if (url.endsWith('/status.json')) {
        attempt++
        return response(
          statusFile({
            revision: attempt === 1 ? 'old' : 'new',
            components: [],
          }),
        )
      }
      return response(heartbeat({ revision: 'new', aliveAt: NOW + 1 }))
    })
    const seen: Array<string | undefined> = []
    cleanup.push(
      subscribeSignal(h.reader.projection, (value) =>
        seen.push(value?.revision),
      ),
    )
    h.hint()
    await settle()
    expect(h.fetchFile).toHaveBeenCalledTimes(6)
    expect(seen).toEqual(['new'])
    expect(h.reader.components.get()).toEqual([])
    expect(h.reader.freshness.get().aliveAt).toBe(NOW + 1)
  })

  it('aborts a disposed mount, ignores late completion and detaches all work', async () => {
    const blocked = deferred<Pick<Response, 'ok' | 'json'>>()
    const fetchFile = vi.fn<PublicStatusFetch>(async (url) =>
      url.endsWith('/status.json') ? blocked.promise : response(heartbeat()),
    )
    const h = mount(true, { fetch: fetchFile })
    const on = vi.spyOn(h.connection, 'on')
    h.reader.start()
    expect(on).toHaveBeenCalledTimes(3)
    const signal = fetchFile.mock.calls[0]?.[1].signal
    h.reader.dispose()
    expect(signal?.aborted).toBe(true)
    const replacement = createPublicStatus(h.connection, {
      fetch: async (url) =>
        response(
          url.endsWith('/status.json')
            ? statusFile({ revision: 'replacement' })
            : heartbeat({ revision: 'replacement' }),
        ),
    })
    cleanup.push(() => replacement.dispose())
    replacement.start()
    await settle()
    blocked.resolve(response(statusFile()))
    await settle()
    expect(h.reader.projection.get()).toBeNull()
    expect(replacement.projection.get()?.revision).toBe('replacement')
    h.hint()
    h.socket.push('protected_mode', { active: true })
    expect(h.reader.liveConnection.get()).toBe(true)
    await vi.advanceTimersByTimeAsync(60000)
    expect(fetchFile).toHaveBeenCalledTimes(2)
    h.reader.start()
    h.reader.dispose()
    replacement.dispose()
    h.connection.close()
    expect(vi.getTimerCount()).toBe(0)
  })
})

describe('freshness and truthful current state', () => {
  it('has no invented since or health after a first failed read', async () => {
    const h = mount()
    h.fail()
    await h.start()
    await vi.advanceTimersByTimeAsync(130000)
    expect(h.reader.freshness.get()).toEqual({
      kind: 'unknown',
      aliveAt: null,
      since: null,
    })
    expect(h.reader.banner.get()).toEqual({
      kind: 'unknown',
      title: 'Status unknown',
      since: null,
    })
    expect(h.reader.connectionMessage.get()).toBeNull()
    expect(h.reader.history.get()).toEqual([])
  })

  it.each([-3600000, 3600000])(
    'stales at strictly greater than the threshold with a live socket, ignoring server offset %i',
    async (offset) => {
      applyServerTime(NOW + offset)
      const h = mount(true)
      await h.start()
      await vi.advanceTimersByTimeAsync(120000)
      expect(h.reader.freshness.get().kind).toBe('fresh')
      await vi.advanceTimersByTimeAsync(1)
      expect(h.reader.freshness.get()).toEqual({
        kind: 'stale',
        aliveAt: NOW,
        since: NOW,
      })
      expect(h.reader.banner.get().kind).toBe('not_responding')
      expect(h.reader.banner.get().since).toBe(NOW)
      expect(h.reader.components.get()[0]?.state).toBe('unknown')
      expect(h.reader.liveConnection.get()).toBe(true)
      expect(h.fetchFile).toHaveBeenCalledTimes(2)
      h.publish(statusFile(), heartbeat({ aliveAt: Date.now() }))
      h.hint()
      await settle()
      expect(h.reader.freshness.get().kind).toBe('fresh')
      expect(h.reader.overall.get()).toBe('operational')
      expect(h.reader.banner.get().since).toBeNull()
    },
  )

  it('invalid files/hints do not renew aliveAt or erase last known history', async () => {
    const h = mount(true)
    h.publish(statusFile({ outages: [outage({ resolvedAt: NOW - 1000 })] }))
    await h.start()
    const accepted = h.reader.projection.get()
    await vi.advanceTimersByTimeAsync(119000)
    h.fetchFile.mockImplementation(async (url) =>
      response(
        url.endsWith('/status.json')
          ? {
              ...statusFile({ generatedAt: Date.now() }),
              privateError: 'hidden',
            }
          : heartbeat({ aliveAt: Date.now() }),
      ),
    )
    h.hint()
    await settle()
    expect(h.reader.projection.get()).toBe(accepted)
    expect(h.reader.freshness.get().aliveAt).toBe(NOW)
    await vi.advanceTimersByTimeAsync(1001)
    expect(h.reader.freshness.get().kind).toBe('stale')
    expect(h.reader.history.get()).toHaveLength(1)
    expect(h.reader.dayStrips.get()[0]?.days.at(-1)?.state).toBe('major_outage')
  })

  it.each(['major_outage', 'maintenance'] as const)(
    'keeps %s and its facts beside the lost-connection explanation',
    async (state) => {
      const h = mount()
      h.publish(
        statusFile({
          components: [
            { componentKey: 'site', name: 'Site', description: null, state },
          ],
          outages: [outage()],
        }),
      )
      await h.start()
      expect(h.reader.overall.get()).toBe(state)
      expect(h.reader.connectionMessage.get()).not.toBeNull()
      expect(h.reader.currentOutages.get()).toHaveLength(1)
    },
  )

  it('keeps published facts when the component is Unknown, but never fabricates Operational', async () => {
    const h = mount(true)
    h.publish(
      statusFile({
        components: [
          {
            componentKey: 'site',
            name: 'Site',
            description: null,
            state: 'unknown',
          },
        ],
        outages: [outage()],
      }),
    )
    await h.start()
    expect(h.reader.overall.get()).toBe('major_outage')
    expect(h.reader.components.get()[0]?.state).toBe('unknown')
    h.publish(statusFile({ components: [] }))
    h.hint()
    await settle()
    expect(h.reader.overall.get()).toBe('unknown')
  })
})

describe('calendar dependencies without server events', () => {
  it('moves the window at local midnight and changes the reader zone without another read', async () => {
    vi.setSystemTime(Date.parse('2026-12-31T23:59:59Z'))
    const h = mount(true)
    cleanup.push(bindTimeZoneState(h.connection))
    h.socket.push('handshake_response', {
      entities: { currentUser: { id: 1, timeZone: 'UTC' } },
    })
    expect(hilosTimeZone.get()).toBe('UTC')
    await h.start()
    const before = h.reader.dayStrips.get()[0]?.days
    expect(before?.at(-1)?.startTs).toBe(Date.parse('2026-12-31T00:00:00Z'))
    const format = vi.spyOn(Intl, 'DateTimeFormat')
    await vi.advanceTimersByTimeAsync(1000)
    expect(h.reader.dayStrips.get()[0]?.days.at(-1)?.startTs).toBe(
      Date.parse('2027-01-01T00:00:00Z'),
    )
    const calendarReads = format.mock.calls.length
    await vi.advanceTimersByTimeAsync(5000)
    h.reader.dayStrips.get()
    expect(format.mock.calls.length).toBe(calendarReads)
    h.socket.push('handshake_response', {
      entities: { currentUser: { id: 1, timeZone: 'America/Los_Angeles' } },
    })
    expect(hilosTimeZone.get()).toBe('America/Los_Angeles')
    expect(h.reader.dayStrips.get()[0]?.days.at(-1)?.startTs).toBe(
      Date.parse('2026-12-31T08:00:00Z'),
    )
    expect(h.fetchFile).toHaveBeenCalledTimes(2)
  })
})
