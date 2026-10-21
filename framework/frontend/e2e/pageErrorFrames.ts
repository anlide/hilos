import type { Page } from '@playwright/test'
import { watchSocketFrames } from './socket.js'

/** One `subscription_page_error` frame, its payload and the original text. */
export interface PageErrorFrameLog {
  type: 'subscription_page_error'
  data: unknown
  raw: string
}

/**
 * Observe page-subscription refusals on this page, including sockets opened
 * later. Arm it before navigation, so the frame is not already gone. A
 * malformed frame of this type stays in the log.
 *
 * @param page The page whose socket frames are observed.
 * @returns The log later frames are appended to.
 */
export function watchPageErrorFrames(page: Page): PageErrorFrameLog[] {
  const frames: PageErrorFrameLog[] = []
  watchSocketFrames(page, (frame) => {
    if (frame.raw === undefined) return
    const wire = frame.wire
    if (wire === null || typeof wire !== 'object' || !('type' in wire)) {
      if (wire !== undefined) return
      const type = frame.raw.match(
        /"type"\s*:\s*"(subscription_page_error)"/,
      )?.[1] as PageErrorFrameLog['type'] | undefined
      if (type) frames.push({ type, data: undefined, raw: frame.raw })
      return
    }
    if (wire.type === 'subscription_page_error') {
      frames.push({
        type: wire.type,
        data: 'data' in wire ? wire.data : undefined,
        raw: frame.raw,
      })
    }
  })
  return frames
}
