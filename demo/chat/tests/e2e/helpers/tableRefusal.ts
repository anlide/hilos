import { createCommandChannel } from '../../../../../framework/frontend/scripts/commandChannel.mjs'

// The daemon command channel — the same socket the CLI test:table:refuse command
// speaks. The Playwright runner has no PHP, so the e2e names the refused table over
// the wire directly; the master answers it itself and writes the node's refusal
// row, which the workers read every time they build a window (HIL-1131).
const COMMAND_HOST = process.env.COMMAND_HOST ?? 'chat-test'
const COMMAND_PORT = Number(process.env.COMMAND_PORT ?? 8094)

// The master answers out of its own memory, without parking the request for an
// agent, so the reply is as quick as the socket.
const REPLY_TIMEOUT_MS = 5_000

const REFUSE_COMMAND = 'test:table:refuse'

const sendCommand = createCommandChannel({
  host: COMMAND_HOST,
  port: COMMAND_PORT,
  timeoutMs: REPLY_TIMEOUT_MS,
})

/**
 * Refuses every window of one table on this node, as if the server could not build
 * it, until the refusal is cleared.
 *
 * The refusal is node-wide, which is safe only because the chat suite runs in a
 * single worker; a spec that sets one clears it in its `afterEach`, failed test or
 * not. A master that refuses the command rejects the returned promise.
 *
 * @param tableKey The table's key on the wire, as a page names it.
 */
export async function refuseTableWindow(tableKey: string): Promise<void> {
  await sendCommand(REFUSE_COMMAND, { tableKey })
}

/**
 * Takes the refusal off; the very next window of the table is built again.
 */
export async function clearTableRefusal(): Promise<void> {
  await refuseTableWindow('')
}
