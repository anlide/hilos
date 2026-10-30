import { createHash } from 'node:crypto'
import type { Page, WebSocket } from '@playwright/test'
import { deliveryObject } from './deliveryFrames.js'
import { createSocketFrameReader, isApplicationSocket } from './socket.js'

/** One declared source; reusing an upload id never reuses its byte counters. */
interface UploadIncarnation {
  readonly socket: number
  readonly requestId: string
  readonly clientUploadId: string
  readonly filename: string
  readonly target: string
  readonly size: number
  accepted: boolean | null
  reply: Record<string, unknown> | null
  readonly demands: number[]
  readonly grants: number[]
  readonly chunks: Buffer[]
  readonly states: Record<string, unknown>[]
}

interface UploadSlot {
  current?: UploadIncarnation
  pending?: UploadIncarnation
}

/**
 * Record real wire frames without attributing a replacement's bytes to its
 * predecessor. Init replies select the incarnation; a refused init preserves
 * the current source. Ambiguous evidence fails instead of refreshing a snapshot.
 * The caller supplies socket identities and decoded incoming batch inners.
 */
export function createUploadIncarnationObserver(
  participant: string,
  evidence: (event: Record<string, unknown>) => void,
) {
  const attempts: UploadIncarnation[] = []
  const errors: string[] = []
  const sockets = new Map<number, Map<string, UploadSlot>>()
  const requests = new Map<string, UploadIncarnation>()
  const fail = (error: unknown) => {
    errors.push(String(error))
    evidence({
      kind: 'incarnation-observer-error',
      participant,
      error: String(error),
    })
  }
  const slotFor = (socket: number, id: string) => {
    let uploads = sockets.get(socket)
    if (!uploads) sockets.set(socket, (uploads = new Map()))
    let slot = uploads.get(id)
    if (!slot) uploads.set(id, (slot = {}))
    return slot
  }
  const record = (
    kind: string,
    attempt: UploadIncarnation,
    fields: Record<string, unknown>,
  ) =>
    evidence({
      kind,
      participant,
      socket: attempt.socket,
      initRequestId: attempt.requestId,
      clientUploadId: attempt.clientUploadId,
      ...fields,
    })
  const current = (socket: number, id: unknown) => {
    const slot = slotFor(socket, String(id))
    if (!slot.current || slot.pending || slot.current.accepted !== true)
      throw new Error(
        'Upload bytes or credit have no unambiguous accepted init',
      )
    return slot.current
  }
  const sent = (socket: number, payload: string | Buffer) => {
    try {
      if (typeof payload !== 'string') {
        const length = payload[0]
        if (!length || length > 64 || payload.length <= 1 + length)
          throw new Error('Invalid signed upload chunk')
        const id = payload.subarray(1, 1 + length).toString('ascii')
        if (!/^[A-Za-z0-9_-]+$/.test(id))
          throw new Error('Invalid signed upload id')
        const attempt = current(socket, id)
        const bytes = payload.subarray(1 + length)
        const offset = attempt.chunks.reduce(
          (total, chunk) => total + chunk.length,
          0,
        )
        const grant = Math.max(0, ...attempt.grants)
        if (
          bytes.length > 65_536 ||
          offset + bytes.length > grant ||
          offset + bytes.length > attempt.size
        )
          throw new Error('Upload chunk exceeds its incarnation credit or size')
        attempt.chunks.push(Buffer.from(bytes))
        record('incarnation-chunk', attempt, {
          offset,
          bytes: bytes.length,
          grant,
          sha256: createHash('sha256').update(bytes).digest('hex'),
        })
        return
      }
      const wire = deliveryObject(JSON.parse(payload))
      const data = deliveryObject(wire.data)
      if (wire.type === 'action' && wire.action === 'hilos_upload_init') {
        if (
          typeof wire.requestId !== 'string' ||
          !wire.requestId ||
          typeof data.clientUploadId !== 'string' ||
          !data.clientUploadId ||
          typeof data.filename !== 'string' ||
          typeof data.target !== 'string' ||
          !Number.isSafeInteger(data.size) ||
          Number(data.size) < 0
        )
          throw new Error('Upload init lacks its request id or declaration')
        const requestKey = `${socket}/${wire.requestId}`
        const slot = slotFor(socket, data.clientUploadId)
        if (requests.has(requestKey) || slot.pending)
          throw new Error('Overlapping or repeated init request is ambiguous')
        const attempt: UploadIncarnation = {
          socket,
          requestId: wire.requestId,
          clientUploadId: data.clientUploadId,
          filename: data.filename,
          target: data.target,
          size: Number(data.size),
          accepted: null,
          reply: null,
          demands: [],
          grants: [],
          chunks: [],
          states: [],
        }
        slot.pending = attempt
        attempts.push(attempt)
        requests.set(requestKey, attempt)
        record('incarnation-init', attempt, {
          filename: attempt.filename,
          size: attempt.size,
          target: attempt.target,
        })
      } else if (wire.type === 'hilos_upload_credit_request') {
        const attempt = current(socket, wire.clientUploadId)
        if (wire.bytes !== attempt.size)
          throw new Error('Upload demand differs from its incarnation size')
        attempt.demands.push(Number(wire.bytes))
        record('incarnation-demand', attempt, { bytes: wire.bytes })
      }
    } catch (error) {
      fail(error)
    }
  }
  const received = (socket: number, value: unknown) => {
    try {
      const wire = deliveryObject(value)
      const data = deliveryObject(wire.data)
      if (
        (wire.type === 'action_success' || wire.type === 'action_error') &&
        data.action === 'hilos_upload_init'
      ) {
        const attempt = requests.get(`${socket}/${String(wire.requestId)}`)
        if (!attempt || attempt.accepted !== null)
          throw new Error('Upload init reply has no matching pending request')
        attempt.accepted = wire.type === 'action_success'
        attempt.reply = wire
        const slot = slotFor(socket, attempt.clientUploadId)
        if (slot.pending !== attempt)
          throw new Error('Upload init reply does not match its pending source')
        slot.pending = undefined
        if (attempt.accepted) slot.current = attempt
        record('incarnation-init-reply', attempt, {
          accepted: attempt.accepted,
          reply: wire,
        })
      } else if (wire.type === 'hilos_upload_credit') {
        const attempt = current(socket, data.clientUploadId)
        if (
          !Number.isSafeInteger(data.sendUpToBytes) ||
          Number(data.sendUpToBytes) < Math.max(0, ...attempt.grants) ||
          Number(data.sendUpToBytes) > attempt.size ||
          attempt.demands.length === 0
        )
          throw new Error('Invalid cumulative credit for this incarnation')
        attempt.grants.push(Number(data.sendUpToBytes))
        record('incarnation-credit', attempt, {
          sendUpToBytes: data.sendUpToBytes,
        })
      } else if (wire.type === 'hilos_upload_state') {
        const slot = slotFor(socket, String(data.clientUploadId))
        // READY precedes init's ack; gone during replacement names the old source.
        const attempt =
          data.phase === 'ready' ? (slot.pending ?? slot.current) : slot.current
        if (
          !attempt ||
          (slot.pending && data.phase !== 'ready' && data.phase !== null)
        )
          throw new Error(
            'Upload state cannot be attributed to one init request',
          )
        if (
          data.phase === 'complete' &&
          (data.receivedBytes !== attempt.size ||
            data.declaredSize !== attempt.size ||
            attempt.chunks.reduce((total, chunk) => total + chunk.length, 0) !==
              attempt.size)
        )
          throw new Error(
            'Complete upload does not match this incarnation bytes',
          )
        attempt.states.push(data)
        record('incarnation-state', attempt, { ...data })
      }
    } catch (error) {
      fail(error)
    }
  }
  return {
    attempts,
    errors,
    sent,
    received,
    fail,
    summary: () =>
      attempts.map(({ chunks, ...attempt }) => ({
        ...attempt,
        sentBytes: chunks.reduce((total, chunk) => total + chunk.length, 0),
        sentSha256: createHash('sha256')
          .update(Buffer.concat(chunks))
          .digest('hex'),
      })),
  }
}

