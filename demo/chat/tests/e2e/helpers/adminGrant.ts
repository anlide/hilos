import net from 'node:net'
import { randomBytes } from 'node:crypto'
import { expect, type Page } from '@playwright/test'

import { signUp } from './session'

// The daemon command channel — the same socket the CLI admin:grant / admin:revoke
// commands speak. The Playwright runner has no PHP, so the e2e drives the grant
// over the wire directly; this still exercises the real CommandServer parking,
// the sessions library that answers the pair (HIL-729), and the users-table fan-out.
const COMMAND_HOST = process.env.COMMAND_HOST ?? 'chat-test'
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
/**
 * Registers a fresh account and grants it admin over the command channel.
 *
 * The framework admin surface (/hilos/*) is closed by default (HIL-441:
 * pages inherit the ADMIN access level), so any spec that navigates there
 * signs up and takes the grant first. The account is a per-test throwaway,
 * so no revoke is needed afterwards.
 *
 * @param page Playwright page still anonymous in its browser context.
 * @returns The granted account's durable user id.
 */
export async function signUpAdmin(page: Page): Promise<number> {
  const { userId } = await signUp(page)
  await setAdmin(userId, true)
  // The command channel answers when the daemon has written the grant, which is
  // not the same as this browser knowing about it. The daemon re-sends the
  // handshake response to the granted user's live connections, and the shell
  // draws the admin entry from it — so the gear appearing is the proof that the
  // grant reached this page, rather than merely the server.
  await expect(page.getByTestId('nav-admin')).toBeVisible()

  return userId
}

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
            `${admin ? COMMAND_GRANT : COMMAND_REVOKE} failed: ` +
              `${reply.payload?.message ?? 'unknown error'}`,
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
