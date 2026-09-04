import { test, expect } from '@playwright/test'

import {
  mailsTo,
  readMagicLinkCode,
  readMagicLinkUrl,
  readPasswordResetCode,
  readRegisterCode,
} from '../helpers/mail'
import { PAGE_REFUSED, gotoAuthReturn, gotoPage } from '../helpers/page'
import {
  PASSWORD,
  clickSubmit,
  continueFromDone,
  enterIdentifierAndPassword,
  login,
  logout,
  nameFromEmail,
  openSignIn,
  register,
  submitCode,
  submitRegistration,
  typeInto,
  uniqueEmail,
  waitAuthSettled,
} from '../helpers/session'
import { uniquePhone, waitForSmsCode } from '../helpers/sms'
import { waitForTelegramCode } from '../helpers/telegram'

// Sign-in e2e for the simple-poll demo (HIL-634). This demo had no auth handler
// at all until the AUTH feature was declared; nothing about the machine is this
// leaf's, so what is proved here is the ACTIVATION — that the framework surface
// reaches this project's seams and comes back with an account in this project's
// own users table.
//
// It goes further than its twin (HIL-623) on purpose, and the reason is the view
// framework rather than the demo: the Angular sign-in surface has no component
// test of its own — `ng test` is blocked upstream — so HIL-425 could prove it
// only by tsc, an AOT build and pinned exports, and wrote that its behavior would
// be proved here. This file is that proof, so every method the stand can deliver
// is walked and not just the five the activation needs: phone codes over SMS and
// over Telegram, the sign-in link and the code that comes with it, and both OAuth
// providers through the offline stub.
//
// What the SDK proves, though, is a SCREEN and not a delivery method (HIL-774):
// SMS and Telegram draw the same code screen, GitHub and Google the same return,
// and the difference between them lives in the backend under the Integration
// suite. So the methods that share a screen walk it inside ONE case, as steps
// named after the leg — the report still says which leg broke — and the setup
// between them is paid once.
//
// The device key is the one method left out: no demo runs a virtual authenticator
// today, and that belongs to the parity leaf HIL-426 along with the shared spec
// the three demos will eventually run (HIL-774 collapses the set first).

test('holds the address in a modal over the page, and signs the session in on the mailed code', async ({
  page,
}) => {
  const email = uniqueEmail()

  await gotoPage(page, '/')
  await expect(page.getByTestId('conn-state')).toHaveText('connected')
  await expect(page.getByTestId('self-user')).not.toBeEmpty()

  await openSignIn(page)

  // A modal, not a replacement: the page underneath is still mounted and still
  // showing the guest's own identity line. The dialog's name is asserted on the
  // first screen only — it follows the surface heading, which moves with the
  // step (HIL-832).
  await expect(page.getByRole('dialog', { name: 'Sign in' })).toBeVisible()
  await expect(page.getByTestId('self-user')).toBeVisible()

  // Dismissing it leaves the guest exactly where they were.
  await page.keyboard.press('Escape')
  await expect(page.getByTestId('auth-surface')).toHaveCount(0)
  await expect(page.getByTestId('self-user')).toBeVisible()

  await openSignIn(page)
  await submitRegistration(page, email)

  // The surface is on the code step and the session is still a guest: the
  // submit reserved the address, it did not create an account (HIL-415).
  await expect(page.getByTestId('auth-code')).toBeVisible()
  await expect(page.getByTestId('self-user-id')).toBeEmpty()

  await submitCode(page, await readRegisterCode(email))
  await continueFromDone(page)

  // The account exists in this demo's own users table and the session carries
  // it: the identity line switches from the guest branch to the account one.
  await expect(page.getByTestId('self-user')).toHaveText(nameFromEmail(email))
  await expect(page.getByTestId('self-user-id')).not.toBeEmpty()
  // And the shell grows the signed-in region the mockup describes.
  await expect(page.getByTestId('nav-profile-name')).toContainText(
    nameFromEmail(email),
  )
  await expect(page.getByTestId('nav-signin')).toHaveCount(0)
})