/**
 * Observe a page's real sockets from before navigation, including batch inners.
 */
export function watchUploadIncarnations(
  page: Page,
  participant: string,
  evidence: (event: Record<string, unknown>) => void,
) {
  const observer = createUploadIncarnationObserver(participant, evidence)
  let sockets = 0
  const cleanups: (() => void)[] = []
  const opened = (socket: WebSocket) => {
    if (!isApplicationSocket(new URL(socket.url()))) return
    const id = ++sockets
    const read = createSocketFrameReader(id)
    const sent = ({ payload }: { payload: string | Buffer }) =>
      observer.sent(id, payload)
    const received = ({ payload }: { payload: string | Buffer }) => {
      for (const incoming of read(payload)) {
        evidence({
          kind: 'incarnation-received-frame',
          participant,
          ...incoming,
        })
        if (incoming.wire === undefined)
          observer.fail(`Invalid incoming frame: ${incoming.failure?.kind}`)
        else observer.received(id, incoming.wire)
      }
    }
    socket.on('framesent', sent)
    socket.on('framereceived', received)
    cleanups.push(() => {
      socket.off('framesent', sent)
      socket.off('framereceived', received)
    })
  }
  page.on('websocket', opened)
  return {
    ...observer,
    stop() {
      page.off('websocket', opened)
      for (const cleanup of cleanups.splice(0)) cleanup()
    },
  }
}
