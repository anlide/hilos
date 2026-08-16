import { expect } from '@playwright/test'

// The stand's window into the messenger (HIL-492). A Telegram code has no inbox a
// spec can open, so the mock Gateway carries test routes beside its provider ones
// and this helper is the client for them — the Telegram analogue of helpers/mail.ts
// (Mailpit's HTTP API) and helpers/sms.ts (the stub provider's .txt artifacts).
//
// Reading the code back through the transport is the point: the daemon really built
// a request, really posted it, and the mock really refused it without a bearer token.
// A helper that read the challenge out of the database instead would pass with the
// whole transport removed.
const MOCK_URL = process.env.TELEGRAM_MOCK_URL ?? 'http://telegram-mock-test:18000'

/** One message the mock recorded as delivered to a number. */
interface TelegramMessage {
  readonly phone_number: string
  readonly code: string
  readonly sender_username: string
}

/**
 * Wait for the code the Gateway delivered to one number, and return it.
 *
 * @param phone Recipient number in canonical E.164, as `uniquePhone` produces it.
 * @returns The plaintext verification code.
 */
export async function waitForTelegramCode(phone: string): Promise<string> {
  await expect
    .poll(async () => (await readMessages(phone)).length > 0, {
      message: `no Telegram message to ${phone} reached the mock Gateway`,
    })
    .toBe(true)

  const messages = await readMessages(phone)

  return messages[messages.length - 1].code
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
  await post('/test/reachable', { phone_number: phone, reachable })
}

/**
 * Forget every delivered message and every declared number.
 *
 * A whole-store wipe, so it is for a spec that genuinely needs a clean slate and not
 * for ordinary isolation: everything the mock holds is keyed by number, and a unique
 * number per test isolates it already. Calling this under parallel workers would
 * clear state a neighbouring spec is still using.
 */
export async function resetTelegram(): Promise<void> {
  await post('/test/reset', {})
}

/**
 * Read what the mock recorded for one number.
 *
 * @param phone Recipient number.
 * @returns The messages delivered to it, oldest first.
 */
async function readMessages(phone: string): Promise<TelegramMessage[]> {
  const response = await fetch(
    `${MOCK_URL}/test/messages?phone_number=${encodeURIComponent(phone)}`,
  )
  if (!response.ok) {
    return []
  }

  const body = (await response.json()) as { messages?: TelegramMessage[] }

  return body.messages ?? []
}

/**
 * Call one test route on the mock Gateway.
 *
 * @param path The route path.
 * @param payload The JSON body.
 */
async function post(path: string, payload: unknown): Promise<void> {
  const response = await fetch(`${MOCK_URL}${path}`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(payload),
  })
  expect(response.ok, `mock Gateway refused ${path}`).toBe(true)
}