test('answers a wrong password inline, then signs the account back in', async ({
  page,
}) => {
  const email = uniqueEmail()

  await gotoPage(page, '/')
  await openSignIn(page)
  await register(page, email)
  await expect(page.getByTestId('self-user')).toHaveText(nameFromEmail(email))

  await logout(page)
  // Signing out puts the visitor back on the guest branch, which is the state
  // the header's Sign in button belongs to.
  await expect(page.getByTestId('nav-signin')).toBeVisible()
  await expect(page.getByTestId('self-user-id')).toBeEmpty()

  await openSignIn(page)
  await enterIdentifierAndPassword(page, email, 'not the password')
  await clickSubmit(page.getByTestId('auth-submit'))
  await waitAuthSettled(page)

  await expect(page.getByTestId('auth-error')).toBeVisible()
  await expect(page.getByTestId('auth-surface')).toBeVisible()
  await expect(page.getByTestId('self-user-id')).toBeEmpty()

  // The surface is still standing, so the right password goes into the same
  // form the wrong one was refused on.
  await login(page, email)
  await expect(page.getByTestId('self-user')).toHaveText(nameFromEmail(email))
})

test('recovers a forgotten password and signs in with the new one', async ({
  page,
}) => {
  const email = uniqueEmail()
  const newPassword = 'a whole other passphrase'

  await gotoPage(page, '/')
  await openSignIn(page)
  await register(page, email)
  await logout(page)

  // Recovery starts from the same one field: the lookup finds the account,
  // reveals the password, and the key beside it asks for a code instead.
  await openSignIn(page)
  await enterIdentifierAndPassword(page, email, PASSWORD)
  await page.getByTestId('auth-recovery').click()
  await expect(page.getByTestId('auth-code')).toBeVisible()

  await typeInto(
    page.getByTestId('auth-code'),
    await readPasswordResetCode(email),
  )
  await clickSubmit(page.getByTestId('auth-submit'))

  // An accepted recovery code does not sign anybody in: it opens the step that
  // chooses the new password, and only that ends the ceremony.
  const newPasswordField = page.getByTestId('auth-new-password')
  await expect(newPasswordField).toBeVisible()
  await typeInto(newPasswordField, newPassword)
  await clickSubmit(page.getByTestId('auth-submit'))
  await continueFromDone(page)
  await expect(page.getByTestId('self-user')).toHaveText(nameFromEmail(email))

  await logout(page)
  await openSignIn(page)
  await login(page, email, newPassword)
  await expect(page.getByTestId('self-user')).toHaveText(nameFromEmail(email))
})

test('shows the surface in place of an admin page a guest is refused', async ({
  page,
}) => {
  await gotoPage(page, '/hilos/settings', PAGE_REFUSED)

  // In place of the page, not over it: the refusal IS the reason the surface is
  // on screen, so there is no dialog and no second copy of the machine.
  await expect(page.getByTestId('auth-surface')).toBeVisible()
  await expect(page.getByRole('dialog', { name: 'Sign in' })).toHaveCount(0)
  await expect(page.getByTestId('hilos-admin-title')).toHaveCount(0)

  // Signing in re-decides the page, which is what the surface standing in place
  // of it is for. This account is not an administrator - nothing in the product
  // hands out that flag - so the settings page answers forbidden rather than
  // opening; what matters here is that the 401 is gone and the surface with it.
  const email = uniqueEmail()
  await register(page, email)
  await expect(page.getByTestId('auth-surface')).toHaveCount(0)
  await expect(page.getByTestId('page-error')).toHaveAttribute(
    'data-error-code',
    '403',
  )
})

// The sign-in link (HIL-417, HIL-606). One letter carries two secrets, and the
// two halves are two different people: the one whose mail is open on another
// device types the code, the one whose mail is on this device clicks. Both are
// walked, because on Angular neither had ever been opened by anything.

/**
 * The path and query of a sign-in link, as the address bar should hold it.
 *
 * The letter carries an absolute URL built from `HILOS_MAGIC_LINK_URL`, which is
 * the address a real deployment publishes and NOT the stand's own base — opening
 * it verbatim would leave the stand entirely. Only the path and query belong to
 * the click; the host is the deployment's business.
 *
 * @param url The sign-in URL exactly as the letter spells it.
 * @returns Its path and query, for `gotoAuthReturn`.
 */
function returnPath(url: string): string {
  const parsed = new URL(url)

  return `${parsed.pathname}${parsed.search}`
}

