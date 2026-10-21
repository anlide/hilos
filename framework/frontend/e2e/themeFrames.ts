import type { Page } from '@playwright/test'
import { watchSocketFrames } from './socket.js'

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
  watchSocketFrames(page, (frame) => {
    if (frame.raw === undefined) return
    const wire = frame.wire
    if (wire === null || typeof wire !== 'object' || !('type' in wire)) {
      if (wire !== undefined) return
      const type = frame.raw.match(
        /"type"\s*:\s*"(handshake_response|hilos_theme_settings)"/,
      )?.[1] as ThemeFrameLog['type'] | undefined
      if (type) frames.push({ type, data: undefined, raw: frame.raw })
      return
    }
    if (
      wire.type === 'handshake_response' ||
      wire.type === 'hilos_theme_settings'
    ) {
      frames.push({
        type: wire.type,
        data: 'data' in wire ? wire.data : undefined,
        raw: frame.raw,
      })
    }
  })
  return frames
}
