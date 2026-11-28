import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { z } from 'zod'
import { type ProjectSignal } from '../../src/base/index.js'
import {
  HilosConnection,
  type WebSocketLike,
} from '../../src/connection/HilosConnection.js'
import {
  bindTabLanguageState,
  hilosTabLanguage,
  type HilosTabLanguage,
} from '../../src/language/tabLanguageState.js'
import { SIGNAL_HANDSHAKE_RESPONSE } from '../../src/protocol/constants.js'
import { subscribeSignal } from '../../src/state/signal.js'

const GERMAN: HilosTabLanguage = {
  languageId: 2,
  languageCode: 'de',
  rtl: false,
  localeId: 7,
  localeCode: 'de-DE',
  formats: {
    date: 'DD.MM.YYYY',
    time: 'HH:mm:ss',
    number: '1.000,00',
    phone: '+XX (X) XXXX XXXX',
    address: 'Street, House, Index, City',
    measurement: 'metric',
    collation: 'und',
    firstWeekday: 1,
  },
}

const ENGLISH_US: HilosTabLanguage = {
  languageId: 1,
  languageCode: 'en',
  rtl: false,
  localeId: 3,
  localeCode: 'en-US',
  formats: {
    date: 'MM/DD/YYYY',
    time: 'hh:mm:ss A',
    number: '1,000.00',
    phone: '+X (XXX) XXX-XXXX',
    address: 'House Street, City, Region Index',
    measurement: 'imperial',
    collation: 'und',
    firstWeekday: 0,
  },
}

/** The slice of a connection the tab's language reads: one event. */
function fakeConnection() {
  const listeners = new Set<(signal: ProjectSignal) => void>()

  return {
    on(event: string, listener: (signal: ProjectSignal) => void): () => void {
      if (event === 'projectSignal') {
        listeners.add(listener)
      }

      return () => {
        listeners.delete(listener)
      }
    },
    emit(type: string, data: unknown): void {
      this.deliver({ type, data } as ProjectSignal)
    },
    deliver(signal: ProjectSignal): void {
      for (const listener of listeners) {
        listener(signal)
      }
    },
    listenerCount: () => listeners.size,
  }
}

type FakeConnection = ReturnType<typeof fakeConnection>

/** A scripted socket: nothing fires on its own, the test drives each event. */
function scriptedSocket(): WebSocketLike & {
  emit(type: string, data?: unknown): void
} {
  const listeners = new Map<string, ((event: { data?: unknown }) => void)[]>()

  return {
    send: (): void => undefined,
    close: (): void => undefined,
    addEventListener(
      type: string,
      listener: (event: { data?: unknown }) => void,
    ): void {
      listeners.set(type, [...(listeners.get(type) ?? []), listener])
    },
    emit(type: string, data?: unknown): void {
      for (const listener of listeners.get(type) ?? []) {
        listener({ data })
      }
    },
  }
}

/**
 * A handshake response's payload whose data node carries `tabLanguage`.
 *
 * @param tabLanguage The node, or anything a broken server might send.
 */
function response(tabLanguage: unknown) {
  return { entities: { currentUser: null }, data: { tabLanguage } }
}

let attributes: Map<string, string>
let release: (() => void) | null = null

