// The stand gateway's own handles (HIL-922), shared by every resident's helper. The
// gateway answers every non-mail channel on the stand (standTelegram.mjs,
// standSms.mjs); what lives here is what a spec says to the gateway itself rather
// than to one channel: how a provider route answers the product's next call.
//
// Nothing here drives a browser or imports Playwright: the helpers ride the demo
// runner, which refuses a second copy of @playwright/test, so a refusal of the
// gateway fails the spec with this module's own error instead of an expect.

import process from 'node:process'

/** Where the runner reaches the stand gateway. */
export const STAND_GATEWAY_URL =
  process.env.STAND_GATEWAY_URL ?? 'https://stand-gateway:18000'

/**
 * How a provider route answers one call, as a spec dictates it. Every lever is
 * optional and they combine; none at all answers as usual.
 *
 * @typedef {object} GatewayBehavior
 * @property {number} [status] Refuse the call with this status (400–599) without asking the resident.
 * @property {number} [delayMs] Hold the answer's bytes back this many milliseconds after the call arrives.
 * @property {boolean} [cut] Send the headers and the first half of the body, then close the connection.
 * @property {number} [holdMs] Keep the connection open this many milliseconds after the answer's last byte.
 */

/**
 * Call one test route on the stand gateway, failing the spec on the spot when it
 * refuses.
 *
 * @param {string} path The route path.
 * @param {unknown} payload The JSON body.
 * @returns {Promise<void>}
 * @throws {Error} When the gateway answers anything but 2xx.
 */
export async function postToGateway(path, payload) {
  const response = await globalThis.fetch(`${STAND_GATEWAY_URL}${path}`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(payload),
  })
  if (!response.ok) {
    throw new Error(`stand gateway refused ${path}: ${response.status}`)
  }
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
 * @param {string} path The provider route the product calls, e.g. `/telegram/sendVerificationMessage`.
 * @param {string} key The value of the call the declaration is keyed by.
 * @param {GatewayBehavior} behavior The levers to dictate.
 * @returns {Promise<void>}
 */
export async function dictateGatewayBehavior(path, key, behavior) {
  await postToGateway('/test/behavior', { path, key, ...behavior })
}
