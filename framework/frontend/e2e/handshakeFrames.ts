import type { Page } from '@playwright/test'

/** A handshake welcome, retaining its entire payload and original text. */
export interface HandshakeFrameLog {
  type: 'handshake'
  data: unknown
  raw: string
}

/**
 * Observe handshake welcomes before navigation, including subsequent sockets.
 * Malformed relevant frames stay in the log so assertions cannot overlook them.
 */
export function watchHandshakeFrames(page: Page): HandshakeFrameLog[] {
  const frames: HandshakeFrameLog[] = []
  page.on('websocket', (socket) => {
    socket.on('framereceived', ({ payload }) => {
      if (typeof payload !== 'string') return
      let wire: unknown
      try {
        wire = JSON.parse(payload)
      } catch {
        const type = payload.match(/"type"\s*:\s*"(handshake)"/)?.[1] as
          HandshakeFrameLog['type'] | undefined
        if (type) frames.push({ type, data: undefined, raw: payload })
        return
      }
      if (wire === null || typeof wire !== 'object' || !('type' in wire)) return
      if (wire.type === 'handshake') {
        frames.push({
          type: wire.type,
          data: 'data' in wire ? wire.data : undefined,
          raw: payload,
        })
      }
    })
  })
  return frames
}