test('walks both halves of the sign-in link, and turns a tampered one down', async ({
  page,
}) => {
  // Every step asks for its own letter: the send gate holds a second letter to
  // the same address for a minute, so a shared address would leave a step
  // waiting for mail nobody sent. And every step starts from a guest, which is
  // why the signed-in legs end with a sign-out.
  await gotoPage(page, '/')

  await test.step('code from the letter', async () => {
    const email = uniqueEmail()

    await openSignIn(page)

    // A free address: the lookup turns the one field into a registration, and
    // the envelope beside the password is the passwordless way through it.
    await typeInto(page.getByTestId('auth-identifier'), email)
    await expect(page.getByTestId('auth-password')).toBeVisible()
    await page.getByTestId('auth-icon-magic-link').click()

    // Nothing is sent before the terms: accepting them is what mails the letter.
    await page.getByTestId('auth-consent-accept').check()
    await clickSubmit(page.getByTestId('auth-submit'))

    // The screen the person asked for does not change under them — it grows a
    // field (HIL-606). The link is still there to click; this step is the person
    // who cannot, because their mail is open on another device.
    await expect(page.getByTestId('auth-heading')).toHaveText('Check your inbox')
    await expect(page.getByTestId('auth-link-sent')).toBeVisible()
    await expect(page.getByTestId('auth-code')).toBeVisible()

    await typeInto(page.getByTestId('auth-code'), await readMagicLinkCode(email))
    await clickSubmit(page.getByTestId('auth-submit'))
    await continueFromDone(page)
    await expect(page.getByTestId('self-user')).toHaveText(nameFromEmail(email))

    // One letter, whichever half was used — the code did not buy a second one.
    expect(await mailsTo(email)).toHaveLength(1)

    await logout(page)
  })

  await test.step('click from a cold load', async () => {
    const email = uniqueEmail()

    await openSignIn(page)

    await typeInto(page.getByTestId('auth-identifier'), email)
    await expect(page.getByTestId('auth-password')).toBeVisible()
    await page.getByTestId('auth-icon-magic-link').click()
    await page.getByTestId('auth-consent-accept').check()
    await clickSubmit(page.getByTestId('auth-submit'))
    await expect(page.getByTestId('auth-link-sent')).toBeVisible()

    // The click itself: a full browser load of the return route, which is the
    // one navigation this demo's App serves without HilosView at all — the relay
    // replaces the outlet while the path matches.
    await gotoAuthReturn(
      page,
      returnPath(await readMagicLinkUrl(email)),
      'auth-magic',
    )

    // Home, signed in: the relay navigates on success and the session it
    // upgraded is the one this tab is holding.
    await expect(page.getByTestId('nav-logout')).toBeVisible()
    await expect(page.getByTestId('self-user')).toHaveText(nameFromEmail(email))

    // One letter, whichever half was used — the click did not buy a second one.
    expect(await mailsTo(email)).toHaveLength(1)

    // The click signed the session in, so the surface says what was achieved
    // and waits for Continue (HIL-422) — over the shell, in the way of the
    // sign-out the next leg starts from.
    await continueFromDone(page)
    await logout(page)
  })

  await test.step('tampered token', async () => {
    const email = uniqueEmail()

    await openSignIn(page)
    await typeInto(page.getByTestId('auth-identifier'), email)
    await expect(page.getByTestId('auth-password')).toBeVisible()
    await page.getByTestId('auth-icon-magic-link').click()
    await page.getByTestId('auth-consent-accept').check()
    await clickSubmit(page.getByTestId('auth-submit'))
    await expect(page.getByTestId('auth-link-sent')).toBeVisible()

    // A token nobody minted. The point is WHICH screen answers: a refusal that
    // reached the server looks like this, and a click that reached nobody would
    // look exactly the same.
    const tampered = new URL(await readMagicLinkUrl(email))
    tampered.searchParams.set('token', 'not-the-token-that-was-mailed')
    await gotoAuthReturn(page, returnPath(tampered.toString()), 'auth-magic')

    await expect(page.getByTestId('auth-magic-error')).toBeVisible()
    await expect(page.getByTestId('auth-magic-to-login')).toBeVisible()
    // Nothing was signed in, and the letter is still the only one.
    await expect(page.getByTestId('nav-logout')).toHaveCount(0)
    expect(await mailsTo(email)).toHaveLength(1)
  })
})

