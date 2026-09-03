import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import {
  HilosConnection,
  type WebSocketLike,
} from '../connection/HilosConnection.js'
import { createHilosToastStore } from '../state/toasts.js'
import type { HilosSessionToast, HilosToastStore } from '../state/toasts.js'
import {
  bindSessionToasts,
  SIGNAL_SESSION_TOASTS,
  TOAST_ACTION_DISMISS,
  TOAST_ACTION_EXPIRED,
  TOAST_ACTION_READING,
} from './sessionToasts.js'
import { SESSION_SIGNAL_SCHEMAS } from './sessionScope.js'

/** Scripted stand-in for the browser WebSocket; the test drives it explicitly. */
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

  drop(): void {
    this.emit('close')
  }

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
}

/**
 * One card of the session's stack as the server sends it.
 *
 * @param fields What this case cares about; the rest is the first real sender's
 *   card, a finished backup.
 */
function card(
  fields: Partial<HilosSessionToast> & { key: string },
): HilosSessionToast {
  return {
    message: 'Backup "2026-09-03" is ready.',
    severity: 'success',
    source: 'Backup',
    destination: '/hilos/backup',
    repeats: 1,
    ...fields,
  }
}

describe('session toast binder', () => {
  beforeEach(() => {
    vi.useFakeTimers()
    MockWebSocket.instances = []
  })

  afterEach(() => {
    vi.useRealTimers()
  })

  function boot(): { connection: HilosConnection; store: HilosToastStore } {
    const connection = new HilosConnection({
      url: 'ws://test/ws',
      webSocketFactory: (url) => new MockWebSocket(url),
      random: () => 1,
      projectSchemas: { ...SESSION_SIGNAL_SCHEMAS },
    })
    const store = createHilosToastStore()
    bindSessionToasts(connection, store)
    connection.connect()
    MockWebSocket.last.open()

    return { connection, store }
  }

  /**
   * Deliver one session-toast frame, which always carries the whole stack.
   *
   * @param toasts The stack the server says this session is being shown.
   */
  function deliver(toasts: readonly HilosSessionToast[]): void {
    MockWebSocket.last.message(
      JSON.stringify({ type: SIGNAL_SESSION_TOASTS, data: { toasts } }),
    )
  }

  /** The action frames this socket has sent, in order. */
  function actions(): { action?: string; data?: unknown }[] {
    return MockWebSocket.last.sent
      .filter((raw) => raw !== 'ping')
      .map((raw) => JSON.parse(raw) as { action?: string; data?: unknown })
  }

  /**
   * Draw every card the host has not measured yet — what a host does on the frame
   * after the store handed it one. Without it nothing has a countdown.
   *
   * @param store The stack under test.
   * @param viewer The stand-in host.
   */
  function draw(
    store: HilosToastStore,
    viewer: ReturnType<HilosToastStore['attach']>,
  ): void {
    for (const toast of store.toasts.get()) {
      if (!toast.measured) {
        viewer.reportHeight(toast.id, 100)
      }
    }
  }

  it('puts the frame into the store as the whole stack', () => {
    const { store } = boot()

    deliver([card({ key: 'k1' }), card({ key: 'k2', message: 'Second.' })])

    expect(store.toasts.get().map((toast) => toast.sessionKey)).toEqual([
      'k1',
      'k2',
    ])

    // The list rather than a change: what is not in the next frame is gone, and
    // an empty one is the legal way the last card leaves.
    deliver([])
    expect(store.toasts.get()).toEqual([])
  })

  it('sends the close of a card once, and again if it is raised anew', () => {
    const { store } = boot()
    deliver([card({ key: 'k1' })])

    store.dismiss(store.toasts.get()[0].id)
    store.dismiss(store.toasts.get()[0].id)

    expect(actions()).toEqual([
      { type: 'action', action: TOAST_ACTION_DISMISS, data: { key: 'k1' } },
    ])

    // The server takes the card away and raises the same sentence again: this is
    // a new card, and the answer about it is new too.
    deliver([])
    deliver([card({ key: 'k1' })])
    store.dismiss(store.toasts.get()[0].id)

    expect(actions()).toHaveLength(2)
  })

  it('reports a burned-down countdown of its own tab', () => {
    const { store } = boot()
    const viewer = store.attach()
    viewer.setViewportHeight(600)
    deliver([card({ key: 'k1' })])
    draw(store, viewer)

    vi.advanceTimersByTime(20_000)

    expect(actions()).toEqual([
      { type: 'action', action: TOAST_ACTION_EXPIRED, data: { key: 'k1' } },
    ])
    // The card is still up: what happens to it is the server's to say.
    expect(store.toasts.get()).toHaveLength(1)
  })

  it('says when the stack is read here and when it stops being read', () => {
    const { store } = boot()
    const viewer = store.attach()

    viewer.hold('cursor')
    viewer.release('cursor')

    expect(actions()).toEqual([
      {
        type: 'action',
        action: TOAST_ACTION_READING,
        data: { reading: true },
      },
      {
        type: 'action',
        action: TOAST_ACTION_READING,
        data: { reading: false },
      },
    ])
  })

  it('says everything again on a reconnect, because the accept key is new', () => {
    const { store } = boot()
    const viewer = store.attach()
    viewer.setViewportHeight(600)
    deliver([card({ key: 'k1' })])
    draw(store, viewer)
    viewer.hold('cursor')
    vi.advanceTimersByTime(60_000)
    const beforeDrop = actions()
    expect(beforeDrop.map((frame) => frame.action)).toEqual([
      TOAST_ACTION_READING,
    ])

    // The socket dies with the countdown frozen under the cursor, and comes back a
    // new connection: the server weighs both answers against accept keys that are
    // alive, and the one that gave them is not alive any more.
    viewer.release('cursor')
    vi.advanceTimersByTime(20_000)
    MockWebSocket.last.drop()
    // The backoff opens the replacement socket; nothing this tab said reached it.
    vi.advanceTimersByTime(1000)
    MockWebSocket.last.open()

    expect(actions().map((frame) => frame.action)).toEqual([
      TOAST_ACTION_EXPIRED,
    ])
  })

  it('does not count an answer the socket swallowed as said', () => {
    const store = createHilosToastStore()
    const connection = new HilosConnection({
      url: 'ws://test/ws',
      webSocketFactory: (url) => new MockWebSocket(url),
      random: () => 1,
      projectSchemas: { ...SESSION_SIGNAL_SCHEMAS },
    })
    bindSessionToasts(connection, store)
    // Never connected: the store still answers, and sendAction has nowhere to put it.
    store.syncSession([card({ key: 'k1' })])
    store.dismiss(store.toasts.get()[0].id)

    connection.connect()
    MockWebSocket.last.open()

    // Remembering it as sent would leave the card standing on every tab of the
    // session with nothing left to say about it.
    expect(actions()).toEqual([
      { type: 'action', action: TOAST_ACTION_DISMISS, data: { key: 'k1' } },
    ])
  })

  it('says nothing about a hidden tab, which is nobody reading', () => {
    const { store } = boot()
    const viewer = store.attach()

    viewer.hold('tab')

    expect(actions()).toEqual([])
  })
})
