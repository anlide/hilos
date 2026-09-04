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
// Nothing here arranges anything on the gateway. Its test routes — declaring a
// number unreachable, wiping the store — belong to the spec that exercises a
// channel REFUSING a number, and this demo has no such case: what it walks is a
// code arriving. The routes are the chat demo's (helpers/telegram.ts there), and
// they come back here when a case needs them.

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
