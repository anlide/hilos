import { test, expect, type BrowserContext } from '@playwright/test'

import {
  nameFromEmail,
  register,
  SESSION_COOKIE,
  uniqueEmail,
} from '../helpers/session'
import { gotoPage } from '../helpers/page'

// Session-fixation e2e (HIL-582): the leg of the rotation that only a real browser
// can show. The parts before it are covered elsewhere — the seam's rotate-and-promote
// in the Integration suite, the ticket trade on the 101 in the framework unit suite —
// but whether the browser ends up HOLDING the new cookie depends on three things
// agreeing across the wire: the frontend writing the auxiliary cookie under the name
// the welcome frame gave it, the master reading it back on the reconnect, and the
// Set-Cookie landing in the jar. Only the browser can answer that.
//
// The value of the cookie is the assertion, not its presence: fixation is precisely
// the case where a session cookie the attacker knows keeps working after the victim
// logs in, so what must change is the VALUE, while the person stays signed in.

/** The auxiliary cookie the ticket travels in (SessionRotationTicket::cookieName). */
const ROTATE_COOKIE = 'hilos_session_token_rotate'

/**
 * Read one cookie out of the context's jar.
 *
 * Through the jar rather than through `document.cookie`: the session cookie is
 * HttpOnly, so the page itself cannot see the very value this spec is about.
 *
 * @param context The browser context holding the jar.
 * @param name The cookie name to read.
 * @returns The cookie's value, or the empty string when the jar holds none.
 */
async function cookieValue(
  context: BrowserContext,
  name: string,
): Promise<string> {
  const cookies = await context.cookies()

  return cookies.find((cookie) => cookie.name === name)?.value ?? ''
}

test('a login moves the live session onto a new cookie value', async ({
  page,
  context,
}) => {
  await gotoPage(page, '/')
  await expect(page.getByTestId('conn-state')).toHaveText('connected')

  // The anonymous session the visitor already has: this is the value an attacker
  // would have planted, and the one that must stop naming the session.
  const planted = await cookieValue(context, SESSION_COOKIE)
  expect(planted).not.toBe('')

  const email = uniqueEmail()
  await page.getByTestId('message-signin').click()
  await register(page, email)
  await expect(page.getByTestId('self-user')).toHaveText(nameFromEmail(email))

  // The new value arrives on the 101 of the reconnect the ticket triggers, which is a
  // round trip after the sign-in the assertion above already saw.
  await expect(async () => {
    expect(await cookieValue(context, SESSION_COOKIE)).not.toBe(planted)
  }).toPass()

  // And the ticket does not linger: the master erases the auxiliary cookie on the same
  // handshake it spends it on, so a replay has nothing to present.
  await expect(async () => {
    expect(await cookieValue(context, ROTATE_COOKIE)).toBe('')
  }).toPass()

  // The rotation is invisible to the person: still signed in, still on the same page.
  await expect(page.getByTestId('self-user')).toHaveText(nameFromEmail(email))
  expect(new URL(page.url()).pathname).toBe('/')
})

test('a second tab of the same browser comes back into the rotated session', async ({
  context,
}) => {
  const tabA = await context.newPage()
  const tabB = await context.newPage()
  await gotoPage(tabA, '/')
  await gotoPage(tabB, '/')
  await expect(tabA.getByTestId('conn-state')).toHaveText('connected')
  await expect(tabB.getByTestId('conn-state')).toHaveText('connected')

  const email = uniqueEmail()
  await tabA.getByTestId('message-signin').click()
  await register(tabA, email)
  await expect(tabA.getByTestId('self-user')).toHaveText(nameFromEmail(email))

  // Tab B's connection was deliberately left anonymous by the login — promoting it is
  // the second half of the fixation attack — and is dropped once the browser holds the
  // new cookie. It reconnects carrying that cookie, lands in the same session, and only
  // then learns who it is. Nothing is clicked here: this is the whole point.
  await expect(tabB.getByTestId('self-user')).toHaveText(nameFromEmail(email))
  await expect(tabB.getByTestId('conn-state')).toHaveText('connected')

  await tabB.close()
  await tabA.close()
})
