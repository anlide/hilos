import { expect, test } from '@playwright/test'

import {
  expectAnalyticsDashboardCard,
  expectAnalyticsOverview,
  expectAnalyticsOverviewTiles,
  readAnalyticsOverviewSnapshot,
  watchSocketFrames,
  type SocketFrame,
} from '../../../../../framework/frontend/e2e/index.js'
import { signUpAdmin } from '../helpers/adminGrant.js'
import { expectPageReady, gotoPage } from '../helpers/page.js'

test.use({ timezoneId: 'Asia/Kathmandu' })

test('opens the Analytics overview tiles from the admin dashboard', async ({
  page,
}) => {
  const frames: SocketFrame[] = []
  const observer = watchSocketFrames(page, (frame) => frames.push(frame))
  try {
    await signUpAdmin(page)
    await gotoPage(page, '/hilos')

    await expectAnalyticsDashboardCard(expect, page)
    const browserTimeZone = await page.evaluate(
      () => Intl.DateTimeFormat().resolvedOptions().timeZone,
    )
    const opening = frames.length
    await page.getByTestId('dashboard-card-hilos_analytics').click()
    await expectPageReady(page)
    await expectAnalyticsOverview(expect, page, 'tiles')

    await test.info().attach('analytics-overview-frames', {
      body: JSON.stringify(frames.slice(opening), null, 2),
      contentType: 'application/json',
    })
    const snapshot = readAnalyticsOverviewSnapshot(frames.slice(opening))

    await expectAnalyticsOverviewTiles(expect, page, snapshot, browserTimeZone)
  } finally {
    observer.stop()
  }
})
