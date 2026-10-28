import type { Expect, Page } from '@playwright/test'
import {
  expectSessionRotation,
  logout as logoutSession,
  signUp as signUpSession,
  type SignedInUser,
} from '../../../../../framework/frontend/e2e/index.js'

// Chat alone seeds an orphan cookie to exercise its anonymous identity line.
/**
 * A session token of the minted shape (32 lowercase hex, SessionToken::isValid)
 * that names no session on the server.
 *
 * Well-formed on purpose: a malformed value is simply replaced by a freshly minted
 * one on the 101, which would make the browser arrive as if it had carried no
 * cookie at all. Unique per run for the reason uniqueEmail() is: the stand is
 * shared across specs and retries, and a repeated value could name a session
 * another test really holds.
 *
 * @returns The orphan token.
 */
export function orphanSessionToken(): string {
  const noise = (): string =>
    Math.floor(Math.random() * 0x100000000)
      .toString(16)
      .padStart(8, '0')

  return Date.now().toString(16).padStart(16, '0') + noise() + noise()
}

// Chat opens sign-in through its message composer.
export async function signUp(
  expect: Expect,
  page: Page,
): Promise<SignedInUser> {
  return signUpSession(expect, page, { entryId: 'message-signin' })
}

// Chat waits for token rotation before interacting with the new socket.
export async function logout(expect: Expect, page: Page): Promise<void> {
  await expectSessionRotation(expect, page, async () => {
    await logoutSession(expect, page, { profileId: 'nav-profile' })
  })
  await expect(page.getByTestId('conn-state')).toHaveText('connected')
}
