import { createHash, randomBytes } from 'node:crypto'
import http from 'node:http'
import https from 'node:https'
import type { Socket } from 'node:net'

import type { BrowserContext } from '@playwright/test'

// A browser socket the master hears nothing from, as a spec needs it to prove
// liveness (HIL-1566): the master pings a silent socket with an RFC ping, doubts it
// after a while and closes it after longer still. No browser can be made that
// silent. Chromium answers a ping in its network service, whatever the page, its
// timers or its tab are doing; Node's own WebSocket answers it too; and Playwright's
// offline emulation leaves an established socket running. Silence has to be a
// client that can keep its pong back, so this is one: a socket opened by a plain
// HTTP upgrade, which speaks RFC 6455 framing by hand and nothing else.
//
// It carries the cookies of a browser context, so the master binds it to the
// person signed in there, exactly as it binds that person's tab. It sends no text
// frame, subscribes to nothing and reads every frame of the master only for what
// the scenario watches: pings, and the end of the socket. Everything else is
// dropped unread. A whole frame of any kind tells the master the client is there,
// so a pong is all it ever sends, and nothing at all while it keeps silent.
//
// No dependency comes with it: node:http(s) makes the upgrade and hands over the
// raw stream. It lives in the toolbox rather than in scripts/ because it opens the
// socket of a browser context, which is Playwright's.

/** RFC 6455 §5.2: the opcodes this socket reads or writes. */
const OPCODE_CLOSE = 0x8
const OPCODE_PING = 0x9
const OPCODE_PONG = 0xa

/** RFC 6455 §5.2: the bits of a frame's two first bytes. */
const FIN = 0x80
const MASKED = 0x80
const OPCODE_BITS = 0x0f
const LENGTH_BITS = 0x7f

/** RFC 6455 §5.2: the payload length values that say it continues in 2 or 8 bytes. */
const LENGTH_16 = 126
const LENGTH_64 = 127

/** RFC 6455 §5.3: a client masks every frame with four bytes of its own. */
const MASK_BYTES = 4

/** RFC 6455 §5.5: a control frame carries at most this many bytes. */
const CONTROL_PAYLOAD_MAX = 125

/** RFC 6455 §1.3: what the accept key is derived with. */
const ACCEPT_MAGIC = '258EAFA5-E914-47DA-95CA-C5AB0DC85B11'

/** RFC 6455 §7.4.1: the status of a close the client meant. */
const CLOSE_NORMAL = 1000

/** The path the stand's nginx proxies to the master's WebSocket port. */
const SOCKET_PATH = '/ws'

/** Where the stand is reached, as a project's `use` names it. */
export interface RawWebSocketStand {
  readonly baseURL?: string
  readonly ignoreHTTPSErrors?: boolean
}

/** The side that ended a raw socket. */
export type RawWebSocketCloser = 'server' | 'client'

/** One raw socket of a signed-in person, answering the master's pings or not. */
export interface RawWebSocket {
  /**
   * The pings the master has sent since this socket last sent a frame. The master
   * pings a silence at its idle span and every interval after it, so the count is
   * the master's own clock of that silence.
   */
  silentPings(): number
  /**
   * Resolve once the master has sent so many pings since this socket last sent a
   * frame; reject when the socket closes first.
   *
   * @param count The number of pings to wait for.
   */
  waitForSilentPings(count: number): Promise<void>
  /** Stop answering the master's pings: the socket reads on and sends nothing. */
  keepSilent(): void
  /** Answer every ping again, and speak at once with one unasked pong. */
  answer(): void
  /** Resolves when the socket is gone, naming the side that ended it. */
  readonly closed: Promise<RawWebSocketCloser>
  /** Close from this side, and wait until the socket is gone. */
  close(): Promise<RawWebSocketCloser>
}

/**
 * A frame of the client: masked, final, and a control frame by its size.
 *
 * @param opcode The frame's opcode.
 * @param payload The frame's payload, at most a control frame's.
 */
function clientFrame(opcode: number, payload: Buffer): Buffer {
  if (payload.length > CONTROL_PAYLOAD_MAX) {
    throw new Error(
      `A control frame carries at most ${CONTROL_PAYLOAD_MAX} bytes`,
    )
  }
  const mask = randomBytes(MASK_BYTES)
  const head = Buffer.from([FIN | opcode, MASKED | payload.length])
  const body = Buffer.from(
    payload.map((byte, at) => byte ^ mask[at % MASK_BYTES]),
  )

  return Buffer.concat([head, mask, body])
}

/**
 * Open the upgrade request and hand over the raw stream once the server switched.
 *
 * @param url The socket's address.
 * @param stand Whether the stand's certificate is taken as it is.
 * @param headers The upgrade request's headers.
 */
