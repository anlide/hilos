// Reconnect backoff: exponential growth with full jitter, so a fleet of
// clients dropped by one restart does not reconnect in lockstep.

export interface ReconnectOptions {
  /** Delay cap for the first retry, before jitter. Default 1000. */
  baseDelayMs?: number
  /** Upper bound for any retry delay. Default 30000. */
  maxDelayMs?: number
  /** Exponential growth factor per attempt. Default 2. */
  factor?: number
}

export const DEFAULT_RECONNECT_OPTIONS: Required<ReconnectOptions> = {
  baseDelayMs: 1000,
  maxDelayMs: 30000,
  factor: 2,
}

/**
 * Delay before reconnect attempt `attempt` (0-based): full jitter over the
 * exponential cap — uniformly random in `[0, min(max, base * factor^attempt)]`.
 *
 * @param attempt Reconnect attempt number, 0-based; reset after a successful connect.
 * @param options Resolved backoff parameters (defaults already applied).
 * @param random Uniform `[0, 1)` source; injectable for deterministic tests.
 */
export function computeBackoffDelay(
  attempt: number,
  options: Required<ReconnectOptions>,
  random: () => number,
): number {
  const cap = Math.min(
    options.maxDelayMs,
    options.baseDelayMs * options.factor ** attempt,
  )

  return random() * cap
}
