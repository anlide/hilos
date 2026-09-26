import type { Page } from '@playwright/test'

// The virtual passkey device a spec signs in with. Chrome stands a CDP
// authenticator in for the platform one, so a WebAuthn ceremony runs to the end
// with no real device and no OS prompt. It reads the same in every demo — the
// ceremony it answers is the framework's — so it lives here rather than in one
// demo's spec (HIL-1150: tasks needed it after chat).

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
export async function addVirtualAuthenticator(
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
