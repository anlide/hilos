import { expect, test } from '@playwright/test'

import {
  analyticsPeopleViewports,
  analyticsPersonLink,
  deliveryObject,
  expectAnalyticsDashboardCard,
  expectAnalyticsOverview,
  expectAnalyticsOverviewTiles,
  expectAnalyticsPeopleFacets,
  expectAnalyticsPeoplePerson,
  expectAnalyticsPeopleWindow,
  expectAnalyticsPersonNavigation,
  expectAnalyticsPersonSession,
  expectAnalyticsSectionHead,
  readAnalyticsOverviewSnapshot,
  watchDeliveryFrames,
  watchSocketFrames,
  waitAnalyticsBrowserSession,
  type SocketFrame,
} from '../../../../../framework/frontend/e2e/index.js'
import { createCommandChannel } from '../../../../../framework/frontend/scripts/commandChannel.mjs'
import { signUpAdmin } from '../helpers/adminGrant.js'
import { expectPageReady, gotoPage } from '../helpers/page.js'
import { signUp } from '../helpers/session.js'

const sendCommand = createCommandChannel({
  host: process.env.COMMAND_HOST ?? 'chat-test',
  port: Number(process.env.COMMAND_PORT ?? 8094),
  timeoutMs: 5_000,
})

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

test('opens a real person from Analytics People and returns through People crumbs', async ({
  page,
  browser,
  baseURL,
  ignoreHTTPSErrors,
}) => {
  // Two registrations and the real journal-writer wait exceed the base cap.
  test.slow()
  const user = await signUp(page)
  const browserMajor = await page.evaluate(
    () => navigator.userAgent.match(/Chrome\/(\d+)/)?.[1],
  )
  expect(browserMajor).toBeDefined()
  const adminContext = await browser.newContext({ baseURL, ignoreHTTPSErrors })
  const admin = await adminContext.newPage()
  const frames = watchDeliveryFrames(admin)
  try {
    await signUpAdmin(admin)
    const session = await waitAnalyticsBrowserSession(
      expect,
      sendCommand,
      user.userId,
    )
    await gotoPage(admin, '/hilos/analytics')
    await expectAnalyticsSectionHead(expect, admin, 'overview')
    await admin.getByTestId('hilos-page-tab-hilos_analytics_people').click()
    await expect(admin).toHaveURL('/hilos/analytics/people')
    await expectPageReady(admin)
    await expectAnalyticsSectionHead(expect, admin, 'people')
    const today = await expectAnalyticsPeopleWindow(
      expect,
      admin,
      frames,
      'today',
    )
    await expectAnalyticsPeoplePerson(expect, admin, today, user)

    await analyticsPersonLink(admin, user.userId).click()
    await expect(admin).toHaveURL(`/hilos/analytics/people/${user.userId}`)
    await expectPageReady(admin)
    await expectAnalyticsPersonSession(
      expect,
      admin,
      user,
      session,
      browserMajor!,
    )
    await expectAnalyticsPersonNavigation(expect, admin, frames, user)

    const returning = frames.sent.length
    await admin.getByTestId('hilos-breadcrumb-hilos_analytics_people').click()
    await expect(admin).toHaveURL('/hilos/analytics/people')
    await expectPageReady(admin)
    await expectAnalyticsSectionHead(expect, admin, 'people')
    const returned = await expectAnalyticsPeopleWindow(
      expect,
      admin,
      frames,
      'today',
      returning,
    )
    await expectAnalyticsPeoplePerson(expect, admin, returned, user)
  } finally {
    await test.info().attach('analytics-people-navigation', {
      body: JSON.stringify(
        { sent: frames.sent, packets: frames.packets },
        null,
        2,
      ),
      contentType: 'application/json',
    })
    frames.stop()
    await adminContext.close()
  }
})

