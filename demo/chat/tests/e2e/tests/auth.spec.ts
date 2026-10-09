import { test, expect, type Page } from '@playwright/test'

import {
  watchHeight,
  watchTop,
  watchTopWithin,
} from '../../../../../framework/frontend/e2e/index.js'
import { setAdmin, signUpAdmin } from '../helpers/adminGrant'
import { readRegisterCode } from '../helpers/mail'
import { expectPageRefused, gotoPage } from '../helpers/page'
import {
  clickSubmit,
  enterIdentifierAndPassword,
  login,
  logout,
  PASSWORD,
  register,
  signUp,
  submitFirstPassword,
  submitRegistration,
  submitRegistrationCode,
  typeInto,
  uniqueEmail,
} from '../helpers/session'

// Auth e2e for the chat demo (HIL-167). Following the test consolidation
// (HIL-1324), server-side auth methods (OAuth, phone code channels, magic links,
// multi-tab convergence and recovery) moved to tasks (auth.spec.ts, oauth.spec.ts,
// phone-codes.spec.ts).
//
// What stays in chat:
//   - the AUTHENTICATED-guarded profile page 401s an anonymous subscribe and the
//     auth gate mounts the project sign-in surface IN PLACE (no redirect), then
//     resumes the preserved subscription off the session upgrade — no navigation;
//   - wrong password handling inline and unknown address leading to registration;
//   - narrow-screen responsive layout and button position stability across steps;
//   - password screen stability when magic link is toggled by an administrator;
//   - re-deciding open admin and profile pages on logout in place.

test('registers and signs back in through the gated profile surface, resuming it in place', async ({
  page,
}) => {
  const email = uniqueEmail()

  await gotoPage(page, '/')
  await expect(page.getByTestId('conn-state')).toHaveText('connected')
  // Anonymous: no session user yet, so the chat composer prompts to sign in.
  await expect(page.getByTestId('message-signin')).toBeVisible()

  // The framework profile page is AUTHENTICATED-guarded: an anonymous subscribe
  // 401s and the gate mounts the sign-in surface in place of the profile view.
  await gotoPage(page, '/profile')
  await expect(page.getByTestId('auth-surface')).toBeVisible()
  await expect(page.getByTestId('profile-name')).toHaveCount(0)

  // Register: autoLoginAfterRegister (default) upgrades the live session, the
  // gate clears the 401, and the preserved profile subscription resumes with no
  // document reload and no route change.
  let fullLoads = 0
  page.on('load', () => {
    fullLoads += 1
  })
  await register(page, email)
  await expect(page.getByTestId('profile-name')).toBeVisible()
  await expect(page.getByTestId('auth-surface')).toHaveCount(0)
  expect(new URL(page.url()).pathname).toBe('/profile')
  expect(fullLoads).toBe(0)

  // Logout reverts to anonymous; re-reaching the gated profile 401s again and the
  // surface mounts anew.
  await logout(page)
  await gotoPage(page, '/profile')
  await expect(page.getByTestId('auth-surface')).toBeVisible()
  await expect(page.getByTestId('profile-name')).toHaveCount(0)

  // Anonymous again: the gated profile re-mounts the surface (login mode by
  // default). Signing in with the credentials just registered resumes the same
  // preserved subscription in place — no navigation. Reaching the gated page
  // again was a full load on purpose (gotoPage), so the counter starts over
  // here and vouches for the sign-in the way it vouched for the registration.
  fullLoads = 0
  await login(page, email, PASSWORD)
  await expect(page.getByTestId('profile-name')).toBeVisible()
  await expect(page.getByTestId('auth-surface')).toHaveCount(0)
  expect(new URL(page.url()).pathname).toBe('/profile')
  expect(fullLoads).toBe(0)
})

