import type { Expect, Page } from '@playwright/test'
import { gotoPage } from './page.js'
import { signUp } from './session.js'
import { sessionToken } from './sessionCookie.js'
import { mintAdmin, setAdmin } from '../scripts/adminGrant.mjs'

/**
 * Open the main page and make its visitor an admin over the command channel.
 *
 * The session cookie names even an anonymous visitor; the command creates or
 * finds its account and returns its durable id. Wait for the full-access mark
 * to prove the grant reached this browser, since view mode already shows the gear.
 *
 * @param expect The calling spec's Playwright expect.
 * @param page Playwright page whose context has not opened the app yet.
 * @returns The granted account's durable user id.
 */
export async function grantAdminToSelf(
  expect: Expect,
  page: Page,
): Promise<number> {
  await gotoPage(expect, page, '/')
  await expect(page.getByTestId('conn-state')).toHaveText('connected')

  const userId = await mintAdmin(await sessionToken(page))
  await expect(page.getByTestId('nav-admin')).toHaveAttribute(
    'data-access',
    'full',
  )

  return userId
}

/**
 * Register a fresh account with PASSWORD and grant it admin over the command channel.
 *
 * Unlike an account minted by admin:create, this administrator has a password
 * for step-up. Registration proves its email too. The account is a per-test
 * throwaway, so no revoke is needed afterwards.
 *
 * @param expect The calling spec's Playwright expect.
 * @param page Playwright page still anonymous in its browser context.
 * @param options The entry control used to open sign-in.
 * @returns The granted account's durable user id.
 */
export async function signUpAdmin(
  expect: Expect,
  page: Page,
  options: { entryId?: string } = {},
): Promise<number> {
  const { userId } = await signUp(expect, page, options)
  await setAdmin(userId, true)
  await expect(page.getByTestId('nav-admin')).toHaveAttribute(
    'data-access',
    'full',
  )

  return userId
}
