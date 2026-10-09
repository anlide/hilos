import { test, expect } from '@playwright/test'

import {
  addVirtualAuthenticator,
  platformsLeftOut,
  readPasskeyCreations,
  watchPasskeyCreation,
} from '../../../../../framework/frontend/e2e/index.js'
import {
  PASSWORD,
  addPasswordFromAddressStep,
  clickSubmit,
  continueFromDone,
  createAccountWithPasskey,
  finishWithPasskey,
  login,
  logout,
  signUp,
  submitRegistration,
  submitRegistrationCode,
  typeInto,
  uniqueEmail,
} from '../helpers/session'
import { readRegisterCode } from '../helpers/mail'
import { gotoPage } from '../helpers/page'

// Passkey (WebAuthn) e2e (HIL-284): the register -> login round-trip driven
// through the live daemon and built frontend, with a CDP virtual authenticator
// standing in for a real platform authenticator. It exercises the two ceremonies
// that remain after HIL-418 retired the username-first login:
//   - REGISTER (attestation): a signed-in user enrolls a passkey from the profile
//     ("Ways to sign in → Add a way to sign in → Passkey"); the server issues creation options + a stateless
//     challenge, the authenticator creates a resident credential, and confirm
//     verifies the attestation and stores it on a passkey identity;
//   - DISCOVERABLE LOGIN (assertion): an anonymous visitor names no account at
//     all, the server returns empty allowCredentials, the authenticator offers its
//     resident credential, and confirm verifies the assertion and upgrades the
//     session over the same handshake_response the password login rides.
// The virtual authenticator (ctap2/internal, resident key, user-verified) is
// attached over CDP and auto-approves presence/UV, so the ceremony completes with
// no OS prompt. The crypto itself is unit-covered (framework Auth/WebAuthn); this
// file is the UI-driven cross-surface flow. It relies on the daemon's
// HILOS_WEBAUTHN_RP_ID / _ORIGIN matching the e2e host (tasks-nginx-test, set in
// demo/tasks/docker/docker-compose.test.yml): the authenticator scopes the
// credential to the RP id and the server matches clientDataJSON's origin exactly,
// so a mismatch fails the ceremony before its assertion is accepted.
//
// CDP cannot vary the authenticator's algorithms or show an OS chooser. All
// creation legs judge the actual request against the promised platform table
// (HIL-659), catching an ES256-only request that leaves Windows Hello out.
// Real-device checks and the limits of this table live in docs/agents/manual-checks.md.
//
// HIL-695 adds the third ceremony this file covers: CANCEL. An anonymous visitor
// parks the discoverable login on the waiting screen and backs out of it. That
// test builds its authenticator with presence simulation OFF: Chrome does not
// answer the virtual device's transaction until presence is simulated, so
// navigator.credentials.get() hangs and the external step stays parked for as
// long as the test needs — which is exactly what makes the Cancel button
// reachable instead of the ceremony resolving instantly.
//
// HIL-722 adds the fourth: UNLINK. A signed-in user removes the passkey from
// the profile, and what the test is about is what happens afterwards — the key
// the virtual authenticator still holds must no longer open the account, while
// the email the account was registered with still does. It is the complaint the
// ticket came from, checked on the surface: the Unlink button has to be
// clickable while another sign-in method remains, and the removal has to take
// the stored credential with it rather than only the row on the screen.
//
// HIL-1104 adds the fifth: an account STARTED on a passkey. A guest registers,
// proves the address with the mailed code, and on the password screen takes
// "Create it with a passkey" instead of choosing a password. The key the virtual
// authenticator makes there is the account's way in, which is what the leg ends
// on: signed out, the same key opens the account from an empty field.
// HIL-1106 adds the road without an address: the empty-field entry, consent,
// the key alone on the account, and a later discoverable sign-in with that key.
//
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

