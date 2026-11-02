// The test-only shift affects only this member's cluster clock probe replies.
import { createStandCommandChannel } from './standCommand.mjs'

const CLOCK_SHIFT_COMMAND = 'test:cluster:clock:shift'
const REPLY_TIMEOUT_MS = 15_000

/**
 * Set one member's probe-answer shift and read back the master's applied value.
 *
 * @param {{nodeHosts: Readonly<Record<string, string>>}} stand The running stand's command hosts.
 * @param {string} node The member whose clock replies to shift.
 * @param {number} ms Signed milliseconds; zero removes the test shift.
 * @returns {Promise<number>} The shift read back from that member.
 * @throws {Error} When the member is unknown, the command refuses, or its readback is invalid.
 */
export async function shiftClusterNodeClock(stand, node, ms) {
  const host = stand.nodeHosts[node]
  if (!host) throw new Error(`No command host for cluster node ${node}`)
  const reply = await createStandCommandChannel({
    host,
    timeoutMs: REPLY_TIMEOUT_MS,
  })(CLOCK_SHIFT_COMMAND, { shiftMs: ms })
  if (
    typeof reply.shiftMs !== 'number' ||
    !Number.isInteger(reply.shiftMs) ||
    reply.shiftMs !== ms
  ) {
    throw new Error(
      `Invalid ${CLOCK_SHIFT_COMMAND} reply: ${JSON.stringify(reply)}`,
    )
  }
  return reply.shiftMs
}
