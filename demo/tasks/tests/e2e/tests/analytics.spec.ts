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
  login,
  openSignIn,
  PASSWORD,
  readAnalyticsOverviewSnapshot,
  uniqueEmail,
  waitAnalyticsBrowserSession,
  watchDeliveryFrames,
  watchSocketFrames,
  type SocketFrame,
  expectPageReady,
  gotoPage,
  grantAdminToSelf,
} from '../../../../../framework/frontend/e2e/index.js'
import { createStandCommandChannel } from '../../../../../framework/frontend/scripts/standCommand.mjs'
import { seedTestUser } from '../../../../../framework/frontend/scripts/testUser.mjs'
import { AUTH_ACTION_LOGIN } from '../../../../../framework/frontend/core/src/index.js'

const sendCommand = createStandCommandChannel({ timeoutMs: 5_000 })

test.use({ timezoneId: 'Asia/Kathmandu' })

test('opens the Analytics overview tiles from the admin dashboard', async ({
  page,
}) => {
  const frames: SocketFrame[] = []
  const observer = watchSocketFrames(page, (frame) => frames.push(frame))
  try {
    await grantAdminToSelf(expect, page)
    await gotoPage(expect, page, '/hilos')

    await expectAnalyticsDashboardCard(expect, page)
    const browserTimeZone = await page.evaluate(
      () => Intl.DateTimeFormat().resolvedOptions().timeZone,
    )
    const opening = frames.length
    await page.getByTestId('dashboard-card-hilos_analytics').click()
    await expectPageReady(expect, page)
    await expectAnalyticsOverview(expect, page, 'react')

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
  const user = await seedTestUser({ email: uniqueEmail() })
  await gotoPage(expect, page, '/')
  await openSignIn(expect, page)
  await login(expect, page, user.email, PASSWORD)
  expect(user.userId).toBeGreaterThan(0)
  const browserMajor = await page.evaluate(
    () => navigator.userAgent.match(/Chrome\/(\d+)/)?.[1],
  )
  expect(browserMajor).toBeDefined()
  const adminContext = await browser.newContext({ baseURL, ignoreHTTPSErrors })
  const admin = await adminContext.newPage()
  const frames = watchDeliveryFrames(admin)
  try {
    const adminId = await grantAdminToSelf(expect, admin)
    expect(adminId).not.toBe(user.userId)
    const adminName = await admin.getByTestId('self-user').innerText()
    expect(adminName).not.toBe(user.name)
    const session = await waitAnalyticsBrowserSession(
      expect,
      sendCommand,
      user.userId,
      AUTH_ACTION_LOGIN,
    )
    const personPath = `/hilos/analytics/people/${user.userId}`
    const label = `${user.name} (#${user.userId})`
    const lead =
      'Browser sessions where this person signed in last. Other people may have performed actions in the same browser.'
    const bookmark = () => ({
      sent: frames.sent.length,
      packets: frames.packets.length,
      sockets: frames.sockets(),
    })
    const expectPersonBlock = async (opening: ReturnType<typeof bookmark>) => {
      await expect(admin).toHaveURL(personPath)
      await expectPageReady(expect, admin)
      await expectAnalyticsPersonSession(
        expect,
        admin,
        user,
        session,
        browserMajor!,
      )
      await expectAnalyticsPersonNavigation(expect, admin, frames, user)
      const head = admin.getByTestId('hilos-page-head')
      await expect(
        head.getByTestId('analytics-person-head-description'),
      ).toHaveText(lead)
      await expect(admin).toHaveTitle(`${label} · Hilos Tasks`)

      // The completed head and session row bound the observation interval.
      // Read fresh frames on every entry, including new document sockets.
      const incoming = frames.packets
        .slice(opening.packets)
        .flatMap((packet) => packet.frames)
      const replies = incoming.filter(({ wire }) => {
        const response = deliveryObject(wire)
        return (
          response.type === 'page_response' &&
          deliveryObject(response.data).page === 'hilos_analytics_person'
        )
      })
      expect(replies).toHaveLength(1)
      expect(replies[0].failure).toBeUndefined()
      expect(replies[0].socket).toBe(frames.sockets())
      const data = deliveryObject(
        deliveryObject(deliveryObject(replies[0].wire).data).payload,
      ).data
      expect(data).toMatchObject({
        pageLabel: label,
        name: user.name,
        photo: null,
        pageLead: lead,
        pageHeadOwner: {
          page: 'hilos_analytics_person',
          label: 'Person',
          lead,
        },
        pageBreadcrumb: [
          { page: 'hilos', label: 'Hilos' },
          { page: 'hilos_analytics', label: 'Analytics' },
          { page: 'hilos_analytics_people', label: 'People' },
          { page: 'hilos_analytics_person', label: 'Person' },
        ],
        pageChildren: [],
        pageTabs: [{ page: 'hilos_analytics_person', label: 'Overview' }],
      })
      const requests = frames.sent
        .slice(opening.sent)
        .map(({ wire }) => deliveryObject(wire))
      expect(
        requests
          .filter((wire) => wire.type === 'page_subscribe')
          .map((wire) => wire.page),
      ).toEqual(['hilos_analytics_person'])
      // Neither a User/card subscription nor a catalog/name/photo action is needed.
      expect(requests.filter((wire) => wire.type === 'action')).toEqual([])
      const viewports = requests.filter(
        (wire) => wire.type === 'table_viewport',
      )
      expect(viewports.length).toBeGreaterThan(0)
      for (const viewport of viewports) {
        expect(viewport).toMatchObject({
          page: 'hilos_analytics_person',
          tableKey: 'hilosAnalyticsBrowserSessions',
          filter: { user: user.userId },
        })
        expect(JSON.stringify(viewport)).not.toContain('period')
      }
    }
    await gotoPage(expect, admin, '/hilos/analytics')
    await expectAnalyticsSectionHead(expect, admin, 'overview', 'react')
    await admin.getByTestId('hilos-page-tab-hilos_analytics_people').click()
    await expect(admin).toHaveURL('/hilos/analytics/people')
    await expectPageReady(expect, admin)
    await expectAnalyticsSectionHead(expect, admin, 'people', 'react')
    const today = await expectAnalyticsPeopleWindow(
      expect,
      admin,
      frames,
      'today',
    )
    await expectAnalyticsPeoplePerson(expect, admin, today, user)

    const changing = frames.sent.length
    const period = admin.getByTestId('hilos-table-filter-period')
    await period.getByTestId('hilos-dropdown-toggle').click()
    await period.getByTestId('hilos-dropdown-option-2').click()
    await expect(period.getByTestId('hilos-dropdown-menu')).toBeHidden()
    const week = await expectAnalyticsPeopleWindow(
      expect,
      admin,
      frames,
      'this-week',
      changing,
    )
    await expectAnalyticsPeoplePerson(expect, admin, week, user)

    let opening = bookmark()
    await analyticsPersonLink(admin, user.userId).click()
    await expectPersonBlock(opening)

    opening = bookmark()
    await gotoPage(expect, admin, personPath)
    await expect.poll(() => frames.sockets()).toBeGreaterThan(opening.sockets)
    await expectPersonBlock(opening)

    opening = bookmark()
    await admin.reload()
    await expect.poll(() => frames.sockets()).toBeGreaterThan(opening.sockets)
    await expectPersonBlock(opening)

    const returning = frames.sent.length
    await admin.getByTestId('hilos-breadcrumb-hilos_analytics_people').click()
    await expect(admin).toHaveURL('/hilos/analytics/people')
    await expectPageReady(expect, admin)
    await expectAnalyticsSectionHead(expect, admin, 'people', 'react')
    const returned = await expectAnalyticsPeopleWindow(
      expect,
      admin,
      frames,
      'today',
      returning,
    )
    await expectAnalyticsPeoplePerson(expect, admin, returned, user)

    opening = bookmark()
    await admin.goBack()
    await expectPersonBlock(opening)

    const forwarding = frames.sent.length
    await admin.goForward()
    await expect(admin).toHaveURL('/hilos/analytics/people')
    await expectPageReady(expect, admin)
    const forwarded = await expectAnalyticsPeopleWindow(
      expect,
      admin,
      frames,
      'today',
      forwarding,
    )
    await expectAnalyticsPeoplePerson(expect, admin, forwarded, user)
  } finally {
    await test.info().attach('analytics-people-navigation', {
      body: JSON.stringify(
        {
          connections: frames.connections,
          sent: frames.sent,
          packets: frames.packets,
        },
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
  const frames = watchDeliveryFrames(page)
  try {
    const user = await seedTestUser({ email: uniqueEmail() })
    await gotoPage(expect, page, '/')
    await openSignIn(expect, page)
    await login(expect, page, user.email, PASSWORD)
    await grantAdminToSelf(expect, page)
    const { userId, name } = user
    await waitAnalyticsBrowserSession(
      expect,
      sendCommand,
      userId,
      AUTH_ACTION_LOGIN,
    )
    // A document entry exercises the buffered page_response and lazy view mount.
    await gotoPage(expect, page, '/hilos/analytics/people')
    await expectPageReady(expect, page)
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
    const facetRequests = frames.sent
      .map(({ wire }) => deliveryObject(wire))
      .filter(
        (wire) =>
          (wire.type === 'table_facets' || wire.type === 'table_viewport') &&
          wire.page === 'hilos_analytics_people' &&
          wire.facets !== undefined,
      )
    expect(facetRequests.length).toBeGreaterThan(0)
    for (const request of facetRequests) {
      expect(request.tableKey).toBe('hilosAnalyticsPeople')
      expect(request.facets).toEqual({
        period: ['this-hour', 'today', 'this-week'],
      })
    }

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
