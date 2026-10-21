import type { Page } from '@playwright/test'
import { watchSocketFrames } from './socket.js'

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
  watchSocketFrames(page, (frame) => {
    if (frame.raw === undefined) return
    const wire = frame.wire
    if (wire === null || typeof wire !== 'object' || !('type' in wire)) {
      if (wire !== undefined) return
      const type = frame.raw.match(/"type"\s*:\s*"(handshake)"/)?.[1] as
        HandshakeFrameLog['type'] | undefined
      if (type) frames.push({ type, data: undefined, raw: frame.raw })
      return
    }
    if (wire.type === 'handshake') {
      frames.push({
        type: wire.type,
        data: 'data' in wire ? wire.data : undefined,
        raw: frame.raw,
      })
    }
  })
  return frames
}
