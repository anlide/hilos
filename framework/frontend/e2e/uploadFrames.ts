import { createHash } from 'node:crypto'
import type { Page, WebSocket, WebSocketRoute } from '@playwright/test'
import {
  createSocketFrameReader,
  isApplicationSocket,
  type SocketFrame,
} from './socket.js'

interface UploadObservation {
  readonly id: string
  readonly filename: string
  readonly size: number
  readonly requestIds: string[]
  readonly demands: number[]
  readonly grants: number[]
  readonly chunks: Buffer[]
  readonly states: Record<string, unknown>[]
  canceled: number
}

function record(value: unknown): Record<string, unknown> {
  return typeof value === 'object' && value !== null && !Array.isArray(value)
    ? (value as Record<string, unknown>)
    : {}
}

/**
 * Observe real SDK init, credit, signed binary and state frames on this context's
 * page. No generated frame or fake counter feeds the product. Cumulative grants
 * are compared per id, so an identical credit never counts twice.
 */
export function watchUploadFrames(
  page: Page,
  participant: string,
  evidence: (event: Record<string, unknown>) => void,
) {
  const uploads = new Map<string, UploadObservation>()
  const errors: string[] = []
  let sockets = 0
  let closes = 0
  const frame = (
    direction: 'sent' | 'received',
    payload: string | Buffer,
    wire?: unknown,
  ) => {
    try {
      if (typeof payload !== 'string') {
        if (direction !== 'sent') return
        const idLength = payload[0]
        const id = payload.subarray(1, 1 + idLength).toString('ascii')
        const upload = uploads.get(id)
        if (
          !upload ||
          idLength < 1 ||
          idLength > 64 ||
          payload.length <= 1 + idLength
        )
          throw new Error('A signed binary frame has no matching real init')
        const bytes = payload.subarray(1 + idLength)
        const offset = upload.chunks.reduce(
          (total, chunk) => total + chunk.length,
          0,
        )
        const grant = Math.max(0, ...upload.grants)
        if (
          bytes.length > 65_536 ||
          offset + bytes.length > grant ||
          offset + bytes.length > upload.size
        )
          throw new Error(
            'The SDK sent bytes beyond its actual cumulative grant or file',
          )
        upload.chunks.push(bytes)
        evidence({
          kind: 'binary',
          participant,
          id,
          offset,
          length: bytes.length,
          grant,
          sha256: createHash('sha256').update(bytes).digest('hex'),
        })
        return
      }
      const envelope = record(direction === 'sent' ? JSON.parse(payload) : wire)
      const data = record(envelope.data)
      if (
        direction === 'sent' &&
        envelope.type === 'action' &&
        envelope.action === 'hilos_upload_init'
      ) {
        const id = String(data.clientUploadId)
        const existing = uploads.get(id)
        if (existing) existing.requestIds.push(String(envelope.requestId))
        else
          uploads.set(id, {
            id,
            filename: String(data.filename),
            size: Number(data.size),
            requestIds: [String(envelope.requestId)],
            demands: [],
            grants: [],
            chunks: [],
            states: [],
            canceled: 0,
          })
        evidence({
          kind: 'init',
          participant,
          requestId: envelope.requestId,
          ...data,
        })
      } else if (
        direction === 'sent' &&
        envelope.type === 'hilos_upload_credit_request'
      ) {
        const upload = uploads.get(String(envelope.clientUploadId))
        if (!upload) throw new Error('A credit request has no init')
        upload.demands.push(Number(envelope.bytes))
        evidence({ kind: 'demand', participant, ...envelope })
      } else if (
        direction === 'received' &&
        envelope.type === 'hilos_upload_credit'
      ) {
        const upload = uploads.get(String(data.clientUploadId))
        if (
          !upload ||
          !Number.isSafeInteger(data.sendUpToBytes) ||
          Number(data.sendUpToBytes) < 0 ||
          Number(data.sendUpToBytes) > upload.size
        )
          throw new Error(
            'A credit has no matching file or valid cumulative offset',
          )
        upload.grants.push(Number(data.sendUpToBytes))
        evidence({ kind: 'credit', participant, ...data })
      } else if (
        direction === 'received' &&
        envelope.type === 'hilos_upload_state'
      ) {
        const upload = uploads.get(String(data.clientUploadId))
        if (upload) upload.states.push(data)
        evidence({ kind: 'upload-state', participant, ...data })
      } else if (
        direction === 'sent' &&
        envelope.type === 'action' &&
        envelope.action === 'hilos_upload_cancel'
      ) {
        const upload = uploads.get(String(data.clientUploadId))
        if (upload) upload.canceled++
        evidence({ kind: 'cancel', participant, ...data })
      }
    } catch (error) {
      errors.push(String(error))
      evidence({ kind: 'observer-error', participant, error: String(error) })
    }
  }
  const receive = (frames: SocketFrame[]) => {
    for (const incoming of frames) {
      if (incoming.raw === undefined) continue
      evidence({ kind: 'received-frame', participant, ...incoming })
      if (incoming.wire === undefined) {
        const error = `Invalid incoming upload evidence: ${incoming.failure?.kind}`
        errors.push(error)
        evidence({ kind: 'observer-error', participant, error, ...incoming })
      } else frame('received', incoming.raw, incoming.wire)
    }
  }
  const watch = (socket: WebSocket) => {
    const read = createSocketFrameReader(++sockets)
    socket.on('close', () => {
      closes++
    })
    socket.on('framesent', ({ payload }) => frame('sent', payload))
    socket.on('framereceived', ({ payload }) => receive(read(payload)))
  }
  page.on('websocket', watch)
  return {
    uploads,
    errors,
    /** Arm before navigation. Observe the real wire at the route, without a second CDP observer. */
    holdCredits: async () => {
      page.off('websocket', watch)
      const credits: { clientUploadId: string; sendUpToBytes: number }[] = []
      let open = false
      const held = new Map<WebSocketRoute, (string | Buffer)[]>()
      await page.routeWebSocket(isApplicationSocket, (socket) => {
        const read = createSocketFrameReader(++sockets)
        const server = socket.connectToServer()
        const buffered: (string | Buffer)[] = []
        held.set(socket, buffered)
        socket.onMessage((message) => {
          frame('sent', message)
          server.send(message)
        })
        server.onMessage((message) => {
          const incoming = read(message)
          receive(incoming)
          const withheld = open
            ? []
            : incoming.filter(
                ({ wire }) => record(wire).type === 'hilos_upload_credit',
              )
          for (const credit of withheld) {
            const data = record(record(credit.wire).data)
            credits.push({
              clientUploadId: String(data.clientUploadId),
              sendUpToBytes: Number(data.sendUpToBytes),
            })
            evidence({
              kind: 'withheld-real-credit',
              participant,
              originalFrame: message,
              ...credit,
            })
          }
          // Keep the whole physical message and its successors in order. A
          // batch's neighboring frames are released once with the real credit.
          if (!open && (withheld.length > 0 || buffered.length > 0))
            buffered.push(message)
          else socket.send(message)
        })
        socket.onClose((code, reason) => {
          closes++
          held.delete(socket)
          void server.close({ code, reason })
        })
      })
      const release = () => {
        open = true
        for (const [socket, buffered] of held) {
          for (const message of buffered.splice(0)) socket.send(message)
        }
      }
      return { credits, errors, release, dispose: release }
    },
    connections: () => ({ sockets, closes }),
    sentBytes: () =>
      [...uploads.values()].reduce(
        (total, upload) =>
          total + upload.chunks.reduce((sum, chunk) => sum + chunk.length, 0),
        0,
      ),
    grantedBytes: () =>
      [...uploads.values()].reduce(
        (total, upload) => total + Math.max(0, ...upload.grants),
        0,
      ),
    summary: () =>
      [...uploads.values()].map(({ chunks, ...upload }) => ({
        ...upload,
        lengths: chunks.map((chunk) => chunk.length),
        sentBytes: chunks.reduce((sum, chunk) => sum + chunk.length, 0),
        sentSha256: createHash('sha256')
          .update(Buffer.concat(chunks))
          .digest('hex'),
      })),
  }
}
