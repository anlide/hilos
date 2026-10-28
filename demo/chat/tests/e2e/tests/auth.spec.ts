import { test, expect, type Page } from '@playwright/test'

import {
  enterIdentifierAndPassword,
  login,
  PASSWORD,
  register,
  submitFirstPassword,
  submitRegistration,
  submitRegistrationCode,
  uniqueEmail,
  armSocketDrop,
  dropSocket,
  expectWelcomedSockets,
  watchHeight,
  watchOpenedSockets,
  watchTop,
  watchTopWithin,
  type OpenedSocket,
  clickSubmit,
  typeInto,
  expectPageRefused,
  gotoPage,
  PAGE_READY,
  createTestUser,
  grantAdminToSelf,
} from '../../../../../framework/frontend/e2e/index.js'
import { setAdmin } from '../../../../../framework/frontend/scripts/adminGrant.mjs'
import { readRegisterCode } from '../../../../../framework/frontend/scripts/mail.mjs'
import { logout } from '../helpers/session.js'

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
//   - button position stability across steps;
//   - password screen stability when magic link is toggled by an administrator;
//   - re-deciding open admin and profile pages on logout in place;
//   - the browser's own time zone named on every socket, a guest's and a
//     signed-in person's alike (HIL-1582).

test('registers and signs back in through the gated profile surface, resuming it in place', async ({
  page,
}) => {
  const email = uniqueEmail()

  await gotoPage(expect, page, '/')
  await expect(page.getByTestId('conn-state')).toHaveText('connected')
  // Anonymous: no session user yet, so the chat composer prompts to sign in.
  await expect(page.getByTestId('message-signin')).toBeVisible()

  // The framework profile page is AUTHENTICATED-guarded: an anonymous subscribe
  // 401s and the gate mounts the sign-in surface in place of the profile view.
  await gotoPage(expect, page, '/profile')
  await expect(page.getByTestId('auth-surface')).toBeVisible()
  await expect(page.getByTestId('profile-name')).toHaveCount(0)

  // Register: autoLoginAfterRegister (default) upgrades the live session, the
  // gate clears the 401, and the preserved profile subscription resumes with no
  // document reload and no route change.
  let fullLoads = 0
  page.on('load', () => {
    fullLoads += 1
  })
  await register(expect, page, email)
  await expect(page.getByTestId('profile-name')).toBeVisible()
  await expect(page.getByTestId('auth-surface')).toHaveCount(0)
  expect(new URL(page.url()).pathname).toBe('/profile')
  expect(fullLoads).toBe(0)

  // Logout reverts to anonymous; re-reaching the gated profile 401s again and the
  // surface mounts anew.
  await logout(expect, page)
  await gotoPage(expect, page, '/profile')
  await expect(page.getByTestId('auth-surface')).toBeVisible()
  await expect(page.getByTestId('profile-name')).toHaveCount(0)

  // Anonymous again: the gated profile re-mounts the surface (login mode by
  // default). Signing in with the credentials just registered resumes the same
  // preserved subscription in place — no navigation. Reaching the gated page
  // again was a full load on purpose (gotoPage), so the counter starts over
  // here and vouches for the sign-in the way it vouched for the registration.
  fullLoads = 0
  await login(expect, page, email, PASSWORD)
  await expect(page.getByTestId('profile-name')).toBeVisible()
  await expect(page.getByTestId('auth-surface')).toHaveCount(0)
  expect(new URL(page.url()).pathname).toBe('/profile')
  expect(fullLoads).toBe(0)
})

/** The socket query parameter the core names the browser's own zone in. */
const BROWSER_TIME_ZONE_PARAM = 'browserTimeZone'

/**
 * A zone neither the stand's runner nor the chat project runs in, so a socket
 * naming it can only have read it from the browser.
 */
const BROWSER_TIME_ZONE = 'Pacific/Auckland'

/**
 * Every value the socket's address gives the zone parameter, decoded: one entry
 * when the client named the zone once, none when it named none.
 *
 * @param socket A socket the page opened.
 */
function namedZones(socket: OpenedSocket): string[] {
  return socket.url.searchParams.getAll(BROWSER_TIME_ZONE_PARAM)
}

