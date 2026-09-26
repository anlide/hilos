import { test, expect } from '@playwright/test'

import { addVirtualAuthenticator } from '../../../../../framework/frontend/e2e/index.js'
import { readRegisterCode } from '../helpers/mail'
import { gotoPage } from '../helpers/page'
import {
  clickSubmit,
  continueFromDone,
  logout,
  openSignIn,
  submitRegistration,
  submitRegistrationCode,
  uniqueEmail,
} from '../helpers/session'

// Passkey e2e for the tasks demo (HIL-1150). The ceremony itself is the
// framework's and chat covers it in full; what this file catches is a sign-in
// signal schema this demo's connection does not mount. Until HIL-1150 tasks
// mounted none of them, and both ceremonies it offers hung here — "Create it
// with a passkey" on the registration password screen (new_account) and
// "Sign in with a passkey" on the sign-in surface (login): the options frame
// arrived as an unknown signal, and the surface waited on it until Cancel.
//
// One test walks both: the account is started on a key, signed out, and opened
// again by the same key from an empty field. The virtual authenticator is
// attached over CDP and scoped to this stand's RP id (HILOS_WEBAUTHN_RP_ID in
// demo/tasks/docker/docker-compose.test.yml).

test('creates an account on a passkey at registration and signs back in with it', async ({
  page,
}) => {
  await addVirtualAuthenticator(page)
  const email = uniqueEmail()

  await gotoPage(page, '/')
  await openSignIn(page)
  await submitRegistration(page, email)
  await submitRegistrationCode(page, await readRegisterCode(email))

  // The key replaces the password: the device makes it, and the account is
  // created on it. A hang here is the new_account options never landing.
  await clickSubmit(page.getByTestId('auth-complete-passkey'))
  await expect(page.getByTestId('auth-continue')).toBeVisible()
  await expect(page.getByTestId('auth-error')).toHaveCount(0)
  await continueFromDone(page)

  const selfUserId = page.getByTestId('self-user-id')
  await expect(selfUserId).not.toBeEmpty()
  const accountId = (await selfUserId.textContent()) ?? ''

  await logout(page)
  await openSignIn(page)

  // Signed out, the same key opens the same account from an empty field. A hang
  // here is the login options never landing.
  const discoverable = page.getByTestId('auth-icon-passkey')
  await discoverable.scrollIntoViewIfNeeded()
  await expect(discoverable).toBeEnabled()
  await discoverable.click()

  await expect(page.getByTestId('auth-surface')).toHaveCount(0)
  await expect(selfUserId).toHaveText(accountId)
})
