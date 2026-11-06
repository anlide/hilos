import { expect, test } from '../test.js'

import {
  ANALYTICS_SESSION_LIST_PATH,
  analyticsBrowserSessionPath,
  analyticsPeopleViewports,
  analyticsPersonLink,
  analyticsSessionLink,
  analyticsSessionListCell,
  analyticsSessionListViewports,
  createTestAdmin,
  deliveryObject,
  expectAnalyticsBrowserSessionHead,
  expectAnalyticsDashboardCard,
  expectAnalyticsOverview,
  expectAnalyticsOverviewTiles,
  expectAnalyticsPeopleFacets,
  expectAnalyticsPeoplePerson,
  expectAnalyticsPeopleWindow,
  expectAnalyticsPersonNavigation,
  expectAnalyticsPersonSession,
  expectAnalyticsSectionHead,
  expectAnalyticsSessionListFacets,
  expectAnalyticsSessionListRow,
  expectAnalyticsSessionListViewer,
  expectAnalyticsSessionListWindow,
  login,
  openSignIn,
  PASSWORD,
  readAnalyticsOverviewSnapshot,
  uniqueEmail,
  waitAnalyticsBrowserSession,
  watchDeliveryFrames,
  expectPageReady,
  gotoPage,
  grantAdminToSelf,
} from '../../../../../framework/frontend/e2e/index.js'
import { setAdminViewMode } from '../../../../../framework/frontend/scripts/adminViewMode.mjs'
import { createStandCommandChannel } from '../../../../../framework/frontend/scripts/standCommand.mjs'
import { seedTestUser } from '../../../../../framework/frontend/scripts/testUser.mjs'
import { AUTH_ACTION_LOGIN } from '../../../../../framework/frontend/core/src/index.js'

// spec-owner: demo — carries Tasks' React browser journeys for these shared pages

const sendCommand = createStandCommandChannel({ timeoutMs: 5_000 })

test.use({ timezoneId: 'Asia/Kathmandu' })

