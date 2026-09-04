import { test, expect } from '@playwright/test'

import { readPasswordResetCode, readRegisterCode } from '../helpers/mail'
import { PAGE_REFUSED, gotoPage } from '../helpers/page'
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

// Sign-in e2e for the tasks demo (HIL-623). This demo had no auth handler at all
// until the AUTH feature was declared; nothing about the machine is this leaf's,
// so what is proved here is the ACTIVATION — that the framework surface reaches
// this project's seams and comes back with an account in this project's own
// users table.
//
// Four cases carry that: registering by the mailed code — which also pins the
// frame the surface arrives in over the page, as a modal, because that frame is
// this demo's App and not the framework's — signing back in with the password
// after a wrong one, recovering a forgotten one, and the surface standing in
// place of a page a guest is refused.
//
// The exhaustive per-method coverage (phone codes, magic links, passkeys, OAuth)
// belongs to the parity leaf HIL-426; repeating it here would prove the
// framework twice and this activation once.

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
  // The account is signed in, so the refusal must not call it a guest. The
  // sentence itself is pinned by the SDK unit; e2e pins only the word, so that
  // rewording the copy stays one file of work (HIL-776).
  await expect(page.getByTestId('page-error')).not.toContainText(/guest/i)
})
