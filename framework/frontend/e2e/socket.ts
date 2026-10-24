import type {
  Page,
  Request,
  WebSocket as PlaywrightWebSocket,
  WebSocketRoute,
} from '@playwright/test'
import {
  parseSignalValue,
  unpackSignalFrames,
  type ParseFailure,
} from '../core/src/base/index.js'

/** One inner frame, or a failed physical message, retaining transport evidence. */
export interface SocketFrame {
  readonly socket: number
  /** One-based physical message number on this socket, shared by batch inners. */
  readonly message: number
  /** The original text; binary transport has no text representation. */
  readonly raw: string | undefined
  readonly wire: unknown
  /** Validation never hides the original inner value from an assertion. */
  readonly failure: ParseFailure | undefined
}

/**
 * Tell the application's socket by its path alone. The core puts the browser's
 * time zone, and on a protected node the presented pass, into the query of every
 * socket it opens, so a glob over the whole address that ends in `/ws` misses
 * it. Hand it to `routeWebSocket` as the address matcher.
 *
 * @param url The address of a socket the page opens.
 */
export function isApplicationSocket(url: URL): boolean {
  return url.pathname === '/ws'
}

/**
 * Read physical messages from one socket through the core decoder. A failed
 * outer or binary message produces one observation; invalid inners stay in order
 * beside valid ones. No synthetic inner JSON replaces the physical raw text.
 *
 * @param socket The observer's identity for this connection, including reconnects.
 */
export function createSocketFrameReader(socket: number) {
  let message = 0
  return (payload: unknown): SocketFrame[] => {
    message++
    const raw = typeof payload === 'string' ? payload : undefined
    const delivery = unpackSignalFrames(payload)
    if (!delivery.ok) {
      return [
        { socket, message, raw, wire: undefined, failure: delivery.failure },
      ]
    }
    return delivery.frames.map((wire) => {
      const parsed = parseSignalValue(wire)
      return {
        socket,
        message,
        raw,
        wire,
        failure: parsed.ok ? undefined : parsed.failure,
      }
    })
  }
}

/**
 * Arm before navigation to observe all subsequent sockets. The callback receives
 * every inner and every decoding failure synchronously, including binary input.
 *
 * @param page The page whose incoming messages are observed.
 * @param received The observer, which narrows unknown payloads for its own checks.
 */
export function watchSocketFrames(
  page: Page,
  received: (frame: SocketFrame) => void,
) {
  let sockets = 0
  const listeners = new Map<
    PlaywrightWebSocket,
    (frame: { payload: string | Buffer }) => void
  >()
  const opened = (socket: PlaywrightWebSocket) => {
    const read = createSocketFrameReader(++sockets)
    const listener = ({ payload }: { payload: string | Buffer }) => {
      for (const frame of read(payload)) received(frame)
    }
    listeners.set(socket, listener)
    socket.on('framereceived', listener)
  }
  page.on('websocket', opened)
  return {
    sockets: () => sockets,
    stop() {
      page.off('websocket', opened)
      for (const [socket, listener] of listeners) {
        socket.off('framereceived', listener)
      }
      listeners.clear()
    },
  }
}

/** A JSON object at the test proxy's wire boundary. */
function object(value: unknown): value is Record<string, unknown> {
  return value !== null && typeof value === 'object' && !Array.isArray(value)
}

/** Copy only an existing signed-in session's own zone, preserving its identity. */
function sessionWithTimeZone(
  wire: unknown,
  timeZone: string | null,
): Record<string, unknown> | null {
  if (
    !object(wire) ||
    wire.type !== 'handshake_response' ||
    !object(wire.data) ||
    !object(wire.data.entities) ||
    !object(wire.data.entities.currentUser) ||
    !('timeZone' in wire.data.entities.currentUser)
  ) {
    return null
  }
  return {
    ...wire,
    data: {
      ...wire.data,
      entities: {
        ...wire.data.entities,
        currentUser: { ...wire.data.entities.currentUser, timeZone },
      },
    },
  }
}

/**
 * Forward the real daemon, changing only currentUser.timeZone in session replies.
 * Install before navigation. The retained frames are unmodified server evidence;
 * replay sends only the latest signed-in session, never its batch neighbors.
 * Routing lasts until the page closes; dispose makes it passthrough.
 *
 * @param page A fresh page whose application socket is not open yet.
 * @param initialTimeZone The own zone to deliver until replay changes it.
 */
