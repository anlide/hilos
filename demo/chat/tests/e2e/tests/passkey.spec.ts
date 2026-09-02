import { test, expect, type Page } from '@playwright/test'

import { clickSubmit, login, logout, signUp } from '../helpers/session'
import { gotoPage } from '../helpers/page'

// Passkey (WebAuthn) e2e (HIL-284): the register -> login round-trip driven
// through the live daemon and built frontend, with a CDP virtual authenticator
// standing in for a real platform authenticator. It exercises the two ceremonies
// that remain after HIL-418 retired the username-first login:
//   - REGISTER (attestation): a signed-in user enrolls a passkey from the profile
//     ("Add a passkey"); the server issues creation options + a stateless
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
// HILOS_WEBAUTHN_RP_ID / _ORIGIN matching the e2e host (chat-nginx-test, set in
// tests/.env): the authenticator scopes the credential to the RP id and the
// server matches the clientDataJSON origin exactly, so a mismatch fails the
// assertion before any assertion is even attempted.
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

/**
 * Attach a CDP virtual platform authenticator to the page so
 * navigator.credentials create/get resolve without a real device or OS prompt.
 * ctap2 + internal transport + resident key + auto-verified user models a modern
 * platform passkey; automaticPresenceSimulation auto-answers the user-presence
 * gesture. Attached once and left for the whole test so the credential minted in
 * the register ceremony survives into the later discoverable login on the same
 * page.
 *
 * @param page The page whose browser context gets the authenticator.
 * @param automaticPresence Whether the authenticator auto-answers the
 *   user-presence gesture. Pass false to leave the ceremony hanging on the
 *   waiting screen — the cancel test parks there on purpose.
 */
async function addVirtualAuthenticator(
  page: Page,
  automaticPresence = true,
): Promise<void> {
  const client = await page.context().newCDPSession(page)
  await client.send('WebAuthn.enable')
  await client.send('WebAuthn.addVirtualAuthenticator', {
    options: {
      protocol: 'ctap2',
      transport: 'internal',
      hasResidentKey: true,
      hasUserVerification: true,
      isUserVerified: true,
      automaticPresenceSimulation: automaticPresence,
    },
  })
}

test('signs in usernameless with a discoverable passkey — no email', async ({
  page,
}) => {
  // A resident credential must exist for a discoverable login; the register
  // ceremony (HIL-284) mints one, so enroll a passkey first, then sign out.
  await addVirtualAuthenticator(page)
  await signUp(page)
  await gotoPage(page, '/profile')
  await expect(page.getByTestId('profile-name')).toBeVisible()
  await page.getByTestId('profile-passkey-add').click()
  await expect(
    page.getByTestId('hilos-toasts').getByText('Passkey added.'),
  ).toBeVisible()

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

test('unlinks a passkey and leaves it unable to sign in', async ({ page }) => {
  await addVirtualAuthenticator(page)
  const user = await signUp(page)

  await gotoPage(page, '/profile')
  await expect(page.getByTestId('profile-name')).toBeVisible()
  await page.getByTestId('profile-passkey-add').click()
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
  await clickSubmit(passkeyRow.getByTestId('identity-unlink-yes'))

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
