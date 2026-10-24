import { test, expect, type Locator, type Page } from '@playwright/test'

import { openRawWebSocket } from '../../../../../framework/frontend/e2e/index.js'
import { signUpAdmin } from '../helpers/adminGrant.js'
import { gotoPage } from '../helpers/page.js'
import { signUpPerson, typeInto } from '../helpers/session.js'

// Presence over silent sockets (HIL-1566). The master pings a browser socket that
// has said nothing for the idle span, holds it probably offline from a longer span
// and closes it at the longest; any frame from the browser ends the silence. The
// people list and the card say a person is online while one socket of theirs
// still answers, probably offline when every one of them is in doubt, and offline
// when none is left.
//
// No browser keeps that silence — Chromium answers a ping by itself, whatever its
// page is doing — so the person's two sockets here are raw ones
// (framework/frontend/e2e/rawWebSocket.ts), opened with the cookies of the
// person's own browser, which then leaves. An administrator watches the person on
// the list and on the card: two subscriptions, opened once and never reopened.
//
// The stand runs the spans at a fortieth of production's 40/50/120/600 s and in
// the same proportions (docker/docker-compose.test.yml): a ping after 1 s of
// silence, the next every 1.25 s, probably offline at 3 s, closed at 15 s. The
// spec keeps no clock of its own: each step waits for what the master did to a
// socket — the pings it sent into a silence, the close — and then for what the
// observer was told.

/**
 * The ping of a silence by which the master holds the socket in doubt. It pings at
 * the idle span and every interval after it — 40, 90 and 140 s in production, the
 * same proportions on the stand — so the third falls past the probably-offline
 * span of 120 s, and the master settles the doubt on the tick before it pings.
 */
const PING_PAST_DOUBT = 3

/**
 * The person's presence on the card. The badge stands over an invisible twin that
 * holds the room of the widest word, so the visible one is read.
 *
 * @param card The observer's tab on the person's card.
 * @returns The visible presence badge.
 */
function cardPresence(card: Page): Locator {
  return card
    .getByTestId('hilos-page-head-block')
    .locator('.hilos-stack .badge')
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

test('says a person probably offline while every socket of theirs is silent', async ({
  browser,
  page,
}) => {
  // Two silences waited into doubt and one into the close, 15 s of the stand's
  // clock on its own, on top of two sign-ups: 25.5 s on an idle box (scale 1),
  // most of the 30 s cap, and the stand's clock does not stretch with the load.
  test.slow()
  const use = test.info().project.use
  const personContext = await browser.newContext({
    baseURL: use.baseURL,
    ignoreHTTPSErrors: use.ignoreHTTPSErrors,
  })
  try {
    await signUpAdmin(page)
    const personPage = await personContext.newPage()
    const person = await signUpPerson(personPage)
    // A fresh document spends a rotation the sign-up may have left in flight, so
    // the cookie the raw sockets present is the one the person's session ends on.
    await gotoPage(personPage, '/')
    await expect(personPage.getByTestId('self-user')).toHaveText(person.name)
    // The person's own tab answers every ping; it leaves, and the raw sockets are
    // all of the person the master hears.
    await personPage.close()
    const first = await openRawWebSocket(personContext, use)
    const second = await openRawWebSocket(personContext, use)

    await gotoPage(page, `/hilos/user/${person.userId}`)
    const list = await page.context().newPage()
    await gotoPage(list, '/hilos/users')
    await typeInto(list.getByTestId('hilos-table-search'), person.name)
    await expectPresence(page, list, person.userId, 'online', 2)

    // One socket silent past the doubt, the other answering: still online.
    first.keepSilent()
    await first.waitForSilentPings(PING_PAST_DOUBT)
    await expectPresence(page, list, person.userId, 'online', 2)

    // Both silent past the doubt: probably offline, and both still counted.
    second.keepSilent()
    await second.waitForSilentPings(PING_PAST_DOUBT)
    await expectPresence(page, list, person.userId, 'probably offline', 2)

    // One answers: online again, while the other is still silent and in doubt.
    second.answer()
    await expectPresence(page, list, person.userId, 'online', 2)
    expect(first.silentPings()).toBeGreaterThanOrEqual(PING_PAST_DOUBT)

    // The master closes the one that kept silent; the one answering is left.
    expect(await first.closed).toBe('server')
    await expectPresence(page, list, person.userId, 'online', 1)

    // The last one closes: offline.
    expect(await second.close()).toBe('client')
    await expectPresence(page, list, person.userId, 'offline', 0)

    // A new socket of the same person brings them back to the same observer.
    const again = await openRawWebSocket(personContext, use)
    await expectPresence(page, list, person.userId, 'online', 1)
    await again.close()
  } finally {
    await personContext.close()
  }
})