export async function forwardSessionTimeZone(
  page: Page,
  initialTimeZone: string | null,
) {
  let timeZone = initialTimeZone
  let active = true
  let sockets = 0
  let latest: { client: WebSocketRoute; wire: unknown } | null = null
  const frames: SocketFrame[] = []
  const sessions: Record<string, unknown>[] = []
  await page.routeWebSocket(isApplicationSocket, (client) => {
    const server = client.connectToServer()
    const read = createSocketFrameReader(++sockets)
    client.onMessage((message) => server.send(message))
    client.onClose((code, reason) => {
      if (latest?.client === client) latest = null
      void server.close({ code, reason })
    })
    server.onMessage((message) => {
      const incoming = read(message)
      frames.push(...incoming)
      let changed = false
      const forwarded = incoming.map((frame) => {
        if (!active || frame.failure) return frame.wire
        const session = sessionWithTimeZone(frame.wire, timeZone)
        if (session) {
          latest = { client, wire: frame.wire }
          sessions.push(session)
          changed = true
          return session
        }
        return frame.wire
      })
      if (!changed || typeof message !== 'string') {
        client.send(message)
        return
      }
      // Decode before editing, but keep the complete outer batch metadata too.
      const outer = JSON.parse(message) as Record<string, unknown>
      client.send(
        JSON.stringify(
          outer.type === 'signal_batch'
            ? {
                ...outer,
                data: { ...(outer.data as object), frames: forwarded },
              }
            : forwarded[0],
        ),
      )
    })
  })
  return {
    frames,
    sessions,
    sockets: () => sockets,
    replay(nextTimeZone: string | null): void {
      if (!active || latest === null) {
        throw new Error('No live signed-in session to replay.')
      }
      const session = sessionWithTimeZone(latest.wire, nextTimeZone)
      if (session === null) throw new Error('Invalid session replay.')
      timeZone = nextTimeZone
      sessions.push(session)
      latest.client.send(JSON.stringify(session))
    },
    dispose(): void {
      active = false
      latest = null
    },
  }
}

// Some specs need one thing the browser will not give them: a socket that dies
// while the page stays put. Playwright's offline emulation was the obvious way
// and does not work — Chromium blocks new requests but leaves an established
// WebSocket running, so the client never notices and `conn-state` stays
// `connected` (measured: three runs, 15 s each, never a transition). The
// connection object is deliberately not exposed on `window`, so the remaining
// seam is the one the page itself goes through: wrap the constructor before the
// app loads, keep the sockets it makes, and close them on demand. The product is
// untouched — only the drop is simulated, and everything after it is the real
// client's own reconnect.
//
// The proof that a drop happened is the NEXT socket, counted with
// `page.on('websocket')`, rather than a glimpse of the disconnected label, which
// a fast reconnect can pass through unseen.

/** Name of the page-side hook {@link armSocketDrop} installs for {@link dropSocket}. */
const DROP_SOCKET_HOOK = '__hilosE2eDropSocket'

/**
 * Wraps the page's WebSocket constructor so {@link dropSocket} can close what it
 * opened. Call it before the page loads: a socket opened before the wrap is out
 * of reach.
 *
 * @param page the page whose sockets are to be dropped later.
 */
export async function armSocketDrop(page: Page): Promise<void> {
  await page.addInitScript((hook: string) => {
    const sockets: WebSocket[] = []
    const NativeWebSocket = window.WebSocket
    class TrackedWebSocket extends NativeWebSocket {
      constructor(url: string | URL, protocols?: string | string[]) {
        super(url, protocols)
        sockets.push(this)
      }
    }
    window.WebSocket = TrackedWebSocket
    Object.defineProperty(window, hook, {
      value: () => {
        for (const socket of sockets.splice(0)) {
          socket.close()
        }
      },
    })
  }, DROP_SOCKET_HOOK)
}

/**
 * Closes every socket the page has opened, the way a dropped network would.
 *
 * @param page a page armed with {@link armSocketDrop} before it loaded.
 */
export function dropSocket(page: Page): Promise<void> {
  return page.evaluate((hook) => {
    ;(window as unknown as Record<string, () => void>)[hook]()
  }, DROP_SOCKET_HOOK)
}

/** An installed, page-local reconnect controller. Await operations in order. */
export interface SocketReconnectControl {
  /** Hold future reconnects without interrupting the current live socket. */
  hold(): Promise<void>
  /** Hold reconnects, then close both sides of the current application sockets. */
  drop(): Promise<void>
  /** Connect live waiting routes; completion is not a server-welcome barrier. */
  release(): Promise<void>
  /** Release live waits and listeners, leaving future routes transparent. */
  dispose(): Promise<void>
}

