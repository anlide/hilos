import type {
  Page,
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
