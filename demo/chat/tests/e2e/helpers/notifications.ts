import type { Page } from '@playwright/test'

import { gotoPage } from './page'
import { signUp } from './session'

// What a chat spec needs to wait for a notification: a person whose socket has
// already joined their notification group. The bell and the email-channel switch
// are the framework's screens and live in the shared toolbox
// (framework/frontend/e2e/notifications.ts); the emit over the command channel
// moved to binance-btc-tracker with the notification-center specs (HIL-1224), and
// chat's own notifications are raised by the product — a mention, a rename.

/**
 * Sign up and land on a page whose socket has already joined the recipient's
 * notification group.
 *
 * The join is the one ordering a spec that waits for a notification depends on: a
 * `notification_created` signal fans to the group, so an emit that overtook the
 * join would be delivered to nobody and the row would never appear. On a cold load
 * bootHilos binds the notification scope BEFORE the page scope and holds the page
 * subscribe until the handshake answers, so the group join is written to the
 * socket ahead of the page subscribe — which makes the page reporting `ready`
 * proof that the daemon has already processed the join. Signing up first and
 * reloading is therefore not a detour: it is what turns the join into something
 * the spec can wait for.
 *
 * @param page Page starting anonymous.
 * @returns The registered account's durable user id.
 */
export async function signUpJoined(page: Page): Promise<number> {
  const { userId } = await signUp(page)
  await gotoPage(page, '/')

  return userId
}
