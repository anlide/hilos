// Interpret delivered Status letters; transport and recipient isolation belong
// to standMailbox.mjs. No token is obtained from a database or a fixture.

import { URL } from 'node:url'

export const STATUS_CONFIRM_SUBJECT =
  'Confirm your subscription to status updates'

/**
 * Read a server-formatted moment using its explicit numeric offset.
 *
 * @param {{ text: string }} mail Delivered plain alternative.
 * @param {'Detected at' | 'Updated at' | 'Resolved at'} label Moment instruction.
 * @returns {number | null} UTC epoch milliseconds, or null when absent/malformed.
 */
export function statusMailMoment(mail, label) {
  const line = new RegExp(
    `^${label}: (\\d{4}-\\d\\d-\\d\\d) (\\d\\d:\\d\\d:\\d\\d) ([+-]\\d\\d:\\d\\d) \\([^)]+\\)\\s*$`,
    'm',
  ).exec(mail.text)
  if (line === null) return null
  const at = Date.parse(`${line[1]}T${line[2]}${line[3]}`)
  return Number.isFinite(at) ? at : null
}

/**
 * Read the public links a person can open from the plain alternative.
 *
 * @param {{ text: string }} mail Delivered letter.
 * @returns {{ status: string, unsubscribe: string, confirm?: string }} Valid public URLs.
 * @throws {Error} When a link is missing or carries a secret outside its fragment.
 */
export function statusMailLinks(mail) {
  const status = readLink(mail.text, 'Status page')
  const unsubscribe = readLink(mail.text, 'Unsubscribe')
  const confirm = /^Confirm your subscription:/m.test(mail.text)
    ? readLink(mail.text, 'Confirm your subscription')
    : undefined
  const publicUrl = new URL(status)
  if (
    !['http:', 'https:'].includes(publicUrl.protocol) ||
    publicUrl.pathname !== '/status' ||
    publicUrl.search !== '' ||
    publicUrl.hash !== '' ||
    publicUrl.username !== '' ||
    publicUrl.password !== ''
  ) {
    throw new Error('Status letter has an invalid public address')
  }
  for (const [link, key] of [
    [unsubscribe, 'status-unsubscribe'],
    ...(confirm === undefined ? [] : [[confirm, 'status-confirm']]),
  ]) {
    const target = new URL(link)
    if (
      `${target.origin}${target.pathname}` !== status ||
      target.search !== '' ||
      target.username !== '' ||
      target.password !== '' ||
      !new RegExp(`^#${key}=[a-f0-9]{64}$`, 'i').test(target.hash)
    ) {
      throw new Error(`Status letter has an invalid ${key} link`)
    }
  }
  return { status, unsubscribe, ...(confirm === undefined ? {} : { confirm }) }
}

/**
 * Read a single instruction line without accepting a URL from unrelated prose.
 *
 * @param {string} text Plain alternative.
 * @param {string} label Template instruction.
 * @returns {string} Complete URL on that line.
 */
function readLink(text, label) {
  const matches = [...text.matchAll(new RegExp(`^${label}: (\\S+)\\s*$`, 'gm'))]
  if (matches.length !== 1) {
    throw new Error(`Status letter needs one ${label} link`)
  }
  return matches[0][1]
}
