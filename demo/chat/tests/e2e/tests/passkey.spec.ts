import { test, expect, type Page } from '@playwright/test'

import { logout, signUp } from '../helpers/session'

// Passkey (WebAuthn) e2e (HIL-284): the register -> login round-trip driven
// through the live daemon and built frontend, with a CDP virtual authenticator
// standing in for a real platform authenticator. It exercises the two ceremonies
// wired in slices 2-4:
//   - REGISTER (attestation): a signed-in user enrolls a passkey from the profile
//     ("Add a passkey"); the server issues creation options + a stateless
//     challenge, the authenticator creates a resident credential, and confirm
//     verifies the attestation and stores it on a passkey identity;
//   - LOGIN (assertion, username-first): an anonymous visitor enters the account
//     email, the server returns that user's allowCredentials + a challenge, the
//     authenticator signs, and confirm verifies the assertion and upgrades the
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
 * the register ceremony survives into the later passkey login on the same page.
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

test('enrolls a passkey in the profile and signs back in with it username-first', async ({
  page,
}) => {
  // A platform authenticator must exist before either ceremony; attach it up
  // front so it persists across the logout and into the passkey login.
  await addVirtualAuthenticator(page)

  // Establish a password account and land signed-in on the gated profile.
  const { email } = await signUp(page)
  await page.goto('/profile')
  await expect(page.getByTestId('profile-name')).toBeVisible()

  // REGISTER: enroll a passkey; the virtual authenticator auto-approves, the
  // attestation verifies server-side, and the profile flags the credential added.
  await page.getByTestId('profile-passkey-add').click()
  await expect(
    page.getByTestId('hilos-toasts').getByText('Passkey added.'),
  ).toBeVisible()
  await expect(page.getByTestId('profile-passkey-error')).toHaveCount(0)

  // Back to anonymous: the gated profile re-mounts the sign-in surface (login
  // mode), from which the passkey method is reachable.
  await logout(page)
  await page.goto('/profile')
  await expect(page.getByTestId('auth-surface')).toBeVisible()

  // LOGIN: switch to the passkey method and enter the account email. Type key by
  // key (never a bare fill) so the surface's reactive form ships the email, not an
  // empty payload (helpers/session.ts).
  await page.getByTestId('auth-to-passkey').click()
  const emailField = page.getByTestId('auth-passkey-email')
  await emailField.fill('')
  await emailField.pressSequentially(email, { delay: 10 })

  // Submit once genuinely actionable, then let the ceremony settle: the server
  // returns this user's allowCredentials, the authenticator signs the assertion,
  // and the verified login upgrades the session in place. Success unmounts the
  // surface off the session upgrade.
  const submit = page.getByTestId('auth-submit')
  await submit.scrollIntoViewIfNeeded()
  await expect(submit).toBeEnabled()
  await submit.focus()
  await submit.click()

  await expect(page.getByTestId('auth-surface')).toHaveCount(0)

  // The preserved profile subscription resumes off the session upgrade — the
  // user's name renders with no navigation.
  await expect(page.getByTestId('profile-name')).toBeVisible()
  expect(new URL(page.url()).pathname).toBe('/profile')
})

test('signs in usernameless with a discoverable passkey — no email', async ({
  page,
}) => {
  // A resident credential must exist for a discoverable login; the register
  // ceremony (HIL-284) mints one, so enroll a passkey first, then sign out.
  await addVirtualAuthenticator(page)
  await signUp(page)
  await page.goto('/profile')
  await expect(page.getByTestId('profile-name')).toBeVisible()
  await page.getByTestId('profile-passkey-add').click()
  await expect(
    page.getByTestId('hilos-toasts').getByText('Passkey added.'),
  ).toBeVisible()

  await logout(page)
  await page.goto('/profile')
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
