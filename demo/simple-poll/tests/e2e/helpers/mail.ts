import { expect } from '@playwright/test'

import { mailWaitTimeout } from '../../../../../framework/frontend/scripts/timeout-scale.mjs'

// The stand's mail interceptor (poll-mailpit-test in docker-compose.test.yml).
// The daemon sends over SMTP to it, so anything the product mails — a
// verification code, a sign-in link — lands in a mailbox the runner can read over
// HTTP. This is the only place a spec can prove a message actually left the node:
// the daemon's own log saying `sent` is the daemon's word for it.
//
// SMS and Telegram land here too (helpers/sms.ts, helpers/telegram.ts): the stand
// gateway forwards everything it catches to this same interceptor as a letter, so
// one mailbox is the whole of what a spec — or a person — has to open.
const MAILPIT_URL = process.env.MAILPIT_URL ?? 'http://poll-mailpit-test:8025'

/**
 * How long a wait on a letter may run on this host, in milliseconds.
 *
 * A letter is the longest chain a spec waits on — mail agent, SMTP, interceptor —
 * and without a limit of its own every poll below inherited `expect`, the
 * shortest cap the config declares (HIL-853).
 *
 * Derived once per module load, not per call: the factor is read off /proc, and
 * a wait that re-derived it would read the host once per poll attempt.
 */
const MAIL_WAIT_TIMEOUT = mailWaitTimeout()

// The mailbox is shared by every spec on the stand, so a message is never
// identified by "the newest one": a read names the recipient, and every spec
// coins an address no other one uses. Clearing is a run-start act only
// (clearMail, from the global setup) — mid-run it would take the letter a
// parallel worker is still waiting for.
//
// Mail is never awaited with a fixed pause: a send travels as a signal to a
// sharded mail agent and settles on its own tick, so every wait polls until the
// letter is there and the count helpers answer whatever has arrived so far.

/** The subject RegisterConfirmMailTemplate sends the registration code under. */
const REGISTER_SUBJECT = 'Confirm your email address'

/** The subject PasswordResetMailTemplate sends the recovery code under. */
const PASSWORD_RESET_SUBJECT = 'Reset your password'

/** The subject MagicLinkMailTemplate sends the sign-in letter under. */
const MAGIC_LINK_SUBJECT = 'Your sign-in link'

// The magic-link letter carries TWO secrets (HIL-606), and only one of them is
// typed: the line that offers the code is what the read anchors on. A bare
// digit-run match cannot be used here — the URL above it is hex, so any four
// digits inside the token would answer first, and the spec would type a slice of
// somebody's link into the code field and call the flow broken.
const MAGIC_LINK_CODE_PATTERN =
  /Or enter this code on the page that asked for it:\s*(\d+)/

// The other half of the same letter, anchored on its own line for the same
// reason: the URL is the only thing on the letter that a loose match could pick
// the code out of, and vice versa. Read as the letter offers it — whole — because
// what the click has to reproduce is the address a mail client would open.
const MAGIC_LINK_URL_PATTERN = /Use this link to sign in:\s*(\S+)/

/** One intercepted message, as a spec reads it back. */
export interface InterceptedMail {
  /** Subject line, which is how a wait picked this message out. */
  subject: string
  /** Plain-text body — where a verification code or a notification body sits. */
  text: string
}

/** The message-list fields this helper reads (Mailpit returns many more). */
interface MailboxEntry {
  ID: string
  Subject: string
  To: { Address: string }[]
}

/**
 * Wait until the interceptor holds a message with this recipient and subject,
 * and return it.
 *
 * @param address Recipient address, as the product addressed it.
 * @param subject Exact subject line the awaited message carries.
 * @returns The matched message's subject and plain-text body.
 * @throws Error When the message vanishes between the poll and the read.
 */
