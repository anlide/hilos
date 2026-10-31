import { randomBytes } from 'node:crypto'

import { createCommandChannel } from '../../../../../framework/frontend/scripts/commandChannel.mjs'
import {
  deriveTimeoutScale,
  readHostPressure,
} from '../../../../../framework/frontend/scripts/timeout-scale.mjs'

// The daemon command channel — the same socket the CLI test:log:append,
// test:logs:rotate and test:logs:walk commands speak. The Playwright runner has
// no PHP, so the e2e asks over the wire directly; the lines are still written by
// the log-store agent the ordinary way, so the master files them under the
// agent's own log and a follower sees exactly what it would see in production.
// Appending to the file from here instead would prove only that a file grew. The
// rotation and the walk are that agent's own work too, asked of it out of turn.
//
// The round trip shared with adminGrant.ts lives in the framework's node-side
// scripts; this helper keeps only the binance-btc-tracker daemon's address, the
// command names and the reply window.
const COMMAND_HOST =
  process.env.COMMAND_HOST ?? 'binance-btc-tracker-daemon-test'
const COMMAND_PORT = Number(process.env.COMMAND_PORT ?? 8094)

// Past each command's own work — the agent writes appended lines synchronously
// and the master files each of them; a rotation moves the live files and walks
// the whole store — so a slow box comes back as the agent's answer rather than
// as a socket timeout.
const REPLY_TIMEOUT_MS = 10_000

const APPEND_COMMAND = 'test:log:append'

const ROTATE_COMMAND = 'test:logs:rotate'

const WALK_COMMAND = 'test:logs:walk'

const channels = new Map<string, ReturnType<typeof createCommandChannel>>()

function channel(host: string): ReturnType<typeof createCommandChannel> {
  let send = channels.get(host)
  if (!send) {
    send = createCommandChannel({
      host,
      port: COMMAND_PORT,
      timeoutMs: REPLY_TIMEOUT_MS,
    })
    channels.set(host, send)
  }

  return send
}

/**
 * The cap a wait on an appended line reaching the screen gets, in milliseconds.
 *
 * Its own cap rather than the config's `expect`, for the reason a wait on an
 * intercepted letter has one: the chain is the longest this suite waits on, and
 * none of it is DOM work. The agent prints the line, the master lifts it out of
 * the worker's output and files it, the owner of the directory notices the file
 * grew on its once-a-second round, and only then does a frame cross the socket.
 * `expect` is the shortest cap there is and describes something else entirely.
 *
 * Scaled by the same host-pressure factor every Playwright cap is, so a starved
 * box makes this slower rather than red.
 */
export const TAIL_ARRIVAL_TIMEOUT_MS =
  15_000 *
  deriveTimeoutScale({
    override: process.env.HILOS_E2E_TIMEOUT_SCALE,
    ...readHostPressure(),
  }).factor

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
 * Asks the live daemon's log-store agent to write lines into its own log.
 *
 * The agent numbers them, so lines of one call can be told apart from each other
 * and from the lines an earlier call with the same message left behind — the log
 * file outlives a test run.
 *
 * @param message Text of every appended line, which the agent numbers.
 * @param count How many lines to write.
 * @param host Daemon whose log-store agent should write the lines.
 * @returns How many lines the agent reports writing.
 */
export async function appendLogLines(
  message: string,
  count: number,
  host = COMMAND_HOST,
): Promise<number> {
  const reply = await channel(host)(APPEND_COMMAND, { message, count })

  return Number(reply.count)
}

/**
 * Asks the live daemon's log-store agent for the rotation attempt an axis makes,
 * now.
 *
 * The attempt is the policy's own: a new batch, the cleanup of confirmed
 * batches, a full walk and the index sent at once. No axis has to come due for
 * it, and none is moved by it beyond what any attempt does — the age axis counts
 * from here. A refusal — no readable store, rotation off on the node, a batch
 * that could not be made — rejects with the server's words.
 *
 * @param host Daemon whose log-store agent should rotate.
 * @returns How many live files the rotation moved; 0 when there was nothing to
 *   put in a batch.
 */
export async function rotateLogs(host = COMMAND_HOST): Promise<number> {
  const reply = await channel(host)(ROTATE_COMMAND, {})

  return Number(reply.moved)
}

/**
 * Asks the live daemon's log-store agent for a full walk and its index, now.
 *
 * The index is sent whether or not the walk changed it, so a verdict that moved
 * with a setting — retention, the undo window — or a batch the carrier has just
 * put into the archive reaches the screen without waiting for the live walk,
 * the full walk or the keepalive.
 *
 * @param host Daemon whose log-store agent should walk.
 */
export async function walkLogs(host = COMMAND_HOST): Promise<void> {
  await channel(host)(WALK_COMMAND, {})
}

/**
 * Mints a marker no other run and no other line of this one can carry.
 *
 * @param label Human-readable prefix, so a stray line names the test that wrote it.
 * @returns The marker.
 */
export function logMarker(label: string): string {
  return `${label}-${randomBytes(6).toString('hex')}`
}