test('signs in usernameless with a discoverable passkey — no email', async ({
  page,
}) => {
  // A resident credential must exist for a discoverable login; the register
  // ceremony (HIL-284) mints one, so enroll a passkey first, then sign out.
  await addVirtualAuthenticator(page)
  await watchPasskeyCreation(page)
  await signUp(page)
  await gotoPage(page, '/profile/sign-in')
  await clickSubmit(page.getByTestId('profile-sign-in-add'))
  // Adding a way in asks the password first (HIL-1138).
  await typeInto(page.getByTestId('step-up-password'), PASSWORD)
  await clickSubmit(page.getByTestId('profile-sign-in-add-step-up-confirm'))
  await clickSubmit(page.getByTestId('profile-passkey-add'))
  await expect(page.getByTestId('profile-sign-in-add-modal')).toHaveCount(0)
  await expect(
    page.getByTestId('hilos-toasts').getByText('Passkey added.'),
  ).toBeVisible()
  const creations = await readPasskeyCreations(page)
  expect(creations).toHaveLength(1)
  expect(platformsLeftOut(creations[0])).toEqual([])

  // The key reads by DEVICE (HIL-418): the row the enrollment adds carries a
  // "Passkey · added <date>" line, and the credential-id line the list used to
  // print for every identity is GONE from it — that string names nothing a person
  // could recognize. The name itself comes from this browser's User-Agent, so it
  // is asserted by shape, not by literal.
  const passkeyRow = page
    .getByTestId('profile-identity-item')
    .filter({ has: page.getByTestId('identity-passkey-added') })
  await expect(passkeyRow).toHaveCount(1)
  await expect(passkeyRow.getByTestId('identity-passkey-added')).toContainText(
    'Passkey · added',
  )
  await expect(passkeyRow.getByTestId('identity-identifier')).toHaveCount(0)

  await logout(page)
  await gotoPage(page, '/profile')
  await expect(page.getByTestId('auth-surface')).toBeVisible()

  // DISCOVERABLE LOGIN (HIL-400): click the usernameless "Sign in with a passkey"
  // icon — no email is entered, which is exactly why it lives in the row that only
  // shows while the identifier field is EMPTY (HIL-423). The server returns empty
  // allowCredentials, the authenticator returns its resident credential, and
  // confirm resolves the account (user handle + credential id) and upgrades the
  // session in place.
  const discoverable = page.getByTestId('auth-icon-passkey')
  await discoverable.scrollIntoViewIfNeeded()
  await expect(discoverable).toBeEnabled()
  await discoverable.click()

  await expect(page.getByTestId('auth-surface')).toHaveCount(0)

  // The preserved profile subscription resumes off the session upgrade — the
  // user's name renders with no navigation.
  await expect(page.getByTestId('profile-name')).toBeVisible()
  expect(new URL(page.url()).pathname).toBe('/profile')
})

test('creates an account on a passkey from the password screen and signs back in with it', async ({
  page,
}) => {
  await addVirtualAuthenticator(page)
  await watchPasskeyCreation(page)
  const email = uniqueEmail()
  await gotoPage(page, '/profile')
  await expect(page.getByTestId('auth-surface')).toBeVisible()

  await submitRegistration(page, email)
  await submitRegistrationCode(page, await readRegisterCode(email))
  // The third ending of the password screen: no password is typed at all.
  // A hang here is the new_account options never landing (HIL-1150).
  await finishWithPasskey(page)
  await expect(page.getByTestId('auth-error')).toHaveCount(0)
  await continueFromDone(page)
  await expect(page.getByTestId('profile-name')).toBeVisible()
  const creations = await readPasskeyCreations(page)
  expect(creations).toHaveLength(1)
  expect(platformsLeftOut(creations[0])).toEqual([])

  await gotoPage(page, '/profile/sign-in')

  // The account signs in with the key the device just made: the profile lists it
  // by device, beside the confirmed address it was registered on.
  const passkeyRow = page
    .getByTestId('profile-identity-item')
    .filter({ has: page.getByTestId('identity-passkey-added') })
  await expect(passkeyRow).toHaveCount(1)

  await logout(page)
  await gotoPage(page, '/profile')
  await expect(page.getByTestId('auth-surface')).toBeVisible()

  // Signed out, the same key opens the account from an empty field.
  // A hang here is the login options never landing (HIL-1150).
  const discoverable = page.getByTestId('auth-icon-passkey')
  await discoverable.scrollIntoViewIfNeeded()
  await expect(discoverable).toBeEnabled()
  await discoverable.click()

  await expect(page.getByTestId('auth-surface')).toHaveCount(0)
  await expect(page.getByTestId('profile-name')).toBeVisible()
})

