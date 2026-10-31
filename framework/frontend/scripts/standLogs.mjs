import { createStandCommandChannel } from './standCommand.mjs'

const WALK_COMMAND = 'test:logs:walk'

// Past the walk itself: the agent reads the whole store before it answers, so a
// slow box comes back as the agent's answer rather than as a socket timeout.
const REPLY_TIMEOUT_MS = 10_000

/**
 * Ask a node's log-store agent for a full walk of its store and its index, now.
 *
 * A line reaches the Logs screen through the walk of the store and the push of
 * its index, each on an interval of its own. This brings the next walk and the
 * next frame to now, out of turn: the index is sent whether or not the walk
 * changed it. The intervals themselves do not change.
 *
 * An accepted command can precede the write of a line it was meant to catch (an
 * answer that is accepted before the work behind it ran), so a spec waiting for
 * such a line walks before every probe rather than once.
 *
 * @param {string} [host] Cluster node whose log-store agent walks, instead of COMMAND_HOST.
 * @returns {Promise<void>}
 * @throws {Error} When the command is refused or the agent does not answer in time.
 */
export async function walkLogStore(host) {
  await createStandCommandChannel({ timeoutMs: REPLY_TIMEOUT_MS, host })(
    WALK_COMMAND,
    {},
  )
}
