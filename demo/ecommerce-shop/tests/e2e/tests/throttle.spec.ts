import { test, expect } from '@playwright/test'

import { gotoPage } from '../helpers/page'
import {
  PASSWORD,
  clickSubmit,
  enterIdentifierAndPassword,
  logout,
  nameFromEmail,
  openSignIn,
  register,
  typeInto,
  uniqueEmail,
  waitAuthSettled,
} from '../helpers/session'
import { resetThrottle } from '../helpers/throttle'

// The anti-abuse guard seen from a browser (HIL-1280). The unit and integration
// suites prove the window and the ladder; this proves the refusal reaches a
// person: a run of wrong passwords ends in "Too many attempts", and while the
// block holds the right password is refused the same way. The layer is on in
// this demo's test environment, at its default numbers.
//
// The counters are the whole node's, and the IP scope is shared by every spec
// signing in from this runner, so the spec clears them before it starts and
// again in `finally`, whatever happened in between.

// HILOS_AUTH_THROTTLE_MAX_SESSION at its default: the sign-ins one session may
// make in one window. The next one is refused.
const SIGN_INS_ALLOWED = 10

const WRONG_PASSWORD = 'not the password'

// The refusal a block gives on the wire, and the words it is drawn in.
const RATE_LIMITED_CODE = '"rate_limited"'
const REFUSED = 'Too many attempts'

test('a run of wrong passwords is refused, and the block holds the right one too', async ({
  page,
}) => {
  // A registration and twelve sign-in round trips, each one a verdict of the
  // throttle agent: longer than the base cap allows a single test.
  test.slow()

  // Counted off the socket, so each step waits for its own reply rather than
  // reading the line the previous refusal left on screen.
  let refusals = 0
  page.on('websocket', (socket) => {
    socket.on('framereceived', (frame) => {
      if (
        typeof frame.payload === 'string' &&
        frame.payload.includes(RATE_LIMITED_CODE)
      ) {
        refusals += 1
      }
    })
  })

  await resetThrottle()
  try {
    const email = uniqueEmail()
    await gotoPage(page, '/')
    await openSignIn(page)
    await register(page, email)
    await expect(page.getByTestId('self-user')).toHaveText(nameFromEmail(email))
    await logout(page)
    await expect(page.getByTestId('self-anonymous')).toBeVisible()

    // The address is typed once. Looking it up is a guarded door of its own,
    // and typing it again on every round would spend that budget too.
    await openSignIn(page)
    await enterIdentifierAndPassword(page, email, WRONG_PASSWORD)
    const error = page.getByTestId('auth-error')
    const password = page.getByTestId('auth-password')
    const submit = page.getByTestId('auth-submit')

    for (let attempt = 1; attempt <= SIGN_INS_ALLOWED; attempt += 1) {
      if (attempt > 1) {
        await typeInto(password, WRONG_PASSWORD)
      }
      await clickSubmit(submit)
      await waitAuthSettled(page)
      await expect(error).toBeVisible()
      await expect(page.getByTestId('self-anonymous')).toBeVisible()
    }
    // Every one of them was the password check answering, none the guard.
    expect(refusals).toBe(0)
    await expect(error).not.toContainText(REFUSED)

    // One past the limit: the guard answers, not the password check.
    await typeInto(password, WRONG_PASSWORD)
    await clickSubmit(submit)
    await expect.poll(() => refusals).toBeGreaterThan(0)
    await expect(error).toContainText(REFUSED)

    // The key is blocked now, and the block holds whatever is typed.
    const refusedBefore = refusals
    await typeInto(password, PASSWORD)
    await clickSubmit(submit)
    await expect.poll(() => refusals).toBeGreaterThan(refusedBefore)
    await expect(error).toContainText(REFUSED)
    await expect(page.getByTestId('self-anonymous')).toBeVisible()
    await expect(page.getByTestId('self-user')).toHaveCount(0)
  } finally {
    await resetThrottle()
  }
})