/**
 * Install before navigation on a fresh page. Only application `/ws` routes are
 * controlled, including their query. Frames pass unchanged through the real
 * server; no application messages are buffered or synthesized here.
 *
 * A held async handler must stay pending: Playwright opens a mock socket when a
 * handler returns without connecting. Closing the owned routes also avoids the
 * unspecified ordering between its constructor init script and armSocketDrop's
 * constructor wrapper; this controller does not need that wrapper.
 *
 * Await drop before checking disconnected UI, then release before waiting for
 * the real welcome/connected state with the existing observers. Repeated drops
 * during one hold and repeated releases/disposals are harmless. After dispose,
 * drop rejects. Main-document navigation requests retire the old routes; closing
 * the page releases all waits without connecting them. Disposal of a live page
 * releases its live waits, and can safely run in finally after the page closes.
 * Playwright has no public WebSocket unroute: the installed handler remains a
 * transparent connectToServer after disposal.
 *
 * @param page A fresh page whose application socket has not opened yet.
 * @returns The installed controller; each operation waits for its route cleanup.
 */
export async function controlSocketReconnect(
  page: Page,
): Promise<SocketReconnectControl> {
  interface Connection {
    client: WebSocketRoute
    server?: WebSocketRoute
    live: boolean
    waiting: boolean
    wake: () => void
    handled: Promise<void>
  }
  const connections = new Set<Connection>()
  const closing = new Set<Promise<void>>()
  let held = false
  let disposed = false

  function retire(connection: Connection): Promise<void> {
    if (!connection.live) return Promise.resolve()
    connection.live = false
    connections.delete(connection)
    connection.client.onClose(() => {})
    connection.server?.onClose(() => {})
    // Close the page side before allowing the handler to return, so Playwright's
    // automatic mock-open cannot revive a canceled CONNECTING socket.
    const done = Promise.allSettled([
      connection.client.close(),
      connection.server?.close(),
    ]).then(async () => {
      connection.wake()
      await connection.handled
    })
    closing.add(done)
    void done.then(() => closing.delete(done))
    return done
  }

  function detach(connection: Connection): void {
    const { client, server } = connection
    if (!server) return
    // Public onClose replaces the callback. These transparent callbacks retain
    // only the paired routes, releasing the controller's tracking references.
    client.onClose((code, reason) => server.close({ code, reason }))
    server.onClose((code, reason) => client.close({ code, reason }))
    connections.delete(connection)
  }

  async function release(): Promise<void> {
    held = false
    const waiting = [...connections].filter((connection) => connection.waiting)
    for (const connection of waiting) connection.wake()
    await Promise.all(waiting.map((connection) => connection.handled))
    await Promise.all(closing)
  }

  function removeListeners(): void {
    page.off('close', closed)
    page.off('request', navigating)
  }

  function closed(): void {
    disposed = true
    held = false
    removeListeners()
    for (const connection of connections) void retire(connection)
  }

  function navigating(request: Request): void {
    if (request.isNavigationRequest() && request.frame() === page.mainFrame()) {
      for (const connection of connections) void retire(connection)
    }
  }

  await page.routeWebSocket(isApplicationSocket, async (client) => {
    if (page.isClosed()) {
      await client.close()
      return
    }
    if (disposed) {
      client.connectToServer()
      return
    }
    let wake!: () => void
    const gate = new Promise<void>((resolve) => {
      wake = resolve
    })
    let handled!: () => void
    const connection: Connection = {
      client,
      live: true,
      waiting: held,
      wake,
      handled: new Promise<void>((resolve) => {
        handled = resolve
      }),
    }
    connections.add(connection)
    client.onClose(() => retire(connection))
    try {
      if (connection.waiting) await gate
      connection.waiting = false
      if (!connection.live || page.isClosed()) return
      connection.server = client.connectToServer()
      connection.server.onClose(() => retire(connection))
      if (disposed) detach(connection)
    } finally {
      handled()
    }
  })
  page.on('close', closed)
  page.on('request', navigating)

  return {
    async hold(): Promise<void> {
      if (disposed) throw new Error('Socket reconnect control is disposed.')
      held = true
    },
    async drop(): Promise<void> {
      if (disposed) throw new Error('Socket reconnect control is disposed.')
      held = true
      await Promise.all(
        [...connections]
          .filter((connection) => !connection.waiting)
          .map(retire),
      )
    },
    release,
    async dispose(): Promise<void> {
      disposed = true
      removeListeners()
      await release()
      for (const connection of connections) detach(connection)
    },
  }
}

/**
 * Count the frames the page sends over its sockets whose payload carries a text —
 * an action's name, or `"type":"action"` for any action at all — for a spec that
 * asserts no request left the page. Call it before the page opens its socket
 * (before signing up): a socket opened earlier is not listened to.
 *
 * @param page the page whose outgoing frames are counted.
 * @param needle the text a counted frame's payload contains.
 * @returns a reader of the count so far.
 */
export function countSentFrames(page: Page, needle: string): () => number {
  let count = 0
  page.on('websocket', (socket) => {
    socket.on('framesent', ({ payload }) => {
      if (payload.toString().includes(needle)) {
        count += 1
      }
    })
  })

  return () => count
}
