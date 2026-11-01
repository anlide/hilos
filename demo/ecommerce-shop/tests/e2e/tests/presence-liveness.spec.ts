import { test, expect, type Locator, type Page } from '@playwright/test'

import {
  bindTestUser,
  bringLivenessMomentToNow,
  createTestUser,
  openRawWebSocket,
  sessionToken,
  typeInto,
  gotoPage,
  grantAdminToSelf,
  type SignedInUser,
} from '../../../../../framework/frontend/e2e/index.js'

// Presence over silent sockets (HIL-1566). The master pings a browser socket that
// has said nothing for the idle span, holds it probably offline from a longer span
// and closes it at the longest; any frame from the browser ends the silence. The
// people list and the card say a person is online while one socket of theirs
// still answers, probably offline when every one of them is in doubt, and offline
// when none is left.
//
// No browser keeps that silence — Chromium answers a ping by itself, whatever its
// page is doing — so the person's sockets here are raw ones
// (framework/frontend/e2e/rawWebSocket.ts), opened with the cookies of the
// person's own browser, which then leaves. The person signs in on two browsers and
// each leaves one raw socket: the moment below is brought to the sockets of one
// browser, and presence counts the person's sockets across all of them. An
// administrator watches the person on the list and on the card: two
// subscriptions, opened once and never reopened.
//
// The stand runs production's spans: a ping after 40 s of silence, the next every
// 50 s, probably offline at 120 s, closed at 600 s. The spec never waits them out:
// test:connection:liveness brings one browser's silence to the moment of doubt or
// of the close, and the master works its liveness tick at once. Each step then
// waits for what the master did to the socket — the ping it sent into the silence,
// the close — and for what the observer was told.

/**
 * The person's presence on the card. The badge stands over an invisible twin that
 * holds the room of the widest word, so the visible one is read.
 *
 * @param card The observer's tab on the person's card.
 * @returns The visible presence badge.
 */
function cardPresence(card: Page): Locator {
  return card
    .locator('[data-id="hilos-user-detail"] .badge')
    .filter({ visible: true })
}

/**
 * The person's presence in their row of the people list, read the same way.
 *
 * @param list The observer's tab on the people list.
 * @param userId The person's id, the key of their row.
 * @returns The visible presence badge.
 */
function listPresence(list: Page, userId: number): Locator {
  return list
    .locator(`[data-id="hilos-table-row-${userId}"] .badge`)
    .filter({ visible: true })
}

/**
 * Wait until the list and the card both say the person's presence, and the card
 * counts the person's sockets — doubted ones included, until they close.
 *
 * @param card The observer's tab on the person's card.
 * @param list The observer's tab on the people list.
 * @param userId The person's id.
 * @param presence The word both badges say.
 * @param sockets The number of the person's sockets the card counts.
 */
async function expectPresence(
  card: Page,
  list: Page,
  userId: number,
  presence: string,
  sockets: number,
): Promise<void> {
  await expect(cardPresence(card)).toHaveText(presence)
  await expect(listPresence(list, userId)).toHaveText(presence)
  await expect(card.getByTestId('hilos-user-sessions')).toHaveText(
    String(sockets),
  )
}

/**
 * Read the session token of one of the person's browsers, and close its tab.
 * A fresh document spends a rotation the fixture binding may have left in flight,
 * so the token is the cookie the browser's raw socket presents and the one the
 * master finds that socket by. The tab answers every ping, so it leaves, and the
 * raw socket is all of that browser the master hears.
 *
 * @param personPage The person's tab in that browser, already bound to them.
 * @param person The person.
 * @returns The session token the browser ends on.
 */
async function leaveWithToken(
  personPage: Page,
  person: SignedInUser,
): Promise<string> {
  await gotoPage(expect, personPage, '/')
  await expect(personPage.getByTestId('self-user')).toHaveText(person.name)
  const token = await sessionToken(personPage)
  await personPage.close()

  return token
}

test('says a person probably offline while every socket of theirs is silent', async ({
  browser,
  page,
}) => {
  const use = test.info().project.use
  const contextOptions = {
    baseURL: use.baseURL,
    ignoreHTTPSErrors: use.ignoreHTTPSErrors,
  }
  const contextA = await browser.newContext(contextOptions)
  const contextB = await browser.newContext(contextOptions)
  try {
    await grantAdminToSelf(expect, page)
    const pageA = await contextA.newPage()
    const person = await createTestUser(expect, pageA)
    const tokenA = await leaveWithToken(pageA, person)
    const pageB = await contextB.newPage()
    await bindTestUser(expect, pageB, person)
    const tokenB = await leaveWithToken(pageB, person)
    const first = await openRawWebSocket(contextA, use)
    const second = await openRawWebSocket(contextB, use)

    await gotoPage(expect, page, `/hilos/user/${person.userId}`)
    const list = await page.context().newPage()
    await gotoPage(expect, list, '/hilos/users')
    await typeInto(list.getByTestId('hilos-table-search'), person.name)
    await expectPresence(page, list, person.userId, 'online', 2)

    // One socket silent into the doubt, the other answering: still online. The
    // silence goes first — an answering socket would pong the master's ping and end
    // the doubt — and the ping the master sends into it is its trace on this side.
    first.keepSilent()
    await bringLivenessMomentToNow(tokenA, 'probably-offline')
    await first.waitForSilentPings(1)
    await expectPresence(page, list, person.userId, 'online', 2)

    // Both silent into the doubt: probably offline, and both still counted.
    second.keepSilent()
    await bringLivenessMomentToNow(tokenB, 'probably-offline')
    await second.waitForSilentPings(1)
    await expectPresence(page, list, person.userId, 'probably offline', 2)

    // One answers: online again, while the other is still silent and in doubt.
    second.answer()
    await expectPresence(page, list, person.userId, 'online', 2)
    expect(first.silentPings()).toBeGreaterThanOrEqual(1)

    // The master closes the one that kept silent; the one answering is left.
    await bringLivenessMomentToNow(tokenA, 'close')
    expect(await first.closed).toBe('server')
    await expectPresence(page, list, person.userId, 'online', 1)

    // The last one closes: offline.
    expect(await second.close()).toBe('client')
    await expectPresence(page, list, person.userId, 'offline', 0)

    // A new socket of the same person brings them back to the same observer.
    const again = await openRawWebSocket(contextA, use)
    await expectPresence(page, list, person.userId, 'online', 1)
    await again.close()
  } finally {
    await contextA.close()
    await contextB.close()
  }
})
