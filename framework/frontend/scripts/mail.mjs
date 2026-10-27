// Product letters share one mailbox reader with the stand's SMS and Telegram
// interceptors. Keep template-specific codes and links here, without Playwright.

import { waitForMailTo } from './standMailbox.mjs'

/** The subject RegisterConfirmMailTemplate sends the registration code under. */
const REGISTER_SUBJECT = 'Confirm your email address'

/** The subject PasswordResetMailTemplate sends the recovery code under. */
const PASSWORD_RESET_SUBJECT = 'Reset your password'

/** The subject MagicLinkMailTemplate sends the sign-in letter under. */
export const MAGIC_LINK_SUBJECT = 'Your sign-in link'

// Anchor each secret on its instruction: the link token can itself contain a run
// of digits, which must never be typed as the companion code.
const MAGIC_LINK_CODE_PATTERN =
  /Or enter this code on the page that asked for it:\s*(\d+)/
const MAGIC_LINK_URL_PATTERN = /Use this link to sign in:\s*(\S+)/

/**
 * Wait for a verification letter and read the numeric code in its body.
 *
 * @param {string} address Recipient address the code was issued for.
 * @param {string} subject Exact subject line of the letter carrying it.
 * @param {number} [minimumCount=1] How many matching letters must have arrived; the newest is read.
 * @returns {Promise<string>} The plaintext verification code.
 * @throws {Error} When the delivered letter carries no code.
 */
export async function waitForMailCode(address, subject, minimumCount = 1) {
  const mail = await waitForMailTo(address, subject, minimumCount)
  const code = /(\d{4,})/.exec(mail.text)?.[1]
  if (code === undefined) {
    throw new Error(`mail "${subject}" to ${address} carried no code`)
  }

  return code
}

/**
 * Wait for the registration letter and return its code.
 *
 * @param {string} email Address the registration was submitted for.
 * @returns {Promise<string>} The plaintext confirmation code.
 */
export async function readRegisterCode(email) {
  return waitForMailCode(email, REGISTER_SUBJECT)
}

/**
 * Wait for the recovery letter and return its code.
 *
 * @param {string} email Address the reset was requested for.
 * @returns {Promise<string>} The plaintext recovery code.
 */
export async function readPasswordResetCode(email) {
  return waitForMailCode(email, PASSWORD_RESET_SUBJECT)
}

/**
 * Wait for the sign-in letter and read the code offered beside its link.
 *
 * @param {string} email Address the letter was sent to.
 * @returns {Promise<string>} The plaintext companion sign-in code.
 * @throws {Error} When the letter offers no code to type.
 */
export async function readMagicLinkCode(email) {
  const mail = await waitForMailTo(email, MAGIC_LINK_SUBJECT)
  const code = MAGIC_LINK_CODE_PATTERN.exec(mail.text)?.[1]
  if (code === undefined) {
    throw new Error(`sign-in letter to ${email} offered no code to type`)
  }

  return code
}

/**
 * Wait for the sign-in letter and read the whole link a mail client would open.
 *
 * @param {string} email Address the letter was sent to.
 * @returns {Promise<string>} The sign-in URL as the letter spells it.
 * @throws {Error} When the letter carries no link to click.
 */
export async function readMagicLinkUrl(email) {
  const mail = await waitForMailTo(email, MAGIC_LINK_SUBJECT)
  const url = MAGIC_LINK_URL_PATTERN.exec(mail.text)?.[1]
  if (url === undefined) {
    throw new Error(`sign-in letter to ${email} carried no link to click`)
  }

  return url
}