test.describe('in a browser that runs in its own time zone', () => {
  test.use({ timezoneId: BROWSER_TIME_ZONE })

  test('names the zone once on every socket, for a guest and for a signed-in person', async ({
    page,
  }) => {
    // Only the transport is proved here: the zone rides the address of each
    // socket and the server welcomes that socket. What the server keeps of it is
    // the integration test's (BrowserTimeZoneSessionIntegrationTest).
    await armSocketDrop(page)
    const sockets = watchOpenedSockets(page)

    // A guest's first socket: the gated profile is refused and the sign-in
    // surface mounts over a socket the server welcomed.
    await gotoPage(expect, page, '/profile')
    await expect(page.getByTestId('auth-surface')).toBeVisible()
    await expectWelcomedSockets(expect, sockets, 1)
    expect(sockets.map(namedZones)).toEqual([[BROWSER_TIME_ZONE]])

    // The same connection, reconnecting after a drop, reads the zone again and
    // names it once, not on top of the copy the previous socket carried.
    await dropSocket(page)
    await expectWelcomedSockets(expect, sockets, 2)
    await expect(page.getByTestId('conn-state')).toHaveText('connected')
    expect(sockets.map(namedZones)).toEqual([
      [BROWSER_TIME_ZONE],
      [BROWSER_TIME_ZONE],
    ])

    // Registering signs the session in place, with no document reload. The
    // sign-in rotates the session, and the socket the client opens to collect
    // the new cookie names the zone as well.
    let fullLoads = 0
    page.on('load', () => {
      fullLoads += 1
    })
    await register(expect, page, uniqueEmail())
    await expect(page.getByTestId('profile-name')).toBeVisible()
    await expect(page.getByTestId('auth-surface')).toHaveCount(0)
    expect(fullLoads).toBe(0)
    await expectWelcomedSockets(expect, sockets, 3)
    expect(sockets.map(namedZones)).toEqual([
      [BROWSER_TIME_ZONE],
      [BROWSER_TIME_ZONE],
      [BROWSER_TIME_ZONE],
    ])

    // Opened anew, the page comes back signed in over a socket that names the
    // zone: the parameter costs the person nothing of their session.
    await gotoPage(expect, page, '/profile', PAGE_READY)
    await expect(page.getByTestId('profile-name')).toBeVisible()
    await expectWelcomedSockets(expect, sockets, 4)
    expect(sockets.map(namedZones)).toEqual([
      [BROWSER_TIME_ZONE],
      [BROWSER_TIME_ZONE],
      [BROWSER_TIME_ZONE],
      [BROWSER_TIME_ZONE],
    ])
  })
})

