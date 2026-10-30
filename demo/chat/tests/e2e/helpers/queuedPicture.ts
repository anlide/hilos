import type { Expect, Page } from '@playwright/test'
import {
  createSocketFrameReader,
  isApplicationSocket,
} from '../../../../../framework/frontend/e2e/socket.js'
import { deliveryObject } from '../../../../../framework/frontend/e2e/deliveryFrames.js'
import {
  createUploadIncarnationObserver,
  watchUploadIncarnations,
} from '../../../../../framework/frontend/e2e/uploadIncarnations.js'
import {
  signUploadChunk,
  UPLOAD_CHUNK_BYTES,
} from '../../../../../framework/frontend/core/src/uploads/uploadProtocol.js'

/** Queue a replacement ahead of Chat's real submit on the same live socket. */
export async function queuedPicture(
  page: Page,
  replacement: { name: string; buffer: Buffer },
  record: (event: Record<string, unknown>) => void,
) {
  const observer = createUploadIncarnationObserver('author', record)
  const connections: { socket: number; acceptKey?: string; closed: boolean }[] =
    []
  let original: { socket: number; wire: Record<string, unknown> } | undefined
  let armed = false
  let queued:
    | {
        socket: number
        clientUploadId: string
        requestId: string
        sentAt: number
        acceptKey: string
        message: string
        accepted: boolean | null
        sentBytes: number
      }
    | undefined
  let admitted = false
  let submissions = 0

  await page.routeWebSocket(isApplicationSocket, (client) => {
    const connection = { socket: connections.length + 1, closed: false }
    connections.push(connection)
    const socket = connection.socket
    const read = createSocketFrameReader(socket)
    const server = client.connectToServer()
    const send = (message: string | Buffer) => {
      observer.sent(socket, message)
      if (typeof message === 'string')
        record({ kind: 'sent-frame', socket, wire: JSON.parse(message) })
      server.send(message)
    }
    client.onMessage((message) => {
      try {
        const wire =
          typeof message === 'string' ? deliveryObject(JSON.parse(message)) : {}
        if (wire.type === 'action' && wire.action === 'hilos_upload_init')
          original = { socket, wire }
        if (wire.type === 'action' && wire.action === 'message') {
          submissions++
          if (armed) {
            armed = false
            if (!original || original.socket !== socket || queued)
              throw new Error('The replacement lost its original socket/init')
            const data = deliveryObject(original.wire.data)
            if (
              typeof data.clientUploadId !== 'string' ||
              JSON.stringify(deliveryObject(wire.data).attachments) !==
                JSON.stringify([data.clientUploadId])
            )
              throw new Error('Chat did not submit the original picture id')
            const content = deliveryObject(wire.data).content
            const acceptKey = connections[socket - 1]!.acceptKey
            if (typeof content !== 'string' || !acceptKey)
              throw new Error(
                'The submitted message has no identified author socket',
              )
            queued = {
              socket,
              clientUploadId: data.clientUploadId,
              requestId: 'queued-picture-init',
              sentAt: Date.now(),
              acceptKey,
              message: content,
              accepted: null,
              sentBytes: 0,
            }
            send(
              JSON.stringify({
                ...original.wire,
                requestId: queued.requestId,
                data: {
                  ...data,
                  filename: replacement.name,
                  size: replacement.buffer.length,
                },
              }),
            )
          }
        }
        send(message)
      } catch (error) {
        observer.fail(error)
      }
    })
    server.onMessage((message) => {
      try {
        for (const frame of read(message)) {
          record({ kind: 'received-frame', ...frame })
          if (frame.wire === undefined) {
            observer.fail(`Invalid incoming frame: ${frame.failure?.kind}`)
            continue
          }
          observer.received(socket, frame.wire)
          const wire = deliveryObject(frame.wire)
          const data = deliveryObject(wire.data)
          const self = deliveryObject(
            deliveryObject(deliveryObject(data.payload).data).selfConnection,
          )
          if (
            wire.type === 'page_response' &&
            typeof self.acceptKey === 'string'
          )
            connections[socket - 1]!.acceptKey = self.acceptKey
          if (
            !admitted &&
            queued?.socket === socket &&
            self.acceptKey === queued.acceptKey &&
            self.outboundModerationPhase === 'checking' &&
            self.outboundModerationMessage === queued.message
          ) {
            admitted = true
            record({ kind: 'message-admitted', socket, wire })
          }
          if (!queued || queued.socket !== socket) continue
          if (
            data.action === 'hilos_upload_init' &&
            wire.requestId === queued.requestId
          ) {
            queued.accepted = wire.type === 'action_success'
            if (queued.accepted)
              send(
                JSON.stringify({
                  type: 'hilos_upload_credit_request',
                  clientUploadId: queued.clientUploadId,
                  bytes: replacement.buffer.length,
                }),
              )
          }
          if (
            wire.type === 'hilos_upload_credit' &&
            data.clientUploadId === queued.clientUploadId &&
            queued.accepted === true
          ) {
            const grant = data.sendUpToBytes
            if (
              !Number.isSafeInteger(grant) ||
              Number(grant) > replacement.buffer.length
            )
              throw new Error('Invalid replacement credit')
            while (queued.sentBytes < Number(grant)) {
              const end = Math.min(
                queued.sentBytes + UPLOAD_CHUNK_BYTES,
                Number(grant),
              )
              const bytes = Uint8Array.from(
                replacement.buffer.subarray(queued.sentBytes, end),
              )
              send(
                Buffer.from(
                  signUploadChunk(queued.clientUploadId, bytes.buffer),
                ),
              )
              queued.sentBytes = end
            }
          }
        }
      } catch (error) {
        observer.fail(error)
      }
      client.send(message)
    })
    client.onClose((code, reason) => {
      connections[socket - 1]!.closed = true
      record({ kind: 'socket-close', socket, code, reason })
      void server.close({ code, reason })
    })
  })
  return {
    observer,
    connections,
    queued: () => queued,
    admitted: () => admitted,
    submissions: () => submissions,
    arm() {
      if (!original || armed || queued)
        throw new Error('No unique original init to replace')
      armed = true
    },
  }
}

