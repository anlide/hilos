import { createCommandChannel } from '../../../../../framework/frontend/scripts/commandChannel.mjs'

// The daemon command channel — the same socket the CLI test:throttle:reset
// command speaks. The Playwright runner has no PHP, so the e2e asks over the wire
// directly; the master parks the request for the throttle agent, the one process
// allowed to clear the counters, wherever the placement put it (HIL-1280).
const COMMAND_HOST = process.env.COMMAND_HOST ?? 'ecommerce-shop-daemon-test'
const COMMAND_PORT = Number(process.env.COMMAND_PORT ?? 8094)

// The reply comes from an agent, not from the master, so the caller waits the
// MIDDLE of the command channel's three windows — a hand-kept copy of
// CommandChannelWindows::CALLER_WAIT_SECONDS, as in helpers/protectedMode.ts.
const REPLY_TIMEOUT_MS = 15_000

const THROTTLE_RESET_COMMAND = 'test:throttle:reset'

const sendCommand = createCommandChannel({
  host: COMMAND_HOST,
  port: COMMAND_PORT,
  timeoutMs: REPLY_TIMEOUT_MS,
})

/**
 * Forgets every anti-abuse counter and stored block on the stand, so the next
 * attempt starts from an address and a session nothing is held against.
 *
 * The counters are the whole node's: the IP scope is shared by every spec that
 * signs in from this runner, so a spec that drives a key into a block clears it
 * again in its `finally`, failed test or not. A master or agent that refuses the
 * command rejects the returned promise.
 */
export async function resetThrottle(): Promise<void> {
  await sendCommand(THROTTLE_RESET_COMMAND, {})
}