test('answers a wrong password inline, and an unknown address with the registration path', async ({
  page,
}) => {
  const email = uniqueEmail()

  // Seed a known account, then log out so the sign-in path is exercised.
  await createTestUser(expect, page, { email })
  await gotoPage(expect, page, '/profile')
  await expect(page.getByTestId('profile-name')).toBeVisible()
  await logout(expect, page)

  await gotoPage(expect, page, '/profile')
  await expect(page.getByTestId('auth-surface')).toBeVisible()

  // A known address reveals its password field and the wrong one is refused with
  // the backend's own sentence, still gated. The room the sentence lands in was
  // taken before it existed (HIL-647), so nothing on the card moves: the slot
  // keeps its height, the field the person is about to correct keeps its place,
  // and so does the button they just pressed - the node the old block used to
  // shove down, because it stood between the two.
  await enterIdentifierAndPassword(expect, page, email, 'a different password')
  const slotRoom = await watchHeight(page.getByTestId('auth-error-slot'))
  const passwordTop = await watchTop(page.getByTestId('auth-password'))
  const submitTop = await watchTop(page.getByTestId('auth-submit'))
  await clickSubmit(expect, page.getByTestId('auth-submit'))
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

test('keeps the main button still while the field answers', async ({
  page,
}) => {
  const member = uniqueEmail()
  const free = uniqueEmail()

  // What must not move is the button under the field.
  await createTestUser(expect, page, { email: member })
  await gotoPage(expect, page, '/profile')
  await expect(page.getByTestId('profile-name')).toBeVisible()
  await logout(expect, page)

  await gotoPage(expect, page, '/profile')
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
  await grantAdminToSelf(expect, page)
  await gotoPage(expect, page, '/hilos/security/sign-in-methods')
  const magicLinkToggle = page
    .getByTestId('hilos-table-row-magic_link')
    .getByTestId('hilos-sign-in-method-enabled-magic_link')
  await expect(magicLinkToggle).toBeChecked()

  const guestContext = await browser.newContext()
  try {
    const guestPage = await guestContext.newPage()
    const email = uniqueEmail()
    await gotoPage(expect, guestPage, '/profile')
    await submitRegistration(expect, guestPage, email)
    const code = await readRegisterCode(email)
    await submitRegistrationCode(expect, guestPage, code)

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
 * session in, and the shell around the card changes with it — the button inside
 * the card does not move.
 *
 * @param page The page to register on.
 */
async function registerWithTheMainButtonStill(page: Page): Promise<void> {
  const email = uniqueEmail()

  await gotoPage(expect, page, '/profile')
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
  await clickSubmit(expect, page.getByTestId('auth-submit'))
  await expect(page.getByTestId('auth-consent-accept')).toBeVisible()
  const consentMain = await watchTopWithin(
    page.getByTestId('auth-step-actions').locator('.btn-primary'),
    page.getByTestId('auth-surface'),
  )
  await clickSubmit(
    expect,
    page.locator('[data-id="legal-consent-read"][data-document="terms"]'),
  )
  await expect(page.getByTestId('legal-consent-reading')).toBeVisible()
  await clickSubmit(expect, page.getByTestId('legal-consent-back'))
  await expect(page.getByTestId('auth-consent-accept')).toBeVisible()
  await consentMain.unchanged()

  // The code: the address plaque, the send line and the field — the tallest
  // step of the path, and the one the card's room is measured on.
  await page.getByTestId('auth-consent-accept').check()
  await clickSubmit(expect, page.getByTestId('auth-submit'))
  await expect(page.getByTestId('auth-code')).toBeVisible()
  await main.unchanged()

  // The password, with its two-line lead and the way past it under the button.
  await submitRegistrationCode(expect, page, await readRegisterCode(email))
  await expect(page.getByTestId('auth-new-password')).toBeVisible()
  await main.unchanged()

  // The finished panel: one button, standing where the main one stood.
  await submitFirstPassword(expect, page, PASSWORD)
  await expect(page.getByTestId('auth-continue')).toBeVisible()
  await main.unchanged()
}

test('keeps the main button at one height on ordinary registration steps', async ({
  page,
}) => {
  await registerWithTheMainButtonStill(page)
})

test('re-decides an open admin page when the person signs out, in place', async ({
  page,
}) => {
  const { userId } = await createTestUser(expect, page)
  await setAdmin(userId, true)
  await gotoPage(expect, page, '/hilos/users')
  await expect(page.getByTestId('hilos-viewport-table')).toBeVisible()

  let fullLoads = 0
  page.on('load', () => {
    fullLoads += 1
  })

  await logout(expect, page)

  // The table is gone because the outlet stopped rendering the page, not because
  // the view hid itself: this component draws unconditionally.
  await expectPageRefused(expect, page)
  await expect(page.getByTestId('auth-surface')).toBeVisible()
  await expect(page.getByTestId('hilos-viewport-table')).toHaveCount(0)
  expect(new URL(page.url()).pathname).toBe('/hilos/users')
  expect(fullLoads).toBe(0)
})

test('re-decides an open profile when the person signs out, in place', async ({
  page,
}) => {
  await createTestUser(expect, page)
  await gotoPage(expect, page, '/profile')
  await expect(page.getByTestId('profile-name')).toBeVisible()

  let fullLoads = 0
  page.on('load', () => {
    fullLoads += 1
  })

  await logout(expect, page)

  // /profile is not an administrative surface and gets no client reaction of any
  // kind, so the sign-in invitation on screen is the server's own answer to a
  // subscription it re-decided - the case the by-user criterion could never reach,
  // since the person it would have named is exactly who just left.
  await expectPageRefused(expect, page)
  await expect(page.getByTestId('auth-surface')).toBeVisible()
  await expect(page.getByTestId('profile-name')).toHaveCount(0)
  expect(new URL(page.url()).pathname).toBe('/profile')
  expect(fullLoads).toBe(0)
})
