import { createCommandChannel } from '../../../../../framework/frontend/scripts/commandChannel.mjs'

// Playwright has no PHP CLI, so the test asks the live session holder through
// the same command channel as the test-only CLI command.
const COMMAND_HOST = process.env.COMMAND_HOST ?? 'polls-daemon-test'
const COMMAND_PORT = Number(process.env.COMMAND_PORT ?? 8094)
const REPLY_TIMEOUT_MS = 10_000
const FORCE_PURGE_COMMAND = 'test:account:force-purge'

const sendCommand = createCommandChannel({
  host: COMMAND_HOST,
  port: COMMAND_PORT,
  timeoutMs: REPLY_TIMEOUT_MS,
})

/**
 * Age and erase a standing account-deletion request through the live daemon.
 *
 * @param userId User whose account is scheduled for deletion.
 */
export async function forceAccountPurge(userId: number): Promise<void> {
  await sendCommand(FORCE_PURGE_COMMAND, { userId })
}
