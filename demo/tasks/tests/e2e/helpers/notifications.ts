import net from 'node:net'
import { randomBytes } from 'node:crypto'

// The daemon command channel — the same socket the CLI test:notification:emit
// command speaks. The Playwright runner has no PHP, so the e2e emits over the
// wire directly; the emit still runs where a product caller's would, in a worker
// (AbstractHilosIndexAgent), so it writes the durable row and fans the live
// in-app signal exactly as the product path does.
const COMMAND_HOST = process.env.COMMAND_HOST ?? 'tasks-daemon-test'
const COMMAND_PORT = Number(process.env.COMMAND_PORT ?? 8094)

// Past the emit's own work — a durable write and a group fan-out — so a slow box
// comes back as the agent's answer rather than as a socket timeout.
const REPLY_TIMEOUT_MS = 10_000

const EMIT_COMMAND = 'test:notification:emit'

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
}

/**
 * Emits one notification to a user through the live daemon.
 *
 * The reply's other half — the channels that got a delivery row — is not
 * returned here: this demo activates NOTIFICATIONS without NOTIFICATION_DELIVERY,
 * so no delivery journal is mounted and the agent always reads back an empty
 * list. Handing a caller a field that cannot say anything would invite a channel
 * assertion this stand can never answer; the chat demo, which does deliver, is
 * where that half is exercised.
 *
 * @param userId Recipient's durable user id.
 * @param draft The notification to emit.
 * @returns Id of the persisted notification — the bell row's stable selector suffix.
 */
export async function emitNotification(
  userId: number,
  draft: NotificationDraft,
): Promise<number> {
  const reply = await sendCommand(EMIT_COMMAND, { userId, ...draft })

  return Number(reply.notificationId)
}

/**
 * Sends one command over the daemon command channel and resolves its payload.
 *
 * @param command Command-channel wire name.
 * @param payload Request payload.
 * @returns The reply payload on success.
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
      reject(
        new Error(
          `No command-channel reply to ${command} within ${REPLY_TIMEOUT_MS}ms`,
        ),
      )
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
        payload?: Record<string, unknown> & { message?: string }
      }
      if (reply.status === 'ok') {
        resolve(reply.payload ?? {})
      } else {
        reject(
          new Error(
            `${command} failed: ${reply.payload?.message ?? 'unknown error'}`,
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
