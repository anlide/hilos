import { createStandCommandChannel } from './standCommand.mjs'

const sendCommand = createStandCommandChannel({ timeoutMs: 10_000 })

/**
 * Age and erase a standing account-deletion request through the live daemon.
 *
 * @param {number} userId User whose account is scheduled for deletion.
 * @returns {Promise<void>}
 */
export async function forceAccountPurge(userId) {
  await sendCommand('test:account:force-purge', { userId })
}
