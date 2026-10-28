import type { Expect, Page } from '@playwright/test'
import { gotoPage } from './page.js'
import { uniqueEmail, type SignedInUser } from './session.js'
import { sessionToken } from './sessionCookie.js'
import {
  bindTestSession,
  FIXTURE_PASSWORD_HASH,
  seedTestUser,
} from '../scripts/testUser.mjs'

/** Account fixtures and optional command-channel placement for one browser. */
export interface TestUserOptions {
  email?: string
  passwordless?: boolean
  phone?: string | null
  themePick?: 'light' | 'dark' | 'system' | null
  commandHost?: string
}

/**
 * Prepare a verified account and bind this browser's live session to it.
 *
 * @param expect The calling spec's Playwright expect.
 * @param page Browser page to open on the main page.
 * @param options Account fixture values and an optional cluster command host.
 * @returns The account's normalized email, display name, and durable user id.
 */
export async function createTestUser(
  expect: Expect,
  page: Page,
  {
    email = uniqueEmail(),
    passwordless = false,
    phone = null,
    themePick = null,
    commandHost,
  }: TestUserOptions = {},
): Promise<SignedInUser> {
  await gotoPage(expect, page, '/')
  await expect(page.getByTestId('conn-state')).toHaveText('connected')
  const token = await sessionToken(page)
  const user = await seedTestUser(
    {
      email,
      passwordHash: passwordless ? null : FIXTURE_PASSWORD_HASH,
      phone,
      themePick,
    },
    commandHost,
  )
  await bindTestSession(
    { sessionToken: token, userId: user.userId },
    commandHost,
  )
  await expect(page.getByTestId('self-user')).toHaveText(user.name)
  await expect(page.getByTestId('self-user-id')).toHaveText(String(user.userId))

  return user
}
