import { createStandCommandChannel } from './standCommand.mjs'

const sendCommand = createStandCommandChannel({ timeoutMs: 5_000 })

/**
 * Turn the node's admin view mode on or off for a browser test.
 *
 * @param {boolean} enabled Whether non-admin visitors may look at the admin section.
 * @returns {Promise<void>}
 */
export async function setAdminViewMode(enabled) {
  await sendCommand('test:admin-view-mode', { enabled })
}
