import { waitForAnyMailTo } from './mail'

// The stand's SMS interceptor (HIL-653). The daemon posts every message to the
// stand gateway with the generic HTTP provider, and the gateway forwards what it
// caught to Mailpit as a letter addressed <E.164>@sms.stand — so SMS is read
// exactly where mail is read (helpers/mail.ts), by this helper and by a person
// with the mailbox open in a browser.
//
// Reading the code back through the transport is the point: the daemon really
// built a request, really posted it, and the gateway really answered. A helper
// that read the challenge out of the database instead would pass with the whole
// transport removed.
//
// It replaced the .txt artifacts this helper first read. Those proved only that
// the stub had written a file, they were unreachable on a local stand, and two
// of them from an earlier run were once mistaken for the code a person had just
// asked for — which is the whole reason the recipient now names the channel.
const SMS_MAIL_DOMAIN = 'sms.stand'

/**
 * A phone number no other run holds, so a login mints a fresh passwordless user
 * rather than resolving somebody else's existing `sms` identity.
 *
 * E.164 as the backend normalizes it (PhoneNumber): a `+` and 8–15 digits. The
 * millisecond clock plus three random digits keeps two workers of the same run
 * apart as well as two runs.
 *
 * @returns A canonical E.164 number.
 */
export function uniquePhone(): string {
  const spread = Math.floor(Math.random() * 1000)
    .toString()
    .padStart(3, '0')

  return `+1${Date.now().toString().slice(-9)}${spread}`
}

/**
 * Wait for the code the stand texted to one number, and return it.
 *
 * The code is read off the subject, which the gateway writes as the message text
 * itself — the same line a person reads out of the mailbox list without opening
 * anything. The body cannot be matched on loosely: it names the recipient and the
 * time above the text, and a bare digit-run would answer with the phone number.
 *
 * @param phone Recipient number in canonical E.164, as `uniquePhone` produces it.
 * @returns The plaintext verification code.
 * @throws Error When the delivered message carries no code.
 */
export async function waitForSmsCode(phone: string): Promise<string> {
  const mail = await waitForAnyMailTo(`${phone}@${SMS_MAIL_DOMAIN}`)
  const code = /(\d{4,})/.exec(mail.subject)?.[1]
  if (code === undefined) {
    throw new Error(`the SMS to ${phone} carried no code`)
  }

  return code
}
