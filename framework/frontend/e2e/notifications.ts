import type { Expect, Locator, Page } from '@playwright/test'

import { gotoPage } from './page.js'
import { signUp, type SignedInUser } from './session.js'
import { shownByTestId } from './table.js'
import { createTestUser, type TestUserOptions } from './testUser.js'

// Notification steps shared by all three SDKs, whose data-id controls match. They
// drive the framework's own screens — the bell a demo mounts in its header slot
// (HilosNotificationBell) and the communications hub — so they read the same in
// any demo. The scenario that raises and receives a notification stays in its spec.

/** `data-id` of the bell's toggle, which opens its dropdown. */
const BELL_TOGGLE = 'hilos-notification-toggle'

/** `data-id` of the bell's dropdown. */
const BELL_MENU = 'hilos-notification-menu'

/** `data-id` of the unread badge on the bell. */
const BELL_BADGE = 'hilos-notification-badge'

/** `data-id` of the email channel's switch on the communications hub. */
const EMAIL_CHANNEL_SWITCH = 'hilos-channel-enabled-email'

/**
 * Sign up and reload onto a page whose socket has joined the notification group.
 *
 * On a cold load bootHilos binds the notification scope before the page scope
 * and holds the page subscribe until the handshake answers. The group join is
 * therefore written ahead of the page subscribe, so its settled answer proves
 * the daemon processed the join. Registration also proves the email address.
 *
 * @param expect The calling spec's Playwright expect.
 * @param page Page starting anonymous.
 * @param options The entry control used to open sign-in.
 * @returns The registered address and the account's durable user id.
 */
export async function signUpJoined(
  expect: Expect,
  page: Page,
  options: { entryId?: string } = {},
): Promise<{ email: string; userId: number }> {
  const { email, userId } = await signUp(expect, page, options)
  await gotoPage(expect, page, '/')

  return { email, userId }
}

/**
 * Prepare a fixture account and reload after binding so notifications have joined.
 * The settled cold-load page answer follows the notification group's join.
 *
 * @param expect The calling spec's Playwright expect.
 * @param page Browser page to bind to the fixture account.
 * @param options Account fixture values and an optional cluster command host.
 * @returns The fixture account's email, display name, and durable user id.
 */
export async function createTestUserJoined(
  expect: Expect,
  page: Page,
  options: TestUserOptions = {},
): Promise<SignedInUser> {
  const user = await createTestUser(expect, page, options)
  await gotoPage(expect, page, '/')

  return user
}

/**
 * Opens the bell's dropdown so its rows are on screen.
 *
 * @param page The page whose bell is opened.
 */
export async function openBell(page: Page): Promise<void> {
  await page.getByTestId(BELL_TOGGLE).click()
  await page.getByTestId(BELL_MENU).waitFor({ state: 'visible' })
}

/**
 * The unread badge. Its label carries a visually-hidden suffix — unread is never
 * signalled by color alone — so a count is matched at the front of the text.
 *
 * @param page The page whose bell is read.
 * @returns The badge locator.
 */
export function unreadBadge(page: Page): Locator {
  return page.getByTestId(BELL_BADGE)
}

/**
 * Turns the email delivery channel on from the communications hub.
 *
 * Expects the operator's page already on /hilos/communications: navigation is the
 * demo's (its own page helper), as with the maintenance steps. Global enablement is
 * a persisted setting of the whole stand rather than of one account, so the step is
 * idempotent: a channel another test already switched on is left alone instead of
 * being toggled off and back on under it.
 *
 * @param page Page of a signed-in administrator on the communications hub.
 */
export async function enableEmailChannel(page: Page): Promise<void> {
  // The switch stands in a cell, and the hub is a table drawn both as rows
  // and as cards, so it is aimed at through the copy on screen.
  const toggle = shownByTestId(page, EMAIL_CHANNEL_SWITCH)
  await toggle.waitFor({ state: 'visible' })
  if (await toggle.isChecked()) {
    return
  }

  await toggle.click()
  // The server's table update moves this same switch; no page re-read is needed.
  await toggle.and(page.locator(':checked')).waitFor({ state: 'visible' })
}
