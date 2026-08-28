import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import {
  HilosConnection,
  type WebSocketLike,
} from '../../src/connection/HilosConnection.js'
import {
  bindNotificationsScope,
  createHilosNotificationStore,
  NOTIFICATION_GROUP,
  NOTIFICATION_SIGNAL_SCHEMAS,
  type HilosNotification,
} from '../../src/notifications/notificationCenter.js'
import { GROUP_SIGNAL_SCHEMAS } from '../../src/protocol/groupError.js'
import { SESSION_SIGNAL_SCHEMAS } from '../../src/session/sessionScope.js'
import { createSignal } from '../../src/state/signal.js'

/** Scripted stand-in for the browser WebSocket; tests drive callbacks explicitly. */
class MockWebSocket implements WebSocketLike {
  static instances: MockWebSocket[] = []

  readonly sent: string[] = []
  private readonly listeners = new Map<
    string,
    ((event: { data?: unknown }) => void)[]
  >()

  constructor(readonly url: string) {
    MockWebSocket.instances.push(this)
  }

  static get last(): MockWebSocket {
    const instance = MockWebSocket.instances.at(-1)
    if (instance === undefined) {
      throw new Error('No MockWebSocket has been constructed')
    }

    return instance
  }

  send(data: string | ArrayBuffer | Blob): void {
    this.sent.push(String(data))
  }

  close(): void {}

  addEventListener(
    type: string,
    listener: (event: { data?: unknown }) => void,
  ): void {
    const list = this.listeners.get(type) ?? []
    list.push(listener)
    this.listeners.set(type, list)
  }

  emit(type: string, event: { data?: unknown } = {}): void {
    for (const listener of this.listeners.get(type) ?? []) {
      listener(event)
    }
  }

  open(): void {
    this.emit('open')
  }

  message(data: string): void {
    this.emit('message', { data })
  }

  drop(): void {
    this.emit('close')
  }
}

function row(overrides: Partial<HilosNotification> = {}): HilosNotification {
  return {
    id: 1,
    userId: 42,
    type: 'info',
    severity: 'info',
    title: 'Hello',
    body: null,
    data: null,
    readAt: null,
    createdAt: '2026-07-28T00:00:00Z',
    ...overrides,
  }
}

function frame(type: string, data: unknown): string {
  return JSON.stringify({ type, data })
}

describe('notification store', () => {
  it('ingests a snapshot as the recent list and unread count', () => {
    const store = createHilosNotificationStore()
    store.ingestSnapshot({
      recent: [row({ id: 2 }), row({ id: 1, readAt: '2026-07-28T01:00:00Z' })],
      unreadCount: 1,
    })

    expect(store.notifications.get().map((n) => n.id)).toEqual([2, 1])
    expect(store.unreadCount.get()).toBe(1)
  })

  it('prepends a created notification and bumps the unread count', () => {
    const store = createHilosNotificationStore()
    store.ingestSnapshot({ recent: [row({ id: 1 })], unreadCount: 1 })

    store.onCreated(row({ id: 5 }))

    expect(store.notifications.get().map((n) => n.id)).toEqual([5, 1])
    expect(store.unreadCount.get()).toBe(2)
  })

  it('does not double-count a re-delivered created notification', () => {
    const store = createHilosNotificationStore()
    store.onCreated(row({ id: 5 }))
    store.onCreated(row({ id: 5 }))

    expect(store.notifications.get()).toHaveLength(1)
  })

  it('marks one row read and decrements the count once', () => {
    const store = createHilosNotificationStore()
    store.ingestSnapshot({
      recent: [row({ id: 1 }), row({ id: 2 })],
      unreadCount: 2,
    })

    store.onRead(1)
    expect(store.notifications.get()[0]?.readAt).not.toBeNull()
    expect(store.unreadCount.get()).toBe(1)

    // A re-delivered read for an already-read row does not decrement again.
    store.onRead(1)
    expect(store.unreadCount.get()).toBe(1)
  })

  it('marks all rows read and zeroes the count', () => {
    const store = createHilosNotificationStore()
    store.ingestSnapshot({
      recent: [row({ id: 1 }), row({ id: 2 })],
      unreadCount: 2,
    })

    store.onRead('all')

    expect(store.notifications.get().every((n) => n.readAt != null)).toBe(true)
    expect(store.unreadCount.get()).toBe(0)
  })
})

