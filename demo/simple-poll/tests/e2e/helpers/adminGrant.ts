import net from 'node:net'
import { randomBytes } from 'node:crypto'
import { expect, type Page } from '@playwright/test'

import { gotoPage } from './page'

// The daemon command channel — the same socket the CLI admin:create /
// admin:grant / admin:revoke commands speak. The Playwright runner has no PHP,
// so the e2e drives the commands over the wire directly; this still exercises
// the real CommandServer parking, the framework's routing, and the demo's own
// seams behind it.
const COMMAND_HOST = process.env.COMMAND_HOST ?? 'poll-daemon-test'
const COMMAND_PORT = Number(process.env.COMMAND_PORT ?? 8094)
const REPLY_TIMEOUT_MS = 5_000

/** The framework command names, as CliCommands spells them on the wire. */
const COMMAND_CREATE = 'admin:create'
const COMMAND_GRANT = 'admin:grant'
const COMMAND_REVOKE = 'admin:revoke'

/**
 * The session cookie the daemon sets on the 101, at its default name
 * (HILOS_SESSION_COOKIE_NAME renames it; no stand does).
 */
const SESSION_COOKIE = 'hilos_session_token'

/** What a command reply carries back over the socket. */
type CommandReply = {
  status?: string
  payload?: Record<string, unknown>
}

/**
 * Sends one command over the daemon command channel and resolves with its ok
 * payload, rejecting when the daemon refuses or stays silent.
 *
 * @param command Wire name of the command to send.
 * @param payload Request payload the agent reads.
 * @returns The reply payload of an ok reply.
 */
function sendCommand(
  command: string,
  payload: Record<string, unknown>,
): Promise<Record<string, unknown>> {
  return new Promise((resolve, reject) => {
    const request =
      JSON.stringify({
        correlationId: randomBytes(8).toString('hex'),
        command,
        payload,
      }) + '\n'

    const socket = net.connect(COMMAND_PORT, COMMAND_HOST)
    let buffer = ''

    const timer = setTimeout(() => {
      socket.destroy()
      reject(new Error(`No command-channel reply within ${REPLY_TIMEOUT_MS}ms`))
    }, REPLY_TIMEOUT_MS)

    socket.on('connect', () => {
      socket.write(request)
    })
    socket.on('data', (chunk: Buffer) => {
      buffer += chunk.toString()
      const newline = buffer.indexOf('\n')
      if (newline === -1) {
        return
      }
      clearTimeout(timer)
      socket.destroy()

      const reply = JSON.parse(buffer.slice(0, newline)) as CommandReply
      if (reply.status === 'ok') {
        resolve(reply.payload ?? {})
      } else {
        reject(
          new Error(
            `${command} failed: ${String(reply.payload?.message ?? 'unknown error')}`,
          ),
        )
      }
    })
    socket.on('error', (error) => {
      clearTimeout(timer)
      reject(error)
    })
  })
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
  const token = cookies.find((cookie) => cookie.name === SESSION_COOKIE)?.value
  if (token === undefined) {
    throw new Error(`no ${SESSION_COOKIE} cookie on this context`)
  }

  return token
}

/**
 * Makes one browser session an administrator over the command channel, minting
 * its user when the session carries none.
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
 * self-user-id marker (HIL-609). Both name the same person today — this demo's
 * handshake binds every visitor to a user row — but the marker only exists
 * because of that, and it goes away when the visitor moves behind the session
 * (HIL-610/611). The reply carries the id, so the specs calling this keep
 * getting one either way.
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