test('creates a passkey account without an address from the empty field and signs back in', async ({
  page,
}) => {
  await addVirtualAuthenticator(page)
  await watchPasskeyCreation(page)
  await gotoPage(page, '/profile')
  // Chromium reports this CDP internal/UV authenticator as a platform key;
  // this exercises the real browser half of the entry gate without a stub.
  expect(
    await page.evaluate(() =>
      PublicKeyCredential.isUserVerifyingPlatformAuthenticatorAvailable(),
    ),
  ).toBe(true)
  await expect(page.getByTestId('auth-create-passkey')).toBeVisible()
  await createAccountWithPasskey(page)
  await expect(page.getByTestId('auth-error')).toHaveCount(0)
  await continueFromDone(page)
  await expect(page.getByTestId('profile-name')).toHaveText(/^User[1-9]\d{5}$/)
  const name = await page.getByTestId('profile-name').innerText()
  const creations = await readPasskeyCreations(page)
  expect(creations).toHaveLength(1)
  expect(platformsLeftOut(creations[0])).toEqual([])

  await gotoPage(page, '/profile/sign-in')
  await expect(page.getByTestId('profile-identity-item')).toHaveCount(1)
  await expect(page.getByTestId('identity-passkey-added')).toHaveCount(1)
  await expect(page.getByTestId('identity-identifier')).toHaveCount(0)

  await logout(page)
  await gotoPage(page, '/profile')
  await expect(page.getByTestId('auth-surface')).toBeVisible()
  await clickSubmit(page.getByTestId('auth-icon-passkey'))
  await expect(page.getByTestId('auth-surface')).toHaveCount(0)
  await expect(page.getByTestId('profile-name')).toHaveText(name)
})

test('warns a passkey-only account on the profile and adds a password from the warning', async ({
  page,
}) => {
  await addVirtualAuthenticator(page)
  await gotoPage(page, '/profile')
  await createAccountWithPasskey(page)
  await continueFromDone(page)

  // The overview says it under the row, without opening the section (HIL-1166).
  await expect(
    page.getByTestId('profile-sign-in-passkey-only-line'),
  ).toBeVisible()

  await gotoPage(page, '/profile/sign-in')
  const warning = page.getByTestId('profile-sign-in-passkey-only')
  await expect(warning).toBeVisible()
  await expect(
    warning.getByTestId('profile-sign-in-passkey-only-password'),
  ).toBeVisible()
  await expect(
    warning.getByRole('button', { name: 'Passkey', exact: true }),
  ).toHaveCount(0)

  // The button opens the window on the password itself; the confirmation first
  // is answered by the same device key the account lives on.
  await clickSubmit(
    warning.getByTestId('profile-sign-in-passkey-only-password'),
  )
  await clickSubmit(page.getByTestId('profile-sign-in-add-step-up-confirm'))
  await expect(page.getByTestId('profile-sign-in-choose-password')).toHaveCount(
    0,
  )
  await addPasswordFromAddressStep(page, uniqueEmail())

  // The new way arrives in the live list and takes the warning with it.
  await expect(page.getByTestId('profile-password-change')).toBeVisible()
  await expect(warning).toHaveCount(0)
})

