import { createStandCommandChannel } from './standCommand.mjs'

// The middle of the command channel's nested windows, a hand-kept copy of
// CommandChannelWindows::CALLER_WAIT_SECONDS. The session holder keeps the reply until the
// erasure ends or its own shorter wait runs out, so the caller waits as long as the CLI does.
const REPLY_TIMEOUT_MS = 15_000

const sendCommand = createStandCommandChannel({ timeoutMs: REPLY_TIMEOUT_MS })

/**
 * Age and erase a standing account-deletion request through the live daemon.
 *
 * @param {number} userId User whose account is scheduled for deletion.
 * @returns {Promise<void>}
 * @throws {Error} When the reply names a stage other than a finished erasure.
 */
export async function forceAccountPurge(userId) {
  const reply = await sendCommand('test:account:force-purge', { userId })
  if (reply.outcome !== 'completed') {
    throw new Error(
      `test:account:force-purge did not finish the erasure of user ${userId}: ${reply.outcome}`,
    )
  }
}
