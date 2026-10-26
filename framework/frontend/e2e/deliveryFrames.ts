import type { Expect, Page, WebSocket } from '@playwright/test'

import { watchSocketFrames, type SocketFrame } from './socket.js'

/** A physical delivery, timed once in the observing runner's monotonic clock. */
export interface DeliveryPacket {
  readonly socket: number
  readonly message: number
  readonly at: number
  readonly raw: string | undefined
  readonly frames: SocketFrame[]
}

/** A real browser request, retaining its payload and the same monotonic clock. */
export interface DeliveryRequest {
  readonly socket: number
  readonly at: number
  readonly raw: string
  readonly wire: unknown
}

/** Narrow an observed wire object without parsing or replacing its raw message. */
export function deliveryObject(value: unknown): Record<string, unknown> {
  return value !== null && typeof value === 'object' && !Array.isArray(value)
    ? (value as Record<string, unknown>)
    : {}
}

/**
 * Arm before navigation. The existing socket decoder owns incoming unpacking;
 * this log groups its observations by physical identity and timestamps each
 * physical delivery exactly once. Failures and binary messages remain visible.
 */
export function watchDeliveryFrames(page: Page) {
  const packets: DeliveryPacket[] = []
  const sent: DeliveryRequest[] = []
  const connections: Array<{
    socket: number
    opened: number
    closed?: number
  }> = []
  const incoming = watchSocketFrames(page, (frame) => {
    let packet = packets.at(-1)
    if (packet?.socket !== frame.socket || packet.message !== frame.message) {
      packet = {
        socket: frame.socket,
        message: frame.message,
        at: performance.now(),
        raw: frame.raw,
        frames: [],
      }
      packets.push(packet)
    }
    packet.frames.push(frame)
  })
  const listeners = new Map<
    WebSocket,
    {
      send: (frame: { payload: string | Buffer }) => void
      close: () => void
    }
  >()
  const opened = (socket: WebSocket) => {
    const connection = {
      socket: connections.length + 1,
      opened: performance.now(),
    }
    connections.push(connection)
    const send = ({ payload }: { payload: string | Buffer }) => {
      const raw = payload.toString()
      let wire: unknown
      try {
        wire = JSON.parse(raw)
      } catch {
        wire = undefined
      }
      sent.push({ socket: connection.socket, at: performance.now(), raw, wire })
    }
    const close = () => {
      Object.assign(connection, { closed: performance.now() })
    }
    listeners.set(socket, { send, close })
    socket.on('framesent', send)
    socket.on('close', close)
  }
  page.on('websocket', opened)
  return {
    packets,
    sent,
    connections,
    sockets: incoming.sockets,
    stop() {
      incoming.stop()
      page.off('websocket', opened)
      for (const [socket, listener] of listeners) {
        socket.off('framesent', listener.send)
        socket.off('close', listener.close)
      }
      listeners.clear()
    },
  }
}

/** The retained log can be attached directly, including all inner metadata. */
export type DeliveryFrameLog = ReturnType<typeof watchDeliveryFrames>

/**
 * Wait for a named delivery deadline/quiet eligibility in the runner's clock.
 * This is a property boundary, never a substitute for awaiting UI or a reply.
 */
export async function waitDeliveryBoundary(at: number): Promise<void> {
  while (performance.now() < at) {
    await new Promise((resolve) => setTimeout(resolve, at - performance.now()))
  }
}

/**
 * Observe an entire physical quiet interval on every socket. Any incoming
 * packet, including an opaque hint or parse failure, restarts the measurement.
 * Browser requests also restart it; transport pings do not open request grace.
 */
export async function waitForDeliveryQuiet(
  expect: Expect,
  logs: DeliveryFrameLog[],
  windowMs: number,
  marginMs: number,
  timeoutMs: number,
) {
  const startedAt = performance.now()
  const activity = () =>
    logs.map((log) =>
      Math.max(
        log.connections.at(-1)?.opened ?? startedAt,
        log.packets.at(-1)?.at ?? 0,
        log.sent.filter(({ raw }) => raw !== 'ping').at(-1)?.at ?? 0,
      ),
    )
  await expect
    .poll(() => performance.now() - Math.max(...activity()), {
      timeout: timeoutMs,
      // The next preparation brackets a real heartbeat inside an 8s window;
      // a 1s polling overshoot would consume its source-write budget.
      intervals: [20, 50, 100],
      message: 'all physical traffic reached real quiet',
    })
    .toBeGreaterThanOrEqual(windowMs + marginMs)
  return {
    startedAt,
    at: performance.now(),
    lastActivity: activity(),
    packetCounts: logs.map(({ packets }) => packets.length),
  }
}
