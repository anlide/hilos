import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createHilosConnection } from '../../src/connection/createHilosConnection.js'
import { type WebSocketLike } from '../../src/connection/HilosConnection.js'
import {
  bindAvailableLanguagesScope,
  createHilosAvailableLanguagesStore,
  LANGUAGES_GROUP,
  SIGNAL_LANGUAGES_STATE,
} from '../../src/language/availableLanguages.js'
import { NOTIFICATION_GROUP } from '../../src/notifications/notificationCenter.js'
import {
  SIGNAL_TYPE_GROUP_RESPONSE,
  SIGNAL_TYPE_GROUP_SUBSCRIBE,
  SIGNAL_TYPE_GROUP_SUBSCRIPTION_ERROR,
  SIGNAL_TYPE_HANDSHAKE,
  SIGNAL_TYPE_PROTECTED_MODE,
} from '../../src/protocol/constants.js'
import {
  bindSessionScope,
  sessionUserId,
  SIGNAL_HANDSHAKE_RESPONSE,
} from '../../src/session/sessionScope.js'
import { ScopeManager } from '../../src/state/ScopeManager.js'

const english = {
  id: 1,
  code: 'en',
  nativeName: 'English',
  locales: [{ id: 1, code: 'en', countryCode: null, countryName: null }],
}
const ukrainian = {
  id: 2,
  code: 'uk',
  nativeName: 'Українська',
  locales: [
    { id: 2, code: 'uk-UA', countryCode: 'ua', countryName: 'Ukraine' },
  ],
}
const polish = { id: 3, code: 'pl', nativeName: 'Polski', locales: [] }
const join = { type: SIGNAL_TYPE_GROUP_SUBSCRIBE, group: 'hilos_languages' }

class TestSocket implements WebSocketLike {
  private readonly listeners = new Map<
    string,
    ((event: { data?: unknown }) => void)[]
  >()
  private readonly sent: string[] = []

  send(data: string | ArrayBuffer | Blob): void {
    this.sent.push(String(data))
  }

  close(): void {}

  addEventListener(
    type: string,
    listener: (event: { data?: unknown }) => void,
  ): void {
    const listeners = this.listeners.get(type) ?? []
    listeners.push(listener)
    this.listeners.set(type, listeners)
  }

  open(): void {
    this.emit('open')
  }

  drop(): void {
    this.emit('close')
  }

  message(type: string, data: unknown): void {
    this.emit('message', { data: JSON.stringify({ type, data }) })
  }

  joins(): unknown[] {
    return this.sent
      .filter((raw) => raw !== 'ping')
      .map((raw) => JSON.parse(raw) as { type: string })
      .filter((frame) => frame.type === SIGNAL_TYPE_GROUP_SUBSCRIBE)
  }

  private emit(type: string, event: { data?: unknown } = {}): void {
    for (const listener of this.listeners.get(type) ?? []) listener(event)
  }
}

function answerSession(socket: TestSocket, userId: number | null = null): void {
  socket.message(SIGNAL_HANDSHAKE_RESPONSE, {
    entities: { currentUser: userId === null ? null : { id: userId } },
    data: { accessLossReason: null },
  })
}

