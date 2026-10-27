import type { Expect, Page } from '@playwright/test'
import {
  profileNameStateSchema,
  SIGNAL_PROFILE_NAME_STATE,
  type HilosProfileNameChange,
  type HilosProfileNameState,
} from '../core/src/protocol/profileNameStateSignal.js'
import { deliveryObject } from './deliveryFrames.js'
import { watchHeight, watchTop } from './geometry.js'
import { watchSocketFrames, type SocketFrame } from './socket.js'

/** A validated session-holder snapshot, retaining its socket identity. */
export interface ProfileNameSnapshot {
  readonly socket: number
  readonly state: HilosProfileNameState
}

/** Wire evidence across reloads; malformed frames remain visible to assertions. */
export interface ProfileRenameFrameLog {
  readonly frames: SocketFrame[]
  readonly snapshots: ProfileNameSnapshot[]
  readonly invalid: SocketFrame[]
  readonly stop: () => void
}

/** Arm before navigation to keep name snapshots and welcomes on every socket. */
export function watchProfileRenameFrames(page: Page): ProfileRenameFrameLog {
  const frames: SocketFrame[] = []
  const snapshots: ProfileNameSnapshot[] = []
  const invalid: SocketFrame[] = []
  const observer = watchSocketFrames(page, (frame) => {
    const wire = deliveryObject(frame.wire)
    if (frame.failure) {
      invalid.push(frame)
      frames.push(frame)
      return
    }
    if (
      wire.type !== 'handshake' &&
      wire.type !== 'handshake_response' &&
      wire.type !== SIGNAL_PROFILE_NAME_STATE
    )
      return
    frames.push(frame)
    if (wire.type !== SIGNAL_PROFILE_NAME_STATE) return
    const parsed = profileNameStateSchema.safeParse(wire.data)
    if (!parsed.success) invalid.push(frame)
    else snapshots.push({ socket: frame.socket, state: parsed.data })
  })
  return { frames, snapshots, invalid, stop: () => observer.stop() }
}

/**
 * Wait for the latest authoritative answer, optionally on a socket after F5.
 * A welcome and own session response must have arrived on that same socket.
 *
 * @param expect The spec's assertion function.
 * @param log The observer armed before the first navigation.
 * @param userId The signed-in person.
 * @param change The expected operation fields, or a known absence.
 * @param afterSocket Require a newer socket than this bookmark.
 */
export async function expectProfileNameSnapshot(
  expect: Expect,
  log: ProfileRenameFrameLog,
  userId: number,
  change: Partial<HilosProfileNameChange> | null,
  afterSocket = 0,
): Promise<ProfileNameSnapshot> {
  const latest = () =>
    log.snapshots.filter((row) => row.socket > afterSocket).at(-1)
  await expect.poll(() => latest()?.state).toMatchObject({ userId, change })
  const snapshot = latest()!
  await expect
    .poll(() =>
      log.frames
        .filter((frame) => frame.socket === snapshot.socket)
        .map((frame) => deliveryObject(frame.wire).type),
    )
    .toEqual(expect.arrayContaining(['handshake', 'handshake_response']))
  expect(log.invalid).toEqual([])
  return snapshot
}

/**
 * Bookmark the name form's permanent polite voice and its reserved room.
 * The returned check proves the same node and controls survive every state.
 *
 * @param expect The spec's assertion function.
 * @param page The browser with the name form already open.
 */
export async function watchProfileRenameStatus(expect: Expect, page: Page) {
  const form = page.getByTestId('profile-name-input').locator('..')
  // The edit-notice slot is a second polite region in the same form.
  const checking = form.getByRole('status').and(page.locator(':not([data-id])'))
  await expect(checking).toHaveCount(1)
  const node = await checking.elementHandle()
  const twin = checking.locator('..').locator(':scope > [aria-hidden="true"]')
  await expect(twin).toHaveClass(/\binvisible\b/)
  await expect(twin).toHaveText('Checking your name…')
  await expect(twin.locator('.spinner-border')).toHaveAttribute(
    'aria-hidden',
    'true',
  )
  const marks = await Promise.all([
    watchHeight(page.getByTestId('modal').locator('.modal-dialog')),
    watchTop(page.getByTestId('profile-name-input')),
    watchTop(page.getByTestId('profile-rename-save')),
    watchTop(page.getByTestId('profile-rename-cancel')),
  ])
  return {
    async unchanged(pending: boolean) {
      await expect(checking).toHaveAttribute('aria-live', 'polite')
      await expect(checking).toHaveText(pending ? 'Checking your name…' : '')
      expect(await node!.evaluate((element) => element.isConnected)).toBe(true)
      if (pending) {
        await expect(checking.locator('.spinner-border')).toHaveAttribute(
          'aria-hidden',
          'true',
        )
      }
      for (const mark of marks) await mark.unchanged()
    },
  }
}