test('opens the Analytics overview tiles from the admin dashboard and keeps their Period in the Browser sessions address', async ({
  page,
}) => {
  const frames = watchDeliveryFrames(page)
  try {
    await grantAdminToSelf(expect, page)
    await gotoPage(expect, page, '/hilos')

    await expectAnalyticsDashboardCard(expect, page)
    const browserTimeZone = await page.evaluate(
      () => Intl.DateTimeFormat().resolvedOptions().timeZone,
    )
    const opened = frames.packets.length
    await page.getByTestId('dashboard-card-hilos_analytics').click()
    await expectPageReady(expect, page)
    await expectAnalyticsOverview(expect, page, 'react')

    const overview = frames.packets
      .slice(opened)
      .flatMap((packet) => packet.frames)
    await test.info().attach('analytics-overview-frames', {
      body: JSON.stringify(overview, null, 2),
      contentType: 'application/json',
    })
    const snapshot = readAnalyticsOverviewSnapshot(overview)

    await expectAnalyticsOverviewTiles(expect, page, snapshot, browserTimeZone)

    const period = page.getByTestId('hilos-table-filter-period')
    const subscriptions = () =>
      frames.sent
        .map(({ wire }) => deliveryObject(wire))
        .filter(
          (wire) =>
            wire.type === 'page_subscribe' &&
            wire.page === 'hilos_analytics_browser_sessions',
        )
    let opening = 0
    await test.step('each count opens Browser sessions on its own Period', async () => {
      // Each real tile owns a calendar address; its snapshot is not sent as list context.
      for (const [tile, value] of [
        ['week', 'this-week'],
        ['day', 'today'],
        ['hour', 'this-hour'],
      ] as const) {
        opening = frames.sent.length
        await page.getByTestId(`hilos-analytics-visitors-${tile}-link`).click()
        await expect(page).toHaveURL(`${ANALYTICS_SESSION_LIST_PATH}/${value}`)
        await expectPageReady(expect, page)
        await expectAnalyticsSessionListWindow(
          expect,
          page,
          frames,
          value,
          opening,
        )
        expect(
          analyticsSessionListViewports(frames).filter(
            (request) => frames.sent.indexOf(request) >= opening,
          ),
        ).toHaveLength(1)
        await page.getByTestId('hilos-page-tab-hilos_analytics').click()
        await expect(page).toHaveURL('/hilos/analytics')
        await expectPageReady(expect, page)
      }
    })

    await test.step('Period and Reset rewrite the address on the same subscription', async () => {
      opening = frames.sent.length
      await page
        .getByTestId('hilos-page-tab-hilos_analytics_browser_sessions')
        .click()
      await expect(page).toHaveURL(ANALYTICS_SESSION_LIST_PATH)
      await expectPageReady(expect, page)
      const today = await expectAnalyticsSessionListWindow(
        expect,
        page,
        frames,
        'today',
        opening,
      )
      const before = subscriptions().length
      opening = frames.sent.length
      await period.getByTestId('hilos-dropdown-toggle').click()
      await period.getByTestId('hilos-dropdown-option-2').click()
      await expect(period.getByTestId('hilos-dropdown-menu')).toBeHidden()
      const week = await expectAnalyticsSessionListWindow(
        expect,
        page,
        frames,
        'this-week',
        opening,
      )
      expect(week.request.filter).toEqual({
        ...today.context,
        period: 'this-week',
      })
      await expect(page).toHaveURL(`${ANALYTICS_SESSION_LIST_PATH}/this-week`)
      expect(subscriptions()).toHaveLength(before)
      opening = frames.sent.length
      await page.getByTestId('hilos-table-filter-reset').click()
      const reset = await expectAnalyticsSessionListWindow(
        expect,
        page,
        frames,
        undefined,
        opening,
      )
      expect(reset.request.filter).toEqual(today.context)
      await expect(page).toHaveURL(`${ANALYTICS_SESSION_LIST_PATH}/any-time`)
      await expect(period.getByTestId('hilos-dropdown-toggle')).toHaveText(
        'Period: Any time',
      )
      await expect(page.getByTestId('hilos-table-filter-badge')).toHaveCount(0)
      expect(subscriptions()).toHaveLength(before)
    })

    await test.step('F5, Back, Forward and an unknown tail keep the addressed Period', async () => {
      const before = subscriptions().length
      opening = frames.sent.length
      await page.reload()
      await expectPageReady(expect, page)
      await expectAnalyticsSessionListWindow(
        expect,
        page,
        frames,
        undefined,
        opening,
      )
      expect(subscriptions()).toHaveLength(before + 1)
      // Same-page history returns to the actual replaced address, not default Today.
      await page.getByTestId('hilos-page-tab-hilos_analytics').click()
      await expect(page).toHaveURL('/hilos/analytics')
      await expectPageReady(expect, page)
      opening = frames.sent.length
      await page.goBack()
      await expect(page).toHaveURL(`${ANALYTICS_SESSION_LIST_PATH}/any-time`)
      await expectPageReady(expect, page)
      await expectAnalyticsSessionListWindow(
        expect,
        page,
        frames,
        undefined,
        opening,
      )
      // One step deeper is the Overview the counts left; Forward reopens the
      // list on its addressed Period.
      await page.goBack()
      await expect(page).toHaveURL('/hilos/analytics')
      await expectPageReady(expect, page)
      await expect(
        page.getByTestId('hilos-analytics-overview-tiles'),
      ).toBeVisible()
      opening = frames.sent.length
      await page.goForward()
      await expect(page).toHaveURL(`${ANALYTICS_SESSION_LIST_PATH}/any-time`)
      await expectPageReady(expect, page)
      await expectAnalyticsSessionListWindow(
        expect,
        page,
        frames,
        undefined,
        opening,
      )
      for (const [tail, expected] of [
        ['unknown-period', 'today'],
        ['this-hour', 'this-hour'],
      ] as const) {
        opening = frames.sent.length
        await gotoPage(expect, page, `${ANALYTICS_SESSION_LIST_PATH}/${tail}`)
        await expectAnalyticsSessionListWindow(
          expect,
          page,
          frames,
          expected,
          opening,
        )
      }
    })
  } finally {
    await test.info().attach('analytics-session-list-period', {
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

test('opens a real person and session from People and Browser sessions and returns through their crumbs', async ({
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
  const adminContext = await browser.newContext({
    baseURL,
    ignoreHTTPSErrors,
    timezoneId: 'Asia/Baghdad',
  })
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
    const personPath = `/hilos/analytics/person/${user.userId}`
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

    const id = session.browserSessionId!
    const sessionPath = analyticsBrowserSessionPath(id)
    const sessionLabel = `Browser session #${id}`
    const expectListReturn = async () => {
      const returning = frames.sent.length
      await admin
        .getByTestId('hilos-breadcrumb-hilos_analytics_browser_sessions')
        .click()
      await expect(admin).toHaveURL(ANALYTICS_SESSION_LIST_PATH)
      await expectPageReady(expect, admin)
      await expectAnalyticsSessionListWindow(
        expect,
        admin,
        frames,
        'today',
        returning,
      )
    }
    await test.step('Browser sessions shows the real session and opens it', async () => {
      await admin
        .getByTestId('hilos-page-tab-hilos_analytics_browser_sessions')
        .click()
      await expect(admin).toHaveURL(ANALYTICS_SESSION_LIST_PATH)
      await expectPageReady(expect, admin)
      await expectAnalyticsSectionHead(expect, admin, 'sessions', 'react')
      const listed = await expectAnalyticsSessionListWindow(
        expect,
        admin,
        frames,
        'today',
      )
      expect(analyticsSessionListViewports(frames)).toHaveLength(1)
      expect(listed.request).toMatchObject({
        limit: 25,
        sort: [{ field: 'lastSeenTs', direction: 'desc' }],
      })
      await expectAnalyticsSessionListRow(
        expect,
        admin,
        listed,
        id,
        user,
        browserMajor!,
      )
      const period = admin.getByTestId('hilos-table-filter-period')
      await period.getByTestId('hilos-dropdown-toggle').click()
      await expectAnalyticsSessionListFacets(
        expect,
        admin,
        frames,
        listed.context,
      )
      await period.getByTestId('hilos-dropdown-toggle').press('Escape')
      await expect(period.getByTestId('hilos-dropdown-menu')).toBeHidden()

      await analyticsSessionListCell(admin, 'session', id).click()
      await expect(admin).toHaveURL(sessionPath)
      await expectPageReady(expect, admin)
      await expectAnalyticsBrowserSessionHead(expect, admin, sessionLabel)
      await expectListReturn()
    })

    await test.step('Signed in opens Person, whose session link has the same return', async () => {
      opening = bookmark()
      await analyticsSessionListCell(admin, 'person', id).click()
      await expectPersonBlock(opening)
      await analyticsSessionLink(admin, id).click()
      await expect(admin).toHaveURL(sessionPath)
      await expectPageReady(expect, admin)
      await expectAnalyticsBrowserSessionHead(expect, admin, sessionLabel)
      await expectListReturn()
    })
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

test('Browser sessions viewer receives hidden personal values with only public session links', async ({
  page,
  browser,
  baseURL,
  ignoreHTTPSErrors,
}) => {
  const userId = await createTestAdmin(expect, page)
  const session = await waitAnalyticsBrowserSession(expect, sendCommand, userId)
  const viewerContext = await browser.newContext({
    baseURL,
    ignoreHTTPSErrors,
    timezoneId: 'America/Santarem',
  })
  const viewer = await viewerContext.newPage()
  const frames = watchDeliveryFrames(viewer)
  try {
    await setAdminViewMode(true)
    await gotoPage(expect, viewer, ANALYTICS_SESSION_LIST_PATH)
    await expect(viewer.getByTestId('view-mode-banner')).toBeVisible()
    let window = await expectAnalyticsSessionListWindow(
      expect,
      viewer,
      frames,
      'today',
    )
    await expectAnalyticsSessionListViewer(expect, viewer, window)
    // Period counts are public: the viewer's facets answer like an admin's.
    const period = viewer.getByTestId('hilos-table-filter-period')
    await period.getByTestId('hilos-dropdown-toggle').click()
    await expectAnalyticsSessionListFacets(
      expect,
      viewer,
      frames,
      window.context,
    )
    await period.getByTestId('hilos-dropdown-toggle').press('Escape')
    await expect(period.getByTestId('hilos-dropdown-menu')).toBeHidden()
    // Viewer order uses public row numbers; the newly written row can be past 25.
    const pages = Math.ceil(
      Number(window.window.totalCount) / Number(window.window.limit),
    )
    for (
      let index = 1;
      index < pages &&
      !window.rows.some((row) => row.rowKey === session.browserSessionId);
      index++
    ) {
      const changing = frames.sent.length
      await viewer.getByTestId('hilos-table-next').click()
      window = await expectAnalyticsSessionListWindow(
        expect,
        viewer,
        frames,
        'today',
        changing,
      )
      await expectAnalyticsSessionListViewer(expect, viewer, window)
    }
    expect(window.rows.map((row) => row.rowKey)).toContain(
      session.browserSessionId,
    )
    await analyticsSessionListCell(
      viewer,
      'session',
      session.browserSessionId!,
    ).click()
    await expect(viewer).toHaveURL(
      analyticsBrowserSessionPath(session.browserSessionId!),
    )
    await expectPageReady(expect, viewer)
    await expectAnalyticsBrowserSessionHead(expect, viewer, 'Browser session')
    await expect(
      viewer.getByTestId('hilos-analytics-browser-session-header'),
    ).toHaveText('Hidden')
  } finally {
    await test.info().attach('analytics-session-list-viewer', {
      body: JSON.stringify(
        { sent: frames.sent, packets: frames.packets },
        null,
        2,
      ),
      contentType: 'application/json',
    })
    frames.stop()
    await viewerContext.close()
    await setAdminViewMode(false)
  }
})