/** The existing browser socket that actually declared each Chat picture. */
interface PictureWireWindow extends Window {
  chatPictureSockets: Map<string, WebSocket>
}

/** Send a control or signed bytes on the browser socket that declared this id. */
async function sendPictureFrame(
  page: Page,
  clientUploadId: string,
  payload: string | number[],
): Promise<void> {
  await page.evaluate(
    ({ clientUploadId, payload }) => {
      const socket = (
        window as unknown as PictureWireWindow
      ).chatPictureSockets.get(clientUploadId)
      if (!socket || socket.readyState !== WebSocket.OPEN)
        throw new Error('The original picture socket is unavailable')
      socket.send(
        typeof payload === 'string' ? payload : Uint8Array.from(payload),
      )
    },
    { clientUploadId, payload },
  )
}

/**
 * Probe Chat's submitted pictures through their original browser sockets.
 * The SDK picks, declares and completes the originals. Adversarial actions and
 * a credited replacement use those same sockets and the SDK's chunk signer.
 */
export async function pictureControls(
  page: Page,
  record: (event: Record<string, unknown>) => void,
) {
  const replies: { socket: unknown; wire: Record<string, unknown> }[] = []
  const requests = new Map<string, number>()
  const self: Record<string, unknown> = {}
  const observer = watchUploadIncarnations(page, 'author', (event) => {
    record(event)
    if (event.kind !== 'incarnation-received-frame') return
    const wire = deliveryObject(event.wire)
    if (wire.type === 'action_success' || wire.type === 'action_error')
      replies.push({ socket: event.socket, wire })
    const data = deliveryObject(wire.data)
    Object.assign(
      self,
      deliveryObject(
        deliveryObject(deliveryObject(data.payload).data).selfConnection,
      ),
    )
  })
  await page.addInitScript(() => {
    const sockets = new Map<string, WebSocket>()
    ;(window as unknown as PictureWireWindow).chatPictureSockets = sockets
    const send = WebSocket.prototype.send
    WebSocket.prototype.send = function (data) {
      if (typeof data === 'string') {
        const wire = JSON.parse(data)
        if (wire.type === 'action' && wire.action === 'hilos_upload_init') {
          const previous = sockets.get(wire.data.clientUploadId)
          if (previous && previous !== this)
            throw new Error('A Chat picture moved to another socket')
          sockets.set(wire.data.clientUploadId, this)
        }
      }
      send.call(this, data)
    }
  })
  let sequence = 0
  return {
    observer,
    self,
    reply(requestId: string) {
      const matches = replies.filter(
        ({ socket, wire }) =>
          socket === requests.get(requestId) && wire.requestId === requestId,
      )
      if (matches.length > 1) throw new Error('Repeated picture action reply')
      return matches[0]?.wire
    },
    async mutate(
      clientUploadId: string,
      action: 'init' | 'cancel',
      replacement?: {
        name: string
        buffer: Buffer
        target: string
        mimeType?: string
      },
    ) {
      const original = observer
        .summary()
        .find((upload) => upload.clientUploadId === clientUploadId)
      if (!original?.accepted)
        throw new Error('The picture has no accepted original declaration')
      const requestId = `picture-mutation-${++sequence}`
      const wire = {
        type: 'action',
        action: `hilos_upload_${action}`,
        requestId,
        data: {
          clientUploadId,
          ...(action === 'init'
            ? {
                target: replacement?.target ?? original.target,
                filename: replacement?.name ?? original.filename,
                mimeType: replacement?.mimeType ?? 'image/png',
                size: replacement?.buffer.length ?? original.size,
              }
            : {}),
        },
      }
      requests.set(requestId, original.socket)
      await sendPictureFrame(page, clientUploadId, JSON.stringify(wire))
      record({ kind: 'picture-mutation-sent', socket: original.socket, wire })
      return requestId
    },
    async finishReplacement(
      expect: Expect,
      clientUploadId: string,
      buffer: Buffer,
    ) {
      const replacement = observer.attempts
        .filter((attempt) => attempt.clientUploadId === clientUploadId)
        .at(-1)
      if (
        !replacement ||
        replacement.accepted !== true ||
        replacement.chunks.length !== 0 ||
        replacement.size !== buffer.length
      )
        throw new Error('No unique accepted replacement to complete')
      await sendPictureFrame(
        page,
        clientUploadId,
        JSON.stringify({
          type: 'hilos_upload_credit_request',
          clientUploadId,
          bytes: buffer.length,
        }),
      )
      await expect
        .poll(() => Math.max(0, ...replacement.grants))
        .toBe(buffer.length)
      for (
        let offset = 0;
        offset < buffer.length;
        offset += UPLOAD_CHUNK_BYTES
      ) {
        const bytes = Uint8Array.from(
          buffer.subarray(offset, offset + UPLOAD_CHUNK_BYTES),
        )
        await sendPictureFrame(page, clientUploadId, [
          ...new Uint8Array(signUploadChunk(clientUploadId, bytes.buffer)),
        ])
      }
      await expect
        .poll(() =>
          replacement.states.some(({ phase }) => phase === 'complete'),
        )
        .toBe(true)
    },
  }
}