test('answers a wrong password inline, and an unknown address with the registration path', async ({
  page,
}) => {
  const email = uniqueEmail()

  // Seed a known account, then log out so the sign-in path is exercised.
  await gotoPage(page, '/profile')
  await expect(page.getByTestId('auth-surface')).toBeVisible()
  await register(page, email)
  await expect(page.getByTestId('profile-name')).toBeVisible()
  await logout(page)

  await gotoPage(page, '/profile')
  await expect(page.getByTestId('auth-surface')).toBeVisible()

  // A known address reveals its password field and the wrong one is refused with
  // the backend's own sentence, still gated. The room the sentence lands in was
  // taken before it existed (HIL-647), so nothing on the card moves: the slot
  // keeps its height, the field the person is about to correct keeps its place,
  // and so does the button they just pressed - the node the old block used to
  // shove down, because it stood between the two.
  await enterIdentifierAndPassword(page, email, 'a different password')
  const slotRoom = await watchHeight(page.getByTestId('auth-error-slot'))
  const passwordTop = await watchTop(page.getByTestId('auth-password'))
  const submitTop = await watchTop(page.getByTestId('auth-submit'))
  await clickSubmit(page.getByTestId('auth-submit'))
  await expect(page.getByTestId('auth-error')).toHaveText('Incorrect password')
  await slotRoom.unchanged()
  await passwordTop.unchanged()
  await submitTop.unchanged()
  await expect(page.getByTestId('profile-name')).toHaveCount(0)

  // An address with no account is never given a sign-in to fail: the lookup in
  // FRONT of the form answers first, so the same screen becomes the registration
  // (HIL-414) — which is why there is no "no account found" sentence any more. It
  // shows no password field either (HIL-825): a registration is asked for one
  // after the code, on the screen that creates the account.
  await typeInto(page.getByTestId('auth-identifier'), uniqueEmail())
  await expect(page.getByTestId('auth-heading')).toHaveText(
    'Create your account',
  )
  await expect(page.getByTestId('auth-password')).toHaveCount(0)
  await expect(page.getByTestId('auth-error')).toHaveCount(0)
  await expect(page.getByTestId('profile-name')).toHaveCount(0)
})

test('holds the refusal to one line on a narrow screen', async ({ page }) => {
  const email = uniqueEmail()

  // 375 is the narrowest screen the frontend is built for, and the card (24rem)
  // is wider than it: this is where a refusal used to take a second line, and
  // where the truncation has to keep it to one.
  await page.setViewportSize({ width: 375, height: 800 })

  await gotoPage(page, '/profile')
  await expect(page.getByTestId('auth-surface')).toBeVisible()
  await register(page, email)
  await expect(page.getByTestId('profile-name')).toBeVisible()
  await logout(page)

  await gotoPage(page, '/profile')
  await expect(page.getByTestId('auth-surface')).toBeVisible()

  await enterIdentifierAndPassword(page, email, 'a different password')
  const slotRoom = await watchHeight(page.getByTestId('auth-error-slot'))
  const submitTop = await watchTop(page.getByTestId('auth-submit'))
  await clickSubmit(page.getByTestId('auth-submit'))
  await expect(page.getByTestId('auth-error')).toHaveText('Incorrect password')

  await slotRoom.unchanged()
  await submitTop.unchanged()
})

test('keeps the main button still while the field answers, on a narrow screen', async ({
  page,
}) => {
  const member = uniqueEmail()
  const free = uniqueEmail()

  // 375 is the narrowest screen the frontend is built for, and the one the owner
  // saw the form jump on. What must not move is the button under the field.
  await page.setViewportSize({ width: 375, height: 800 })

  await gotoPage(page, '/profile')
  await expect(page.getByTestId('auth-surface')).toBeVisible()
  await register(page, member)
  await expect(page.getByTestId('profile-name')).toBeVisible()
  await logout(page)

  await gotoPage(page, '/profile')
  await expect(page.getByTestId('auth-surface')).toBeVisible()

  // A free address: the grey line under the field and nothing else.
  await typeInto(page.getByTestId('auth-identifier'), free)
  await expect(page.getByTestId('auth-identifier-hint')).toHaveText(
    'No account yet — this creates one.',
  )
  const submitTop = await watchTop(page.getByTestId('auth-submit'))

  // An account that signs in with a password: the reveal, which is the tallest
  // thing this slot ever holds and therefore what its room was measured on.
  await typeInto(page.getByTestId('auth-identifier'), member)
  await expect(page.getByTestId('auth-password')).toBeVisible()
  await submitTop.unchanged()

  // And back. The room was taken with the first character and never given up,
  // so no reply the lookup brings moves what sits under it (HIL-1008).
  await typeInto(page.getByTestId('auth-identifier'), free)
  await expect(page.getByTestId('auth-identifier-hint')).toHaveText(
    'No account yet — this creates one.',
  )
  await submitTop.unchanged()
})

