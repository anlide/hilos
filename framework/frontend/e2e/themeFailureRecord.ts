import type { Page, TestInfo } from '@playwright/test'
import {
  deliveryObject,
  type DeliveryFrameLog,
  type DeliveryRequest,
} from './deliveryFrames.js'
import {
  THEME_PICK_STORAGE_KEY,
  THEME_SETTINGS_STORAGE_KEY,
  type ThemeTestPick,
} from './theme.js'

const THEME_FRAME_TYPES = ['handshake_response', 'hilos_theme_settings']
const THEME_ACTIONS = ['hilos_theme_pick', 'hilos_theme_adopt']
const ACTION_REPLY_TYPES = ['action_success', 'action_error']

/** A closed page or a read past this bound is recorded, and the next session continues. */
const STATE_READ_BOUND_MS = 2000
const PAGE_CLOSED = 'page closed'
const STATE_READ_EXCEEDED = `state read exceeded ${STATE_READ_BOUND_MS} ms`

/** One browser the failure record copies, plus the delivery log it reads. */
export interface ThemeFailureSession {
  readonly page: Page
  readonly node: string
  readonly userId: number | null
  readonly expectedPick: ThemeTestPick
  readonly expectedInitialized: boolean | undefined
  readonly system: 'light' | 'dark'
  readonly counts: unknown
  readonly errors: unknown
  readonly delivery: DeliveryFrameLog
}

/** One handshake or theme-settings inner, stamped with its physical packet. */
export interface ThemeFailureFrame {
  readonly at: number
  readonly socket: number
  readonly message: number
  readonly type: string
  readonly wire: unknown
}

/** The matching action reply, stamped with the packet that carried it. */
export interface ThemeFailureReply {
  readonly at: number
  readonly type: string
  readonly data: unknown
}

/** One theme pick or adopt, kept even when no reply arrived. */
export interface ThemeFailureAction {
  readonly at: number
  readonly socket: number
  readonly action: string
  readonly requestId: unknown
  readonly raw: string
  readonly reply: ThemeFailureReply | null
}

interface ThemeFailureBrowserState {
  readonly theme: string | null
  readonly storedPick: string | null
  readonly storedSettings: string | null
}

interface ThemeFailureUnavailable {
  readonly unavailable: string
}

/** Handshake and theme-settings inners, stamped with the physical packet time. */
export function themeFailureFrames(log: DeliveryFrameLog): ThemeFailureFrame[] {
  return log.packets.flatMap((packet) =>
    packet.frames.flatMap((frame) => {
      const wire = deliveryObject(frame.wire)
      if (
        typeof wire.type !== 'string' ||
        !THEME_FRAME_TYPES.includes(wire.type)
      ) {
        return []
      }
      return [
        {
          at: packet.at,
          socket: frame.socket,
          message: frame.message,
          type: wire.type,
          wire: frame.wire,
        },
      ]
    }),
  )
}

/**
 * Theme pick and adopt actions. The reply matches the same socket, request id
 * and action as a tracked action reply; an action with no such frame stays.
 */
export function themeFailureActions(
  log: DeliveryFrameLog,
): ThemeFailureAction[] {
  return log.sent.flatMap((request) => {
    const wire = deliveryObject(request.wire)
    if (wire.type !== 'action' || typeof wire.action !== 'string') return []
    if (!THEME_ACTIONS.includes(wire.action)) return []
    return [
      {
        at: request.at,
        socket: request.socket,
        action: wire.action,
        requestId: wire.requestId ?? null,
        raw: request.raw,
        reply: themeFailureReply(log, request),
      },
    ]
  })
}

/**
 * Attach the first theme failure. A closed page or a slow read is recorded
 * as unavailable and the next session continues. An attach failure propagates.
 */
export async function attachThemeFailure(
  info: TestInfo,
  sessions: readonly ThemeFailureSession[],
): Promise<void> {
  const recorded = []
  for (const session of sessions) {
    const frames = themeFailureFrames(session.delivery)
    const actions = themeFailureActions(session.delivery)
    recorded.push({
      node: session.node,
      userId: session.userId,
      expectedPick: session.expectedPick,
      expectedInitialized: session.expectedInitialized,
      system: session.system,
      counts: session.counts,
      errors: session.errors,
      frames,
      actions,
      state: await readSessionState(session.page),
    })
  }
  await info.attach('theme-first-failure.json', {
    contentType: 'application/json',
    body: JSON.stringify({ sessions: recorded }),
  })
}

function themeFailureReply(
  log: DeliveryFrameLog,
  request: DeliveryRequest,
): ThemeFailureReply | null {
  const envelope = deliveryObject(request.wire)
  for (const packet of log.packets) {
    for (const { wire, socket } of packet.frames) {
      const frame = deliveryObject(wire)
      const data = deliveryObject(frame.data)
      if (
        socket === request.socket &&
        typeof envelope.requestId === 'string' &&
        (frame.requestId ?? data.requestId) === envelope.requestId &&
        data.action === envelope.action &&
        ACTION_REPLY_TYPES.includes(String(frame.type))
      ) {
        return {
          at: packet.at,
          type: String(frame.type),
          data: 'data' in frame ? frame.data : null,
        }
      }
    }
  }
  return null
}

async function readSessionState(
  page: Page,
): Promise<ThemeFailureBrowserState | ThemeFailureUnavailable> {
  if (page.isClosed()) return { unavailable: PAGE_CLOSED }
  const read = page.evaluate(
    (keys: { pick: string; settings: string }) => ({
      theme: document.documentElement.getAttribute('data-bs-theme'),
      storedPick: localStorage.getItem(keys.pick),
      storedSettings: localStorage.getItem(keys.settings),
    }),
    { pick: THEME_PICK_STORAGE_KEY, settings: THEME_SETTINGS_STORAGE_KEY },
  )
  let timer: ReturnType<typeof setTimeout> | undefined
  const timedOut = new Promise<typeof STATE_READ_EXCEEDED>((resolve) => {
    timer = setTimeout(() => resolve(STATE_READ_EXCEEDED), STATE_READ_BOUND_MS)
  })
  try {
    const winner = await Promise.race([
      read.then((state) => ({ state })),
      timedOut.then((unavailable) => ({ unavailable })),
    ])
    if ('unavailable' in winner) {
      void read.catch(() => undefined)
      return { unavailable: winner.unavailable }
    }
    return winner.state
  } catch (error) {
    if (page.isClosed()) return { unavailable: PAGE_CLOSED }
    throw error
  } finally {
    if (timer !== undefined) clearTimeout(timer)
  }
}
