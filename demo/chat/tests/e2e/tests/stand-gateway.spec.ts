import { connect } from 'node:tls'

import { test, expect } from '@playwright/test'

import {
  dictateGatewayBehavior,
  STAND_GATEWAY_URL,
} from '../../../../../framework/frontend/scripts/standGateway.mjs'
import { uniquePhone } from '../../../../../framework/frontend/scripts/standSms.mjs'

// The stand gateway's behavior handles, proved on the gateway itself (HIL-922). The
// gateway has no unit suite of its own, and the product's scenarios read a lever only
// through what a person sees; this file is where each lever is held to its contract —
// once per call, queued, scoped to its key, and a delay or a hold that never stalls
// the gateway's other connections.
//
// No browser: the product is not involved. The provider route is /sms/send, called
// with a form the way the daemon's SMS provider calls it and keyed by `to`; every call
// leaves a letter in Mailpit under a number nobody else uses, so it disturbs no other
// spec. /test/reset is never called here — it would wipe what the other workers
// declared.

/** The provider route the mechanics are exercised on. */
const SMS_SEND = '/sms/send'

/** Name the gateway's certificate is issued for, which a raw TLS connection has to ask for. */
const GATEWAY_SERVER_NAME = 'stand-gateway'

/** Delay dictated to show that the gateway keeps serving while an answer waits. */
const DELAY_MS = 1500

/** Hold dictated to show that the connection outlives its answer. */
const HOLD_MS = 500

/** Least gap a hold must leave between the answer and the end of the stream; below HOLD_MS for the network. */
const HOLD_FLOOR_MS = 400

/** What one call of the provider route came back with. */
interface GatewayAnswer {
  status: number
  error: string | undefined
}

/** One raw exchange over TLS, read to the end of the stream. */
interface RawExchange {
  /** Every byte the gateway sent. */
  received: Buffer
  /** When the answer was whole by its Content-Length, or null when it never was. */
  answeredAt: number | null
  /** When the stream ended. */
  endedAt: number
}

/** An answer split at its headers. */
interface SplitAnswer {
  /** The body length the headers declare. */
  contentLength: number
  /** The body bytes that actually arrived. */
  body: Buffer
}

/**
 * Call the provider route the way the daemon's SMS provider does.
 *
 * @param to The recipient, which is also the key a behavior is declared under.
 * @returns The status and the refusal code, if any.
 */
async function sendSms(to: string): Promise<GatewayAnswer> {
  const response = await fetch(`${STAND_GATEWAY_URL}${SMS_SEND}`, {
    method: 'POST',
    body: new URLSearchParams({ to, text: 'stand gateway mechanics' }),
  })

  return readAnswer(response)
}

/**
 * Post a behavior declaration without failing on a refusal, so a spec can assert it.
 *
 * @param declaration The declaration as the gateway reads it.
 * @returns The status and the refusal code, if any.
 */
async function declare(
  declaration: Record<string, unknown>,
): Promise<GatewayAnswer> {
  const response = await fetch(`${STAND_GATEWAY_URL}/test/behavior`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(declaration),
  })

  return readAnswer(response)
}

/**
 * Read a gateway answer's status and refusal code.
 *
 * @param response The answer.
 * @returns The status and the refusal code, if any.
 */
async function readAnswer(response: Response): Promise<GatewayAnswer> {
  const payload = (await response.json()) as { error?: string }

  return { status: response.status, error: payload.error }
}

/**
 * Send one request over a raw TLS connection and read until the gateway ends the stream.
 *
 * Raw rather than fetch, because what is measured is the connection itself: where the
 * bytes stop and when the stream ends, neither of which an HTTP client reports.
 *
 * @param to The recipient the request is keyed by.
 * @returns What arrived, and when.
 */
function exchangeOverTls(to: string): Promise<RawExchange> {
  const body = new URLSearchParams({
    to,
    text: 'stand gateway mechanics',
  }).toString()
  const request = [
    `POST ${SMS_SEND} HTTP/1.1`,
    `Host: ${GATEWAY_SERVER_NAME}`,
    'Content-Type: application/x-www-form-urlencoded',
    `Content-Length: ${Buffer.byteLength(body)}`,
    'Connection: close',
    '',
    body,
  ].join('\r\n')
  const { hostname, port } = new URL(STAND_GATEWAY_URL)

  return new Promise((resolve, reject) => {
    const chunks: Buffer[] = []
    let answeredAt: number | null = null
    const socket = connect(
      { host: hostname, port: Number(port), servername: GATEWAY_SERVER_NAME },
      () => socket.write(request),
    )

    socket.on('data', (chunk: Buffer) => {
      chunks.push(chunk)
      const answer = splitAnswer(Buffer.concat(chunks))
      if (
        answeredAt === null &&
        answer !== null &&
        answer.body.length >= answer.contentLength
      ) {
        answeredAt = Date.now()
      }
    })
    socket.on('error', reject)
    socket.on('close', () =>
      resolve({
        received: Buffer.concat(chunks),
        answeredAt,
        endedAt: Date.now(),
      }),
    )
  })
}