export async function waitForMailTo(
  address: string,
  subject: string,
): Promise<InterceptedMail> {
  await expect
    .poll(async () => (await matchingIds(address, subject)).length, {
      message: `no mail to ${address} subject "${subject}" reached the interceptor`,
      timeout: MAIL_WAIT_TIMEOUT,
    })
    .toBeGreaterThan(0)

  const [id] = await matchingIds(address, subject)
  if (id === undefined) {
    throw new Error(`mail to ${address} subject "${subject}" disappeared`)
  }

  return readMessage(id)
}

/**
 * Wait until the interceptor holds any message for this recipient, and return
 * the newest one.
 *
 * The read a channel letter needs (HIL-653). The stand gateway forwards a caught
 * SMS or Telegram message under the message's own text as its subject, so there
 * is no fixed subject to wait on — the recipient is the whole of the match, and
 * that is enough for the same reason it is enough above: every spec coins an
 * address no other one uses.
 *
 * @param address Recipient address, as the gateway addressed it.
 * @returns The newest message's subject and plain-text body.
 * @throws Error When the message vanishes between the poll and the read.
 */
export async function waitForAnyMailTo(
  address: string,
): Promise<InterceptedMail> {
  await expect
    .poll(async () => (await entriesTo(address)).length, {
      message: `no mail to ${address} reached the interceptor`,
      timeout: MAIL_WAIT_TIMEOUT,
    })
    .toBeGreaterThan(0)

  const [entry] = await entriesTo(address)
  if (entry === undefined) {
    throw new Error(`mail to ${address} disappeared`)
  }

  return readMessage(entry.ID)
}

/**
 * Wait for a verification code the product mailed, and return it.
 *
 * The `auth.*` templates all frame one numeric code in prose
 * (AbstractVerificationCodeMailTemplate), so the code is the run of digits the
 * body ends its instruction with.
 *
 * @param address Recipient address the code was issued for.
 * @param subject Exact subject line of the mail carrying it.
 * @returns The plaintext verification code.
 * @throws Error When the awaited mail carries no code.
 */
export async function waitForMailCode(
  address: string,
  subject: string,
): Promise<string> {
  const mail = await waitForMailTo(address, subject)
  const code = /(\d{4,})/.exec(mail.text)?.[1]
  if (code === undefined) {
    throw new Error(`mail "${subject}" to ${address} carried no code`)
  }

  return code
}

/**
 * Wait for the registration letter and return the code it carries.
 *
 * Registration is code-gated since HIL-415 — the submit only holds the address,
 * and the account exists once the mailed code comes back — so a spec reads the
 * code the way a person reads it out of their inbox, from a place the page
 * cannot see.
 *
 * @param email The address the registration was submitted for.
 * @returns The plaintext confirmation code.
 * @throws Error When the delivered letter carries no code.
 */
export async function readRegisterCode(email: string): Promise<string> {
  return waitForMailCode(email, REGISTER_SUBJECT)
}

/**
 * Wait for the recovery letter and return the code it carries.
 *
 * @param email The address recovery was requested for.
 * @returns The plaintext recovery code.
 * @throws Error When the delivered letter carries no code.
 */
export async function readPasswordResetCode(email: string): Promise<string> {
  return waitForMailCode(email, PASSWORD_RESET_SUBJECT)
}

/**
 * Wait for the sign-in letter and return the code it carries beside the link.
 *
 * @param email The address the letter was sent to.
 * @returns The plaintext companion sign-in code.
 * @throws Error When the delivered letter offers no code to type.
 */
export async function readMagicLinkCode(email: string): Promise<string> {
  const mail = await waitForMailTo(email, MAGIC_LINK_SUBJECT)
  const code = MAGIC_LINK_CODE_PATTERN.exec(mail.text)?.[1]
  if (code === undefined) {
    throw new Error(`sign-in letter to ${email} offered no code to type`)
  }

  return code
}

/**
 * Wait for the sign-in letter and return the link it carries beside the code.
 *
 * The companion of {@link readMagicLinkCode} for the person who CAN click: the
 * half the code screen never exercises, and the half whose click reached no
 * server at all until HIL-607. The URL comes back whole, host and all — it is
 * built from `HILOS_MAGIC_LINK_URL`, which is not the stand's own base address,
 * so a spec opens its path rather than the string itself.
 *
 * @param email The address the letter was sent to.
 * @returns The sign-in URL as the letter spells it.
 * @throws Error When the delivered letter carries no link.
 */
