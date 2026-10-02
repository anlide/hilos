import { createCommandChannel } from '../../../../../framework/frontend/scripts/commandChannel.mjs'

// The daemon command channel — the same socket the CLI test:admin-view-mode command
// speaks. The Playwright runner has no PHP, so the e2e pulls the lever over the wire
// directly; the master answers it itself and writes the node's admin view mode row,
// which every worker reads when it stamps a session response or judges a page
// (HIL-1249).
const COMMAND_HOST = process.env.COMMAND_HOST ?? 'online-testing-daemon-test'
const COMMAND_PORT = Number(process.env.COMMAND_PORT ?? 8094)

// The master answers out of its own memory, without parking the request for an
// agent, so the reply is as quick as the socket.
const REPLY_TIMEOUT_MS = 5_000

const ADMIN_VIEW_MODE_COMMAND = 'test:admin-view-mode'

const sendCommand = createCommandChannel({
  host: COMMAND_HOST,
  port: COMMAND_PORT,
  timeoutMs: REPLY_TIMEOUT_MS,
})

/**
 * Turns the node's admin view mode on or off: on, a non-admin — a guest too —
 * may open the admin section to look (HIL-1253).
 *
 * The mode is the whole node's, which is safe only because the online-testing
 * suite runs in a single worker (playwright.config.ts: CI → workers: 1); a spec
 * that turns it on turns it off in its `afterEach`, failed test or not. The flip
 * is not sent to open tabs: a tab learns the mode on its next handshake, so a
 * spec opens its page again (`gotoPage`) after pulling the lever. A master that
 * refuses the command rejects the returned promise.
 *
 * @param enabled Whether the node is in the admin view mode.
 */
export async function setAdminViewMode(enabled: boolean): Promise<void> {
  await sendCommand(ADMIN_VIEW_MODE_COMMAND, { enabled })
}
