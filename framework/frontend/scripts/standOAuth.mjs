// stub-oauth: the world of the provider the stand emulates (HIL-923). The gateway plays
// one OAuth provider for every profile — a consent screen a browser really opens, a code
// really exchanged over HTTPS, a userinfo really read — and what a spec arranges here is
// only that world: WHICH ACCOUNTS EXIST over there, and which of them get their next code
// already expired (HIL-926).
//
// Nothing about a login in progress is declared, and that is what makes the arrangement
// free of races: an account is a fact about the provider, so a spec may declare it at any
// moment before the button on the consent screen is pressed.
//
// The PERSON at that screen is not here — that is framework/frontend/e2e/standOAuthUser.ts,
// because waiting for the provider's window, picking an account in it and closing it happen
// in the browser. Two files and not one on purpose: the provider and its user are two
// different things, and only the second one takes orders from a spec.

import { postToGateway } from './standGateway.mjs'

/** Test route of the provider's own half, where the world is declared. */
const DECLARE_ACCOUNT_PATH = '/oauth/test/account'

/** Test route of the provider's own half, where an expired code is ordered. */
const EXPIRED_CODE_PATH = '/oauth/test/expired-code'

/** What the emulator makes a login handle out of when a declaration named none. */
const LOGIN_PREFIX = 'user'

/**
 * A provider the stand's OAuth emulator plays.
 *
 * @typedef {'github' | 'google'} StandOAuthProfile
 */

/**
 * One account of a provider's world, as the emulator holds it.
 *
 * @typedef {object} StandOAuthAccount
 * @property {StandOAuthProfile} profile Provider the account lives at.
 * @property {string} subject Immutable id at the provider: a run of digits, as both real providers use.
 * @property {string} login Login handle, which every account has — the emulator fills one in when a spec does not.
 * @property {string | null} name Display name, or null when the account has none.
 * @property {string | null} email Email, or null when the account has none.
 */

/**
 * An account id no other worker holds, so a login mints a fresh identity rather than
 * resolving somebody else's.
 *
 * Digits only, because that is what both providers use — a number at GitHub and a string
 * of digits at Google. The millisecond clock plus three random digits keeps two workers of
 * the same run apart as well as two runs, and the nine-digit slice keeps the id small
 * enough to survive a JSON number on the way back.
 *
 * Isolation rests on this id and never on /test/reset, which would wipe what the other
 * workers declared.
 *
 * @returns {string} An id of digits.
 */
export function uniqueOAuthSubject() {
  const spread = Math.floor(Math.random() * 1000)
    .toString()
    .padStart(3, '0')

  return `${Date.now().toString().slice(-9)}${spread}`
}

/**
 * Declare — or re-declare — one account of a provider's world.
 *
 * What a spec does not name, the account HAS NOT: an account declared with no email is an
 * account with no email, which is the ordinary case at a real provider and the one HIL-573
 * was found in. Each profile then says it in its own way, so the two are not
 * interchangeable. The login is the one exception, because a real account always has one:
 * left unnamed, the emulator fills it in and this helper returns what it filled in.
 *
 * Declaring the same id twice overwrites the account, which is how a spec shows that
 * userinfo is read live rather than replayed from the moment the code was issued.
 *
 * @param {StandOAuthProfile} profile The provider whose world to declare in.
 * @param {Partial<Omit<StandOAuthAccount, 'profile'>>} [account] What to pin about the account; anything left out is left out.
 * @returns {Promise<StandOAuthAccount>} The account as the provider now holds it, which is what the spec picks on screen.
 */
export async function declareOAuthAccount(profile, account) {
  const subject = account?.subject ?? uniqueOAuthSubject()
  /** @type {Record<string, string>} */
  const declaration = { profile, subject }

  for (const field of /** @type {const} */ (['login', 'name', 'email'])) {
    const value = account?.[field]
    if (value !== undefined && value !== null) {
      declaration[field] = value
    }
  }

  await postToGateway(DECLARE_ACCOUNT_PATH, declaration)

  return {
    profile,
    subject,
    login: declaration.login ?? `${LOGIN_PREFIX}${subject}`,
    name: declaration.name ?? null,
    email: declaration.email ?? null,
  }
}

/**
 * Order the next code the provider hands this account to be born expired (HIL-926).
 *
 * Like a declaration, the order is a fact about the provider's world and not about a login
 * in progress, so it is made before the button on the consent screen is pressed. One order
 * is one code: orders pile up, and each confirmation spends one. The exchange then gets the
 * provider's own refusal of that code, in the profile's form — GitHub a 200 with
 * `bad_verification_code` inside, Google a 400 with `invalid_grant`.
 *
 * @param {StandOAuthAccount} account The declared account whose next code is to be expired.
 * @returns {Promise<void>}
 */
export async function orderExpiredCode(account) {
  await postToGateway(EXPIRED_CODE_PATH, {
    profile: account.profile,
    subject: account.subject,
  })
}
