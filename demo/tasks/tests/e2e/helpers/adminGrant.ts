import { expect, type Page } from '@playwright/test'

import { createCommandChannel } from '../../../../../framework/frontend/scripts/commandChannel.mjs'
import { gotoPage } from './page'

// The daemon command channel — the same socket the CLI admin:create /
// admin:grant / admin:revoke commands speak. The Playwright runner has no PHP,
// so the e2e drives the commands over the wire directly; this still exercises
// the real CommandServer parking, the framework's routing, and the demo's own
// seams behind it.
const COMMAND_HOST = process.env.COMMAND_HOST ?? 'tasks-daemon-test'
const COMMAND_PORT = Number(process.env.COMMAND_PORT ?? 8094)
const REPLY_TIMEOUT_MS = 5_000

/** The framework command names, as CliCommands spells them on the wire. */
const COMMAND_CREATE = 'admin:create'
const COMMAND_GRANT = 'admin:grant'
const COMMAND_REVOKE = 'admin:revoke'

const sendCommand = createCommandChannel({
  host: COMMAND_HOST,
  port: COMMAND_PORT,
  timeoutMs: REPLY_TIMEOUT_MS,
})

/**
 * Session cookie prefix derived by the framework when HILOS_SESSION_COOKIE_NAME is unset.
 * The auxiliary rotation cookie shares this prefix and ends with '_rotate'.
 */
const SESSION_COOKIE_PREFIX = 'hilos_session_token_'
const ROTATE_COOKIE_SUFFIX = '_rotate'

function isSessionCookie(name: string): boolean {
  return name.startsWith(SESSION_COOKIE_PREFIX) && !name.endsWith(ROTATE_COOKIE_SUFFIX)
}

/**
 * Sets a user's admin flag over the daemon command channel, resolving once the
 * daemon replies ok (its DB write and browser fan-out have completed by then).
 *
 * @param userId Target user id.
 * @param admin Whether to grant (true) or revoke (false) admin.
 */
export async function setAdmin(userId: number, admin: boolean): Promise<void> {
  await sendCommand(admin ? COMMAND_GRANT : COMMAND_REVOKE, { userId, admin })
}

/**
 * Reads the session cookie of a browser context — the address admin:create
 * names a session by.
 *
 * @param page Playwright page whose context has already opened the app.
 * @returns The session token this browser presents on its handshake.
 */
export async function sessionToken(page: Page): Promise<string> {
  const cookies = await page.context().cookies()
  const token = cookies.find((cookie) => isSessionCookie(cookie.name))?.value
  if (token === undefined) {
    throw new Error(`no ${SESSION_COOKIE_PREFIX}* cookie on this context`)
  }

  return token
}

/**
 * Makes one browser session an administrator over the command channel, minting
 * its user when the session carries none — which, since HIL-610, is every
 * browser that has not been granted one before.
 *
 * @param token Session cookie token naming the browser session.
 * @returns The id of the user that is now an administrator.
 */
export async function mintAdmin(token: string): Promise<number> {
  const payload = await sendCommand(COMMAND_CREATE, { sessionToken: token })
  const userId = Number(payload.userId)
  expect(userId).toBeGreaterThan(0)

  return userId
}

/**
 * Opens the main page and makes its visitor an admin over the command channel.
 *
 * The framework admin surface (/hilos/*) is closed by default (HIL-441: pages
 * inherit the ADMIN access level), so any spec that navigates there takes the
 * grant first.
 *
 * The account is named by the SESSION cookie rather than by the page's
 * self-user-id marker (HIL-609), which since HIL-610 is empty until a browser has
 * an account — a visitor has no user row to publish an id from. The command reply
 * carries the id, so this is also how a spec that needs one gets it.
 *
 * @param page Playwright page, in a browser context that has not opened the app yet.
 * @returns The granted account's durable user id.
 */
export async function grantAdminToSelf(page: Page): Promise<number> {
  await gotoPage(page, '/')
  await expect(page.getByTestId('conn-state')).toHaveText('connected')

  const userId = await mintAdmin(await sessionToken(page))
  // The command channel answers when the daemon has written the grant, which is
  // not the same as this browser knowing about it. The daemon re-points the
  // session's live connections and re-sends them the handshake response, and the
  // shell draws the admin entry from it — so the gear appearing is the proof that
  // the grant reached this page, rather than merely the server.
  await expect(page.getByTestId('nav-admin')).toBeVisible()

  return userId
}
