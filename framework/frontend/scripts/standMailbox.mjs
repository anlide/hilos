// The stand's mail interceptor, read the one way every resident's helper needs it:
// the newest letter to one recipient (HIL-653). The daemon sends over SMTP to it,
// and the stand gateway forwards every SMS and Telegram message it catches there as
// a letter, so anything that left the node lands in a mailbox the runner can read
// over HTTP. This is the only place a spec can prove a message actually left the
// node: the daemon saying `sent` is the daemon's own word for it.
//
// The mailbox is shared by every spec on the stand, so a message is never
// identified by "the newest one" alone: a read names the recipient, and every spec
// coins an address no other one uses. Clearing is a run-start act of each demo's
// global setup — mid-run it would take the letter a parallel worker is still
// waiting for. Mail is never awaited with a fixed pause either: a send travels as a
// signal to a sharded agent and settles on its own tick, so the wait polls until
// the letter is there.
//
// One read and not the whole reader: the product's own letters, picked out by
// subject, are read by each demo itself (demo/*/tests/e2e/helpers/mail.ts). Nothing
// here drives a browser or imports Playwright, so the poll is this module's own
// rather than expect.poll, on the schedule expect.poll rides by default.

import process from 'node:process'

import { mailWaitTimeout } from './timeout-scale.mjs'

/**
 * How long a wait on a letter may run on this host, in milliseconds.
 *
 * Derived once per module load, not per call: the factor is read off /proc, and
 * a wait that re-derived it would read the host once per poll attempt.
 */
const MAIL_WAIT_TIMEOUT = mailWaitTimeout()

/** Pauses between two polls of the mailbox, in milliseconds; the last one repeats. */
const POLL_INTERVALS = [100, 250, 500, 1000]

/**
 * One intercepted message, as a spec reads it back.
 *
 * @typedef {object} InterceptedMail
 * @property {string} subject Subject line — for a channel letter, the message text itself.
 * @property {string} text Plain-text body.
 */

/**
 * The message-list fields this module reads (Mailpit returns many more).
 *
 * @typedef {object} MailboxEntry
 * @property {string} ID Message id, which the full read is addressed by.
 * @property {{ Address: string }[]} To Recipients as the sender wrote them.
 */

/**
 * Wait until the interceptor holds any message for this recipient, and return
 * the newest one.
 *
 * The read a channel letter needs. The stand gateway forwards a caught SMS or
 * Telegram message under the message's own text as its subject, so there is no
 * fixed subject to wait on — the recipient is the whole of the match, and that is
 * enough because every spec coins an address no other one uses.
 *
 * @param {string} address Recipient address, as the gateway addressed it.
 * @returns {Promise<InterceptedMail>} The newest message's subject and plain-text body.
 * @throws {Error} When the runner names no mailbox, when no message arrives within
 *   the wait, when the message vanishes between the poll and the read, or when the
 *   interceptor answers anything but 2xx.
 */
export async function waitForAnyMailTo(address) {
  const mailbox = mailboxUrl()
  const deadline = Date.now() + MAIL_WAIT_TIMEOUT

  for (let attempt = 0; ; attempt += 1) {
    if ((await entriesTo(mailbox, address)).length > 0) {
      break
    }
    const pause = POLL_INTERVALS[Math.min(attempt, POLL_INTERVALS.length - 1)]
    if (Date.now() + pause >= deadline) {
      throw new Error(`no mail to ${address} reached the interceptor`)
    }
    await new Promise((resolve) => globalThis.setTimeout(resolve, pause))
  }

  const [entry] = await entriesTo(mailbox, address)
  if (entry === undefined) {
    throw new Error(`mail to ${address} disappeared`)
  }

  return readMessage(mailbox, entry.ID)
}

/**
 * The runner's address of the stand's mailbox, read at the call rather than at
 * load: nearly every spec loads this module through a demo helper, and one that
 * reads no mail must not fail for a mailbox it never opens.
 *
 * @returns {string} The mailbox's base URL.
 * @throws {Error} When the runner names no mailbox.
 */
function mailboxUrl() {
  const url = process.env.MAILPIT_URL
  if (url === undefined || url === '') {
    throw new Error(
      'MAILPIT_URL is not set: the e2e runner names its stand mailbox in its docker-compose.test.yml',
    )
  }

  return url
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
 * @param {string} mailbox The mailbox's base URL.
 * @param {string} address Recipient address to match.
 * @returns {Promise<MailboxEntry[]>} Matching mailbox entries, newest first (the order Mailpit lists in).
 */
async function entriesTo(mailbox, address) {
  /** @type {{ messages: MailboxEntry[] }} */
  const listing = await request(mailbox, '/api/v1/messages?limit=200')
  const wanted = address.toLowerCase()

  return listing.messages.filter((entry) =>
    entry.To.some((recipient) => recipient.Address.toLowerCase() === wanted),
  )
}

/**
 * Read one held message in full.
 *
 * @param {string} mailbox The mailbox's base URL.
 * @param {string} id Message id from the mailbox listing.
 * @returns {Promise<InterceptedMail>} The message's subject and plain-text body.
 */
async function readMessage(mailbox, id) {
  /** @type {{ Subject: string, Text: string }} */
  const message = await request(mailbox, `/api/v1/message/${id}`)

  return { subject: message.Subject, text: message.Text }
}

/**
 * Call one Mailpit endpoint and decode its JSON, refusing anything but a 2xx.
 *
 * @template T
 * @param {string} mailbox The mailbox's base URL.
 * @param {string} path API path including its query.
 * @returns {Promise<T>} The decoded body.
 * @throws {Error} When the interceptor answers anything but 2xx.
 */
async function request(mailbox, path) {
  const response = await globalThis.fetch(`${mailbox}${path}`)
  if (!response.ok) {
    throw new Error(`mail interceptor answered ${response.status} for ${path}`)
  }

  return response.json()
}
