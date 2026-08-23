import { expect } from '@playwright/test'
import { waitForAnyMailTo } from './mail'

// The stand's window into the messenger (HIL-492, HIL-653). A Telegram code has
// no inbox a spec can open, so the stand gateway answers the Gateway's provider
// API and forwards every code it is given to Mailpit as a letter addressed
// <E.164>@telegram.stand — read by this helper and by a person out of the same
// mailbox as mail and SMS (helpers/mail.ts).
//
// Reading the code back through the transport is the point: the daemon really
// built a request, really posted it, and the gateway really refused it without a
// bearer token. A helper that read the challenge out of the database instead
// would pass with the whole transport removed.
//
// What remains of the gateway's own API here is arrangement only: what arrived is
// no longer asked of it.
const STAND_GATEWAY_URL =
  process.env.STAND_GATEWAY_URL ?? 'http://stand-gateway-test:18000'

/** The mail domain the gateway re-addresses a caught Telegram code under. */
const TELEGRAM_MAIL_DOMAIN = 'telegram.stand'

/**
 * Wait for the code the Gateway delivered to one number, and return it.
 *
 * The Gateway carries a code rather than free text, so the letter's subject is
 * the code — read off the subject and not the body, which names the recipient
 * above the text and would answer a bare digit-run with the phone number.
 *
 * @param phone Recipient number in canonical E.164, as `uniquePhone` produces it.
 * @returns The plaintext verification code.
 * @throws Error When the delivered message carries no code.
 */
export async function waitForTelegramCode(phone: string): Promise<string> {
  const mail = await waitForAnyMailTo(`${phone}@${TELEGRAM_MAIL_DOMAIN}`)
  const code = /(\d{4,})/.exec(mail.subject)?.[1]
  if (code === undefined) {
    throw new Error(`the Telegram message to ${phone} carried no code`)
  }

  return code
}

/**
 * Declare whether a number is on Telegram at all.
 *
 * The one arrangement a Telegram spec cannot make any other way: a number nobody
 * put on Telegram is the ordinary case in the real world, and the flow's whole
 * promise is that it costs the person nothing — no code spent, SMS still available.
 *
 * @param phone The number to declare.
 * @param reachable Whether `checkSendAbility` should accept it.
 */
export async function setTelegramReachable(
  phone: string,
  reachable: boolean,
): Promise<void> {
  await post('/telegram/test/reachable', { phone_number: phone, reachable })
}

/**
 * Forget every declared number.
 *
 * A whole-store wipe, so it is for a spec that genuinely needs a clean slate and not
 * for ordinary isolation: everything the gateway holds is keyed by number, and a
 * unique number per test isolates it already. Calling this under parallel workers
 * would clear state a neighbouring spec is still using.
 */
export async function resetTelegram(): Promise<void> {
  await post('/test/reset', {})
}

/**
 * Call one test route on the stand gateway.
 *
 * @param path The route path.
 * @param payload The JSON body.
 */
async function post(path: string, payload: unknown): Promise<void> {
  const response = await fetch(`${STAND_GATEWAY_URL}${path}`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(payload),
  })
  expect(response.ok, `stand gateway refused ${path}`).toBe(true)
}
