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
    await expectAnalyticsOverview(expect, page, 'angular')

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

test('People filters real sessions and opens the scoped Person through navigation and cold entries', async ({
  page,
  browser,
  baseURL,
  ignoreHTTPSErrors,
}) => {
  // Real sign-in, journal rotation and independent document entries exceed the base cap.
  test.slow()
  const user = await seedTestUser({ email: `person-${uniqueEmail()}` })
  await gotoPage(expect, page, '/')
  await openSignIn(expect, page)
  await login(expect, page, user.email, PASSWORD)
  const browserMajor = await page.evaluate(
    () => navigator.userAgent.match(/Chrome\/(\d+)/)?.[1],
  )
  expect(browserMajor).toBeDefined()
  const adminContext = await browser.newContext({
    baseURL,
    ignoreHTTPSErrors,
    timezoneId: 'Asia/Kathmandu',
  })
  const admin = await adminContext.newPage()
  const frames = watchDeliveryFrames(admin)
  try {
    const adminId = await grantAdminToSelf(expect, admin)
    expect(adminId).not.toBe(user.userId)
    const session = await waitAnalyticsBrowserSession(
      expect,
      sendCommand,
      user.userId,
      AUTH_ACTION_LOGIN,
    )
    const personPath = `/hilos/analytics/people/${user.userId}`
    const label = `${user.name} (#${user.userId})`
    const bookmark = () => ({
      sent: frames.sent.length,
      packets: frames.packets.length,
      sockets: frames.sockets(),
    })
    const expectPerson = async (opening: ReturnType<typeof bookmark>) => {
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
      await expect(admin).toHaveTitle(`${label} · Hilos Polls`)
      const replies = frames.packets
        .slice(opening.packets)
        .flatMap((packet) => packet.frames)
        .filter(({ wire }) => {
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
        pageHeadOwner: { page: 'hilos_analytics_person', label: 'Person' },
        pageBreadcrumb: expect.arrayContaining([
          { page: 'hilos_analytics_people', label: 'People' },
          { page: 'hilos_analytics_person', label: 'Person' },
        ]),
      })
      const requests = frames.sent
        .slice(opening.sent)
        .map(({ wire }) => deliveryObject(wire))
      expect(requests.filter((wire) => wire.type === 'page_subscribe')).toEqual(
        [
          expect.objectContaining({
            page: 'hilos_analytics_person',
            params: { userId: String(user.userId) },
          }),
        ],
      )
      // The first page reply supplies identity; no User or name/photo action follows.
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
        expect(viewport.filter).toEqual({ user: user.userId })
      }
    }

    await test.step('Overview opens People with one Today window for the real person', async () => {
      await gotoPage(expect, admin, '/hilos/analytics')
      await expectAnalyticsOverview(expect, admin, 'angular')
      await admin.getByTestId('hilos-page-tab-hilos_analytics_people').click()
      await expect(admin).toHaveURL('/hilos/analytics/people')
      await expectPageReady(expect, admin)
      await expectAnalyticsSectionHead(expect, admin, 'people', 'angular')
      const today = await expectAnalyticsPeopleWindow(
        expect,
        admin,
        frames,
        'today',
      )
      expect(analyticsPeopleViewports(frames)).toHaveLength(1)
      expect(today.request).toMatchObject({
        limit: 25,
        sort: [{ field: 'lastSeenTs', direction: 'desc' }],
      })
      await expectAnalyticsPeoplePerson(expect, admin, today, user)
    })

    await test.step('Period and Reset reuse the captured context for rows, totals and facets', async () => {
      const opening = frames.sent.length
      await gotoPage(expect, admin, '/hilos/analytics/people')
      await expectPageReady(expect, admin)
      const today = await expectAnalyticsPeopleWindow(
        expect,
        admin,
        frames,
        'today',
        opening,
      )
      expect(
        analyticsPeopleViewports(frames).filter(
          (frame) => frames.sent.indexOf(frame) >= opening,
        ),
      ).toHaveLength(1)
      const browserTimeZone = await admin.evaluate(
        () => Intl.DateTimeFormat().resolvedOptions().timeZone,
      )
      expect(
        new Intl.DateTimeFormat('en-GB', {
          timeZone: String(today.context.timeZone),
        }).resolvedOptions().timeZone,
      ).toBe(browserTimeZone)
      const period = admin.getByTestId('hilos-table-filter-period')
      await expect(period.getByTestId('hilos-dropdown-toggle')).toHaveText(
        'Period: Today',
      )
      await expect(admin.getByTestId('hilos-table-filter-badge')).toContainText(
        '1 filter',
      )
      for (const [option, selection, caption] of [
        ['2', 'this-week', 'This week'],
        ['-1', undefined, 'Any time'],
        ['0', 'this-hour', 'This hour'],
      ] as const) {
        await period.getByTestId('hilos-dropdown-toggle').click()
        await expectAnalyticsPeopleFacets(expect, admin, frames)
        const changing = frames.sent.length
        await period.getByTestId(`hilos-dropdown-option-${option}`).click()
        await expect(period.getByTestId('hilos-dropdown-menu')).toBeHidden()
        const window = await expectAnalyticsPeopleWindow(
          expect,
          admin,
          frames,
          selection,
          changing,
        )
        expect(window.request.filter).toEqual({
          ...today.context,
          ...(selection ? { period: selection } : {}),
        })
        await expect(period.getByTestId('hilos-dropdown-toggle')).toHaveText(
          `Period: ${caption}`,
        )
        await expectAnalyticsPeoplePerson(expect, admin, window, user)
      }
      const changing = frames.sent.length
      await admin.getByTestId('hilos-table-filter-reset').click()
      const reset = await expectAnalyticsPeopleWindow(
        expect,
        admin,
        frames,
        undefined,
        changing,
      )
      expect(reset.request.filter).toEqual(today.context)
      await expect(period.getByTestId('hilos-dropdown-toggle')).toHaveText(
        'Period: Any time',
      )
      await expect(admin.getByTestId('hilos-table-filter-badge')).toHaveCount(0)
      await expectAnalyticsPeoplePerson(expect, admin, reset, user)
      expect(
        analyticsPeopleViewports(frames)
          .filter((frame) => frames.sent.indexOf(frame) >= opening)
          .map(
            ({ wire }) => deliveryObject(deliveryObject(wire).filter).period,
          ),
      ).toEqual(['today', 'this-week', undefined, 'this-hour', undefined])
      const facets = frames.sent.slice(opening).filter(({ wire }) => {
        const request = deliveryObject(wire)
        return (
          request.page === 'hilos_analytics_people' &&
          request.facets !== undefined
        )
      })
      expect(facets.length).toBeGreaterThan(0)
      for (const frame of facets) {
        const request = deliveryObject(frame.wire)
        expect(request.tableKey).toBe('hilosAnalyticsPeople')
        // Facet options use the window already registered on this socket.
        const viewport = analyticsPeopleViewports(frames)
          .filter(({ socket, at }) => socket === frame.socket && at <= frame.at)
          .at(-1)
        expect(viewport).toBeDefined()
        const context = {
          ...deliveryObject(deliveryObject(viewport!.wire).filter),
        }
        delete context.period
        expect(context).toEqual(today.context)
        expect(request.facets).toEqual({
          period: ['this-hour', 'today', 'this-week'],
        })
      }
    })

    await test.step('People opens Person with name and photo in the first scoped reply', async () => {
      const opening = bookmark()
      await analyticsPersonLink(admin, user.userId).click()
      await expectPerson(opening)
    })
    await test.step('Direct entry and F5 keep the person and their real session', async () => {
      let opening = bookmark()
      await gotoPage(expect, admin, personPath)
      await expect.poll(() => frames.sockets()).toBeGreaterThan(opening.sockets)
      await expectPerson(opening)
      opening = bookmark()
      await admin.reload()
      await expect.poll(() => frames.sockets()).toBeGreaterThan(opening.sockets)
      await expectPerson(opening)
    })
    await test.step('People return, Back and reopening retain the correct target', async () => {
      const returning = frames.sent.length
      await admin.getByTestId('hilos-breadcrumb-hilos_analytics_people').click()
      await expect(admin).toHaveURL('/hilos/analytics/people')
      await expectPageReady(expect, admin)
      await expectAnalyticsSectionHead(expect, admin, 'people', 'angular')
      const today = await expectAnalyticsPeopleWindow(
        expect,
        admin,
        frames,
        'today',
        returning,
      )
      await expectAnalyticsPeoplePerson(expect, admin, today, user)
      let opening = bookmark()
      await admin.goBack()
      await expectPerson(opening)
      const forward = frames.sent.length
      await admin.goForward()
      await expectPageReady(expect, admin)
      const restored = await expectAnalyticsPeopleWindow(
        expect,
        admin,
        frames,
        'today',
        forward,
      )
      await expectAnalyticsPeoplePerson(expect, admin, restored, user)
      opening = bookmark()
      await analyticsPersonLink(admin, user.userId).click()
      await expectPerson(opening)
    })
  } finally {
    await test.info().attach('analytics-people-journey', {
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