test('People starts with one Today viewport and resets only its Period filter', async ({
  page,
}) => {
  // The probe waits for the real rotating analytics journal before opening People.
  test.slow()
  const frames = watchDeliveryFrames(page)
  try {
    const userId = await signUpAdmin(page)
    const name = await page.getByTestId('self-user').innerText()
    await waitAnalyticsBrowserSession(expect, sendCommand, userId)
    // A document entry exercises the buffered page_response and lazy view mount.
    await gotoPage(page, '/hilos/analytics/people')
    await expectPageReady(page)
    const today = await expectAnalyticsPeopleWindow(
      expect,
      page,
      frames,
      'today',
    )
    expect(analyticsPeopleViewports(frames)).toHaveLength(1)
    expect(today.request).toMatchObject({
      limit: 25,
      sort: [{ field: 'lastSeenTs', direction: 'desc' }],
    })
    const browserTimeZone = await page.evaluate(
      () => Intl.DateTimeFormat().resolvedOptions().timeZone,
    )
    expect(
      new Intl.DateTimeFormat('en-GB', {
        timeZone: String(today.context.timeZone),
      }).resolvedOptions().timeZone,
    ).toBe(browserTimeZone)
    await expectAnalyticsPeoplePerson(expect, page, today, { userId, name })
    const period = page.getByTestId('hilos-table-filter-period')
    await expect(period.getByTestId('hilos-dropdown-toggle')).toHaveText(
      'Period: Today',
    )
    await expect(page.getByTestId('hilos-table-filter-badge')).toContainText(
      '1 filter',
    )
    await period.getByTestId('hilos-dropdown-toggle').click()
    await expectAnalyticsPeopleFacets(expect, page, frames)

    let changing = frames.sent.length
    await period.getByTestId('hilos-dropdown-option-2').click()
    await expect(period.getByTestId('hilos-dropdown-menu')).toBeHidden()
    const week = await expectAnalyticsPeopleWindow(
      expect,
      page,
      frames,
      'this-week',
      changing,
    )
    expect(week.request.filter).toEqual({
      ...today.context,
      period: 'this-week',
    })
    await expect(period.getByTestId('hilos-dropdown-toggle')).toHaveText(
      'Period: This week',
    )
    await expectAnalyticsPeoplePerson(expect, page, week, { userId, name })

    changing = frames.sent.length
    await period.getByTestId('hilos-dropdown-toggle').click()
    await period.getByTestId('hilos-dropdown-option--1').click()
    await expect(period.getByTestId('hilos-dropdown-menu')).toBeHidden()
    const any = await expectAnalyticsPeopleWindow(
      expect,
      page,
      frames,
      undefined,
      changing,
    )
    expect(any.request.filter).toEqual(today.context)
    await expect(period.getByTestId('hilos-dropdown-toggle')).toHaveText(
      'Period: Any time',
    )
    await expect(page.getByTestId('hilos-table-filter-badge')).toHaveCount(0)

    changing = frames.sent.length
    await period.getByTestId('hilos-dropdown-toggle').click()
    await period.getByTestId('hilos-dropdown-option-0').click()
    await expect(period.getByTestId('hilos-dropdown-menu')).toBeHidden()
    const hour = await expectAnalyticsPeopleWindow(
      expect,
      page,
      frames,
      'this-hour',
      changing,
    )
    expect(hour.request.filter).toEqual({
      ...today.context,
      period: 'this-hour',
    })
    await expect(page.getByTestId('hilos-table-filter-badge')).toContainText(
      '1 filter',
    )
    changing = frames.sent.length
    await page.getByTestId('hilos-table-filter-reset').click()
    const reset = await expectAnalyticsPeopleWindow(
      expect,
      page,
      frames,
      undefined,
      changing,
    )
    expect(reset.request.filter).toEqual(today.context)
    await expect(period.getByTestId('hilos-dropdown-toggle')).toHaveText(
      'Period: Any time',
    )
    await expect(page.getByTestId('hilos-table-filter-badge')).toHaveCount(0)
    await expectAnalyticsPeoplePerson(expect, page, reset, { userId, name })
    expect(
      analyticsPeopleViewports(frames).map(
        ({ wire }) => deliveryObject(deliveryObject(wire).filter).period,
      ),
    ).toEqual(['today', 'this-week', undefined, 'this-hour', undefined])
  } finally {
    await test.info().attach('analytics-people-period', {
      body: JSON.stringify(
        { sent: frames.sent, packets: frames.packets },
        null,
        2,
      ),
      contentType: 'application/json',
    })
    frames.stop()
  }
})