function start(connection: FakeConnection = fakeConnection()): FakeConnection {
  release = bindTabLanguageState(connection as unknown as HilosConnection)

  return connection
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

beforeEach(() => {
  // The value index.html serves before the first response.
  attributes = new Map([['lang', 'en']])
  vi.stubGlobal('document', {
    documentElement: {
      setAttribute: (key: string, value: string) => attributes.set(key, value),
    },
  })
})

afterEach(() => {
  release?.()
  release = null
  vi.unstubAllGlobals()
  vi.useRealTimers()
})

describe('a handshake response with a language', () => {
  it('publishes the tab language and names it on the document', () => {
    const connection = start()
    expect(hilosTabLanguage.get()).toBeNull()

    connection.emit(SIGNAL_HANDSHAKE_RESPONSE, response(GERMAN))

    expect(hilosTabLanguage.get()).toEqual(GERMAN)
    expect(attributes.get('lang')).toBe('de')
  })

  it('names the language, not the region, and leaves dir alone', () => {
    const connection = start()

    connection.emit(
      SIGNAL_HANDSHAKE_RESPONSE,
      response({ ...GERMAN, rtl: true, localeCode: 'de-AT' }),
    )

    expect(attributes.get('lang')).toBe('de')
    expect(attributes.has('dir')).toBe(false)
  })

  it('reads a language with no stored region', () => {
    const connection = start()
    const countryless = { ...GERMAN, localeId: null, localeCode: null }

    connection.emit(SIGNAL_HANDSHAKE_RESPONSE, response(countryless))

    expect(hilosTabLanguage.get()).toEqual(countryless)
    expect(attributes.get('lang')).toBe('de')
  })

  it('rewrites lang at once when a later response names another language', () => {
    const connection = start()
    connection.emit(SIGNAL_HANDSHAKE_RESPONSE, response(GERMAN))

    connection.emit(SIGNAL_HANDSHAKE_RESPONSE, response(ENGLISH_US))

    expect(hilosTabLanguage.get()).toEqual(ENGLISH_US)
    expect(attributes.get('lang')).toBe('en')
  })

  it('wakes nobody when a response restates the same answer', () => {
    const connection = start()
    connection.emit(SIGNAL_HANDSHAKE_RESPONSE, response(GERMAN))
    const seen = changes(hilosTabLanguage)

    connection.emit(SIGNAL_HANDSHAKE_RESPONSE, response({ ...GERMAN }))
    connection.emit(
      SIGNAL_HANDSHAKE_RESPONSE,
      response({ ...GERMAN, formats: { ...GERMAN.formats, date: 'D.M.YYYY' } }),
    )

    expect(seen).toEqual([
      { ...GERMAN, formats: { ...GERMAN.formats, date: 'D.M.YYYY' } },
    ])
  })

  it('carries the first weekday of the region, and a change of the day alone wakes the readers', () => {
    const connection = start()
    connection.emit(SIGNAL_HANDSHAKE_RESPONSE, response(ENGLISH_US))
    expect(hilosTabLanguage.get()?.formats.firstWeekday).toBe(0)
    const seen = changes(hilosTabLanguage)

    connection.emit(
      SIGNAL_HANDSHAKE_RESPONSE,
      response({
        ...ENGLISH_US,
        formats: { ...ENGLISH_US.formats, firstWeekday: 1 },
      }),
    )

    expect(seen).toEqual([
      { ...ENGLISH_US, formats: { ...ENGLISH_US.formats, firstWeekday: 1 } },
    ])
  })

  it('drops keys the contract does not name', () => {
    const connection = start()

    connection.emit(
      SIGNAL_HANDSHAKE_RESPONSE,
      response({
        ...GERMAN,
        firstWeekday: 1,
        formats: { ...GERMAN.formats, calendar: 'gregory' },
      }),
    )

    expect(hilosTabLanguage.get()).toEqual(GERMAN)
  })

  it('publishes the language even with no document to name it on', () => {
    vi.unstubAllGlobals()
    const connection = start()

    connection.emit(SIGNAL_HANDSHAKE_RESPONSE, response(GERMAN))

    expect(hilosTabLanguage.get()).toEqual(GERMAN)
  })
})

describe('a handshake response without a language', () => {
  it('publishes null and leaves the served lang as it stands', () => {
    const connection = start()

    connection.emit(SIGNAL_HANDSHAKE_RESPONSE, response(null))

    expect(hilosTabLanguage.get()).toBeNull()
    expect(attributes.get('lang')).toBe('en')
  })

  it('publishes null after a language and keeps that language on the document', () => {
    const connection = start()
    connection.emit(SIGNAL_HANDSHAKE_RESPONSE, response(GERMAN))

    connection.emit(SIGNAL_HANDSHAKE_RESPONSE, response(null))

    expect(hilosTabLanguage.get()).toBeNull()
    expect(attributes.get('lang')).toBe('de')
  })
})

describe('a response that cannot replace the answer', () => {
  it.each([
    ['no data node', { entities: { currentUser: null } }],
    ['a data node without the key', { data: { themeSettings: {} } }],
    ['a data node that is not an object', { data: 'tabLanguage' }],
    ['a payload that is not an object', 'tabLanguage'],
    ['a list for the node', response([GERMAN])],
    ['a string for the language id', response({ ...GERMAN, languageId: '2' })],
    ['a fractional language id', response({ ...GERMAN, languageId: 2.5 })],
    ['a numeric language code', response({ ...GERMAN, languageCode: 49 })],
    ['a missing rtl flag', response({ ...GERMAN, rtl: undefined })],
    ['a missing region id', response({ ...GERMAN, localeId: undefined })],
    ['a missing region code', response({ ...GERMAN, localeCode: undefined })],
    ['no formats', response({ ...GERMAN, formats: null })],
    [
      'a missing format',
      response({
        ...GERMAN,
        formats: { ...GERMAN.formats, collation: undefined },
      }),
    ],
    [
      'an unknown measurement system',
      response({
        ...GERMAN,
        formats: { ...GERMAN.formats, measurement: 'nautical' },
      }),
    ],
    [
      'a missing first weekday',
      response({
        ...GERMAN,
        formats: { ...GERMAN.formats, firstWeekday: undefined },
      }),
    ],
    [
      'a first weekday past Saturday',
      response({
        ...GERMAN,
        formats: { ...GERMAN.formats, firstWeekday: 7 },
      }),
    ],
    [
      'a first weekday as text',
      response({
        ...GERMAN,
        formats: { ...GERMAN.formats, firstWeekday: '1' },
      }),
    ],
  ])('keeps the last answer and lang on %s', (_name, payload) => {
    const connection = start()
    connection.emit(SIGNAL_HANDSHAKE_RESPONSE, response(GERMAN))

    connection.emit(SIGNAL_HANDSHAKE_RESPONSE, payload)

    expect(hilosTabLanguage.get()).toEqual(GERMAN)
    expect(attributes.get('lang')).toBe('de')
  })

  it('reads only handshake responses', () => {
    const connection = start()

    connection.emit('theme_settings', response(GERMAN))

    expect(hilosTabLanguage.get()).toBeNull()
    expect(attributes.get('lang')).toBe('en')
  })

  it('keeps the last answer on a response its socket no longer stands behind', () => {
    vi.useFakeTimers()
    const socket = scriptedSocket()
    const carrier = new HilosConnection({
      url: 'ws://test/ws',
      webSocketFactory: () => socket,
      projectSchemas: { [SIGNAL_HANDSHAKE_RESPONSE]: z.looseObject({}) },
    })
    const carried: ProjectSignal[] = []
    carrier.on('projectSignal', (signal) => carried.push(signal))
    carrier.connect()
    socket.emit('open')
    socket.emit(
      'message',
      JSON.stringify({
        type: SIGNAL_HANDSHAKE_RESPONSE,
        data: response(ENGLISH_US),
      }),
    )
    carrier.close()
    expect(carried).toHaveLength(1)
    const connection = start()
    connection.emit(SIGNAL_HANDSHAKE_RESPONSE, response(GERMAN))

    // The very frame the closed connection delivered, reaching the tab late.
    connection.deliver(carried[0]!)

    expect(hilosTabLanguage.get()).toEqual(GERMAN)
    expect(attributes.get('lang')).toBe('de')
  })
})

describe('binding', () => {
  it('stops following on release and keeps the last answer readable', () => {
    const connection = start()
    connection.emit(SIGNAL_HANDSHAKE_RESPONSE, response(GERMAN))

    release?.()
    connection.emit(SIGNAL_HANDSHAKE_RESPONSE, response(ENGLISH_US))

    expect(connection.listenerCount()).toBe(0)
    expect(hilosTabLanguage.get()).toEqual(GERMAN)
    expect(attributes.get('lang')).toBe('de')
  })

  it('releases the first binding on a second bind and knows no language until its first response', () => {
    const first = start()
    first.emit(SIGNAL_HANDSHAKE_RESPONSE, response(GERMAN))
    const staleRelease = release

    const second = start()
    expect(first.listenerCount()).toBe(0)
    expect(hilosTabLanguage.get()).toBeNull()
    expect(attributes.get('lang')).toBe('de')

    staleRelease?.()
    second.emit(SIGNAL_HANDSHAKE_RESPONSE, response(ENGLISH_US))

    expect(second.listenerCount()).toBe(1)
    expect(hilosTabLanguage.get()).toEqual(ENGLISH_US)
    expect(attributes.get('lang')).toBe('en')
  })
})
