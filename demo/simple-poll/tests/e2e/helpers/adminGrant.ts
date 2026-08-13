import net from 'node:net'
import { randomBytes } from 'node:crypto'
import { expect, type Page } from '@playwright/test'

import { gotoPage } from './page'

// The daemon command channel — the same socket the CLI admin:grant / admin:revoke
// commands speak. The Playwright runner has no PHP, so the e2e drives the grant
// over the wire directly; this still exercises the real CommandServer parking,
// the framework's hilos-index route, and the demo's own applyAdminGrant.
const COMMAND_HOST = process.env.COMMAND_HOST ?? 'poll-daemon-test'
const COMMAND_PORT = Number(process.env.COMMAND_PORT ?? 8094)
const REPLY_TIMEOUT_MS = 5_000

/** The framework command names, as CliCommands spells them on the wire. */
const COMMAND_GRANT = 'admin:grant'
const COMMAND_REVOKE = 'admin:revoke'

/**
 * Sets a user's admin flag over the daemon command channel, resolving once the
 * daemon replies ok (its DB write and browser fan-out have completed by then).
 *
 * @param userId Target user id.
 * @param admin Whether to grant (true) or revoke (false) admin.
 */
export function setAdmin(userId: number, admin: boolean): Promise<void> {
  return new Promise((resolve, reject) => {
    const request =
      JSON.stringify({
        correlationId: randomBytes(8).toString('hex'),
        command: admin ? COMMAND_GRANT : COMMAND_REVOKE,
        payload: { userId, admin },
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

      const reply = JSON.parse(buffer.slice(0, newline)) as {
        status?: string
        payload?: { message?: string }
      }
      if (reply.status === 'ok') {
        resolve()
      } else {
        reject(
          new Error(
            `admin grant failed: ${reply.payload?.message ?? 'unknown error'}`,
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
 * Opens the main page and grants its visitor admin over the command channel.
 *
 * The framework admin surface (/hilos/*) is closed by default (HIL-441: pages
 * inherit the ADMIN access level), so any spec that navigates there takes the
 * grant first. This demo's visitor is never anonymous — the handshake maps the
 * session cookie to a durable user row — so the account to grant is whoever this
 * browser already is, read from the main page's hidden self-user-id marker.
 *
 * @param page Playwright page, in a browser context that has not opened the app yet.
 * @returns The granted account's durable user id.
 */
export async function grantAdminToSelf(page: Page): Promise<number> {
  await gotoPage(page, '/')
  await expect(page.getByTestId('conn-state')).toHaveText('connected')
  await expect(page.getByTestId('self-user')).not.toBeEmpty()
  const marker = await page.getByTestId('self-user-id').textContent()
  const userId = Number(marker)
  expect(userId).toBeGreaterThan(0)

  await setAdmin(userId, true)
  // The command channel answers when the daemon has written the grant, which is
  // not the same as this browser knowing about it. The daemon re-sends the
  // handshake response to the granted user's live connections, and the shell
  // draws the admin entry from it — so the gear appearing is the proof that the
  // grant reached this page, rather than merely the server.
  await expect(page.getByTestId('nav-admin')).toBeVisible()

  return userId
}
