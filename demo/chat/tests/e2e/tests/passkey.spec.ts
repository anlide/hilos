import { test, expect, type Page } from '@playwright/test'

import { logout, signUp } from '../helpers/session'
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
 */
async function addVirtualAuthenticator(page: Page): Promise<void> {
  const client = await page.context().newCDPSession(page)
  await client.send('WebAuthn.enable')
  await client.send('WebAuthn.addVirtualAuthenticator', {
    options: {
      protocol: 'ctap2',
      transport: 'internal',
      hasResidentKey: true,
      hasUserVerification: true,
      isUserVerified: true,
      automaticPresenceSimulation: true,
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
  // action — no email is entered. The server returns empty allowCredentials, the
  // authenticator returns its resident credential, and confirm resolves the
  // account (user handle + credential id) and upgrades the session in place.
  const discoverable = page.getByTestId('auth-passkey-discoverable')
  await discoverable.scrollIntoViewIfNeeded()
  await expect(discoverable).toBeEnabled()
  await discoverable.click()

  await expect(page.getByTestId('auth-surface')).toHaveCount(0)

  // The preserved profile subscription resumes off the session upgrade — the
  // user's name renders with no navigation.
  await expect(page.getByTestId('profile-name')).toBeVisible()
  expect(new URL(page.url()).pathname).toBe('/profile')
})
