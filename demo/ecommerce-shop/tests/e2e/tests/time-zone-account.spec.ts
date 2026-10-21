import { expect, test } from '@playwright/test'

import {
  watchThemeFrames,
  type ThemeFrameLog,
} from '../../../../../framework/frontend/e2e/index.js'
import { gotoPage } from '../helpers/page.js'
import { signUp } from '../helpers/session.js'

// The live handshake carries the stored personal time zone. Registration has
// no time-zone choice yet, so a new account must carry an explicit null.

/** Reject malformed wire objects rather than treating them as a guest. */
function object(value: unknown): Record<string, unknown> {
  expect(value).not.toBeNull()
  expect(typeof value).toBe('object')
  expect(Array.isArray(value)).toBe(false)
  return value as Record<string, unknown>
}

/** Validate every observed handshake before using its identity. */
function currentUser(frame: ThemeFrameLog): Record<string, unknown> | null {
  const entities = object(object(frame.data).entities)
  expect(Object.hasOwn(entities, 'currentUser'), frame.raw).toBe(true)
  if (entities.currentUser === null) return null

  const user = object(entities.currentUser)
  expect(user.id, frame.raw).toEqual(expect.any(Number))
  expect(user.id, frame.raw).toBeGreaterThan(0)
  expect(Object.hasOwn(user, 'timeZone'), frame.raw).toBe(true)
  expect(user.timeZone, frame.raw).toBeNull()
  return user
}

test('account handshake keeps an explicit null time zone after reload', async ({
  page,
}) => {
  const frames = watchThemeFrames(page)
  await signUp(page)

  await expect
    .poll(() =>
      frames
        .filter((frame) => frame.type === 'handshake_response')
        .map(currentUser)
        .some((user) => user !== null),
    )
    .toBe(true)
  const userId = frames
    .filter((frame) => frame.type === 'handshake_response')
    .map(currentUser)
    .at(-1)?.id
  expect(userId).toEqual(expect.any(Number))

  // Only frames received after this boundary may prove the reloaded session.
  const beforeReload = frames.length
  await page.reload()
  await expect
    .poll(
      () =>
        frames
          .slice(beforeReload)
          .filter((frame) => frame.type === 'handshake_response').length,
    )
    .toBeGreaterThan(0)
  for (const frame of frames.slice(beforeReload)) {
    if (frame.type === 'handshake_response') {
      expect(currentUser(frame), frame.raw).toMatchObject({
        id: userId,
        timeZone: null,
      })
    }
  }
})

test('guest handshake carries an explicit null current user', async ({
  page,
}) => {
  const frames = watchThemeFrames(page)
  await gotoPage(page, '/')

  await expect
    .poll(
      () =>
        frames.filter((frame) => frame.type === 'handshake_response').length,
    )
    .toBeGreaterThan(0)
  for (const frame of frames) {
    if (frame.type === 'handshake_response') {
      expect(currentUser(frame), frame.raw).toBeNull()
    }
  }
})