test('unlinks a passkey and leaves it unable to sign in', async ({ page }) => {
  await addVirtualAuthenticator(page)
  const user = await signUp(page)

  await gotoPage(page, '/profile/sign-in')
  await clickSubmit(page.getByTestId('profile-sign-in-add'))
  // Adding a way in asks the password first (HIL-1138).
  await typeInto(page.getByTestId('step-up-password'), PASSWORD)
  await clickSubmit(page.getByTestId('profile-sign-in-add-step-up-confirm'))
  await clickSubmit(page.getByTestId('profile-passkey-add'))
  await expect(page.getByTestId('profile-sign-in-add-modal')).toHaveCount(0)
  await expect(
    page.getByTestId('hilos-toasts').getByText('Passkey added.'),
  ).toBeVisible()

  // The account now holds two sign-in methods, so neither is the last one and the
  // passkey row's Unlink must be offered. That assertion IS the reported bug: the
  // button was dead while an email sign-in was standing right next to it.
  const passkeyRow = page
    .getByTestId('profile-identity-item')
    .filter({ has: page.getByTestId('identity-passkey-added') })
  await expect(passkeyRow).toHaveCount(1)
  await expect(page.getByTestId('profile-identity-item')).toHaveCount(2)
  const unlink = passkeyRow.getByTestId('identity-unlink')
  await expect(unlink).toBeEnabled()
  await unlink.click()
  await clickSubmit(page.getByTestId('identity-unlink-yes'))
  await expect(page.getByTestId('profile-unlink-modal')).toHaveCount(0)

  // Success is state-driven: the delete broadcast re-emits the projection, so the
  // passkey row leaves on its own and the email row stays.
  await expect(passkeyRow).toHaveCount(0)
  await expect(page.getByTestId('profile-identity-item')).toHaveCount(1)

  await logout(page)
  await gotoPage(page, '/profile')
  await expect(page.getByTestId('auth-surface')).toBeVisible()

  // The authenticator still holds the resident credential, which is the whole
  // point: the browser can still offer the key, and the server must refuse it
  // because the credential it was stored under is gone.
  const discoverable = page.getByTestId('auth-icon-passkey')
  await discoverable.scrollIntoViewIfNeeded()
  await expect(discoverable).toBeEnabled()
  await discoverable.click()
  await expect(page.getByTestId('auth-error')).toBeVisible()
  await expect(page.getByTestId('auth-surface')).toBeVisible()
  await expect(page.getByTestId('auth-identifier')).toBeVisible()

  // The account itself is untouched: the address it was registered with signs in.
  await login(page, user.email)
  await expect(page.getByTestId('auth-surface')).toHaveCount(0)
  await expect(page.getByTestId('profile-name')).toBeVisible()
})

test('cancels a parked passkey ceremony and leaves the surface usable', async ({
  page,
}) => {
  // Presence simulation OFF: the ceremony parks on the waiting screen instead of
  // resolving, so Cancel is reachable. The client-side ceremony timeout is 60 s —
  // far beyond what this test needs.
  await addVirtualAuthenticator(page, false)

  // An anonymous visitor lands on the gated profile: the auth surface mounts in
  // its place.
  await gotoPage(page, '/profile')
  await expect(page.getByTestId('auth-surface')).toBeVisible()
  await expect(page.getByTestId('profile-name')).toHaveCount(0)

  // Start the discoverable login from the icon row (visible while the identifier
  // field is empty — and the visitor typed nothing).
  const passkey = page.getByTestId('auth-icon-passkey')
  await passkey.scrollIntoViewIfNeeded()
  await expect(passkey).toBeEnabled()
  await passkey.click()

  // The surface parks on the external step: the Cancel button is the mark.
  const cancel = page.getByTestId('auth-cancel')
  await expect(cancel).toBeVisible()

  // Back out. cancelMethod aborts the ceremony first, then clears pending and
  // returns to the identifier step.
  await clickSubmit(cancel)
  await expect(page.getByTestId('auth-identifier')).toBeVisible()
  await expect(page.getByTestId('auth-cancel')).toHaveCount(0)

  // Nothing is left hanging: no error (a cancel is not a failure), the surface
  // is still mounted (no sign-in happened), and the icon is active again.
  await expect(page.getByTestId('auth-error')).toHaveCount(0)
  await expect(page.getByTestId('auth-surface')).toBeVisible()
  await expect(passkey).toBeEnabled()

  // The ceremony starts over cleanly — neither pending nor the single-flight
  // guard got stuck.
  await passkey.click()
  await expect(page.getByTestId('auth-cancel')).toBeVisible()

  // Close the second ceremony too so the test leaves no WebAuthn request in
  // flight.
  await clickSubmit(page.getByTestId('auth-cancel'))
  await expect(page.getByTestId('auth-identifier')).toBeVisible()
})