/**
 * Split what arrived into the declared body length and the body bytes.
 *
 * @param received The bytes read so far.
 * @returns The split answer, or null while the headers are not complete.
 */
function splitAnswer(received: Buffer): SplitAnswer | null {
  const headersEnd = received.indexOf('\r\n\r\n')
  if (headersEnd === -1) {
    return null
  }

  const headers = received.subarray(0, headersEnd).toString()
  const contentLength = /^content-length:\s*(\d+)$/im.exec(headers)?.[1]
  if (contentLength === undefined) {
    return null
  }

  return {
    contentLength: Number(contentLength),
    body: received.subarray(headersEnd + '\r\n\r\n'.length),
  }
}

test('answers a dictated status exactly once', async () => {
  const phone = uniquePhone()
  await dictateGatewayBehavior(SMS_SEND, phone, { status: 500 })

  expect(await sendSms(phone)).toEqual({
    status: 500,
    error: 'STATUS_DICTATED',
  })
  expect(await sendSms(phone)).toEqual({ status: 200, error: undefined })
})

test('plays declarations for one key out in the order they were made', async () => {
  const phone = uniquePhone()
  await dictateGatewayBehavior(SMS_SEND, phone, { status: 503 })
  await dictateGatewayBehavior(SMS_SEND, phone, { status: 500 })

  expect((await sendSms(phone)).status).toBe(503)
  expect((await sendSms(phone)).status).toBe(500)
  expect((await sendSms(phone)).status).toBe(200)
})

test('leaves a call with another key alone', async () => {
  const declared = uniquePhone()
  // One digit longer rather than a second uniquePhone(), which two calls in the same
  // millisecond could return equal.
  const other = `${declared}0`
  await dictateGatewayBehavior(SMS_SEND, declared, { status: 500 })

  expect((await sendSms(other)).status).toBe(200)
  expect((await sendSms(declared)).status).toBe(500)
})

test('keeps serving other calls while a delayed answer waits', async () => {
  const phone = uniquePhone()
  await dictateGatewayBehavior(SMS_SEND, phone, { delayMs: DELAY_MS })

  // A connection opened ahead is reused by the delayed call, so that call reaches the
  // gateway before the health probe has finished its own handshake: a gateway that
  // stalled on the delay would then answer the probe only after it.
  await (await fetch(`${STAND_GATEWAY_URL}/test/health`)).text()

  const settled: string[] = []
  const startedAt = Date.now()
  const delayed = sendSms(phone).then((answer) => {
    settled.push('delayed')

    return { answer, elapsedMs: Date.now() - startedAt }
  })
  const health = fetch(`${STAND_GATEWAY_URL}/test/health`).then(
    async (response) => {
      await response.text()
      settled.push('health')

      return response.status
    },
  )

  expect(await health).toBe(200)
  const { answer, elapsedMs } = await delayed

  expect(settled).toEqual(['health', 'delayed'])
  expect(answer.status).toBe(200)
  expect(elapsedMs).toBeGreaterThanOrEqual(DELAY_MS)
})

test('cuts the answer in the middle of its body and closes', async () => {
  const phone = uniquePhone()
  await dictateGatewayBehavior(SMS_SEND, phone, { cut: true })

  const answer = splitAnswer((await exchangeOverTls(phone)).received)
  if (answer === null) {
    throw new Error('the cut answer did not carry its headers whole')
  }

  expect(answer.body.length).toBe(Math.floor(answer.contentLength / 2))
})

test('holds the connection open after the answer', async () => {
  const phone = uniquePhone()
  await dictateGatewayBehavior(SMS_SEND, phone, { holdMs: HOLD_MS })

  const { answeredAt, endedAt } = await exchangeOverTls(phone)
  if (answeredAt === null) {
    throw new Error('the held answer never arrived whole')
  }

  expect(endedAt - answeredAt).toBeGreaterThanOrEqual(HOLD_FLOOR_MS)
})

test('refuses a declaration it cannot honor', async () => {
  const phone = uniquePhone()
  const refusals: [Record<string, unknown>, string][] = [
    [{ path: '/sms/nope', key: phone, status: 500 }, 'PATH_NOT_PROVIDER'],
    [
      { path: '/telegram/test/reachable', key: phone, status: 500 },
      'PATH_NOT_PROVIDER',
    ],
    [{ path: '/test/reset', key: phone, status: 500 }, 'PATH_NOT_PROVIDER'],
    [{ path: SMS_SEND, key: '', status: 500 }, 'KEY_REQUIRED'],
    [{ path: SMS_SEND, key: phone, delay_ms: 100 }, 'FIELD_UNKNOWN'],
    [{ path: SMS_SEND, key: phone, status: 200 }, 'STATUS_OUT_OF_RANGE'],
  ]

  for (const [declaration, error] of refusals) {
    expect(await declare(declaration), JSON.stringify(declaration)).toEqual({
      status: 400,
      error,
    })
  }

  // A refused declaration is not kept: the key still answers as usual.
  expect((await sendSms(phone)).status).toBe(200)
})
