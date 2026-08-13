import { expect } from '@playwright/test'
import { readdir, readFile, unlink } from 'node:fs/promises'
import { fileURLToPath } from 'node:url'

// Reads the mail the daemon actually delivered. The test stack configures no SMTP
// relay, so the framework file transport is auto-selected and every message lands
// as an .eml artifact under MAIL_FILE_DIR (demo/chat/tests/.env); the daemon writes
// them at /app/data/mail-test and the runner reads them at
// /hilos/demo/chat/data/mail-test — the same host directory through two bind mounts.
//
// This is what makes a code-gated flow drivable from the browser: registration
// (HIL-415) only creates an account once the emailed code comes back, and the code
// exists nowhere the page can see it. The reference this mirrors is hleb's
// email.helper.ts, which reads the same thing out of a MailHog HTTP API; we read the
// artifact instead because the stack has no mail catcher (moving to one is P-062).
//
// Mail is never awaited with a fixed pause: a send travels as a signal to a sharded
// mail agent and settles on its own tick, so every read polls until the letter is
// there (expect.toPass) and the count helpers answer whatever has arrived so far.

/**
 * The artifact directory as the runner sees it — MAIL_FILE_DIR resolved through the
 * runner's own mount (its compose service mounts the repo root at /hilos).
 */
const MAIL_DIR = fileURLToPath(new URL('../../../data/mail-test', import.meta.url))

/** Header/body separator of an RFC 5322 message (MailMessageEncoder emits CRLF). */
const HEADER_SEPARATOR = '\r\n\r\n'

/** The registration letter's code line, as RegisterConfirmMailTemplate words it. */
const REGISTER_CODE_PATTERN =
  /Use this code to confirm your email address:\s*(\d{6})/

/** One delivered message, decoded. */
interface MailArtifact {
  /** Send time in epoch milliseconds, taken from the artifact's file name. */
  readonly sentAtMs: number
  /** Bare recipient address of the `To:` header. */
  readonly to: string
  /** Decoded plain-text body. */
  readonly text: string
}

/**
 * Every letter delivered to one address, newest first.
 *
 * @param email The recipient address.
 */
export async function mailsTo(email: string): Promise<MailArtifact[]> {
  const delivered = await readMailbox()

  return delivered
    .filter((message) => message.to === email.toLowerCase())
    .sort((first, second) => second.sentAtMs - first.sentAtMs)
}

/**
 * Wait for the registration letter and return the code it carries.
 *
 * Polls the mailbox until a letter for the address is there and its body carries
 * the code line, so a spec can type the code the way a person reads it out of
 * their inbox. The newest letter wins — a resend supersedes the code before it.
 *
 * @param email The address the registration was submitted for.
 */
export async function readRegisterCode(email: string): Promise<string> {
  let code = ''
  await expect(async () => {
    const [newest] = await mailsTo(email)
    expect(newest, `no mail delivered to ${email}`).toBeDefined()
    const match = newest.text.match(REGISTER_CODE_PATTERN)
    expect(match, `no confirmation code in the mail to ${email}`).not.toBeNull()
    code = match?.[1] ?? ''
  }).toPass()

  return code
}

/**
 * Drop every delivered artifact.
 *
 * Called once from the global setup: the directory is a bind mount that outlives
 * the run, so without this a run reads a mailbox that still holds the previous
 * one's letters. Between tests nothing is cleared — every spec registers a unique
 * address, so a per-address read is already isolated from its neighbours.
 */
export async function clearMail(): Promise<void> {
  for (const name of await listArtifacts()) {
    await unlink(`${MAIL_DIR}/${name}`)
  }
}

/**
 * The artifact file names currently in the mail directory.
 *
 * An absent directory is an empty mailbox, not an error: nothing has been sent yet
 * (the daemon creates it on its first delivery).
 */
async function listArtifacts(): Promise<string[]> {
  try {
    const names = await readdir(MAIL_DIR)

    return names.filter((name) => name.endsWith('.eml'))
  } catch {
    return []
  }
}

/** Every delivered message, decoded, in no particular order. */
async function readMailbox(): Promise<MailArtifact[]> {
  const messages: MailArtifact[] = []
  for (const name of await listArtifacts()) {
    const encoded = await readFile(`${MAIL_DIR}/${name}`, 'utf8')
    messages.push({
      sentAtMs: Number.parseInt(name, 10),
      to: recipientOf(encoded),
      text: decodeBody(encoded),
    })
  }

  return messages
}

/**
 * The bare recipient address of an encoded message.
 *
 * A raw send carries no display name, but a `Name <address>` header is unwrapped
 * anyway so the read does not depend on which sender built the message.
 *
 * @param encoded The encoded wire message.
 */
function recipientOf(encoded: string): string {
  const header = encoded.match(/^To:\s*(.+)$/m)
  if (header === null) {
    return ''
  }
  const value = header[1].trim()
  const angled = value.match(/<([^>]+)>/)

  return (angled === null ? value : angled[1]).toLowerCase()
}

/**
 * The decoded plain-text body of an encoded message.
 *
 * The encoder emits a single text/plain part for these letters, base64 with CRLF
 * folding, after the blank line that ends the headers.
 *
 * @param encoded The encoded wire message.
 */
function decodeBody(encoded: string): string {
  const separator = encoded.indexOf(HEADER_SEPARATOR)
  if (separator === -1) {
    return ''
  }
  const body = encoded.slice(separator + HEADER_SEPARATOR.length)

  return Buffer.from(body.replace(/\r?\n/g, ''), 'base64').toString('utf8')
}
