import type {
  Browser,
  BrowserContextOptions,
  Expect,
  Page,
} from '@playwright/test'
import { gotoPage } from './page.js'
import { signUp } from './session.js'
import { sessionToken } from './sessionCookie.js'
import { createTestUser, type TestUserOptions } from './testUser.js'
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
 * Create a verified fixture account and grant it admin through the command channel.
 *
 * @param expect The calling spec's Playwright expect.
 * @param page Browser page to bind to the fixture account.
 * @param options Account fixture values and an optional cluster command host.
 * @returns The granted account's durable user id.
 */
export async function createTestAdmin(
  expect: Expect,
  page: Page,
  options: TestUserOptions = {},
): Promise<number> {
  const { userId } = await createTestUser(expect, page, options)
  await setAdmin(userId, true)
  await expect(page.getByTestId('nav-admin')).toHaveAttribute(
    'data-access',
    'full',
  )

  return userId
}

/**
 * Mint an administrator in a separate setup context and keep its state in memory.
 *
 * @param expect The calling spec's Playwright expect.
 * @param browser The browser from which to create the isolated setup context.
 * @param contextOptions Project options such as baseURL and ignoreHTTPSErrors.
 * @returns The granted browser's storage state, after its setup context closes.
 */
export async function createAdminStorageState(
  expect: Expect,
  browser: Browser,
  contextOptions: BrowserContextOptions,
) {
  const context = await browser.newContext(contextOptions)
  try {
    const page = await context.newPage()
    await grantAdminToSelf(expect, page)
    return await context.storageState()
  } finally {
    await context.close()
  }
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
