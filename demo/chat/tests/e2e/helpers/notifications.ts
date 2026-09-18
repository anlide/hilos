import { expect, type Locator, type Page } from '@playwright/test'

import { createCommandChannel } from '../../../../../framework/frontend/scripts/commandChannel.mjs'
import { gotoPage } from './page'
import { signUp } from './session'

// The daemon command channel — the same socket the CLI test:notification:emit
// command speaks. The Playwright runner has no PHP, so the e2e emits over the
// wire directly; the emit still runs where a product caller's would, in a worker
// (AbstractHilosIndexAgent), so it writes the durable row, fans the live in-app
// signal, and dispatches the channels exactly as the product path does.
const COMMAND_HOST = process.env.COMMAND_HOST ?? 'chat-test'
const COMMAND_PORT = Number(process.env.COMMAND_PORT ?? 8094)

// Past the emit's own work — a durable write, a group fan-out, and one delivery
// row per channel — so a slow box comes back as the agent's answer rather than
// as a socket timeout.
const REPLY_TIMEOUT_MS = 10_000

const EMIT_COMMAND = 'test:notification:emit'

const sendCommand = createCommandChannel({
  host: COMMAND_HOST,
  port: COMMAND_PORT,
  timeoutMs: REPLY_TIMEOUT_MS,
})

/** The draft fields the emit command accepts; type and title are required. */
export interface NotificationDraft {
  /** Machine notification type. */
  type: string
  /** Rendered title, which the bell row shows. */
  title: string
  /** Rendered body, omitted for a title-only notification. */
  body?: string
  /** Severity level (NotificationSeverity); the backend defaults to `info`. */
  severity?: string
  /** Channel narrowing; omitted means every channel the recipient allows. */
  channels?: string[]
}

/** What the emit produced, as the delivery journal holds it. */
export interface EmittedNotification {
  /** Id of the persisted notification — the bell row's stable selector suffix. */
  notificationId: number
  /**
   * Channels that got a delivery row, read back from the table. This is the
   * verdict a channel test needs: it tells "nothing was queued at all" apart
   * from "queued, and its agent did not deliver".
   */
  queuedChannels: string[]
}

/**
 * Emits one notification to a user through the live daemon.
 *
 * @param userId Recipient's durable user id.
 * @param draft The notification to emit.
 * @returns The persisted id and the channels that got a delivery row.
 */
export async function emitNotification(
  userId: number,
  draft: NotificationDraft,
): Promise<EmittedNotification> {
  const reply = await sendCommand(EMIT_COMMAND, { userId, ...draft })

  return {
    notificationId: Number(reply.notificationId),
    queuedChannels: (reply.queuedChannels as string[] | undefined) ?? [],
  }
}

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

/**
 * Open the bell's dropdown so its rows are on screen.
 *
 * @param page The page whose bell is opened.
 */
export async function openBell(page: Page): Promise<void> {
  await page.getByTestId('hilos-notification-toggle').click()
  await expect(page.getByTestId('hilos-notification-menu')).toBeVisible()
}

/**
 * The unread badge. Its label carries a visually-hidden suffix — unread is never
 * signalled by color alone — so a count is matched at the front of the text.
 *
 * @param page The page whose bell is read.
 * @returns The badge locator.
 */
export function unreadBadge(page: Page): Locator {
  return page.getByTestId('hilos-notification-badge')
}
