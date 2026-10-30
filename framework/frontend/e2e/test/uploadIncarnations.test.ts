import { createHash } from 'node:crypto'
import { EventEmitter } from 'node:events'
import type { Page } from '@playwright/test'
import { describe, expect, it } from 'vitest'
import {
  createUploadIncarnationObserver,
  watchUploadIncarnations,
} from '../uploadIncarnations.js'

const ID = 'picture'
const A = Buffer.from('original')
const B = Buffer.from('a different replacement')
const hash = (bytes: Buffer) => createHash('sha256').update(bytes).digest('hex')
type Observer = ReturnType<typeof createUploadIncarnationObserver>

function init(observer: Observer, requestId: string, bytes = A, socket = 1) {
  observer.sent(
    socket,
    JSON.stringify({
      type: 'action',
      action: 'hilos_upload_init',
      requestId,
      data: {
        clientUploadId: ID,
        filename: `${requestId}.png`,
        target: 'chat_attachment',
        size: bytes.length,
      },
    }),
  )
}

function ack(
  observer: Observer,
  requestId: string,
  accepted = true,
  socket = 1,
) {
  observer.received(socket, {
    type: accepted ? 'action_success' : 'action_error',
    requestId,
    data: {
      action: 'hilos_upload_init',
      ...(accepted ? {} : { reason: 'File is being checked' }),
    },
  })
}

function state(
  observer: Observer,
  phase: string | null,
  size: number,
  socket = 1,
) {
  observer.received(socket, {
    type: 'hilos_upload_state',
    data: {
      clientUploadId: ID,
      phase,
      receivedBytes: phase === 'complete' ? size : 0,
      declaredSize: size,
    },
  })
}

function sendBytes(observer: Observer, bytes = A, socket = 1) {
  observer.sent(
    socket,
    JSON.stringify({
      type: 'hilos_upload_credit_request',
      clientUploadId: ID,
      bytes: bytes.length,
    }),
  )
  observer.received(socket, {
    type: 'hilos_upload_credit',
    data: { clientUploadId: ID, sendUpToBytes: bytes.length },
  })
  observer.sent(
    socket,
    Buffer.concat([Buffer.from([ID.length]), Buffer.from(ID), bytes]),
  )
}

function complete(
  observer: Observer,
  requestId: string,
  bytes = A,
  socket = 1,
) {
  init(observer, requestId, bytes, socket)
  state(observer, 'ready', bytes.length, socket)
  ack(observer, requestId, true, socket)
  sendBytes(observer, bytes, socket)
  state(observer, 'complete', bytes.length, socket)
}

