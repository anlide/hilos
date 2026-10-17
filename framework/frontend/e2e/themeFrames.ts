import type { Page } from '@playwright/test'

/** A relevant theme frame, retaining its entire payload and original text. */
export interface ThemeFrameLog {
  type: 'handshake_response' | 'hilos_theme_settings'
  data: unknown
  raw: string
}

/**
 * Observe theme delivery before navigation, including subsequent sockets.
 * Malformed relevant frames stay in the log so assertions cannot overlook them.
 */
export function watchThemeFrames(page: Page): ThemeFrameLog[] {
  const frames: ThemeFrameLog[] = []
  page.on('websocket', (socket) => {
    socket.on('framereceived', ({ payload }) => {
      if (typeof payload !== 'string') return
      let wire: unknown
      try {
        wire = JSON.parse(payload)
      } catch {
        const type = payload.match(
          /"type"\s*:\s*"(handshake_response|hilos_theme_settings)"/,
        )?.[1] as ThemeFrameLog['type'] | undefined
        if (type) frames.push({ type, data: undefined, raw: payload })
        return
      }
      if (wire === null || typeof wire !== 'object' || !('type' in wire)) return
      if (
        wire.type === 'handshake_response' ||
        wire.type === 'hilos_theme_settings'
      ) {
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
