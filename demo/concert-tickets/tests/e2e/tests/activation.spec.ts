import { expect, test } from '../test.js'

import {
  PAGE_READY,
  PAGE_REFUSED,
  PASSWORD,
  expectAnalyticsOverviewTiles,
  expectAnalyticsPeoplePerson,
  expectAnalyticsPeopleWindow,
  expectPageReady,
  gotoPage,
  login,
  openSignIn,
  readAnalyticsOverviewSnapshot,
  uniqueEmail,
  waitAnalyticsBrowserSession,
  watchDeliveryFrames,
  watchPageReplies,
  watchSocketFrames,
  type SocketFrame,
} from '../../../../../framework/frontend/e2e/index.js'
import {
  AUTH_ACTION_LOGIN,
  HilosPages,
  resolveHilosPath,
} from '../../../../../framework/frontend/core/src/index.js'
import { setAdmin } from '../../../../../framework/frontend/scripts/adminGrant.mjs'
import { createStandCommandChannel } from '../../../../../framework/frontend/scripts/standCommand.mjs'
import { seedTestUser } from '../../../../../framework/frontend/scripts/testUser.mjs'

const sendCommand = createStandCommandChannel({ timeoutMs: 5_000 })

test.use({ timezoneId: 'Antarctica/Casey' })

test('guest opens the empty home and is refused the admin Analytics', async ({
  page,
}) => {
  const { replies } = watchPageReplies(page)
  await gotoPage(expect, page, '/', PAGE_READY)
  await expect(page.getByTestId('conn-state')).toHaveText('connected')
  expect(replies).toContainEqual(
    expect.objectContaining({ type: 'page_response', page: 'main' }),
  )

  await gotoPage(
    expect,
    page,
    resolveHilosPath(HilosPages.ANALYTICS),
    PAGE_REFUSED,
  )
})

test('a real sign-in shows up in the working Analytics section', async ({
  page,
}) => {
  const user = await seedTestUser({ email: uniqueEmail() })
  const frames: SocketFrame[] = []
  const observer = watchSocketFrames(page, (frame) => frames.push(frame))
  const delivery = watchDeliveryFrames(page)
  try {
    await gotoPage(expect, page, '/', PAGE_READY)
    await openSignIn(expect, page)
    await login(expect, page, user.email, PASSWORD)
    await setAdmin(user.userId, true)
    await waitAnalyticsBrowserSession(
      expect,
      sendCommand,
      user.userId,
      AUTH_ACTION_LOGIN,
    )

    const overviewOpening = frames.length
    await gotoPage(
      expect,
      page,
      resolveHilosPath(HilosPages.ANALYTICS),
      PAGE_READY,
    )
    const snapshot = readAnalyticsOverviewSnapshot(
      frames.slice(overviewOpening),
    )
    expect(snapshot.visitorsToday).toBeGreaterThan(0)
    const browserTimeZone = await page.evaluate(
      () => Intl.DateTimeFormat().resolvedOptions().timeZone,
    )
    await expectAnalyticsOverviewTiles(expect, page, snapshot, browserTimeZone)

    await page.getByTestId('hilos-page-tab-hilos_analytics_people').click()
    await expectPageReady(expect, page)
    const people = await expectAnalyticsPeopleWindow(
      expect,
      page,
      delivery,
      'today',
    )
    await expectAnalyticsPeoplePerson(expect, page, people, user)
  } finally {
    observer.stop()
    delivery.stop()
  }
})
