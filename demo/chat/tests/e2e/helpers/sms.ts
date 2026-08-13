import { expect } from '@playwright/test'
import { readdir, readFile } from 'node:fs/promises'

// The stand's SMS interceptor, such as it is. The stub provider (SMS_PROVIDER
// auto-selects it with no gateway configured) writes every message as a .txt
// artifact under SMS_FILE_DIR, which the daemon and the runner see through the
// same repo bind mount — so the artifact directory is to SMS what Mailpit's HTTP
// API is to mail (helpers/mail.ts), and the only readable side of a channel that
// has no API at all.
const SMS_DIR = process.env.SMS_ARTIFACT_DIR ?? '/hilos/demo/chat/data/sms'

// Artifact layout, written by StubSmsProvider::write():
//   To: +100000000000
//   From: ...
//   Text: Your verification code is: 123456
const RECIPIENT_LINE = /^To: (.+)$/m
const CODE_IN_TEXT = /^Text:.*?(\d{4,})\s*$/m

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
 * Wait for the code the stub texted to one number, and return it.
 *
 * @param phone Recipient number in canonical E.164, as `uniquePhone` produces it.
 * @returns The plaintext verification code.
 * @throws Error When the artifact turns out to carry no code.
 */
export async function waitForSmsCode(phone: string): Promise<string> {
  await expect
    .poll(async () => (await readCode(phone)) !== null, {
      message: `no SMS to ${phone} was written under ${SMS_DIR}`,
    })
    .toBe(true)

  const code = await readCode(phone)
  if (code === null) {
    throw new Error(`SMS artifact for ${phone} carried no code`)
  }

  return code
}

/**
 * Read the code out of the artifact written for one number, if it is there yet.
 *
 * The directory does not exist until the first message is written, and that is a
 * "not yet", not a failure — a poll that threw on it would fail the wait before
 * the daemon ever got to send.
 *
 * @param phone Recipient number to match against the artifact's `To:` line.
 * @returns The code, or null while no artifact for this number carries one.
 */
async function readCode(phone: string): Promise<string | null> {
  let names: string[]
  try {
    names = await readdir(SMS_DIR)
  } catch {
    return null
  }

  for (const name of names) {
    const content = await readFile(`${SMS_DIR}/${name}`, 'utf8')
    if (RECIPIENT_LINE.exec(content)?.[1] !== phone) {
      continue
    }
    const code = CODE_IN_TEXT.exec(content)?.[1]
    if (code !== undefined) {
      return code
    }
  }

  return null
}
