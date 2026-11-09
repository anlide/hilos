import { expect, test } from '../test.js'

import {
  createTestUser,
  watchThemeFrames,
} from '../../../../../framework/frontend/e2e/index.js'
import { currentUser } from '../helpers/handshakeCurrentUser.js'

// The live handshake carries the stored personal time zone. A new account has
// no time-zone choice yet, so it must carry an explicit null.

test('account handshake keeps an explicit null time zone after reload', async ({
  page,
}) => {
  const frames = watchThemeFrames(page)
  const person = await createTestUser(expect, page)

  await expect
    .poll(() =>
      frames
        .filter((frame) => frame.type === 'handshake_response')
        .map((frame) => currentUser(expect, frame))
        .some((user) => user !== null),
    )
    .toBe(true)
  const userId = frames
    .filter((frame) => frame.type === 'handshake_response')
    .map((frame) => currentUser(expect, frame))
    .at(-1)?.id
  expect(userId).toEqual(expect.any(Number))
  expect(userId).toBe(person.userId)

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
      expect(currentUser(expect, frame), frame.raw).toMatchObject({
        id: userId,
        timeZone: null,
      })
    }
  }
})
