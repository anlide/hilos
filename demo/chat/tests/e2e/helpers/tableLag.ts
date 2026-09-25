import { createCommandChannel } from '../../../../../framework/frontend/scripts/commandChannel.mjs'

// The daemon command channel — the same socket the CLI test:table:lag command
// speaks. The Playwright runner has no PHP, so the e2e sets the lag over the wire
// directly; the master answers it itself and writes the node's lag row, which the
// workers serving the tables read on every tick (HIL-1020).
const COMMAND_HOST = process.env.COMMAND_HOST ?? 'chat-test'
const COMMAND_PORT = Number(process.env.COMMAND_PORT ?? 8094)

// The master answers out of its own memory, without parking the request for an
// agent, so the reply is as quick as the socket.
const REPLY_TIMEOUT_MS = 5_000

const LAG_COMMAND = 'test:table:lag'

const sendCommand = createCommandChannel({
  host: COMMAND_HOST,
  port: COMMAND_PORT,
  timeoutMs: REPLY_TIMEOUT_MS,
})

/**
 * Holds this node's table answers back by an artificial lag, until it is set again.
 *
 * Each call names the whole state: a lag left out is a lag turned off. The lag is
 * node-wide, which is safe only because the chat suite runs in a single worker;
 * a spec that sets one clears it in its `afterEach`, failed test or not.
 *
 * @param lags How long to hold each answer, in milliseconds.
 * @param lags.windowMs How long a changed table window is held back.
 * @param lags.facetsMs How long the counts beside the filter options are held back.
 */
export async function setTableLag({
  windowMs = 0,
  facetsMs = 0,
}: {
  windowMs?: number
  facetsMs?: number
}): Promise<void> {
  await sendCommand(LAG_COMMAND, { windowMs, facetsMs })
}

/**
 * Takes both lags off; whatever they were holding goes out at the next worker tick.
 */
export async function clearTableLag(): Promise<void> {
  await setTableLag({})
}