test('keeps the password screen still while the way past the password comes and goes', async ({
  page,
  browser,
}) => {
  await signUpAdmin(page)
  await gotoPage(page, '/hilos/security/sign-in-methods')
  const magicLinkToggle = page
    .getByTestId('hilos-table-row-magic_link')
    .getByTestId('hilos-sign-in-method-enabled-magic_link')
  await expect(magicLinkToggle).toBeChecked()

  const guestContext = await browser.newContext({
    viewport: { width: 375, height: 800 },
  })
  try {
    const guestPage = await guestContext.newPage()
    const email = uniqueEmail()
    await gotoPage(guestPage, '/profile')
    await submitRegistration(guestPage, email)
    const code = await readRegisterCode(email)
    await submitRegistrationCode(guestPage, code)

    // The stand offers passkeys too and Chromium can make one, so the screen has
    // the key's ending beside the link's, and the lead names both (HIL-1104).
    await expect(
      guestPage.getByTestId('auth-complete-passwordless'),
    ).toBeVisible()
    await expect(guestPage.getByTestId('auth-set-password-lead')).toHaveText(
      'Your address is confirmed. Choose a password, or create the account without one and sign in with a passkey or a mailed link.',
    )

    const submitTop = await watchTop(guestPage.getByTestId('auth-submit'))
    const cancelTop = await watchTop(
      guestPage.getByTestId('auth-cancel-registration'),
    )

    // Admin clicks the switch to disable magic link (not uncheck(): the switch
    // is not optimistic and updates on live server state).
    await magicLinkToggle.click()
    await expect(magicLinkToggle).not.toBeChecked()

    await expect(
      guestPage.getByTestId('auth-complete-passwordless'),
    ).toHaveCount(0)
    await expect(guestPage.getByTestId('auth-set-password-lead')).toHaveText(
      'Your address is confirmed. Choose a password, or create the account with a passkey instead.',
    )
    await submitTop.unchanged()
    await cancelTop.unchanged()

    // Restore magic link
    await magicLinkToggle.click()
    await expect(magicLinkToggle).toBeChecked()

    await expect(
      guestPage.getByTestId('auth-complete-passwordless'),
    ).toBeVisible()
    await expect(guestPage.getByTestId('auth-set-password-lead')).toHaveText(
      'Your address is confirmed. Choose a password, or create the account without one and sign in with a passkey or a mailed link.',
    )
    await submitTop.unchanged()
    await cancelTop.unchanged()
  } finally {
    if (!(await magicLinkToggle.isChecked())) {
      await magicLinkToggle.click()
      await expect(magicLinkToggle).toBeChecked()
    }
    await guestContext.close()
  }
})

/**
 * Walk a registration from the address to the finished panel, and prove that the
 * main button of every ordinary step stands where the first one stood (HIL-1107).
 * Consent carries documents since HIL-499: it may grow the frame, as declared in
 * styling-rules.md, "The room a step takes"; the next ordinary step restores it.
 *
 * The button is read through the block of actions of the live step and never
 * by its own data-id: the id changes with the step (auth-submit, then
 * auth-continue), and the twins of the steps that hold the card's room carry no
 * data-id at all, so the locator resolves to the one live button every time.
 * And it is read against the card, not the screen: the last step signs the
 * session in, and on a narrow screen the shell's navigation grows a row for the
 * name and the way out — the card moves down with it, the button inside it does
 * not.
 *
 * @param page The page to register on, with its viewport already set.
 */
