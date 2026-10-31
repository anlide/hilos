import { createStandCommandChannel } from './standCommand.mjs'

const sendCommand = createStandCommandChannel({ timeoutMs: 5_000 })

/**
 * Refuse every window of one table on this node until cleared.
 * The caller owns this node-wide lever and must await cleanup even on failure.
 *
 * @param {string} tableKey The table's wire key, or empty to clear the refusal.
 * @returns {Promise<void>}
 */
export async function refuseTableWindow(tableKey) {
  await sendCommand('test:table:refuse', { tableKey })
}

/** Clear the test-owned refusal; the next window is built normally. */
export async function clearTableRefusal() {
  await refuseTableWindow('')
}
