import { test, expect } from '@playwright/test'
import {
  PASSWORD,
  createTestUser,
  continueFromDone,
  enterIdentifierAndPassword,
  login,
  logout,
  nameFromEmail,
  openSignIn,
  register,
  submitFirstPassword,
  submitRegistrationCode,
  uniqueEmail,
  waitAuthSettled,
  clickSubmit,
  typeInto,
  PAGE_REFUSED,
  gotoPage,
  trackedActionReplies,
  watchDeliveryFrames,
} from '../../../../../framework/frontend/e2e/index.js'

import {
  readPasswordResetCode,
  readRegisterCode,
} from '../../../../../framework/frontend/scripts/mail.mjs'
import {
  resetAuthThrottle,
  THROTTLE_REASON,
  THROTTLE_SESSION_LIMIT,
  THROTTLE_WINDOW_MS,
} from '../../../../../framework/frontend/scripts/authThrottle.mjs'

// spec-owner: demo — proves the BTC Tracker shell activates the shared sign-in surface.

// Sign-in e2e for the binance-btc-tracker demo. Nothing about the machine is
// this demo's, so what is proved here is the ACTIVATION — that the framework
// surface reaches this project's seams and comes back with an account in the
// framework's people table, named on this demo's home.
//
// Four cases carry that: registering by the mailed code — which also pins the
// frame the surface arrives in over the page, as a modal, because that frame is
// this demo's App and not the framework's — signing back in with the password
// after a wrong one, recovering a forgotten one, and the surface standing in
// place of a page a guest is refused.
//
// The password is the only way in this demo offers; the account side of the
// other methods lives in chat, tasks and polls.

test('holds the address in a modal over the page, and signs the session in on the mailed code', async ({
  page,
}) => {
  const email = uniqueEmail()

  await gotoPage(expect, page, '/')
  await expect(page.getByTestId('conn-state')).toHaveText('connected')
  await expect(page.getByTestId('self-anonymous')).toBeVisible()

  await openSignIn(expect, page)

  // A modal, not a replacement: the page underneath is still mounted and still
  // showing the guest's own identity line. The dialog's name is asserted on the
  // first screen only — it follows the surface heading, which moves with the
  // step (HIL-832).
  await expect(page.getByRole('dialog', { name: 'Sign in' })).toBeVisible()
  await expect(page.getByTestId('self-anonymous')).toBeVisible()

  // Dismissing it leaves the guest exactly where they were.
  await page.keyboard.press('Escape')
  await expect(page.getByTestId('auth-surface')).toHaveCount(0)
  await expect(page.getByTestId('self-anonymous')).toBeVisible()

  await openSignIn(expect, page)
  await typeInto(page.getByTestId('auth-identifier'), email)
  await expect(page.getByTestId('auth-heading')).toHaveText(
    'Create your account',
  )
  await clickSubmit(expect, page.getByTestId('auth-submit'))
  await expect(page.getByTestId('legal-consent-deviation')).toHaveCount(1)
  await expect(page.getByTestId('legal-consent-direction')).toHaveText(
    'stricter',
  )
  await page.getByTestId('auth-consent-accept').check()
  await clickSubmit(expect, page.getByTestId('auth-submit'))

  // The surface is on the code step and the session is still a guest: the
  // submit reserved the address, it did not create an account (HIL-415).
  await expect(page.getByTestId('auth-code')).toBeVisible()
  await expect(page.getByTestId('self-anonymous')).toBeVisible()

  await submitRegistrationCode(expect, page, await readRegisterCode(email))
  await submitFirstPassword(expect, page)
  await continueFromDone(expect, page)

  // The account exists and the session carries it: the identity line switches
  // from the anonymous branch to the account one.
  await expect(page.getByTestId('self-user')).toHaveText(nameFromEmail(email))
  await expect(page.getByTestId('self-user-id')).not.toBeEmpty()
  // And the shell grows the signed-in region the mockup describes.
  await expect(page.getByTestId('nav-profile-name')).toContainText(
    nameFromEmail(email),
  )
  await expect(
    page.getByTestId('nav-profile-name').getByTestId('hilos-avatar'),
  ).toHaveText(nameFromEmail(email).charAt(0).toUpperCase())
  await expect(page.getByTestId('nav-signin')).toHaveCount(0)
})

test('answers a wrong password inline, then signs the account back in', async ({
  page,
}) => {
  const email = uniqueEmail()

  await createTestUser(expect, page, { email })
  await expect(page.getByTestId('self-user')).toHaveText(nameFromEmail(email))

  await logout(expect, page)
  // Signing out puts the visitor back on the anonymous branch, which is the
  // state the header's Sign in button belongs to.
  await expect(page.getByTestId('nav-signin')).toBeVisible()
  await expect(page.getByTestId('self-anonymous')).toBeVisible()

  await openSignIn(expect, page)
  await enterIdentifierAndPassword(expect, page, email, 'not the password')
  await clickSubmit(expect, page.getByTestId('auth-submit'))
  await waitAuthSettled(expect, page)

  await expect(page.getByTestId('auth-error')).toBeVisible()
  await expect(page.getByTestId('auth-surface')).toBeVisible()
  await expect(page.getByTestId('self-anonymous')).toBeVisible()

  // The surface is still standing, so the right password goes into the same
  // form the wrong one was refused on.
  await login(expect, page, email)
  await expect(page.getByTestId('self-user')).toHaveText(nameFromEmail(email))
})

