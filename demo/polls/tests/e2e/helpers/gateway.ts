import { expect } from '@playwright/test'

// The stand gateway's own handles (HIL-922), shared by every resident's helper. The
// gateway answers every non-mail channel on the stand (helpers/telegram.ts,
// helpers/sms.ts); what lives here is what a spec says to the gateway itself rather
// than to one channel: how a provider route answers the product's next call.
//
// The chat and polls runners reach the gateway (STAND_GATEWAY_URL and the CA it
// trusts are set on both), so the helper has a twin in
// demo/chat/tests/e2e/helpers/, and the two do not drift apart.

/** Where the runner reaches the stand gateway. */
export const STAND_GATEWAY_URL =
  process.env.STAND_GATEWAY_URL ?? 'https://stand-gateway:18000'

/**
 * How a provider route answers one call, as a spec dictates it. Every lever is
 * optional and they combine; none at all answers as usual.
 */
export interface GatewayBehavior {
  /** Refuse the call with this status (400–599) without asking the resident. */
  status?: number
  /** Hold the answer's bytes back this many milliseconds after the call arrives. */
  delayMs?: number
  /** Send the headers and the first half of the body, then close the connection. */
  cut?: boolean
  /** Keep the connection open this many milliseconds after the answer's last byte. */
  holdMs?: number
}

/**
 * Call one test route on the stand gateway, failing the spec on the spot when it
 * refuses.
 *
 * @param path The route path.
 * @param payload The JSON body.
 */
export async function postToGateway(
  path: string,
  payload: unknown,
): Promise<void> {
  const response = await fetch(`${STAND_GATEWAY_URL}${path}`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(payload),
  })
  expect(response.ok, `stand gateway refused ${path}`).toBe(true)
}

/**
 * Dictate how a provider route answers the next call carrying a key.
 *
 * One declaration answers exactly one call; declarations for the same route and
 * key queue up, so three refusals make a provider that fails three retries and a
 * sequence plays out in the order it was declared. The key is the value of the
 * call the spec coined itself (for Telegram and SMS, the number, as `uniquePhone`
 * produces it), which is what keeps the declaration from being spent by a
 * neighboring worker's call — never reset the gateway to isolate a test.
 *
 * Deadlines are kept on the gateway's tick: a delay or a hold never ends early and
 * may end a tick late, so a spec measures only the lower bound.
 *
 * @param path The provider route the product calls, e.g. `/telegram/sendVerificationMessage`.
 * @param key The value of the call the declaration is keyed by.
 * @param behavior The levers to dictate.
 */
export async function dictateGatewayBehavior(
  path: string,
  key: string,
  behavior: GatewayBehavior,
): Promise<void> {
  await postToGateway('/test/behavior', { path, key, ...behavior })
}
