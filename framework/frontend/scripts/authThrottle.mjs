import { createStandCommandChannel } from './standCommand.mjs'

// The Binance browser stand pins these existing policy knobs in compose.
// Email uses the session ceiling, while each action has its own IP budget.
export const THROTTLE_SESSION_LIMIT = 10
export const THROTTLE_IP_LIMIT = 30
export const THROTTLE_WINDOW_MS = 60_000
export const THROTTLE_REASON =
  'Too many attempts. Please wait a moment and try again.'

const command = createStandCommandChannel({ timeoutMs: 15_000 })

/** Clear counters and durable blocks through their real owner before/after a proof. */
export async function resetAuthThrottle() {
  await command('test:throttle:reset', {})
}
