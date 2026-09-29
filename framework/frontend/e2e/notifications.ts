import type { Locator, Page } from '@playwright/test'

import { shownByTestId } from './table.js'

// Notification steps shared by all three SDKs, whose data-id controls match. They
// drive the framework's own screens — the bell a demo mounts in its header slot
// (HilosNotificationBell) and the communications hub — so they read the same in
// any demo. What stays with a demo is how a notification is made to exist (the
// emit over its daemon's command channel) and how somebody signs in.

/** `data-id` of the bell's toggle, which opens its dropdown. */
const BELL_TOGGLE = 'hilos-notification-toggle'

/** `data-id` of the bell's dropdown. */
const BELL_MENU = 'hilos-notification-menu'

/** `data-id` of the unread badge on the bell. */
const BELL_BADGE = 'hilos-notification-badge'

/** `data-id` of the email channel's switch on the communications hub. */
const EMAIL_CHANNEL_SWITCH = 'hilos-channel-enabled-email'

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
  // The switch stands in a cell, and the hub is a declared table drawn both as rows
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