function upgrade(
  url: URL,
  stand: RawWebSocketStand,
  headers: Record<string, string>,
): Promise<{ response: http.IncomingMessage; socket: Socket; head: Buffer }> {
  return new Promise((resolve, reject) => {
    const request =
      url.protocol === 'https:'
        ? https.request(url, {
            headers,
            rejectUnauthorized: stand.ignoreHTTPSErrors !== true,
          })
        : http.request(url, { headers })
    request.on('upgrade', (response, socket, head) =>
      resolve({ response, socket, head }),
    )
    request.on('response', (response) => {
      response.resume()
      reject(
        new Error(`${url.href} refused the upgrade: ${response.statusCode}`),
      )
    })
    request.on('error', reject)
    request.end()
  })
}

/**
 * Open a raw socket of the person signed in on a browser context. It answers the
 * master's pings from the start, as a browser would; the spec decides when it
 * keeps silent.
 *
 * @param context The browser context whose cookies the upgrade presents.
 * @param stand The project's base URL and its certificate rule.
 * @returns The open socket.
 */
export async function openRawWebSocket(
  context: BrowserContext,
  stand: RawWebSocketStand,
): Promise<RawWebSocket> {
  if (stand.baseURL === undefined) {
    throw new Error('A raw socket needs the base URL of the stand')
  }
  const url = new URL(SOCKET_PATH, stand.baseURL)
  const cookies = await context.cookies(url.href)
  const key = randomBytes(16).toString('base64')
  const { response, socket, head } = await upgrade(url, stand, {
    Connection: 'Upgrade',
    Upgrade: 'websocket',
    'Sec-WebSocket-Version': '13',
    'Sec-WebSocket-Key': key,
    Cookie: cookies.map(({ name, value }) => `${name}=${value}`).join('; '),
  })
  const accept = createHash('sha1')
    .update(key + ACCEPT_MAGIC)
    .digest('base64')
  if (response.headers['sec-websocket-accept'] !== accept) {
    socket.destroy()
    throw new Error(`${url.href} answered the upgrade with a wrong accept key`)
  }

  let answering = true
  let closer: RawWebSocketCloser | undefined
  let pings = 0
  let input = Buffer.alloc(0)
  const waiting = new Set<() => void>()
  const send = (opcode: number, payload: Buffer) => {
    if (!socket.writableEnded) {
      pings = 0
      socket.write(clientFrame(opcode, payload))
    }
  }
  const closed = new Promise<RawWebSocketCloser>((resolve) => {
    // An error ends the stream, and its close follows, so the close says it all.
    socket.on('error', () => undefined)
    // The side that began the close ended it; a stream that just ended was the
    // server's doing, which is how the master drops a socket.
    socket.on('close', () => {
      resolve(closer ?? 'server')
      for (const wake of waiting) wake()
    })
  })

  const read = (chunk: Buffer) => {
    input = Buffer.concat([input, chunk])
    while (input.length >= 2) {
      if ((input[1] & MASKED) !== 0) {
        socket.destroy(new Error('The server masked a frame (RFC 6455 §5.1)'))
        return
      }
      let length = input[1] & LENGTH_BITS
      let offset = 2
      if (length === LENGTH_16) {
        if (input.length < 4) return
        length = input.readUInt16BE(2)
        offset = 4
      } else if (length === LENGTH_64) {
        if (input.length < 10) return
        length = Number(input.readBigUInt64BE(2))
        offset = 10
      }
      if (input.length < offset + length) return
      const opcode = input[0] & OPCODE_BITS
      const payload = input.subarray(offset, offset + length)
      input = input.subarray(offset + length)
      if (opcode === OPCODE_PING) {
        if (answering) {
          send(OPCODE_PONG, Buffer.from(payload))
        } else {
          pings += 1
          for (const wake of waiting) wake()
        }
      } else if (opcode === OPCODE_CLOSE) {
        // The server closes first: echo its close, as RFC 6455 §5.5.1 asks.
        closer ??= 'server'
        send(OPCODE_CLOSE, Buffer.from(payload))
        socket.end()
      }
    }
  }
  socket.setNoDelay(true)
  socket.on('data', read)
  if (head.length > 0) {
    read(head)
  }

  return {
    silentPings: () => pings,
    waitForSilentPings(count) {
      return new Promise((resolve, reject) => {
        const wake = () => {
          if (pings >= count) {
            waiting.delete(wake)
            resolve()
          } else if (socket.destroyed) {
            waiting.delete(wake)
            reject(
              new Error(`The socket closed after ${pings} of ${count} pings`),
            )
          }
        }
        waiting.add(wake)
        wake()
      })
    },
    keepSilent() {
      answering = false
    },
    answer() {
      answering = true
      send(OPCODE_PONG, Buffer.alloc(0))
    },
    closed,
    close() {
      if (closer === undefined && !socket.destroyed) {
        closer = 'client'
        const status = Buffer.alloc(2)
        status.writeUInt16BE(CLOSE_NORMAL)
        send(OPCODE_CLOSE, status)
        socket.end()
      }

      return closed
    },
  }
}