export async function readMagicLinkUrl(email: string): Promise<string> {
  const mail = await waitForMailTo(email, MAGIC_LINK_SUBJECT)
  const url = MAGIC_LINK_URL_PATTERN.exec(mail.text)?.[1]
  if (url === undefined) {
    throw new Error(`sign-in letter to ${email} carried no link to click`)
  }

  return url
}

/**
 * Every letter the interceptor holds for one address, newest first.
 *
 * Counting is what proves a *second* letter was never sent — an assertion about
 * the whole mailbox of an address rather than about one subject — so this read
 * is deliberately not narrowed by subject, and deliberately does not wait: the
 * caller has already awaited the letter whose absence of a sibling it asserts.
 *
 * @param email The recipient address.
 * @returns The delivered messages, newest first (the order Mailpit lists in).
 */
export async function mailsTo(email: string): Promise<InterceptedMail[]> {
  const entries = await entriesTo(email)

  return Promise.all(entries.map((entry) => readMessage(entry.ID)))
}

/**
 * Drop every message the interceptor holds.
 *
 * Called once from the global setup: the interceptor outlives a single run, so
 * without this a run counts letters the previous one sent. Between tests nothing
 * is cleared — every spec uses a unique address, so a per-address read is
 * already isolated from its neighbours.
 */
export async function clearMail(): Promise<void> {
  await call('/api/v1/messages', { method: 'DELETE' })
}

/**
 * The messages currently held for one recipient, whatever their subject.
 *
 * The whole mailbox is listed and filtered here rather than handed to Mailpit's
 * search: the search grammar tokenizes its terms, and an address is exactly the
 * kind of value that does not survive tokenizing intact. Recipients are matched
 * case-insensitively — an address is stored as the sender wrote it, and the
 * product lowercases nothing on its way out.
 *
 * @param address Recipient address to match.
 * @returns Matching mailbox entries, newest first (the order Mailpit lists in).
 */
async function entriesTo(address: string): Promise<MailboxEntry[]> {
  const mailbox = await request<{ messages: MailboxEntry[] }>(
    '/api/v1/messages?limit=200',
  )
  const wanted = address.toLowerCase()

  return mailbox.messages.filter((entry) =>
    entry.To.some((recipient) => recipient.Address.toLowerCase() === wanted),
  )
}

/**
 * Ids of the messages currently held for one recipient and subject.
 *
 * @param address Recipient address to match.
 * @param subject Exact subject line to match.
 * @returns Matching message ids, newest first (the order Mailpit lists in).
 */
async function matchingIds(
  address: string,
  subject: string,
): Promise<string[]> {
  const entries = await entriesTo(address)

  return entries
    .filter((entry) => entry.Subject === subject)
    .map((entry) => entry.ID)
}

/**
 * Read one held message in full.
 *
 * @param id Message id from the mailbox listing.
 * @returns The message's subject and plain-text body.
 */
async function readMessage(id: string): Promise<InterceptedMail> {
  const message = await request<{ Subject: string; Text: string }>(
    `/api/v1/message/${id}`,
  )

  return { subject: message.Subject, text: message.Text }
}

/**
 * Call one Mailpit endpoint and decode its JSON.
 *
 * @param path API path including its query.
 * @returns The decoded body.
 */
async function request<T>(path: string): Promise<T> {
  const response = await call(path)

  return (await response.json()) as T
}

/**
 * Call one Mailpit endpoint, refusing anything but a 2xx.
 *
 * @param path API path including its query.
 * @param init Request options, for the calls that are not a plain GET.
 * @returns The raw response.
 * @throws Error When the interceptor answers anything but 2xx.
 */
async function call(path: string, init?: RequestInit): Promise<Response> {
  const response = await fetch(`${MAILPIT_URL}${path}`, init)
  if (!response.ok) {
    throw new Error(`mail interceptor answered ${response.status} for ${path}`)
  }

  return response
}
