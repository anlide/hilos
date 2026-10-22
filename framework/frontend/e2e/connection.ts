import type { Expect, Page } from '@playwright/test'
import { createSocketFrameReader, type SocketFrame } from './socket.js'

// What a client names on connecting rides the socket address, not a frame: the
// server reads it on the 101 and answers with its welcome. A spec about it needs
// two facts of every socket — the address the browser really opened it on, and
// that the server welcomed that very socket. The frame watchers keep neither the
// address nor which socket a welcome came on, so this log keeps both.

/** One socket the page opened, kept in the order the page opened them. */
export interface OpenedSocket {
  /** The address the browser opened the socket on, query included. */
  readonly url: URL
  /** Whether the server's handshake welcome has arrived on this socket. */
  readonly welcomed: boolean
}

/**
 * Predicate indicating whether a frame is a well-formed handshake welcome.
 *
 * @param frame One inner frame read through the core decoder.
 */
function isWelcome(frame: SocketFrame): boolean {
  const wire = frame.wire
  return (
    frame.failure === undefined &&
    wire !== null &&
    typeof wire === 'object' &&
    'type' in wire &&
    wire.type === 'handshake'
  )
}

/**
 * Keep every socket the page opens, with its address and whether the server has
 * welcomed it. Arm before navigation: a socket opened earlier is not seen.
 *
 * @param page The page whose sockets are kept, across reconnects and reloads.
 * @returns The sockets opened so far; the log grows as the page opens more.
 */
export function watchOpenedSockets(page: Page): readonly OpenedSocket[] {
  const sockets: { url: URL; welcomed: boolean }[] = []
  page.on('websocket', (socket) => {
    const opened = { url: new URL(socket.url()), welcomed: false }
    sockets.push(opened)
    const read = createSocketFrameReader(sockets.length)
    socket.on('framereceived', ({ payload }) => {
      if (read(payload).some(isWelcome)) {
        opened.welcomed = true
      }
    })
  })

  return sockets
}

/**
 * Wait until the page has opened exactly `count` sockets and the server has
 * welcomed every one of them. A socket more than the spec expects keeps the
 * wait from passing, so an unplanned reconnect is reported rather than missed.
 *
 * @param expect The test runner's assertion function.
 * @param sockets The log {@link watchOpenedSockets} keeps.
 * @param count How many sockets the page has opened by now.
 */
export async function expectWelcomedSockets(
  expect: Expect,
  sockets: readonly OpenedSocket[],
  count: number,
): Promise<void> {
  await expect
    .poll(() => sockets.map(({ welcomed }) => welcomed))
    .toEqual(Array.from({ length: count }, () => true))
}