describe('notification binder', () => {
  beforeEach(() => {
    vi.useFakeTimers()
    MockWebSocket.instances = []
  })

  afterEach(() => {
    vi.useRealTimers()
  })

  function boot() {
    const connection = new HilosConnection({
      url: 'ws://test/ws',
      webSocketFactory: (url) => new MockWebSocket(url),
      random: () => 1,
      projectSchemas: {
        ...GROUP_SIGNAL_SCHEMAS,
        ...NOTIFICATION_SIGNAL_SCHEMAS,
        ...SESSION_SIGNAL_SCHEMAS,
      },
    })
    const store = createHilosNotificationStore()
    const userId = createSignal<number | null>(null)
    bindNotificationsScope(connection, store, userId)

    return { connection, store, userId }
  }

  /**
   * Deliver the socket's handshake response — the backend saying it knows this
   * connection, which is what the join waits for.
   */
  function answerSession(): void {
    MockWebSocket.last.message(frame('handshake_response', { entities: {} }))
  }

  function sentFrames(): { type: string; group?: string; action?: string }[] {
    return MockWebSocket.last.sent
      .filter((raw) => raw !== 'ping')
      .map(
        (raw) =>
          JSON.parse(raw) as { type: string; group?: string; action?: string },
      )
  }

  it('joins the notification group once the user id lands, naming no recipient', () => {
    const { connection, userId } = boot()
    connection.connect()
    MockWebSocket.last.open()
    answerSession()

    // Connected but no user yet: nothing sent.
    expect(sentFrames()).toEqual([])

    userId.set(42)

    // One frame and no follow-up: the join answers with the snapshot, and the name
    // carries no recipient - the server builds the full one from this connection.
    expect(sentFrames()).toEqual([
      { type: 'group_subscribe', group: NOTIFICATION_GROUP },
    ])
  })

  it('takes the snapshot out of the join answer', () => {
    const { connection, store, userId } = boot()
    connection.connect()
    MockWebSocket.last.open()
    answerSession()
    userId.set(42)

    MockWebSocket.last.message(
      frame('group_response', {
        group: `${NOTIFICATION_GROUP}:42`,
        payload: { recent: [row({ id: 1 })], unreadCount: 3 },
      }),
    )

    expect(store.notifications.get().map((n) => n.id)).toEqual([1])
    expect(store.unreadCount.get()).toBe(3)
  })

  it('ignores a join answer belonging to another group', () => {
    const { connection, store, userId } = boot()
    connection.connect()
    MockWebSocket.last.open()
    answerSession()
    userId.set(42)

    MockWebSocket.last.message(
      frame('group_response', {
        group: 'something_else',
        payload: { recent: [row({ id: 9 })], unreadCount: 7 },
      }),
    )

    expect(store.notifications.get()).toEqual([])
    expect(store.unreadCount.get()).toBe(0)
  })

  it('leaves the store alone when the join is refused', () => {
    const { connection, store, userId } = boot()
    connection.connect()
    MockWebSocket.last.open()
    answerSession()
    userId.set(42)

    MockWebSocket.last.message(
      frame('group_response', {
        group: `${NOTIFICATION_GROUP}:42`,
        payload: { recent: [row({ id: 1 })], unreadCount: 1 },
      }),
    )
    MockWebSocket.last.message(
      frame('subscription_group_error', {
        group: NOTIFICATION_GROUP,
        httpCode: 401,
        errorCode: 'group_unauthenticated',
        message: 'nobody is behind this connection',
      }),
    )

    // The bell lives in the shell and has no error surface: a refusal is written
    // to the log and changes nothing on screen.
    expect(store.notifications.get().map((n) => n.id)).toEqual([1])
    expect(store.unreadCount.get()).toBe(1)
  })

  it('joins again on the next cue after a refusal', () => {
    const { connection, userId } = boot()
    connection.connect()
    MockWebSocket.last.open()
    answerSession()
    userId.set(42)

    MockWebSocket.last.message(
      frame('subscription_group_error', {
        group: NOTIFICATION_GROUP,
        httpCode: 401,
        errorCode: 'group_unauthenticated',
        message: 'the worker has not learned this connection yet',
      }),
    )
    // The same cue that was ignored while the join stood: without clearing the mark
    // this connection would carry no notifications until its socket dropped.
    answerSession()

    expect(
      sentFrames().filter((f) => f.type === 'group_subscribe'),
    ).toHaveLength(2)
  })

  it('routes live signals into the store', () => {
    const { connection, store, userId } = boot()
    connection.connect()
    MockWebSocket.last.open()
    answerSession()
    userId.set(42)

    MockWebSocket.last.message(
      frame('group_response', {
        group: `${NOTIFICATION_GROUP}:42`,
        payload: { recent: [row({ id: 1 })], unreadCount: 1 },
      }),
    )
    expect(store.unreadCount.get()).toBe(1)

    MockWebSocket.last.message(frame('notification_created', row({ id: 2 })))
    expect(store.notifications.get().map((n) => n.id)).toEqual([2, 1])
    expect(store.unreadCount.get()).toBe(2)

    MockWebSocket.last.message(frame('notification_read', { id: 2 }))
    expect(store.unreadCount.get()).toBe(1)
  })

  it('re-joins the group once the reconnected socket answers', () => {
    const { connection, userId } = boot()
    connection.connect()
    MockWebSocket.last.open()
    answerSession()
    userId.set(42)
    MockWebSocket.last.drop()

    vi.advanceTimersByTime(1000)
    MockWebSocket.last.open()

    // The user id never changed, so only the new socket's own answer can say the
    // backend knows this connection; joining before it is refused as anonymous.
    expect(sentFrames()).toEqual([])

    answerSession()

    expect(sentFrames()).toContainEqual({
      type: 'group_subscribe',
      group: NOTIFICATION_GROUP,
    })
  })
})