// Code channels (HIL-492). Delivery of a login code is a registry, and this demo
// registers two, so what is walked is what a registry is FOR: the same number
// reaches its account through whichever channel carries it. Both legs go through
// the stand gateway rather than around it — the daemon builds a real request and
// really posts it, so a transport quietly removed fails here.

test('signs in by a phone code over every channel the demo registers', async ({
  page,
}) => {
  await gotoPage(page, '/')
  await expect(page.getByTestId('conn-state')).toHaveText('connected')

  await test.step('SMS', async () => {
    const phone = uniquePhone()

    await openSignIn(page)
    await typeInto(page.getByTestId('auth-identifier'), phone)

    // A number reveals its channels instead of a password: choosing one IS the
    // send, and there is no separate send button behind the icon. For a number
    // with no account the choice is only STORED — an account is never made by a
    // click that never showed the terms — so the terms screen is what sends.
    await clickSubmit(page.getByTestId('auth-channel-sms'))
    await page.getByTestId('auth-consent-accept').check()
    await clickSubmit(page.getByTestId('auth-submit'))

    // The code screen opens on the agent's outcome signal, not on the click, and
    // it names the channel the code actually went over.
    await expect(page.getByTestId('auth-code')).toBeVisible()
    await expect(page.getByTestId('auth-delivered-channel')).toContainText('SMS')

    await typeInto(page.getByTestId('auth-code'), await waitForSmsCode(phone))
    await clickSubmit(page.getByTestId('auth-submit'))
    await continueFromDone(page)

    await expect(page.getByTestId('self-user')).toHaveText(phone)

    // The next leg starts from a guest.
    await logout(page)
  })

  await test.step('Telegram', async () => {
    // No global reset: every state the gateway holds is keyed by number, and the
    // number is unique per step, so the legs are isolated without reaching into
    // a store the other workers share.
    const phone = uniquePhone()

    await openSignIn(page)
    await typeInto(page.getByTestId('auth-identifier'), phone)

    await clickSubmit(page.getByTestId('auth-channel-telegram'))
    await page.getByTestId('auth-consent-accept').check()
    await clickSubmit(page.getByTestId('auth-submit'))

    await expect(page.getByTestId('auth-code')).toBeVisible()
    await expect(page.getByTestId('auth-delivered-channel')).toContainText(
      'Telegram',
    )

    await typeInto(
      page.getByTestId('auth-code'),
      await waitForTelegramCode(phone),
    )
    await clickSubmit(page.getByTestId('auth-submit'))
    await continueFromDone(page)

    await expect(page.getByTestId('self-user')).toHaveText(phone)
  })
})

// OAuth (HIL-281, HIL-633). The stub provider bounces its authorize URL straight
// back to the callback, so the whole trip runs offline: the window the click
// opened couriers the return to the page that started it and closes, and the
// leader-pinned OAuth agent resolves the (provider, subject) to a fresh account.
// Both providers are walked because each carries its own stub code — one shared
// between them would hand both the same `<code>@stub.local` address, and the
// second sign-in would land in cross-provider account linking instead.

test('signs in through every OAuth provider the demo offers', async ({
  page,
}) => {
  await gotoPage(page, '/')
  await expect(page.getByTestId('conn-state')).toHaveText('connected')

  await test.step('GitHub', async () => {
    await expect(page.getByTestId('self-user-id')).toBeEmpty()
    await openSignIn(page)

    await page.getByTestId('auth-icon-oauth-github').click()

    // The upgrade is the whole outcome: the shell grows the signed-in region and
    // the identity line names an account this demo's users table now holds.
    await expect(page.getByTestId('nav-logout')).toBeVisible()
    await expect(page.getByTestId('self-user-id')).not.toBeEmpty()
    await expect(page.getByTestId('nav-signin')).toHaveCount(0)

    // Each stub carries its own account, so the second trip must start from a
    // guest — on a signed session it would prove something else.
    await logout(page)
  })

  await test.step('Google', async () => {
    await expect(page.getByTestId('self-user-id')).toBeEmpty()
    await openSignIn(page)

    await page.getByTestId('auth-icon-oauth-google').click()

    await expect(page.getByTestId('nav-logout')).toBeVisible()
    await expect(page.getByTestId('self-user-id')).not.toBeEmpty()
    await expect(page.getByTestId('nav-signin')).toHaveCount(0)
  })
})