describe('upload evidence by socket and init request, rather than accumulated upload id', () => {
  it('records different A/B sizes, hashes and credits independently under the reused id', () => {
    const evidence: Record<string, unknown>[] = []
    const observer = createUploadIncarnationObserver('author', (event) =>
      evidence.push(event),
    )
    complete(observer, 'init-a')
    init(observer, 'init-b', B)
    // Replacing a complete upload removes A before READY(B) and its success ack.
    state(observer, null, A.length)
    state(observer, 'ready', B.length)
    ack(observer, 'init-b')
    sendBytes(observer, B)
    state(observer, 'complete', B.length)

    expect(observer.errors).toEqual([])
    expect(observer.summary()).toMatchObject([
      {
        socket: 1,
        requestId: 'init-a',
        clientUploadId: ID,
        filename: 'init-a.png',
        accepted: true,
        sentBytes: A.length,
        sentSha256: hash(A),
        grants: [A.length],
      },
      {
        socket: 1,
        requestId: 'init-b',
        clientUploadId: ID,
        filename: 'init-b.png',
        accepted: true,
        sentBytes: B.length,
        sentSha256: hash(B),
        grants: [B.length],
      },
    ])
    for (const requestId of ['init-a', 'init-b']) {
      const chunks = evidence.filter(
        (event) =>
          event.kind === 'incarnation-chunk' &&
          event.initRequestId === requestId,
      )
      expect(chunks).toMatchObject([
        { socket: 1, clientUploadId: ID, offset: 0 },
      ])
    }
    expect(observer.attempts[0]!.states.map(({ phase }) => phase)).toEqual([
      'ready',
      'complete',
      null,
    ])
    expect(observer.attempts[1]!.states.map(({ phase }) => phase)).toEqual([
      'ready',
      'complete',
    ])
  })

  it('keeps a refused replacement separate and continues attributing states to A', () => {
    const observer = createUploadIncarnationObserver('author', () => {})
    complete(observer, 'init-a')
    init(observer, 'init-b', B)
    ack(observer, 'init-b', false)
    state(observer, 'complete', A.length)
    expect(observer.errors).toEqual([])
    expect(observer.summary()).toMatchObject([
      {
        requestId: 'init-a',
        accepted: true,
        sentBytes: A.length,
        sentSha256: hash(A),
      },
      {
        requestId: 'init-b',
        accepted: false,
        sentBytes: 0,
        grants: [],
        states: [],
        reply: { data: { reason: 'File is being checked' } },
      },
    ])
    expect(observer.attempts[0]!.states).toHaveLength(3)
  })

  it('separates reconnects even if both client upload id and request id repeat', () => {
    const observer = createUploadIncarnationObserver('author', () => {})
    complete(observer, '1', A, 1)
    complete(observer, '1', B, 2)
    expect(observer.errors).toEqual([])
    expect(observer.summary()).toMatchObject([
      { socket: 1, requestId: '1', sentSha256: hash(A) },
      { socket: 2, requestId: '1', sentSha256: hash(B) },
    ])
  })

  it.each([
    [
      'bytes before init success',
      (observer: Observer) => {
        init(observer, 'a')
        sendBytes(observer)
      },
    ],
    [
      'overlapping init requests',
      (observer: Observer) => {
        init(observer, 'a')
        init(observer, 'b')
      },
    ],
    [
      'a reused request id',
      (observer: Observer) => {
        complete(observer, 'a')
        init(observer, 'a')
      },
    ],
    [
      'reply on a different socket',
      (observer: Observer) => {
        init(observer, 'a')
        ack(observer, 'a', true, 2)
      },
    ],
    [
      'duplicate init ack',
      (observer: Observer) => {
        complete(observer, 'a')
        ack(observer, 'a')
      },
    ],
    [
      'ambiguous complete while replacing',
      (observer: Observer) => {
        complete(observer, 'a')
        init(observer, 'b')
        state(observer, 'complete', A.length)
      },
    ],
    [
      'complete without matching bytes',
      (observer: Observer) => {
        init(observer, 'a')
        ack(observer, 'a')
        state(observer, 'complete', A.length)
      },
    ],
    [
      'credit without demand',
      (observer: Observer) => {
        init(observer, 'a')
        ack(observer, 'a')
        observer.received(1, {
          type: 'hilos_upload_credit',
          data: { clientUploadId: ID, sendUpToBytes: A.length },
        })
      },
    ],
    [
      'a binary frame without a signature',
      (observer: Observer) => observer.sent(1, Buffer.from([0, 1])),
    ],
  ] as const)('fails ambiguous or invalid evidence: %s', (_name, run) => {
    const observer = createUploadIncarnationObserver('author', () => {})
    run(observer)
    expect(observer.errors.length).toBeGreaterThan(0)
  })

  it('rejects credit regression and chunks beyond the accepted incarnation grant', () => {
    const observer = createUploadIncarnationObserver('author', () => {})
    init(observer, 'a')
    ack(observer, 'a')
    observer.sent(
      1,
      JSON.stringify({
        type: 'hilos_upload_credit_request',
        clientUploadId: ID,
        bytes: A.length,
      }),
    )
    observer.received(1, {
      type: 'hilos_upload_credit',
      data: { clientUploadId: ID, sendUpToBytes: 2 },
    })
    observer.received(1, {
      type: 'hilos_upload_credit',
      data: { clientUploadId: ID, sendUpToBytes: 1 },
    })
    observer.sent(
      1,
      Buffer.concat([Buffer.from([ID.length]), Buffer.from(ID), A]),
    )
    expect(observer.errors).toEqual([
      'Error: Invalid cumulative credit for this incarnation',
      'Error: Upload chunk exceeds its incarnation credit or size',
    ])
    expect(observer.summary()[0]!.sentBytes).toBe(0)
  })
})

it('observes real page frame events and batches, and detaches without changing transport', () => {
  const pageEvents = new EventEmitter()
  const page = pageEvents as unknown as Page
  const socket = new EventEmitter()
  Object.assign(socket, { url: () => 'wss://chat.test/ws?tz=UTC' })
  const evidence: Record<string, unknown>[] = []
  const observer = watchUploadIncarnations(page, 'author', (event) =>
    evidence.push(event),
  )
  pageEvents.emit('websocket', socket)
  socket.emit('framesent', {
    payload: JSON.stringify({
      type: 'action',
      action: 'hilos_upload_init',
      requestId: 'a',
      data: {
        clientUploadId: ID,
        filename: 'a.png',
        target: 'chat_attachment',
        size: A.length,
      },
    }),
  })
  const success = {
    type: 'action_success',
    requestId: 'a',
    data: { action: 'hilos_upload_init' },
  }
  const raw = JSON.stringify({
    type: 'signal_batch',
    data: {
      frames: [
        {
          type: 'hilos_upload_state',
          data: { clientUploadId: ID, phase: 'ready' },
        },
        success,
      ],
    },
  })
  socket.emit('framereceived', { payload: raw })
  expect(observer.errors).toEqual([])
  expect(observer.summary()).toMatchObject([
    { socket: 1, requestId: 'a', accepted: true },
  ])
  expect(
    evidence.filter(({ kind }) => kind === 'incarnation-received-frame'),
  ).toMatchObject([
    { socket: 1, message: 1, raw },
    { socket: 1, message: 1, raw },
  ])
  observer.stop()
  observer.stop()
  expect(pageEvents.eventNames()).toEqual([])
  expect(socket.eventNames()).toEqual([])
})