async function registerWithTheMainButtonStill(page: Page): Promise<void> {
  const email = uniqueEmail()

  await gotoPage(page, '/profile')
  await expect(page.getByTestId('auth-surface')).toBeVisible()
  await typeInto(page.getByTestId('auth-identifier'), email)
  await expect(page.getByTestId('auth-heading')).toHaveText(
    'Create your account',
  )
  const main = await watchTopWithin(
    page.getByTestId('auth-step-actions').locator('.btn-primary'),
    page.getByTestId('auth-surface'),
  )

  // The terms may grow the frame. Reading the full text and returning to the
  // same differences must restore the consent button's own position.
  await clickSubmit(page.getByTestId('auth-submit'))
  await expect(page.getByTestId('auth-consent-accept')).toBeVisible()
  const consentMain = await watchTopWithin(
    page.getByTestId('auth-step-actions').locator('.btn-primary'),
    page.getByTestId('auth-surface'),
  )
  await clickSubmit(
    page.locator('[data-id="legal-consent-read"][data-document="terms"]'),
  )
  await expect(page.getByTestId('legal-consent-reading')).toBeVisible()
  await clickSubmit(page.getByTestId('legal-consent-back'))
  await expect(page.getByTestId('auth-consent-accept')).toBeVisible()
  await consentMain.unchanged()

  // The code: the address plaque, the send line and the field — the tallest
  // step of the path, and the one the card's room is measured on.
  await page.getByTestId('auth-consent-accept').check()
  await clickSubmit(page.getByTestId('auth-submit'))
  await expect(page.getByTestId('auth-code')).toBeVisible()
  await main.unchanged()

  // The password, with its two-line lead and the way past it under the button.
  await submitRegistrationCode(page, await readRegisterCode(email))
  await expect(page.getByTestId('auth-new-password')).toBeVisible()
  await main.unchanged()

  // The finished panel: one button, standing where the main one stood.
  await submitFirstPassword(page, PASSWORD)
  await expect(page.getByTestId('auth-continue')).toBeVisible()
  await main.unchanged()
}

test('keeps the main button at one height on ordinary registration steps', async ({
  page,
}) => {
  await registerWithTheMainButtonStill(page)
})

test('keeps the main button at one height on ordinary registration steps, on a narrow screen', async ({
  page,
}) => {
  // 375 is the narrowest screen the frontend is built for: the leads wrap
  // differently here, and the twins of the steps have to wrap with them.
  await page.setViewportSize({ width: 375, height: 800 })

  await registerWithTheMainButtonStill(page)
})

test('re-decides an open admin page when the person signs out, in place', async ({
  page,
}) => {
  const { userId } = await signUp(page)
  await setAdmin(userId, true)
  await gotoPage(page, '/hilos/users')
  await expect(page.getByTestId('hilos-viewport-table')).toBeVisible()

  let fullLoads = 0
  page.on('load', () => {
    fullLoads += 1
  })

  await logout(page)

  // The table is gone because the outlet stopped rendering the page, not because
  // the view hid itself: this component draws unconditionally.
  await expectPageRefused(page)
  await expect(page.getByTestId('auth-surface')).toBeVisible()
  await expect(page.getByTestId('hilos-viewport-table')).toHaveCount(0)
  expect(new URL(page.url()).pathname).toBe('/hilos/users')
  expect(fullLoads).toBe(0)

  // Revoke so the shared-DB user does not stay admin for later specs.
  await setAdmin(userId, false)
})

test('re-decides an open profile when the person signs out, in place', async ({
  page,
}) => {
  await signUp(page)
  await gotoPage(page, '/profile')
  await expect(page.getByTestId('profile-name')).toBeVisible()

  let fullLoads = 0
  page.on('load', () => {
    fullLoads += 1
  })

  await logout(page)

  // /profile is not an administrative surface and gets no client reaction of any
  // kind, so the sign-in invitation on screen is the server's own answer to a
  // subscription it re-decided - the case the by-user criterion could never reach,
  // since the person it would have named is exactly who just left.
  await expectPageRefused(page)
  await expect(page.getByTestId('auth-surface')).toBeVisible()
  await expect(page.getByTestId('profile-name')).toHaveCount(0)
  expect(new URL(page.url()).pathname).toBe('/profile')
  expect(fullLoads).toBe(0)
})
