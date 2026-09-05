// A repair that has been going on for a while: the same reconnect, merely longer
// than expected. Told apart from a fresh one by the backoff pauses themselves —
// once the exponential has reached its ceiling there is nothing left for it to
// grow into, and every further attempt waits the same capped delay (HIL-831).
import type { ReconnectOptions } from './backoff.js'

/**
 * Whether the pause before reconnect attempt `attempt` has reached the ceiling —
 * the point from which the retries no longer get further apart, and the repair
 * has stopped looking like a hiccup.
 *
 * Read off the backoff parameters rather than off a stopwatch or an attempt
 * count, so that there is no second magic number beside them: move the ceiling
 * or the factor and the threshold moves with them. On the shipped defaults
 * (`baseDelayMs` 1000, `maxDelayMs` 30000, `factor` 2) it first holds at attempt
 * 5 — the sixth dial — which is 15 to 31 seconds of a live outage once full
 * jitter is counted in.
 *
 * @param attempt Reconnect attempt number, 0-based; reset after a successful connect.
 * @param options Resolved backoff parameters (defaults already applied).
 */
export function isReconnectDragging(
  attempt: number,
  options: Required<ReconnectOptions>,
): boolean {
  return options.baseDelayMs * options.factor ** attempt >= options.maxDelayMs
}

/**
 * The words the mark carries — its tooltip, and its text for a screen reader.
 *
 * Kept in the core for the reason `RT_STALENESS_COPY` next door is: the three
 * view packages must not drift into three different sentences for one state.
 *
 * The sentence states the fact and nothing else. That the link is gone the
 * person already knows from the amber icon and the dead buttons; the only new
 * thing here is that it is taking a while. Advice to check the network was
 * turned down (it sends someone to fix a working line when it is the server that
 * fell), and so was a warning that it may not recover on its own (it reads as a
 * verdict, while the attempts in fact go on forever and the link may come back
 * at any second).
 */
export const RECONNECT_DRAGGING_COPY = {
  dragging: 'still trying; this is taking longer than usual.',
} as const
