import type { Locator, Page } from '@playwright/test'
import { test, expect } from '../test.js'

import {
  HilosPages,
  SIGNAL_TYPE_PAGE_RESPONSE,
} from '../../../../../framework/frontend/core/src/base/index.js'
import {
  USER_CONNECTIONS_SLOT,
  USER_ONLINE_SESSION_COUNT_FIELD,
} from '../../../../../framework/frontend/core/src/admin/users/index.js'
import {
  bindTestUser,
  bringLivenessMomentToNow,
  createTestUser,
  deliveryObject,
  openRawWebSocket,
  sessionToken,
  shownByTestId,
  typeInto,
  gotoPage,
  grantAdminToSelf,
  userColumnCell,
  watchSocketFrames,
  type RawWebSocket,
  type SignedInUser,
  type SocketFrame,
} from '../../../../../framework/frontend/e2e/index.js'

// Presence over silent sockets (HIL-1566). The master pings a browser socket that
// has said nothing for the idle span, holds it probably offline from a longer span
// and closes it at the longest; any frame from the browser ends the silence. The
// people list and the card say a person is online while one socket of theirs
// still answers, probably offline when every one of them is in doubt, and offline
// when none is left. The list counts distinct open sessions beside their presence;
// the card shows that count beside the total saved sessions, which outlive sockets.
//
// No browser keeps that silence — Chromium answers a ping by itself, whatever its
// page is doing — so the person's sockets here are raw ones
// (framework/frontend/e2e/rawWebSocket.ts), opened with the cookies of the
// person's own browser, which then leaves. The person signs in on two browsers and
// temporarily opens a second raw socket of the first session: three connections
// still mean two open sessions. Each browser leaves one raw socket before its
// liveness moment is moved. An administrator watches the list and card live, then
// reloads the card after the last close to compare the snapshot with the live zero.
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
 * The number of the person's distinct open sessions in their people-list row.
 * Multiple sockets of one session count once. The data cell is the one under
 * that column's header, and the number is its text region.
 *
 * @param list The observer's tab on the people list.
 * @param userId The person's id, the key of their row.
 * @returns The value of the row's Sessions cell.
 */
async function listOpenSessions(list: Page, userId: number): Promise<Locator> {
  const cell = await userColumnCell(
    shownByTestId(list, `hilos-table-row-${userId}`),
    list.getByTestId('hilos-table-sort-openSessionCount'),
  )

  return cell.locator('.hilos-table-text')
}

/**
 * Wait until the list and card agree on presence and distinct open sessions —
 * doubted sockets still keep their session open until they close. The person's
 * two saved sessions remain after every socket closes.
 *
 * @param card The observer's tab on the person's card.
 * @param list The observer's tab on the people list.
 * @param userId The person's id.
 * @param presence The word both badges say.
 * @param openSessionCount The number of distinct sessions with a registered socket.
 */
async function expectPresence(
  card: Page,
  list: Page,
  userId: number,
  presence: string,
  openSessionCount: number,
): Promise<void> {
  await expect(cardPresence(card)).toHaveText(presence)
  await expect(listPresence(list, userId)).toHaveText(presence)
  await expect(await listOpenSessions(list, userId)).toHaveText(
    String(openSessionCount),
  )
  await expect(
    card
      .getByTestId('hilos-user-detail')
      .getByText(`${openSessionCount} open now`, { exact: true }),
  ).toBeVisible()
  await expect(card.getByTestId('hilos-user-sessions')).toHaveText('2')
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
  const sockets: RawWebSocket[] = []
  let observer: ReturnType<typeof watchSocketFrames> | undefined
  const connectionSnapshot: { count?: unknown; frame?: SocketFrame } = {}
  try {
    await grantAdminToSelf(expect, page)
    const pageA = await contextA.newPage()
    const person = await createTestUser(expect, pageA)
    const tokenA = await leaveWithToken(pageA, person)
    const pageB = await contextB.newPage()
    await bindTestUser(expect, pageB, person)
    const tokenB = await leaveWithToken(pageB, person)
    const first = await openRawWebSocket(contextA, use)
    sockets.push(first)
    const second = await openRawWebSocket(contextB, use)
    sockets.push(second)

    observer = watchSocketFrames(page, (frame) => {
      const wire = deliveryObject(frame.wire)
      const data = deliveryObject(wire.data)
      if (
        wire.type !== SIGNAL_TYPE_PAGE_RESPONSE ||
        data.page !== HilosPages.USER
      )
        return
      const tables = deliveryObject(deliveryObject(data.payload).tables)
      const detail = deliveryObject(tables.userDetail)
      for (const value of Array.isArray(detail.rows) ? detail.rows : []) {
        const row = deliveryObject(value)
        if (row.rowKey !== person.userId) continue
        const connections = deliveryObject(
          deliveryObject(row.slots)[USER_CONNECTIONS_SLOT],
        )
        connectionSnapshot.count = connections[USER_ONLINE_SESSION_COUNT_FIELD]
        connectionSnapshot.frame = frame
      }
    })
    await gotoPage(expect, page, `/hilos/user/${person.userId}`)
    const list = await page.context().newPage()
    await gotoPage(expect, list, '/hilos/users')
    await typeInto(list.getByTestId('hilos-table-search'), person.name)
    await expectPresence(page, list, person.userId, 'online', 2)

    // Three connections of two sessions: the extra socket changes neither count.
    const extra = await openRawWebSocket(contextA, use)
    sockets.push(extra)
    // The 101 precedes runtime registration. Wait for that registration before
    // closing, then for its deletion before moving either browser's liveness.
    await expect.poll(() => connectionSnapshot.count).toBe(3)
    expect(connectionSnapshot.frame?.failure).toBeUndefined()
    expect(connectionSnapshot.frame?.raw).toEqual(expect.any(String))
    await expectPresence(page, list, person.userId, 'online', 2)
    expect(await extra.close()).toBe('client')
    await expect.poll(() => connectionSnapshot.count).toBe(2)
    expect(connectionSnapshot.frame?.failure).toBeUndefined()
    expect(connectionSnapshot.frame?.raw).toEqual(expect.any(String))
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

    // A fresh snapshot keeps the same known zero and the two saved sessions.
    await page.reload()
    await expectPresence(page, list, person.userId, 'offline', 0)

    // A new socket of the same person brings them back to the same observer.
    const again = await openRawWebSocket(contextA, use)
    sockets.push(again)
    await expectPresence(page, list, person.userId, 'online', 1)
    await again.close()
  } finally {
    observer?.stop()
    await Promise.all(sockets.map((socket) => socket.close()))
    await contextA.close()
    await contextB.close()
  }
})
