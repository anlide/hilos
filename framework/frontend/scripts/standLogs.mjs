import { randomBytes } from 'node:crypto'

import { createStandCommandChannel } from './standCommand.mjs'

// The daemon command channel — the same socket the CLI test:log:append,
// test:logs:rotate and test:logs:walk commands speak. The Playwright runner has
// no PHP, so the e2e asks over the wire directly; the lines are still written by
// the log-store agent the ordinary way, so the master files them under the
// agent's own log and a follower sees exactly what it would see in production.
// Appending to the file from here instead would prove only that a file grew. The
// rotation and the walk are that agent's own work too, asked of it out of turn.

const APPEND_COMMAND = 'test:log:append'

const ROTATE_COMMAND = 'test:logs:rotate'

const WALK_COMMAND = 'test:logs:walk'

// Past each command's own work — the agent writes appended lines synchronously
// and the master files each of them; a rotation moves the live files and walks
// the whole store; a walk reads the whole store before it answers — so a slow
// box comes back as the agent's answer rather than as a socket timeout.
const REPLY_TIMEOUT_MS = 10_000

/**
 * The stream the appended lines land in.
 *
 * Written out rather than read from the reply because the rule behind it — an
 * agent's log file is `agent-` plus its agent id — is private to WorkerServer,
 * and putting it on the wire for a test's sake would declare an internal rule a
 * contract.
 */
export const FOLLOWED_STREAM = 'agent-hilos_log_store.log'

/**
 * Ask a node's log-store agent to write lines into its own log.
 *
 * The agent numbers them, so lines of one call can be told apart from each other
 * and from the lines an earlier call with the same message left behind — the log
 * file outlives a test run.
 *
 * @param {string} message Text of every appended line, which the agent numbers.
 * @param {number} count How many lines to write.
 * @param {string} [host] Cluster node whose log-store agent writes, instead of COMMAND_HOST.
 * @returns {Promise<number>} How many lines the agent reports writing.
 * @throws {Error} When the command is refused or the agent does not answer in time.
 */
export async function appendLogLines(message, count, host) {
  const reply = await createStandCommandChannel({
    timeoutMs: REPLY_TIMEOUT_MS,
    host,
  })(APPEND_COMMAND, { message, count })

  return Number(reply.count)
}

/**
 * Ask a node's log-store agent for the rotation attempt an axis makes, now.
 *
 * The attempt is the policy's own: a new batch, the cleanup of confirmed
 * batches, a full walk and the index sent at once. No axis has to come due for
 * it, and none is moved by it beyond what any attempt does — the age axis counts
 * from here. A refusal — no readable store, rotation off on the node, a batch
 * that could not be made — rejects with the server's words.
 *
 * @param {string} [host] Cluster node whose log-store agent rotates, instead of COMMAND_HOST.
 * @returns {Promise<number>} How many live files the rotation moved; 0 when there
 *   was nothing to put in a batch.
 * @throws {Error} When the command is refused or the agent does not answer in time.
 */
export async function rotateLogs(host) {
  const reply = await createStandCommandChannel({
    timeoutMs: REPLY_TIMEOUT_MS,
    host,
  })(ROTATE_COMMAND, {})

  return Number(reply.moved)
}

/**
 * Ask a node's log-store agent for a full walk of its store and its index, now.
 *
 * A line reaches the Logs screen through the walk of the store and the push of
 * its index, each on an interval of its own. This brings the next walk and the
 * next frame to now, out of turn: the index is sent whether or not the walk
 * changed it, so a verdict that moved with a setting — retention, the undo
 * window — or a batch the carrier has just put into the archive reaches the
 * screen without waiting for the live walk, the full walk or the keepalive. The
 * intervals themselves do not change.
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

/**
 * Mint a marker no other run and no other line of this one can carry.
 *
 * @param {string} label Human-readable prefix, so a stray line names the test that wrote it.
 * @returns {string} The marker.
 */
export function logMarker(label) {
  return `${label}-${randomBytes(6).toString('hex')}`
}
