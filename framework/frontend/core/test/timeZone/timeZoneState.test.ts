import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import {
  type HandshakeSignal,
  type HilosConnection,
  type ProjectSignal,
} from '../../src/index.js'
import {
  bindSessionScope,
  type SessionScopeOptions,
} from '../../src/session/sessionScope.js'
import { USER_ENTITY_TYPE } from '../../src/state/entity.js'
import { ingest } from '../../src/state/normalizer.js'
import { ScopeManager } from '../../src/state/ScopeManager.js'
import { subscribeSignal } from '../../src/state/signal.js'
import {
  bindTimeZoneState,
  hilosBrowserTimeZone,
  hilosOwnTimeZone,
  hilosTimeZone,
} from '../../src/timeZone/timeZoneState.js'

const resolved = new Intl.DateTimeFormat().resolvedOptions()

/**
 * Make the engine report `timeZone` as this browser's zone.
 *
 * @param timeZone The zone the next reads report.
 */
function reportZone(timeZone: unknown): void {
  vi.spyOn(Intl.DateTimeFormat.prototype, 'resolvedOptions').mockReturnValue({
    ...resolved,
    timeZone: timeZone as string,
  })
}

/** The slice of a connection the zone state reads: two events and one getter. */
function fakeConnection(projectTimeZone?: string) {
  const handshakeListeners = new Set<(signal: HandshakeSignal) => void>()
  const projectListeners = new Set<(signal: ProjectSignal) => void>()

  return {
    projectTimeZone,
    on(event: string, listener: (payload: never) => void): () => void {
      const listeners =
        event === 'handshake'
          ? (handshakeListeners as Set<(payload: never) => void>)
          : event === 'projectSignal'
            ? (projectListeners as Set<(payload: never) => void>)
            : new Set<(payload: never) => void>()
      listeners.add(listener)

      return () => {
        listeners.delete(listener)
      }
    },
    welcome(zone?: string): void {
      // The real connection stores the field before it emits the event.
      this.projectTimeZone = zone
      const signal = {
        type: 'handshake',
        data: { build: 'test' },
      } as unknown as HandshakeSignal
      for (const listener of handshakeListeners) {
        listener(signal)
      }
    },
    emit(type: string, data: unknown): void {
      const signal = { type, data } as ProjectSignal
      for (const listener of projectListeners) {
        listener(signal)
      }
    },
    // The session scope bound beside the zone state never lets go of its own.
    listenerCount: () => handshakeListeners.size + projectListeners.size - 1,
  }
}

type FakeConnection = ReturnType<typeof fakeConnection>

let windowEvents: EventTarget
let page: EventTarget & { visibilityState: DocumentVisibilityState }
let release: (() => void) | null = null

function start(
  connection: FakeConnection = fakeConnection(),
  options?: SessionScopeOptions,
) {
  const scopes = new ScopeManager()
  bindSessionScope(connection as unknown as HilosConnection, scopes, options)
  release = bindTimeZoneState(connection as unknown as HilosConnection, options)

  return { connection, scopes }
}

/**
 * Record every change of a signal from now on.
 *
 * @param signal The signal to watch.
 */
function changes<T>(signal: { get(): T }): T[] {
  const seen: T[] = []
  subscribeSignal(signal, (value) => seen.push(value))

  return seen
}

function session(currentUser: unknown, extra: Record<string, unknown> = {}) {
  return { entities: { currentUser, ...extra } }
}

/**
 * Open one tab: its own instance of the module, bound to its own connection,
 * in a browser that reports `browserTimeZone` whenever this tab reads it.
 *
 * @param browserTimeZone The zone this tab's browser reports.
 */
async function openTab(browserTimeZone: string) {
  vi.resetModules()
  const state = await import('../../src/timeZone/timeZoneState.js')
  const connection = fakeConnection()
  reportZone(browserTimeZone)

  return {
    state,
    release: state.bindTimeZoneState(connection as unknown as HilosConnection),
    receive(data: unknown): void {
      // The tabs share this process's engine, so each answers as its own
      // browser while it takes the frame in.
      reportZone(browserTimeZone)
      connection.emit('handshake_response', data)
    },
  }
}

