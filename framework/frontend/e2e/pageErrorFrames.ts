import type { Page } from '@playwright/test'

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
  page.on('websocket', (socket) => {
    socket.on('framereceived', ({ payload }) => {
      const raw = payload.toString()
      let wire: unknown
      try {
        wire = JSON.parse(raw)
      } catch {
        if (/"type"\s*:\s*"subscription_page_error"/.test(raw)) {
          frames.push({
            type: 'subscription_page_error',
            data: undefined,
            raw,
          })
        }
        return
      }
      if (wire === null || typeof wire !== 'object' || !('type' in wire)) {
        return
      }
      if (wire.type !== 'subscription_page_error') return
      frames.push({
        type: 'subscription_page_error',
        data: 'data' in wire ? wire.data : undefined,
        raw,
      })
    })
  })
  return frames
}
