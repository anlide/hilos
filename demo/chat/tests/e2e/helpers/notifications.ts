import { createCommandChannel } from '../../../../../framework/frontend/scripts/commandChannel.mjs'

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