beforeEach(() => {
  reportZone('Europe/Warsaw')
  windowEvents = new EventTarget()
  page = Object.assign(new EventTarget(), {
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
  vi.stubGlobal('document', page)
})

afterEach(() => {
  release?.()
  release = null
  vi.restoreAllMocks()
  vi.unstubAllGlobals()
})

describe('before and at bind', () => {
  it('reads UTC with no inputs before the first bind', async () => {
    vi.resetModules()
    const fresh = await import('../../src/timeZone/timeZoneState.js')
    expect(fresh.hilosOwnTimeZone.get()).toBeNull()
    expect(fresh.hilosBrowserTimeZone.get()).toBeNull()
    expect(fresh.hilosTimeZone.get()).toBe('UTC')
  })

  it("publishes the browser's zone and the connection's project zone, with no own zone yet", () => {
    start(fakeConnection('America/New_York'))
    expect(hilosOwnTimeZone.get()).toBeNull()
    expect(hilosBrowserTimeZone.get()).toBe('Europe/Warsaw')
    expect(hilosTimeZone.get()).toBe('Europe/Warsaw')

    reportZone(undefined)
    start(fakeConnection('America/New_York'))
    expect(hilosBrowserTimeZone.get()).toBeNull()
    expect(hilosTimeZone.get()).toBe('America/New_York')

    start(fakeConnection())
    expect(hilosTimeZone.get()).toBe('UTC')
  })

  it('starts a rebind without the own zone of the previous binding', () => {
    const { connection } = start()
    connection.emit(
      'handshake_response',
      session({ id: 1, timeZone: 'Asia/Tokyo' }),
    )
    expect(hilosOwnTimeZone.get()).toBe('Asia/Tokyo')

    start()
    expect(hilosOwnTimeZone.get()).toBeNull()
    expect(hilosTimeZone.get()).toBe('Europe/Warsaw')
  })
})

describe('welcome', () => {
  it('takes the project zone from the connection on every welcome and clears it with the field', () => {
    reportZone(undefined)
    const { connection } = start()
    expect(hilosTimeZone.get()).toBe('UTC')

    connection.welcome('America/New_York')
    expect(hilosTimeZone.get()).toBe('America/New_York')

    connection.welcome('Not/AZone')
    expect(hilosTimeZone.get()).toBe('UTC')

    connection.welcome('Asia/Tokyo')
    connection.welcome()
    expect(hilosTimeZone.get()).toBe('UTC')
  })

  it("reads the browser again and keeps the person's choice until the next response", () => {
    const { connection } = start(fakeConnection('America/New_York'))
    connection.emit(
      'handshake_response',
      session({ id: 1, timeZone: 'Asia/Tokyo' }),
    )
    reportZone('Europe/Lisbon')

    connection.welcome('America/New_York')
    expect(hilosBrowserTimeZone.get()).toBe('Europe/Lisbon')
    expect(hilosOwnTimeZone.get()).toBe('Asia/Tokyo')
    expect(hilosTimeZone.get()).toBe('Asia/Tokyo')
  })
})

describe('session response', () => {
  it('follows a choice, wakes nobody on its restate, and returns to the browser on Automatic', () => {
    const { connection } = start()
    const own = changes(hilosOwnTimeZone)
    const effective = changes(hilosTimeZone)

    connection.emit(
      'handshake_response',
      session({ id: 1, timeZone: 'Asia/Tokyo' }),
    )
    expect(hilosOwnTimeZone.get()).toBe('Asia/Tokyo')
    expect(hilosTimeZone.get()).toBe('Asia/Tokyo')

    connection.emit(
      'handshake_response',
      session({ id: 1, timeZone: 'Asia/Tokyo' }),
    )
    expect(own).toEqual(['Asia/Tokyo'])
    expect(effective).toEqual(['Asia/Tokyo'])

    connection.emit('handshake_response', session({ id: 1, timeZone: null }))
    expect(hilosOwnTimeZone.get()).toBeNull()
    expect(hilosTimeZone.get()).toBe('Europe/Warsaw')
    expect(own).toEqual(['Asia/Tokyo', null])
    expect(effective).toEqual(['Asia/Tokyo', 'Europe/Warsaw'])
  })

  it('clears the own zone for a guest, and takes the zone of another person', () => {
    const { connection } = start()
    connection.emit(
      'handshake_response',
      session({ id: 1, timeZone: 'Asia/Tokyo' }),
    )

    connection.emit('handshake_response', session(null))
    expect(hilosOwnTimeZone.get()).toBeNull()
    expect(hilosTimeZone.get()).toBe('Europe/Warsaw')

    connection.emit(
      'handshake_response',
      session({ id: 2, timeZone: 'America/New_York' }),
    )
    expect(hilosOwnTimeZone.get()).toBe('America/New_York')
  })

  it("reads the person acted as, never the impersonating admin's zone", () => {
    const { connection } = start()
    connection.emit(
      'handshake_response',
      session(
        { id: 2, timeZone: null },
        { impersonatedBy: { id: 1, timeZone: 'Asia/Tokyo' } },
      ),
    )
    expect(hilosOwnTimeZone.get()).toBeNull()

    connection.emit(
      'handshake_response',
      session(
        { id: 2, timeZone: 'America/New_York' },
        { impersonatedBy: { id: 1, timeZone: 'Asia/Tokyo' } },
      ),
    )
    expect(hilosOwnTimeZone.get()).toBe('America/New_York')
  })

  it('drops the earlier choice of the same person when an older server omits the field', () => {
    const { connection } = start()
    connection.emit(
      'handshake_response',
      session({ id: 1, timeZone: 'Asia/Tokyo' }),
    )

    connection.emit('handshake_response', session({ id: 1, name: 'Ada' }))
    expect(hilosOwnTimeZone.get()).toBeNull()
    expect(hilosTimeZone.get()).toBe('Europe/Warsaw')
  })

  it.each([
    ['no entities', { data: {} }],
    ['no current-user slot', { entities: { impersonatedBy: null } }],
    [
      'a list under the current-user slot',
      session([{ id: 1, timeZone: 'America/New_York' }]),
    ],
  ])('keeps the last own zone through %s', (_label, data) => {
    const { connection } = start()
    connection.emit(
      'handshake_response',
      session({ id: 1, timeZone: 'Asia/Tokyo' }),
    )
    const own = changes(hilosOwnTimeZone)

    connection.emit('handshake_response', data)
    expect(hilosOwnTimeZone.get()).toBe('Asia/Tokyo')
    expect(own).toEqual([])
  })

  it.each([
    ['an unknown name', 'Mars/Olympus'],
    ['an offset', '+02:00'],
    ['an empty name', ''],
    ['a number', 120],
  ])('takes %s as no choice and goes down the chain', (_label, timeZone) => {
    const connection = fakeConnection('America/New_York')
    start(connection)
    connection.emit('handshake_response', session({ id: 1, timeZone }))
    expect(hilosOwnTimeZone.get()).toBeNull()
    expect(hilosTimeZone.get()).toBe('Europe/Warsaw')

    reportZone('Mars/Olympus')
    connection.emit('handshake_response', session({ id: 1, timeZone }))
    expect(hilosTimeZone.get()).toBe('America/New_York')

    connection.welcome()
    expect(hilosTimeZone.get()).toBe('UTC')
  })

  it('wakes the own reader but not the effective one when the choice equals the browser', () => {
    const { connection } = start()
    const own = changes(hilosOwnTimeZone)
    const effective = changes(hilosTimeZone)

    connection.emit(
      'handshake_response',
      session({ id: 1, timeZone: 'Europe/Warsaw' }),
    )
    connection.emit('handshake_response', session({ id: 1, timeZone: null }))
    expect(own).toEqual(['Europe/Warsaw', null])
    expect(effective).toEqual([])
  })

  it('publishes one effective zone when a reset and a new browser zone arrive together', () => {
    const { connection } = start()
    connection.emit(
      'handshake_response',
      session({ id: 1, timeZone: 'Asia/Tokyo' }),
    )
    const effective = changes(hilosTimeZone)
    reportZone('America/New_York')

    connection.emit('handshake_response', session({ id: 1, timeZone: null }))
    expect(effective).toEqual(['America/New_York'])
  })

  it('ignores page and group copies of the person and a slot it does not read', () => {
    const { connection, scopes } = start()
    const ref = { type: USER_ENTITY_TYPE, id: 1 }
    const entityTypes = { owner: USER_ENTITY_TYPE }
    ingest(
      scopes.openPage('profile'),
      { entities: { owner: { id: 1, timeZone: 'Asia/Tokyo' } } },
      { entityTypes },
    )
    ingest(
      scopes.openGroup('people'),
      { entities: { owner: { id: 1, timeZone: 'America/New_York' } } },
      { entityTypes },
    )

    connection.emit(
      'handshake_response',
      session(
        { id: 1, name: 'Ada' },
        { me: { id: 1, timeZone: 'Asia/Tokyo' } },
      ),
    )
    expect(scopes.entitySignal(ref).get()?.fields.timeZone).toBe('Asia/Tokyo')
    expect(hilosOwnTimeZone.get()).toBeNull()
    expect(hilosTimeZone.get()).toBe('Europe/Warsaw')
  })

  it('reads the configured current-user slot', () => {
    const { connection } = start(fakeConnection(), { currentUserSlot: 'me' })
    connection.emit('handshake_response', {
      entities: {
        currentUser: { id: 1, timeZone: 'Asia/Tokyo' },
        me: { id: 2, timeZone: 'America/New_York' },
      },
    })
    expect(hilosOwnTimeZone.get()).toBe('America/New_York')

    connection.emit('handshake_response', {
      entities: { currentUser: { id: 1, timeZone: 'Asia/Tokyo' } },
    })
    expect(hilosOwnTimeZone.get()).toBe('America/New_York')
  })

  it('reads no other project signal', () => {
    const { connection } = start()
    reportZone('Asia/Tokyo')
    connection.emit('hilos_theme_settings', session({ id: 1, timeZone: null }))
    expect(hilosBrowserTimeZone.get()).toBe('Europe/Warsaw')
  })
})

describe('browser return', () => {
  it('reads the browser again on window focus and on becoming visible, never while hidden', () => {
    start()
    const browser = changes(hilosBrowserTimeZone)

    reportZone('Asia/Tokyo')
    windowEvents.dispatchEvent(new Event('focus'))
    expect(hilosBrowserTimeZone.get()).toBe('Asia/Tokyo')

    reportZone('America/New_York')
    page.visibilityState = 'hidden'
    page.dispatchEvent(new Event('visibilitychange'))
    expect(hilosBrowserTimeZone.get()).toBe('Asia/Tokyo')

    page.visibilityState = 'visible'
    page.dispatchEvent(new Event('visibilitychange'))
    expect(hilosTimeZone.get()).toBe('America/New_York')
    expect(browser).toEqual(['Asia/Tokyo', 'America/New_York'])
  })

  it.each([
    ['no window events and no document', undefined],
    ['a document without events', { documentElement: {} }],
  ])('binds and releases with %s', (_label, documentStub) => {
    vi.stubGlobal('addEventListener', undefined)
    vi.stubGlobal('removeEventListener', undefined)
    vi.stubGlobal('document', documentStub)
    const connection = fakeConnection('America/New_York')
    expect(() => start(connection)).not.toThrow()
    connection.emit(
      'handshake_response',
      session({ id: 1, timeZone: 'Asia/Tokyo' }),
    )
    expect(hilosTimeZone.get()).toBe('Asia/Tokyo')
    expect(() => release?.()).not.toThrow()
  })
})

describe('release', () => {
  it('stops every input, stays readable, and is idempotent', () => {
    const { connection } = start()
    connection.emit(
      'handshake_response',
      session({ id: 1, timeZone: 'Asia/Tokyo' }),
    )
    release?.()
    release?.()
    expect(connection.listenerCount()).toBe(0)

    reportZone('America/New_York')
    connection.emit('handshake_response', session(null))
    connection.welcome('America/New_York')
    windowEvents.dispatchEvent(new Event('focus'))
    page.dispatchEvent(new Event('visibilitychange'))
    expect(hilosOwnTimeZone.get()).toBe('Asia/Tokyo')
    expect(hilosBrowserTimeZone.get()).toBe('Europe/Warsaw')
    expect(hilosTimeZone.get()).toBe('Asia/Tokyo')
  })

  it('lets a new bind release the old one, and an old release leave the new one alone', () => {
    const first = start().connection
    const releaseFirst = release
    const second = start().connection
    expect(first.listenerCount()).toBe(0)
    expect(second.listenerCount()).toBe(2)

    second.emit(
      'handshake_response',
      session({ id: 1, timeZone: 'Asia/Tokyo' }),
    )
    releaseFirst?.()
    expect(second.listenerCount()).toBe(2)
    expect(hilosOwnTimeZone.get()).toBe('Asia/Tokyo')

    first.emit('handshake_response', session(null))
    expect(hilosOwnTimeZone.get()).toBe('Asia/Tokyo')
    reportZone('America/New_York')
    windowEvents.dispatchEvent(new Event('focus'))
    expect(hilosBrowserTimeZone.get()).toBe('America/New_York')
    second.emit('handshake_response', session(null))
    expect(hilosTimeZone.get()).toBe('America/New_York')
  })
})

describe('independent tabs', () => {
  // Each tab applies a frame already delivered to it. That the server delivers
  // the change to every session of the person is proved by HIL-1580, not here.
  it('gives every tab the one delivered choice, and a reset returns each to its own browser', async () => {
    const warsaw = await openTab('Europe/Warsaw')
    const newYork = await openTab('America/New_York')
    try {
      expect(warsaw.state.hilosTimeZone.get()).toBe('Europe/Warsaw')
      expect(newYork.state.hilosTimeZone.get()).toBe('America/New_York')

      const choice = session({ id: 1, timeZone: 'Asia/Tokyo' })
      warsaw.receive(choice)
      expect(warsaw.state.hilosTimeZone.get()).toBe('Asia/Tokyo')
      expect(newYork.state.hilosTimeZone.get()).toBe('America/New_York')

      newYork.receive(choice)
      for (const tab of [warsaw, newYork]) {
        expect(tab.state.hilosOwnTimeZone.get()).toBe('Asia/Tokyo')
        expect(tab.state.hilosTimeZone.get()).toBe('Asia/Tokyo')
      }
      expect(warsaw.state.hilosBrowserTimeZone.get()).toBe('Europe/Warsaw')
      expect(newYork.state.hilosBrowserTimeZone.get()).toBe('America/New_York')

      const reset = session({ id: 1, timeZone: null })
      warsaw.receive(reset)
      newYork.receive(reset)
      for (const tab of [warsaw, newYork]) {
        expect(tab.state.hilosOwnTimeZone.get()).toBeNull()
      }
      expect(warsaw.state.hilosTimeZone.get()).toBe('Europe/Warsaw')
      expect(newYork.state.hilosTimeZone.get()).toBe('America/New_York')
    } finally {
      warsaw.release()
      newYork.release()
    }
  })
})