test('recovers a forgotten password and signs in with the new one', async ({
  page,
}) => {
  const email = uniqueEmail()
  const newPassword = 'a whole other passphrase'

  await createTestUser(expect, page, { email })
  await logout(expect, page)

  // Recovery starts from the same one field: the lookup finds the account,
  // reveals the password, and the key beside it asks for a code instead.
  await openSignIn(expect, page)
  await enterIdentifierAndPassword(expect, page, email, PASSWORD)
  await page.getByTestId('auth-recovery').click()
  await expect(page.getByTestId('auth-code')).toBeVisible()

  await typeInto(
    page.getByTestId('auth-code'),
    await readPasswordResetCode(email),
  )
  await clickSubmit(expect, page.getByTestId('auth-submit'))

  // An accepted recovery code does not sign anybody in: it opens the step that
  // chooses the new password, and only that ends the ceremony.
  const newPasswordField = page.getByTestId('auth-new-password')
  await expect(newPasswordField).toBeVisible()
  await typeInto(newPasswordField, newPassword)
  await clickSubmit(expect, page.getByTestId('auth-submit'))
  await continueFromDone(expect, page)
  await expect(page.getByTestId('self-user')).toHaveText(nameFromEmail(email))

  await logout(expect, page)
  await openSignIn(expect, page)
  await login(expect, page, email, newPassword)
  await expect(page.getByTestId('self-user')).toHaveText(nameFromEmail(email))
})

test('shows the surface in place of an admin page a guest is refused', async ({
  page,
}) => {
  await gotoPage(expect, page, '/hilos', PAGE_REFUSED)

  // In place of the page, not over it: the refusal IS the reason the surface is
  // on screen, so there is no dialog and no second copy of the machine.
  await expect(page.getByTestId('auth-surface')).toBeVisible()
  await expect(page.getByRole('dialog', { name: 'Sign in' })).toHaveCount(0)
  await expect(page.getByTestId('hilos-admin-title')).toHaveCount(0)

  // Signing in re-decides the page, which is what the surface standing in place
  // of it is for. This account is not an administrator - nothing in the product
  // hands out that flag - so the dashboard answers forbidden rather than
  // opening; what matters here is that the 401 is gone and the surface with it.
  const email = uniqueEmail()
  await register(expect, page, email)
  await expect(page.getByTestId('auth-surface')).toHaveCount(0)
  await expect(page.getByTestId('page-error')).toHaveAttribute(
    'data-error-code',
    '403',
  )
  // The account is signed in, so the refusal must not call it a guest. The
  // sentence itself is pinned by the SDK unit; e2e pins only the word, so that
  // rewording the copy stays one file of work (HIL-776).
  await expect(page.getByTestId('page-error')).not.toContainText(/guest/i)
})

test('the users verdict host rate-limits wrong passwords and holds the right password too', async ({
  page,
}) => {
  test.slow() // Registration, SMTP confirmation and twelve guarded sign-in replies.
  const log = watchDeliveryFrames(page)
  try {
    await resetAuthThrottle()
    const email = uniqueEmail()
    await gotoPage(expect, page, '/')
    await openSignIn(expect, page)
    await register(expect, page, email)
    await expect(page.getByTestId('self-user')).toHaveText(nameFromEmail(email))
    await logout(expect, page)
    await openSignIn(expect, page)
    await enterIdentifierAndPassword(expect, page, email, 'wrong password')
    const started = performance.now()
    for (let attempt = 0; attempt < THROTTLE_SESSION_LIMIT; attempt++) {
      const since = log.sent.length
      if (attempt > 0)
        await typeInto(page.getByTestId('auth-password'), 'wrong password')
      await clickSubmit(expect, page.getByTestId('auth-submit'))
      await waitAuthSettled(expect, page)
      await expect(page.getByTestId('auth-error')).toBeVisible()
      await expect
        .poll(() => trackedActionReplies(log, 'hilos_login', since).length)
        .toBe(1)
      const reply = trackedActionReplies(log, 'hilos_login', since)[0]
      expect(reply.frame.type).toBe('action_error')
      expect(reply.data.errorCode).not.toBe('rate_limited')
    }
    for (const password of ['wrong password', PASSWORD]) {
      const since = log.sent.length
      await typeInto(page.getByTestId('auth-password'), password)
      await clickSubmit(expect, page.getByTestId('auth-submit'))
      await waitAuthSettled(expect, page)
      await expect(page.getByTestId('auth-error')).toContainText(
        THROTTLE_REASON,
      )
      await expect
        .poll(() => trackedActionReplies(log, 'hilos_login', since).length)
        .toBe(1)
      const reply = trackedActionReplies(log, 'hilos_login', since)[0]
      expect(reply.frame.type).toBe('action_error')
      expect(reply.data).toMatchObject({
        errorCode: 'rate_limited',
        reason: THROTTLE_REASON,
        retryAfter: expect.any(Number),
      })
      expect(reply.data.retryAfter).toBeGreaterThan(0)
      await expect(page.getByTestId('self-anonymous')).toBeVisible()
    }
    expect(performance.now() - started).toBeLessThan(THROTTLE_WINDOW_MS)
  } finally {
    await test.info().attach('users-live-throttle', {
      body: JSON.stringify(log),
      contentType: 'application/json',
    })
    log.stop()
    await resetAuthThrottle()
  }
})