describe('available languages', () => {
  let closeConnection: () => void

  beforeEach(() => {
    vi.useFakeTimers()
  })

  afterEach(() => {
    closeConnection()
    vi.restoreAllMocks()
    vi.useRealTimers()
  })

  function boot() {
    const socket = new TestSocket()
    const nextSocket = new TestSocket()
    const { connection } = createHilosConnection({
      url: 'ws://test/ws',
      webSocketFactory: vi
        .fn()
        .mockReturnValueOnce(socket)
        .mockReturnValueOnce(nextSocket),
    })
    closeConnection = () => connection.close()
    const scopes = new ScopeManager()
    bindSessionScope(connection, scopes)
    const store = createHilosAvailableLanguagesStore()
    bindAvailableLanguagesScope(connection, store, sessionUserId(scopes))
    connection.connect()

    return { connection, socket, nextSocket, store }
  }

  function joinAsGuest() {
    const context = boot()
    context.socket.open()
    answerSession(context.socket)

    return context
  }

  it.each([null, 42])(
    'joins once after the handshake response (user %s)',
    (id) => {
      const { socket, store } = boot()
      expect(socket.joins()).toEqual([])
      expect(store.languages.get()).toBeNull()

      socket.open()
      socket.message(SIGNAL_TYPE_HANDSHAKE, { build: 'test' })
      expect(socket.joins()).toEqual([])

      answerSession(socket, id)
      answerSession(socket, id)

      expect(socket.joins()).toEqual([join])
      expect(store.languages.get()).toBeNull()
    },
  )

  it('matches the full group name and replaces the list on each snapshot', () => {
    const { socket, store } = joinAsGuest()
    for (const group of ['other_group', `${LANGUAGES_GROUP}:42`]) {
      socket.message(SIGNAL_TYPE_GROUP_RESPONSE, {
        group,
        payload: { languages: [english] },
      })
    }
    expect(store.languages.get()).toBeNull()

    socket.message(SIGNAL_TYPE_GROUP_RESPONSE, {
      group: LANGUAGES_GROUP,
      payload: { languages: [english, ukrainian] },
    })
    expect(store.languages.get()).toEqual([english, ukrainian])

    socket.message(SIGNAL_TYPE_GROUP_RESPONSE, {
      group: LANGUAGES_GROUP,
      payload: { languages: [ukrainian] },
    })
    expect(store.languages.get()).toEqual([ukrainian])

    socket.message(SIGNAL_LANGUAGES_STATE, { languages: [polish] })
    expect(store.languages.get()).toEqual([polish])

    store.clear()
    expect(store.languages.get()).toBeNull()
  })

  it('keeps the list and warns when its group answer is unreadable', () => {
    const { socket, store } = joinAsGuest()
    const warn = vi.spyOn(console, 'warn').mockImplementation(() => {})
    socket.message(SIGNAL_LANGUAGES_STATE, { languages: [english] })

    socket.message(SIGNAL_TYPE_GROUP_RESPONSE, {
      group: LANGUAGES_GROUP,
      payload: { languages: [{ ...ukrainian, locales: [{ id: 2 }] }] },
    })

    expect(store.languages.get()).toEqual([english])
    expect(warn).toHaveBeenCalledWith(
      '[hilos] languages group answered with an unreadable snapshot',
      expect.any(Error),
    )
  })

  it('rejects a malformed live update at the connection parser', () => {
    const { connection, socket, store } = joinAsGuest()
    const parseFailure = vi.fn()
    connection.on('parseFailure', parseFailure)
    socket.message(SIGNAL_LANGUAGES_STATE, { languages: [english] })

    socket.message(SIGNAL_LANGUAGES_STATE, {
      languages: [{ ...ukrainian, id: '2' }],
    })

    expect(parseFailure).toHaveBeenCalledOnce()
    expect(store.languages.get()).toEqual([english])
  })

  it('waits for the new socket to answer before joining again', () => {
    const { socket, nextSocket, store } = joinAsGuest()
    socket.message(SIGNAL_LANGUAGES_STATE, { languages: [english] })

    socket.drop()
    vi.runOnlyPendingTimers()
    nextSocket.open()
    expect(nextSocket.joins()).toEqual([])
    expect(store.languages.get()).toEqual([english])

    answerSession(nextSocket)
    expect(nextSocket.joins()).toEqual([join])
  })

  it('joins again after protected mode releases the connection', () => {
    const { socket, store } = joinAsGuest()
    socket.message(SIGNAL_LANGUAGES_STATE, { languages: [english] })

    socket.message(SIGNAL_TYPE_PROTECTED_MODE, {
      active: true,
      operation: 'restore',
      title: 'Restoring a backup',
      message: 'Back shortly.',
    })
    expect(socket.joins()).toEqual([join])

    socket.message(SIGNAL_TYPE_PROTECTED_MODE, {
      active: false,
      acceptsPass: true,
    })
    expect(socket.joins()).toEqual([join, join])
    expect(store.languages.get()).toEqual([english])

    socket.message(SIGNAL_TYPE_GROUP_RESPONSE, {
      group: LANGUAGES_GROUP,
      payload: { languages: [ukrainian] },
    })
    expect(store.languages.get()).toEqual([ukrainian])
  })

  it('joins after every identity change and keeps the list for the next person', () => {
    const { socket, store } = joinAsGuest()
    socket.message(SIGNAL_LANGUAGES_STATE, { languages: [english] })

    for (const [index, id] of [42, 43, null].entries()) {
      answerSession(socket, id)
      expect(socket.joins()).toHaveLength(index + 2)
      expect(store.languages.get()).toEqual([english])
    }
  })

  it('keeps the list on refusal and only retries on the next cue', () => {
    const { socket, store } = joinAsGuest()
    const warn = vi.spyOn(console, 'warn').mockImplementation(() => {})
    socket.message(SIGNAL_LANGUAGES_STATE, { languages: [english] })

    socket.message(SIGNAL_TYPE_GROUP_SUBSCRIPTION_ERROR, {
      group: LANGUAGES_GROUP,
      httpCode: 403,
      errorCode: 'group_forbidden',
      message: 'The group is closed.',
    })
    expect(warn).toHaveBeenCalledWith(
      '[hilos] languages group join refused: group_forbidden - The group is closed.',
    )
    expect(store.languages.get()).toEqual([english])

    vi.advanceTimersByTime(1000)
    expect(socket.joins()).toEqual([join])

    answerSession(socket)
    expect(socket.joins()).toEqual([join, join])
  })

  it('keeps its join and list when another group refuses admission', () => {
    const { socket, store } = joinAsGuest()
    socket.message(SIGNAL_TYPE_GROUP_RESPONSE, {
      group: LANGUAGES_GROUP,
      payload: { languages: [english] },
    })
    expect(store.languages.get()).toEqual([english])

    const warn = vi.spyOn(console, 'warn').mockImplementation(() => {})
    socket.message(SIGNAL_TYPE_GROUP_SUBSCRIPTION_ERROR, {
      group: NOTIFICATION_GROUP,
      httpCode: 403,
      errorCode: 'group_forbidden',
      message: 'The group is closed.',
    })
    expect(warn).not.toHaveBeenCalled()
    expect(store.languages.get()).toEqual([english])

    answerSession(socket)
    expect(socket.joins()).toEqual([join])

    socket.message(SIGNAL_TYPE_GROUP_RESPONSE, {
      group: LANGUAGES_GROUP,
      payload: { languages: [polish] },
    })
    expect(store.languages.get()).toEqual([polish])
  })
})
