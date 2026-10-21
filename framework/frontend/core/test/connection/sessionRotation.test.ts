import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { z } from 'zod'
import { createHilosConnection } from '../../src/connection/createHilosConnection.js'
import { ActionLifecycle } from '../../src/connection/actionLifecycle.js'
import {
  HilosConnection,
  type SessionRotation,
  type WebSocketLike,
} from '../../src/connection/HilosConnection.js'

/** Scripted WebSocket stand-in; the test drives open/message/close explicitly. */
class MockWebSocket implements WebSocketLike {
  static instances: MockWebSocket[] = []

  static get last(): MockWebSocket {
    const instance = MockWebSocket.instances.at(-1)
    if (instance === undefined) {
      throw new Error('No MockWebSocket has been constructed')
    }

    return instance
  }

  closeCalls = 0

  readonly sent: (string | ArrayBuffer | Blob)[] = []

  private readonly listeners = new Map<
    string,
    ((event: { data?: unknown }) => void)[]
  >()

  constructor(readonly url: string) {
    MockWebSocket.instances.push(this)
  }

  send(data: string | ArrayBuffer | Blob): void {
    this.sent.push(data)
  }

  close(): void {
    this.closeCalls += 1
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
}

const WELCOME = JSON.stringify({
  type: 'handshake',
  data: { build: 'dev', sessionCookieName: 'hilos_session_token' },
})

const WELCOME_WITHOUT_COOKIE_NAME = JSON.stringify({
  type: 'handshake',
  data: { build: 'dev' },
})

const ROTATE = JSON.stringify({
  type: 'hilos_session_rotate',
  data: { ticket: 'a1b2c3d4e5f60718293a4b5c6d7e8f90' },
})

/** A connection already open, with the welcome frame delivered. */
function connected(welcome: string = WELCOME): {
  connection: HilosConnection
  rotations: SessionRotation[]
} {
  const connection = new HilosConnection({
    url: 'ws://test/ws',
    webSocketFactory: (url) => new MockWebSocket(url),
    projectSchemas: { project_tail: z.object({}) },
    // Full jitter at its maximum, so a backoff-delayed reconnect would be a whole
    // second away and could never be mistaken for the immediate one.
    random: () => 1,
  })
  const rotations: SessionRotation[] = []
  connection.on('sessionRotate', (rotation) => rotations.push(rotation))
  connection.connect()
  MockWebSocket.last.emit('open')
  MockWebSocket.last.emit('message', { data: welcome })

  return { connection, rotations }
}

beforeEach(() => {
  MockWebSocket.instances = []
})

afterEach(() => {
  MockWebSocket.instances = []
})

describe('session rotation (HIL-582)', () => {
  it.each([false, true])(
    'settles an action and dispatches the whole batch before rotation (already held: %s)',
    async (alreadyHeld) => {
      const { connection } = connected()
      const actions = new ActionLifecycle(connection)
      const handle = actions.dispatch('login', {})
      const before = MockWebSocket.last
      const order: string[] = []
      const ack = {
        type: 'action_success',
        data: { action: 'login', reply: { accepted: true } },
        requestId: handle.requestId,
        outcome: 'success',
        time: 456,
      }
      connection.on('actionSuccess', (signal) => {
        expect(signal.envelope).toEqual(ack)
        order.push('ack')
      })
      connection.on('tableViewportDelta', () => {
        expect(before.closeCalls).toBe(0)
        order.push('table')
      })
      connection.on('projectSignal', (signal) => {
        expect(MockWebSocket.instances).toHaveLength(1)
        order.push(signal.type)
      })
      connection.on('state', (state) => order.push(state))
      if (alreadyHeld) before.emit('message', { data: ROTATE })
      before.emit('message', {
        data: JSON.stringify({
          type: 'signal_batch',
          data: {
            frames: [
              ...(alreadyHeld ? [] : [JSON.parse(ROTATE)]),
              ack,
              {
                type: 'table_viewport_delta',
                data: { page: 'p', tableKey: 't', kind: 'row_updated' },
              },
              { type: 'project_tail', data: {} },
            ],
          },
        }),
      })
      expect(order).toEqual(['ack', 'table', 'project_tail', 'reconnecting'])
      expect(await handle.done).toEqual({
        message: undefined,
        reply: { accepted: true },
      })
      expect(MockWebSocket.instances).toHaveLength(2)
      expect(before.closeCalls).toBe(1)
      connection.close()
    },
  )

  it('dispatches the tail before reconnecting even when no reply is pending', () => {
    const { connection } = connected()
    const order: string[] = []
    connection.on('unknownSignal', () => order.push('tail'))
    connection.on('state', (state) => order.push(state))
    MockWebSocket.last.emit('message', {
      data: JSON.stringify({
        type: 'signal_batch',
        data: { frames: [JSON.parse(ROTATE), { type: 'tail', data: {} }] },
      }),
    })
    expect(order).toEqual(['tail', 'reconnecting'])
    expect(MockWebSocket.instances).toHaveLength(2)
    connection.close()
  })

  it.each(['sessionRotate', 'unknownSignal', 'signal'] as const)(
    'does not revive a connection explicitly closed by a %s listener',
    (event) => {
      const { connection } = connected()
      const before = MockWebSocket.last
      const seen: string[] = []
      connection.on(event, () => connection.close())
      connection.on('unknownSignal', (signal) => seen.push(signal.type))
      before.emit('message', {
        data: JSON.stringify({
          type: 'signal_batch',
          data: {
            frames: [
              JSON.parse(ROTATE),
              { type: 'first_tail', data: {} },
              { type: 'old_tail', data: {} },
            ],
          },
        }),
      })
      expect(seen).toEqual(event === 'unknownSignal' ? ['first_tail'] : [])
      expect(connection.state).toBe('disconnected')
      expect(MockWebSocket.instances).toHaveLength(1)
      expect(before.closeCalls).toBe(1)
    },
  )

  it('does not open a replacement when a reconnect-state listener closes explicitly', () => {
    const { connection } = connected()
    connection.on('state', (state) => {
      if (state === 'reconnecting') connection.close()
    })
    MockWebSocket.last.emit('message', { data: ROTATE })
    expect(connection.state).toBe('disconnected')
    expect(MockWebSocket.instances).toHaveLength(1)
  })

  it('holds rotation through a reentrant delivery until the outer tail is dispatched', () => {
    const { connection } = connected()
    const socket = MockWebSocket.last
    const order: string[] = []
    connection.sendAction('login', {}, 'req')
    connection.on('actionSuccess', () => order.push('ack'))
    connection.on('state', (state) => order.push(state))
    connection.on('unknownSignal', (signal) => {
      order.push(signal.type)
      expect(MockWebSocket.instances).toHaveLength(1)
      if (signal.type === 'trigger') {
        socket.emit('message', {
          data: JSON.stringify({
            type: 'signal_batch',
            data: {
              frames: [
                {
                  type: 'action_success',
                  requestId: 'req',
                  data: { action: 'login' },
                },
                { type: 'inner_tail', data: {} },
              ],
            },
          }),
        })
      }
    })
    socket.emit('message', {
      data: JSON.stringify({
        type: 'signal_batch',
        data: {
          frames: [
            JSON.parse(ROTATE),
            { type: 'trigger', data: {} },
            { type: 'outer_tail', data: {} },
          ],
        },
      }),
    })
    expect(order).toEqual([
      'trigger',
      'ack',
      'inner_tail',
      'outer_tail',
      'reconnecting',
    ])
    expect(MockWebSocket.instances).toHaveLength(2)
    connection.close()
  })

  it('reports the ticket under the cookie name the welcome announced', () => {
    const { rotations } = connected()

    MockWebSocket.last.emit('message', { data: ROTATE })

    expect(rotations).toEqual([
      {
        ticket: 'a1b2c3d4e5f60718293a4b5c6d7e8f90',
        cookieName: 'hilos_session_token_rotate',
      },
    ])
  })

  it('opens the next socket at once, without waiting out a backoff delay', () => {
    connected()
    const before = MockWebSocket.last

    MockWebSocket.last.emit('message', { data: ROTATE })

    // Reconnected synchronously: no timer ran, and the socket carrying the ticket is a
    // new one. The 101 it gets is where the rotated cookie is set, so a delay here is a
    // window the visitor spends on a token the server has already replaced.
    expect(MockWebSocket.instances).toHaveLength(2)
    expect(before.closeCalls).toBe(1)
    expect(MockWebSocket.last).not.toBe(before)
  })

  it('writes the cookie before the socket that carries it is opened', () => {
    const order: string[] = []
    const connection = new HilosConnection({
      url: 'ws://test/ws',
      webSocketFactory: (url) => {
        order.push('socket')

        return new MockWebSocket(url)
      },
      random: () => 1,
    })
    connection.on('sessionRotate', () => order.push('cookie'))
    connection.connect()
    MockWebSocket.last.emit('open')
    MockWebSocket.last.emit('message', { data: WELCOME })

    MockWebSocket.last.emit('message', { data: ROTATE })

    expect(order).toEqual(['socket', 'cookie', 'socket'])
  })

  it('does not schedule a second, delayed reconnect for the socket it closed', () => {
    connected()

    MockWebSocket.last.emit('message', { data: ROTATE })
    // The close event of the socket the rotation dropped arrives after the new one is
    // already open; a connection that acted on it would abandon the live socket.
    MockWebSocket.instances[0].emit('close')

    expect(MockWebSocket.instances).toHaveLength(2)
  })

  it('stays put when the welcome never named the session cookie', () => {
    const { rotations } = connected(WELCOME_WITHOUT_COOKIE_NAME)

    MockWebSocket.last.emit('message', { data: ROTATE })

    // Nothing to write the ticket into, so reconnecting would trade a live session for
    // an anonymous one. Losing the rotation is the cheaper failure.
    expect(rotations).toEqual([])
    expect(MockWebSocket.instances).toHaveLength(1)
  })

  it('waits for the reply of an action still on the wire before reconnecting', () => {
    const { connection } = connected()
    connection.sendAction(
      'register',
      { email: 'someone@example.test' },
      'req-1',
    )

    MockWebSocket.last.emit('message', { data: ROTATE })

    // The login's own reply is behind this signal on the same wire; dropping the socket
    // now would throw away the answer to the click that caused the rotation.
    expect(MockWebSocket.instances).toHaveLength(1)

    MockWebSocket.last.emit('message', {
      data: '{"type":"action_success","data":{"action":"register"},"requestId":"req-1"}',
    })

    expect(MockWebSocket.instances).toHaveLength(2)
  })

  it('delivers the reply it was waiting for before it drops the socket', () => {
    const { connection } = connected()
    const seen: string[] = []
    connection.on('actionSuccess', (signal) => {
      seen.push(signal.action)
    })
    connection.on('state', (state) => {
      seen.push(`state:${state}`)
    })
    connection.sendAction('register', {}, 'req-1')

    MockWebSocket.last.emit('message', { data: ROTATE })
    MockWebSocket.last.emit('message', {
      data: '{"type":"action_success","data":{"action":"register"},"requestId":"req-1"}',
    })

    // Order is the whole point: the reconnect fails every action still in flight,
    // so a reply announced after it would settle nothing and the surface that
    // dispatched the login would wait forever on an answer that did arrive.
    expect(seen).toEqual(['register', 'state:reconnecting'])
  })

  it('keeps waiting while any other action is still unanswered', () => {
    const { connection } = connected()
    connection.sendAction('register', {}, 'req-1')
    connection.sendAction('read', {}, 'req-2')

    MockWebSocket.last.emit('message', { data: ROTATE })
    MockWebSocket.last.emit('message', {
      data: '{"type":"action_success","data":{"action":"register"},"requestId":"req-1"}',
    })

    expect(MockWebSocket.instances).toHaveLength(1)

    MockWebSocket.last.emit('message', {
      data: '{"type":"action_error","data":{"action":"read","reason":"nope"},"requestId":"req-2"}',
    })

    expect(MockWebSocket.instances).toHaveLength(2)
  })

  it('reconnects anyway when a held reply never comes', () => {
    vi.useFakeTimers()
    try {
      const { connection } = connected()
      connection.sendAction('register', {}, 'req-1')

      MockWebSocket.last.emit('message', { data: ROTATE })
      expect(MockWebSocket.instances).toHaveLength(1)

      // A ticket stranded until it expires would leave the browser holding a cookie
      // naming a session that has already moved.
      vi.advanceTimersByTime(10000)

      expect(MockWebSocket.instances).toHaveLength(2)
    } finally {
      vi.useRealTimers()
    }
  })

  it('reports a rotation frame that carries no ticket as a parse failure', () => {
    const { connection, rotations } = connected()
    const failures: string[] = []
    connection.on('parseFailure', (failure) => failures.push(failure.kind))

    MockWebSocket.last.emit('message', {
      data: '{"type":"hilos_session_rotate","data":{}}',
    })

    expect(failures).toEqual(['invalid-signal-data'])
    expect(rotations).toEqual([])
    expect(MockWebSocket.instances).toHaveLength(1)
  })

  it('wires the ticket into a document cookie by default', () => {
    const written: string[] = []
    const { connection } = ((): { connection: HilosConnection } => {
      const bundle = createHilosConnection({
        url: 'ws://test/ws',
        webSocketFactory: (url) => new MockWebSocket(url),
        onSessionRotate: (rotation) =>
          written.push(`${rotation.cookieName}=${rotation.ticket}`),
      })

      return { connection: bundle.connection }
    })()
    connection.connect()
    MockWebSocket.last.emit('open')
    MockWebSocket.last.emit('message', { data: WELCOME })

    MockWebSocket.last.emit('message', { data: ROTATE })

    expect(written).toEqual([
      'hilos_session_token_rotate=a1b2c3d4e5f60718293a4b5c6d7e8f90',
    ])
  })
})
