import { expect, test } from '../test.js'

import { setAdminViewMode } from '../../../../../framework/frontend/scripts/adminViewMode.mjs'
import { PAGE_ERROR_NOT_FOUND } from '../../../../../framework/frontend/core/src/protocol/pageError.js'
import { resolveHilosPath } from '../../../../../framework/frontend/core/src/routing/hilosAdmin.js'
import { HilosPages } from '../../../../../framework/frontend/core/src/routing/hilosPages.js'
import {
  DAEMON_ROOT_PATH,
  DAEMON_PLACEMENT_PATH,
  expectDaemonSectionHead,
  expectDaemonPlacementAnswer,
  expectDaemonPlacementSourceReady,
  expectDaemonPlacementPending,
  expectDaemonPlacementRequests,
  expectDaemonRootEntryPoints,
  expectDaemonPlacementOwnership,
  expectDaemonSectionAccessibility,
  searchDaemonPlacement,
  expectDaemonPlacementReset,
  watchDaemonPlacementLive,
  watchTableSearch,
  watchDeliveryFrames,
  DAEMON_NODE_PATH,
  DAEMON_NODE_TABS,
  expectDaemonNodeHead,
  expectDaemonNodeFits,
  watchPageErrorFrames,
  expectDaemonNodeTableName,
  expectStandaloneNodeOverview,
  holdPageReply,
  countSentFrames,
  watchDaemonNodeFrames,
  watchDaemonWebsocketsFrames,
  readDaemonWebsocketsCount,
  expectStandaloneWebsockets,
  watchHeight,
  expectDaemonNodeAnswer,
  expectDaemonNodeLive,
  DAEMON_WORKERS_PATH,
  DAEMON_WORKERS_CHILD,
  DAEMON_ROOT_CRUMB,
  DAEMON_PLACEMENT_TYPE,
  DAEMON_PLACEMENT_LONG_TYPE,
  DAEMON_PLACEMENT_CELL,
  DAEMON_PLACEMENT_TOTALS,
  expectDaemonPage,
  expectStandaloneOverview,
  expectStandalonePlacement,
  expectUnfilteredTotals,
  expectStandaloneWorkers,
  expectStandaloneCron,
  expectDaemonCronQuery,
  readDaemonCronMoment,
  expectDaemonCronMoments,
  watchDaemonCronDisplay,
  reportDaemonNodeUntil,
  forwardSessionTimeZone,
  expectTimeZoneEnvironment,
  deliveryObject,
  expectStandaloneHttp,
  expectStandaloneEnvironment,
  expectStandaloneEnvOrphans,
  expectViewerEnvOrphans,
  expectStandaloneMismatch,
  ROWS,
  fitsInScrollport,
  shownByTestId,
  gotoPage,
  PAGE_READY,
  grantAdminToSelf,
  RESTART_AGENT,
  readRestartWorker,
  withStoppedRestartWorker,
  typeInto,
  DAEMON_STANDALONE_NODE_NAME,
} from '../../../../../framework/frontend/e2e/index.js'

// The live standalone Daemon section: navigation, placement, node reports,
// keyboard access, themes, viewer privacy and the shared restart mark.
// The forged viewer restart request is covered on Vue as a server check.
// Kit unit tests cover Cron idle/empty and cluster mismatches (P-572);
// cluster coverage lives in binance-btc-tracker's tests/e2e/cluster/daemon/daemon.spec.ts.
const DATABASE_HOST = 'tasks-mysql-test'

test.describe('Cron time zones', () => {
  test.use({
    timezoneId: 'America/Los_Angeles',
    locale: 'en-US',
    colorScheme: 'light',
  })

  test('reads real runs in delivered reader zones and Automatic while the project stays Warsaw', async ({
    page,
  }) => {
    const browserZone = 'America/Los_Angeles'
    const projectZone = 'Europe/Warsaw'
    const rowKey = 'hilos_sessions_library/hilos_sessions_expire'
    const subscribes = countSentFrames(page, '"type":"page_subscribe"')
    const viewports = countSentFrames(page, '"type":"table_viewport"')
    // Only currentUser.timeZone is projected. The welcome and Cron frames below
    // are the unmodified daemon evidence, not a proof of a backend own-zone write.
    const socket = await forwardSessionTimeZone(page, browserZone)
    try {
      const userId = await grantAdminToSelf(expect, page)
      // The project zone comes from Tasks tests/.env and the test compose stack
      // (HILOS_PROJECT_TIME_ZONE=Europe/Warsaw), read back from the real welcome.
      await expectTimeZoneEnvironment(expect, page, socket.frames, {
        browserTimeZone: browserZone,
        projectTimeZone: projectZone,
      })
      // The expected run texts are composed in en-US, so the browser must read so.
      expect(await page.evaluate(() => navigator.language)).toBe('en-US')
      expect(browserZone).not.toBe(projectZone)
      await gotoPage(expect, page, `${DAEMON_NODE_PATH}/cron`, PAGE_READY)
      await expectStandaloneCron(expect, page)
      await expect(
        page.getByTestId('hilos-daemon-cron-project-time-zone'),
      ).toHaveText(projectZone)
      await expect
        .poll(() => readDaemonCronMoment(socket.frames, rowKey))
        .toBeDefined()
      let rule = readDaemonCronMoment(socket.frames, rowKey)!.rule
      const display = await watchDaemonCronDisplay(expect, page, rowKey)
      try {
        const connections = socket.sockets()
        const requests = () => ({
          subscribes: subscribes(),
          viewports: viewports(),
        })
        const baseline = requests()
        let initial:
          Awaited<ReturnType<typeof expectDaemonCronMoments>> | undefined
        let fired = false
        for (const own of [browserZone, 'Asia/Tokyo', null]) {
          if (own !== browserZone) socket.replay(own)
          const zone = own ?? browserZone
          const times = await expectDaemonCronMoments(
            expect,
            page,
            socket.frames,
            rowKey,
            zone,
          )
          // A delivered reader zone changes the presentation only: the raw
          // moments stay as they were. The one frame that may move them is a
          // real firing of this 15-minute rule: lastRunAt is the actual
          // firing second, at or after the former nextRunAt and before the
          // new one. It is read from the current frame whenever it comes,
          // neither guarded against nor waited out.
          const current = readDaemonCronMoment(socket.frames, rowKey)!.rule
          const formerNext = rule.nextRunAt as number
          const { lastRunAt, nextRunAt } = current
          if (
            typeof lastRunAt === 'number' &&
            typeof nextRunAt === 'number' &&
            lastRunAt >= formerNext &&
            lastRunAt < nextRunAt &&
            nextRunAt > formerNext
          ) {
            expect(current).toEqual({
              ...rule,
              lastRunAt: expect.any(Number),
              nextRunAt: expect.any(Number),
            })
            rule = current
            fired = true
          } else expect(current).toEqual(rule)
          // Tokyo shows the moments otherwise than the browser zone did, and
          // Automatic shows them as the browser zone did while they are still
          // the same moments; after a firing the oracle above has already held
          // the row to the browser zone.
          if (own === browserZone) initial = times
          else if (own !== null) expect(times).not.toEqual(initial)
          else if (!fired) expect(times).toEqual(initial)
          expect(socket.sessions.at(-1)).toMatchObject({
            data: { entities: { currentUser: { id: userId, timeZone: own } } },
          })
          // The project zone and the open subscription stay as they were.
          await expect(
            page.getByTestId('hilos-daemon-cron-project-time-zone'),
          ).toHaveText(projectZone)
          expect(requests()).toEqual(baseline)
          expect(socket.sockets()).toBe(connections)
          await display.unchanged()
        }
      } finally {
        await display.dispose()
      }
    } finally {
      socket.dispose()
    }
  })
})

test.describe('Cron search and ordering', () => {
  test.afterEach(() => setAdminViewMode(false))

  for (const viewer of [false, true]) {
    test(`searches real rules and cycles both orders as ${viewer ? 'a viewer' : 'an admin'}`, async ({
      page,
    }) => {
      const windows = await watchTableSearch(page, {
        page: 'hilos_daemon_cron',
        tableKey: 'hilosDaemonCron',
      })
      const proof: unknown[] = []
      try {
        await setAdminViewMode(viewer)
        if (!viewer) await grantAdminToSelf(expect, page)
        await gotoPage(expect, page, `${DAEMON_NODE_PATH}/cron`, PAGE_READY)
        await expectStandaloneCron(expect, page)
        await expect(page.getByTestId('hilos-table-loading')).toHaveCount(0)
        if (viewer) {
          await expect(page.getByTestId('view-mode-banner')).toBeVisible()
          await expect(page.getByTestId('nav-admin')).toHaveAttribute(
            'data-access',
            'view',
          )
        }

        const search = shownByTestId(page, 'hilos-table-search')
        await expect(search).toHaveAccessibleName('Find by rule or agent')
        const initial = await windows.read()
        const initialRows = initial.frames
          .filter(
            (frame) =>
              frame.socket === initial.socket && frame.direction === 'received',
          )
          .flatMap((frame) => {
            const data = deliveryObject(frame.wire.data)
            const window =
              frame.wire.type === 'page_response'
                ? deliveryObject(
                    deliveryObject(deliveryObject(data.payload).windows)
                      .hilosDaemonCron,
                  )
                : frame.wire.type === 'table_window'
                  ? data
                  : undefined
            return Array.isArray(window?.rows) ? [window.rows] : []
          })
          .at(-1) as unknown[]
        expect(initialRows.length).toBeGreaterThan(1)
        const initialRules = initialRows.map((row) =>
          deliveryObject(deliveryObject(deliveryObject(row).slots).rule),
        )
        const target = initialRules.find(
          (rule) =>
            typeof rule.name === 'string' &&
            typeof rule.agentId === 'string' &&
            !rule.name.includes(rule.agentId) &&
            initialRules.filter((other) => other.agentId === rule.agentId)
              .length > 1,
        )!
        expect(target).toBeDefined()
        const name = String(target.name)
        const agentId = String(target.agentId)
        for (const field of ['name', 'nextRunAt']) {
          await expect(
            page.getByTestId(`hilos-table-sort-${field}`).locator('..'),
          ).toHaveAttribute('aria-sort', 'none')
        }

        const missing = 'no-such-cron-rule-or-agent'
        const steps: {
          search: string
          control: string
          field?: 'name' | 'nextRunAt'
          direction?: 'asc' | 'desc'
          key?: 'Enter' | 'Space'
        }[] = [
          { search: name, control: 'hilos-table-search' },
          { search: '', control: 'hilos-table-search-clear' },
          { search: agentId, control: 'hilos-table-search' },
          ...(['name', 'nextRunAt'] as const).flatMap((field) => [
            // Rule cycles inside the agent search; Next run uses all rules.
            ...(field === 'nextRunAt'
              ? [{ search: '', control: 'hilos-table-search-clear' }]
              : []),
            ...(['asc', 'desc', undefined] as const).map(
              (direction, index) => ({
                search: field === 'name' ? agentId : '',
                control: `hilos-table-sort-${field}`,
                field,
                direction,
                key: (['Enter', 'Space', undefined] as const)[index],
              }),
            ),
          ]),
          { search: missing, control: 'hilos-table-search' },
          { search: '', control: 'hilos-table-no-matches-reset' },
        ]

        for (const step of steps) {
          const outcome = step.direction ?? (step.search || 'default')
          await test.step(`${step.control}: ${outcome}`, async () => {
            const mark = await windows.mark()
            const control = shownByTestId(page, step.control)
            if (step.control === 'hilos-table-search') {
              await typeInto(control, step.search)
            } else {
              await control.focus()
              if (step.key) await control.press(step.key)
              else await control.click()
            }
            const filter = {
              node: 'standalone',
              ...(step.search === '' ? {} : { search: step.search }),
            }
            const sort = step.direction
              ? [{ field: step.field!, direction: step.direction }]
              : null
            const { keys } = await expectDaemonCronQuery(
              expect,
              page,
              windows,
              mark,
              filter,
              sort,
              initialRules,
            )
            proof.push(
              await windows.renderedSettled(expect, mark, filter, keys),
            )
            if (step.key) await expect(control).toBeFocused()

            for (const field of ['name', 'nextRunAt']) {
              await expect(
                page.getByTestId(`hilos-table-sort-${field}`).locator('..'),
              ).toHaveAttribute(
                'aria-sort',
                step.field === field && step.direction
                  ? step.direction === 'asc'
                    ? 'ascending'
                    : 'descending'
                  : 'none',
              )
            }
            if (step.search === missing) {
              await expect(
                shownByTestId(page, 'hilos-table-no-matches'),
              ).toContainText('Nothing found')
              await expect(
                shownByTestId(page, 'hilos-table-no-matches-terms'),
              ).toContainText(missing)
            } else {
              await expect(
                page.getByTestId('hilos-table-no-matches'),
              ).toHaveCount(0)
            }
          })
        }
        await expect(search).toHaveValue('')
        await expectStandaloneCron(expect, page)
        await expect(page).toHaveURL(new RegExp(`${DAEMON_NODE_PATH}/cron$`))
      } finally {
        await test.info().attach('cron-search-order-frames', {
          body: JSON.stringify(
            { frames: await windows.read(), proof },
            null,
            2,
          ),
          contentType: 'application/json',
        })
      }
    })
  }
})

test.describe('daemon overview', () => {
  test.afterEach(() => setAdminViewMode(false))

  test('opens the real root from the dashboard, cold loads it and returns from Workers and the node log', async ({
    page,
  }) => {
    // Admin preparation and four page subscriptions are real daemon round trips.
    test.slow()
    await grantAdminToSelf(expect, page)
    await gotoPage(expect, page, '/hilos', PAGE_READY)
    const card = page.getByTestId('dashboard-card-hilos_daemon')
    await expect(card).toHaveAttribute('href', DAEMON_ROOT_PATH)
    await card.click()
    await expect(page).toHaveURL(new RegExp(`${DAEMON_ROOT_PATH}$`))
    await expectStandaloneOverview(
      expect,
      page,
      DATABASE_HOST,
      DAEMON_STANDALONE_NODE_NAME,
    )
    // The applied index from the live primary DB, kept once for the stand SQL check.
    await test.info().attach('daemon-primary-migration', {
      body: await page
        .getByTestId('hilos-daemon-database-migration')
        .innerText(),
      contentType: 'text/plain',
    })

    await gotoPage(expect, page, DAEMON_ROOT_PATH, PAGE_READY)
    await expectStandaloneOverview(
      expect,
      page,
      DATABASE_HOST,
      DAEMON_STANDALONE_NODE_NAME,
    )
    const node = page.getByTestId('hilos-daemon-node-standalone')
    await node.focus()
    await node.press('Enter')
    await expect(page).toHaveURL(new RegExp(`${DAEMON_NODE_PATH}$`))
    await expectStandaloneNodeOverview(expect, page, {
      nodeName: DAEMON_STANDALONE_NODE_NAME,
    })

    await page.getByTestId(DAEMON_WORKERS_CHILD).click()
    await expect(page).toHaveURL(new RegExp(`${DAEMON_WORKERS_PATH}$`))
    await expectDaemonPage(expect, page, 'hilos_daemon_workers')
    await expectStandaloneWorkers(expect, page)
    const crumb = page.getByTestId(DAEMON_ROOT_CRUMB)
    await expect(crumb).toHaveAttribute('href', DAEMON_ROOT_PATH)
    await crumb.click()
    await expect(page).toHaveURL(new RegExp(`${DAEMON_ROOT_PATH}$`))
    await expectStandaloneOverview(
      expect,
      page,
      DATABASE_HOST,
      DAEMON_STANDALONE_NODE_NAME,
    )

    await page.getByTestId('hilos-daemon-node-standalone').click()
    await expectStandaloneNodeOverview(expect, page, {
      nodeName: DAEMON_STANDALONE_NODE_NAME,
    })
    await page.getByTestId('hilos-daemon-node-log').click()
    await expectDaemonPage(expect, page, 'hilos_logs_view')
    await expect(page.getByTestId('hilos-log-source')).toHaveValue('live')
    const streams = page.getByTestId('hilos-log-stream')
    // Nonempty catalog options prove the selected node's live viewer is ready.
    // Its existing policy leaves the stream for the operator to choose.
    await expect(
      streams.locator('option[value]:not([value=""])'),
    ).not.toHaveCount(0)
    await expect(streams).toHaveValue('')
    await expect(page).toHaveURL(/\/hilos\/logs\/view\/standalone\/live$/)
    await page.goBack()
    await expect(page).toHaveURL(new RegExp(`${DAEMON_NODE_PATH}$`))
    await expectStandaloneNodeOverview(expect, page, {
      nodeName: DAEMON_STANDALONE_NODE_NAME,
    })
  })

  test('keeps the complete technical root available in admin view mode', async ({
    page,
  }) => {
    await setAdminViewMode(true)
    await gotoPage(expect, page, DAEMON_ROOT_PATH, PAGE_READY)
    await expect(page.getByTestId('view-mode-banner')).toBeVisible()
    await expect(page.getByTestId('nav-admin')).toHaveAttribute(
      'data-access',
      'view',
    )
    await expectStandaloneOverview(
      expect,
      page,
      DATABASE_HOST,
      DAEMON_STANDALONE_NODE_NAME,
    )
  })

  for (const viewer of [false, true]) {
    test(`searches real placement ids and preserves local selection as ${viewer ? 'a view-mode reader' : 'an admin'}`, async ({
      page,
    }) => {
      await page.setViewportSize({ width: 1280, height: 812 })
      if (viewer) {
        await setAdminViewMode(true)
      } else {
        await grantAdminToSelf(expect, page)
      }
      await gotoPage(expect, page, DAEMON_ROOT_PATH, PAGE_READY)
      await expectStandaloneOverview(
        expect,
        page,
        DATABASE_HOST,
        DAEMON_STANDALONE_NODE_NAME,
      )
      await expectStandalonePlacement(expect, page, DAEMON_STANDALONE_NODE_NAME)
      if (viewer) {
        await expect(page.getByTestId('view-mode-banner')).toBeVisible()
      }
      const totals = await Promise.all(
        DAEMON_PLACEMENT_TOTALS.map((total) =>
          page.getByTestId(`hilos-daemon-placement-${total}`).innerText(),
        ),
      )
      const cell = shownByTestId(page, DAEMON_PLACEMENT_CELL)
      const table = page.getByRole('table', { name: 'Daemon' })
      await expect(table).toBeVisible()
      const selection = table.getByTestId('hilos-daemon-placement-selection')
      const detail = table.getByTestId(
        `hilos-table-row-detail-${DAEMON_PLACEMENT_TYPE}`,
      )
      const expand = shownByTestId(
        page,
        `hilos-table-expand-${DAEMON_PLACEMENT_TYPE}`,
      )
      await expect(page.getByTestId(/^hilos-table-expand-/)).toHaveCount(0)
      await cell.focus()
      await cell.press('Enter')
      await expect(cell).toHaveAttribute('aria-expanded', 'true')
      await expect(selection).toBeVisible()
      await expect(
        detail.getByTestId('hilos-daemon-placement-selection'),
      ).toBeVisible()
      await expect(expand).toHaveAttribute('aria-expanded', 'true')
      const ids = (await selection.locator('code').allTextContents()).slice(1)
      expect(ids).toEqual([DAEMON_PLACEMENT_TYPE])
      await expect(selection.locator('a, button')).toHaveCount(1)
      await expand.click()
      await expect(
        page.getByTestId('hilos-daemon-placement-selection'),
      ).toHaveCount(0)
      await expect(cell).toHaveAttribute('aria-expanded', 'false')
      await expect(expand).toHaveAttribute('aria-expanded', 'false')
      await expand.focus()
      await expand.press('Enter')
      await expect(selection).toContainText(ids[0])
      const collapse = shownByTestId(page, 'hilos-daemon-placement-collapse')
      await collapse.focus()
      await collapse.press('Space')
      await expect(selection).toHaveCount(0)
      await expect(cell).toHaveAttribute('aria-expanded', 'false')
      await cell.focus()
      await cell.press('Space')
      await expect(selection).toContainText(ids[0])

      const otherType = DAEMON_PLACEMENT_LONG_TYPE
      await shownByTestId(
        page,
        `hilos-daemon-placement-cell-${otherType}-standalone`,
      ).click()
      await expect(detail).toHaveCount(0)
      await expect(
        table
          .getByTestId(`hilos-table-row-detail-${otherType}`)
          .getByTestId('hilos-daemon-placement-selection'),
      ).toContainText(otherType)
      await expect(selection).toHaveCount(1)
      await expect(cell).toHaveAttribute('aria-expanded', 'false')
      await cell.click()
      await expect(
        table.getByTestId(`hilos-table-row-detail-${otherType}`),
      ).toHaveCount(0)
      await expect(
        detail.getByTestId('hilos-daemon-placement-selection'),
      ).toContainText(ids[0])

      const search = page.getByTestId('hilos-table-search')
      await typeInto(search, ids[0])
      await expect(table.locator(ROWS)).toHaveCount(1)
      await expect(page.getByTestId('hilos-table-loading')).toHaveCount(0)
      await expect(selection).toHaveCount(0)
      await expect(cell).toHaveAttribute('aria-expanded', 'false')
      await cell.click()
      await expect(selection).toContainText(ids[0])
      await expectUnfilteredTotals(expect, page, totals)

      await page.getByTestId('hilos-page-tab-hilos_daemon').click()
      await expectStandaloneOverview(
        expect,
        page,
        DATABASE_HOST,
        DAEMON_STANDALONE_NODE_NAME,
      )
      await expect(selection).toBeHidden()
      await expectStandalonePlacement(expect, page, DAEMON_STANDALONE_NODE_NAME)
      await expect(search).toHaveValue('')
      await expect(selection).toHaveCount(0)
      await expect(cell).toHaveAttribute('aria-expanded', 'false')
      await cell.click()
      await expect(selection).toContainText(ids[0])
      await expect(cell).toHaveAttribute('aria-expanded', 'true')
      await cell.click()
      await expect(
        page.getByTestId('hilos-daemon-placement-selection'),
      ).toHaveCount(0)
      await expect(cell).toHaveAttribute('aria-expanded', 'false')
      await cell.click()
      await expect(selection).toBeVisible()

      await typeInto(search, 'no-such-placement-instance')
      await expect(shownByTestId(page, 'hilos-table-no-matches')).toBeVisible()
      await expect(page.getByTestId('hilos-table-loading')).toHaveCount(0)
      await expect(selection).toHaveCount(0)
      await expectUnfilteredTotals(expect, page, totals)
      await shownByTestId(page, 'hilos-table-no-matches-reset').click()
      await expect(search).toHaveValue('')
      await expect(cell).toBeVisible()
      await expect(page.getByTestId('hilos-table-loading')).toHaveCount(0)
      await expect(selection).toHaveCount(0)
    })
  }

  test('keeps the live root and every child page readable', async ({
    page,
  }) => {
    // It follows every child and its breadcrumb over live subscriptions.
    test.slow()
    await grantAdminToSelf(expect, page)
    await gotoPage(expect, page, DAEMON_ROOT_PATH, PAGE_READY)
    await page.setViewportSize({ width: 1280, height: 812 })
    await expectStandaloneOverview(
      expect,
      page,
      DATABASE_HOST,
      DAEMON_STANDALONE_NODE_NAME,
    )
    for (const target of [
      page.getByTestId('hilos-daemon-node-standalone'),
      page.getByTestId('hilos-daemon-database-address'),
      page.getByTestId('hilos-daemon-database-migration'),
      page.getByTestId('hilos-page-tab-hilos_daemon_placement'),
      ...(await page.getByTestId(/^hilos-daemon-worker-standalone-/).all()),
    ]) {
      await target.scrollIntoViewIfNeeded()
      await fitsInScrollport(target)
      expect(
        await target.evaluate(
          (element) => element.scrollWidth - element.clientWidth,
        ),
      ).toBe(0)
    }
    await expectStandalonePlacement(expect, page, DAEMON_STANDALONE_NODE_NAME)
    await expect(
      shownByTestId(page, `hilos-table-row-${DAEMON_PLACEMENT_TYPE}`),
    ).toBeVisible()
    const longType = DAEMON_PLACEMENT_LONG_TYPE
    const cell = shownByTestId(
      page,
      `hilos-daemon-placement-cell-${longType}-standalone`,
    )
    await cell.click()
    const selection = shownByTestId(page, 'hilos-daemon-placement-selection')
    const detail = shownByTestId(page, `hilos-table-row-detail-${longType}`)
    await expect(
      detail.getByTestId('hilos-daemon-placement-selection'),
    ).toBeVisible()
    await expect(
      shownByTestId(page, 'hilos-daemon-placement-selection'),
    ).toHaveCount(1)
    await expect(selection.locator('code')).toHaveText([longType, longType])
    await expect(selection).toContainText(
      `on ${DAEMON_STANDALONE_NODE_NAME} (standalone)`,
    )
    for (const target of [
      page.getByTestId('hilos-table-search'),
      cell,
      selection,
      shownByTestId(page, 'hilos-daemon-placement-collapse'),
      ...(await selection.locator('code').all()),
    ]) {
      await target.scrollIntoViewIfNeeded()
      await fitsInScrollport(target)
    }
    await test.info().attach('placement', {
      body: await page.getByTestId('hilos-daemon-placement-view').screenshot(),
      contentType: 'image/png',
    })
    await shownByTestId(page, 'hilos-daemon-placement-collapse').click()
    await page.getByTestId('hilos-page-tab-hilos_daemon').click()
    await expectStandaloneOverview(
      expect,
      page,
      DATABASE_HOST,
      DAEMON_STANDALONE_NODE_NAME,
    )
    await page.getByTestId('hilos-daemon-node-standalone').click()
    await expectStandaloneNodeOverview(expect, page, {
      nodeName: DAEMON_STANDALONE_NODE_NAME,
    })
    await page.getByTestId(DAEMON_WORKERS_CHILD).click()
    await expectDaemonPage(expect, page, 'hilos_daemon_workers')
    await expectStandaloneWorkers(expect, page)
    await expect(page.getByRole('heading', { level: 1 })).toHaveText(
      DAEMON_STANDALONE_NODE_NAME,
    )
    await page.getByTestId(DAEMON_ROOT_CRUMB).click()
    await expectStandaloneOverview(
      expect,
      page,
      DATABASE_HOST,
      DAEMON_STANDALONE_NODE_NAME,
    )
    await page.getByTestId('hilos-daemon-node-standalone').click()
    await expectStandaloneNodeOverview(expect, page, {
      nodeName: DAEMON_STANDALONE_NODE_NAME,
    })
    await page.getByTestId('hilos-page-tab-hilos_daemon_agents').click()
    await expectDaemonPage(expect, page, 'hilos_daemon_agents')
    await expect(page.getByRole('heading', { level: 1 })).toHaveText(
      DAEMON_STANDALONE_NODE_NAME,
    )
    await expect(
      shownByTestId(
        page,
        `hilos-daemon-agent-restart-${DAEMON_PLACEMENT_TYPE}`,
      ),
    ).toBeEnabled()
    await page.getByTestId(DAEMON_ROOT_CRUMB).click()
    await expectStandaloneOverview(
      expect,
      page,
      DATABASE_HOST,
      DAEMON_STANDALONE_NODE_NAME,
    )
    for (const child of ['cron', 'http_server', 'env']) {
      const key = `hilos_daemon_${child}`
      await page.getByTestId('hilos-daemon-node-standalone').click()
      await expectStandaloneNodeOverview(expect, page, {
        nodeName: DAEMON_STANDALONE_NODE_NAME,
      })
      await page.getByTestId(`hilos-page-tab-${key}`).click()
      await expectDaemonPage(expect, page, key)
      if (child === 'cron') await expectStandaloneCron(expect, page)
      if (child === 'http_server') await expectStandaloneHttp(expect, page)
      if (child === 'env') await expectStandaloneEnvironment(expect, page)
      await expect(page.getByRole('heading', { level: 1 })).toHaveCount(1)
      await page.getByTestId(DAEMON_ROOT_CRUMB).click()
      await expectStandaloneOverview(
        expect,
        page,
        DATABASE_HOST,
        DAEMON_STANDALONE_NODE_NAME,
      )
    }
  })
})

test.describe('daemon node pages', () => {
  test.afterEach(() => setAdminViewMode(false))

  for (const viewer of [false, true]) {
    test(`cold loads the addressed Node, reloads and follows every tab with history as ${viewer ? 'a viewer' : 'an admin'}`, async ({
      page,
      context,
    }) => {
      // Cold reply hold, reload and every addressed tab's table subscription.
      test.slow()
      await setAdminViewMode(viewer)
      if (!viewer) await grantAdminToSelf(expect, page)
      const cold = await context.newPage()
      const held = await holdPageReply(cold, HilosPages.DAEMON_NODE)
      const reports = watchDaemonNodeFrames(cold)
      const refusals = watchPageErrorFrames(cold)
      const subscribes = countSentFrames(cold, '"type":"page_subscribe"')
      const actions = countSentFrames(cold, '"type":"action"')
      try {
        const ready = gotoPage(expect, cold, DAEMON_NODE_PATH, PAGE_READY)
        await held.received
        await expect(cold.getByTestId('hilos-page-skeleton')).toBeVisible()
        await expect(cold.getByTestId('hilos-page-head')).toHaveCount(0)
        await expect(cold.getByTestId('hilos-breadcrumb')).toHaveCount(0)
        held.release()
        await ready
        await expectStandaloneNodeOverview(expect, cold, {
          nodeName: DAEMON_STANDALONE_NODE_NAME,
        })
        await expectDaemonNodeAnswer(expect, cold, reports.frames)
        await expectDaemonNodeFits(expect, cold)
        expect(subscribes()).toBe(1)
        expect(actions()).toBe(0)
        const beforeLive = reports.frames.length
        const visitor = await context.newPage()
        try {
          await gotoPage(expect, visitor, '/', PAGE_READY)
          await expectDaemonNodeLive(expect, cold, reports.frames, beforeLive)
          await expectStandaloneNodeOverview(expect, cold, {
            nodeName: DAEMON_STANDALONE_NODE_NAME,
          })
          expect(subscribes()).toBe(1)
          expect(actions()).toBe(0)
        } finally {
          await visitor.close()
        }
        if (viewer)
          await expect(cold.getByTestId('view-mode-banner')).toBeVisible()
        await cold.reload()
        await expectStandaloneNodeOverview(expect, cold, {
          nodeName: DAEMON_STANDALONE_NODE_NAME,
        })
        for (const tab of DAEMON_NODE_TABS.slice(1)) {
          const link = cold.getByTestId(`hilos-page-tab-${tab.key}`)
          await link.focus()
          await expect(link).toBeFocused()
          await link.press('Enter')
          await expect(cold).toHaveURL(`${DAEMON_NODE_PATH}${tab.path}`)
          await expectDaemonNodeHead(expect, cold, tab.key, 'standalone', {
            nodeName: DAEMON_STANDALONE_NODE_NAME,
          })
          if (
            tab.key !== HilosPages.DAEMON_ENV &&
            tab.key !== HilosPages.DAEMON_WEBSOCKETS
          )
            await expectDaemonNodeTableName(
              expect,
              cold,
              DAEMON_STANDALONE_NODE_NAME,
            )
          if (tab.key === HilosPages.DAEMON_AGENTS) {
            const restart = shownByTestId(
              cold,
              `hilos-daemon-agent-restart-${DAEMON_PLACEMENT_TYPE}`,
            )
            if (viewer) await expect(restart).toBeDisabled()
            else await expect(restart).toBeEnabled()
          }
          await cold
            .getByTestId(`hilos-page-tab-${HilosPages.DAEMON_NODE}`)
            .click()
          await expectStandaloneNodeOverview(expect, cold, {
            nodeName: DAEMON_STANDALONE_NODE_NAME,
          })
        }
        await cold.getByTestId('hilos-daemon-environment-link').click()
        await expectDaemonNodeHead(
          expect,
          cold,
          HilosPages.DAEMON_ENV,
          'standalone',
          { nodeName: DAEMON_STANDALONE_NODE_NAME },
        )
        await cold.goBack()
        await expectStandaloneNodeOverview(expect, cold, {
          nodeName: DAEMON_STANDALONE_NODE_NAME,
        })
        await cold.goForward()
        await expectDaemonNodeHead(
          expect,
          cold,
          HilosPages.DAEMON_ENV,
          'standalone',
          { nodeName: DAEMON_STANDALONE_NODE_NAME },
        )
        await cold.goBack()
        await expectStandaloneNodeOverview(expect, cold, {
          nodeName: DAEMON_STANDALONE_NODE_NAME,
        })
        await cold.getByTestId('hilos-daemon-node-log').click()
        await expectDaemonPage(expect, cold, HilosPages.LOGS_VIEW)
        await expect(cold).toHaveURL(
          resolveHilosPath(HilosPages.LOGS_VIEW, {
            nodeId: 'standalone',
            source: 'live',
          }),
        )
        await expect(cold.getByTestId('hilos-log-source')).toHaveValue('live')
        await cold.goBack()
        await expectStandaloneNodeOverview(expect, cold, {
          nodeName: DAEMON_STANDALONE_NODE_NAME,
        })
        await gotoPage(
          expect,
          cold,
          resolveHilosPath(HilosPages.DAEMON_NODE, { nodeId: 'unknown-node' }),
        )
        await expect
          .poll(() => refusals.at(-1)?.data)
          .toMatchObject({
            page: HilosPages.DAEMON_NODE,
            httpCode: 404,
            errorCode: PAGE_ERROR_NOT_FOUND,
          })
        await expect(cold.getByTestId('page-error')).toHaveAttribute(
          'data-error-code',
          '404',
        )
        await expect(cold.getByTestId('hilos-page-head')).toHaveCount(0)
        await expect(cold.getByTestId('hilos-page-tabs')).toHaveCount(0)
        await expect(
          cold.getByTestId('hilos-daemon-node-overview'),
        ).toHaveCount(0)
        await expect(cold.getByTestId('page-error')).not.toContainText(
          'standalone',
        )
      } finally {
        held.dispose()
        reports.stop()
        await cold.close()
      }
    })
  }

  for (const viewer of [false, true]) {
    test(`reads Workers and Cron with named tables and keyboard links as ${viewer ? 'a viewer' : 'an admin'}`, async ({
      page,
    }) => {
      // Three live pages and the worker log.
      test.slow()
      await setAdminViewMode(viewer)
      if (!viewer) await grantAdminToSelf(expect, page)
      await page.setViewportSize({ width: 1280, height: 812 })
      await gotoPage(expect, page, DAEMON_ROOT_PATH, PAGE_READY)
      await expectStandaloneOverview(
        expect,
        page,
        DATABASE_HOST,
        DAEMON_STANDALONE_NODE_NAME,
      )
      await page.getByTestId('hilos-daemon-node-standalone').click()
      await expectStandaloneNodeOverview(expect, page, {
        nodeName: DAEMON_STANDALONE_NODE_NAME,
      })
      await page.getByTestId(DAEMON_WORKERS_CHILD).focus()
      await page.getByTestId(DAEMON_WORKERS_CHILD).press('Enter')
      await expectDaemonPage(expect, page, 'hilos_daemon_workers')
      await expect(page.getByRole('heading', { level: 1 })).toHaveText(
        DAEMON_STANDALONE_NODE_NAME,
      )
      const surface = page.getByRole('table')
      await expect(surface).toHaveAccessibleName(DAEMON_STANDALONE_NODE_NAME)
      const expand = shownByTestId(page, /^hilos-table-expand-/).first()
      await expect(expand).toBeVisible()
      await expand.focus()
      await expect(expand).toBeFocused()
      await expand.press('Enter')
      await expect(expand).toHaveAttribute('aria-expanded', 'true')
      await expect(
        shownByTestId(page, /^hilos-daemon-worker-agents-/),
      ).toBeVisible()
      const log = shownByTestId(page, /^hilos-daemon-worker-log-/).first()
      await expect(log).toHaveAttribute(
        'href',
        /\/hilos\/logs\/view\/standalone\/live\/worker-/,
      )
      await log.click()
      await expectDaemonPage(expect, page, 'hilos_logs_view')
      await expect(page.getByTestId('hilos-log-stream')).not.toHaveValue('')
      await page.goBack()
      await expectDaemonPage(expect, page, 'hilos_daemon_workers')
      await page.getByTestId(DAEMON_ROOT_CRUMB).click()
      await expectStandaloneOverview(
        expect,
        page,
        DATABASE_HOST,
        DAEMON_STANDALONE_NODE_NAME,
      )
      await page.getByTestId('hilos-daemon-node-standalone').click()
      await expectStandaloneNodeOverview(expect, page, {
        nodeName: DAEMON_STANDALONE_NODE_NAME,
      })
      const cron = page.getByTestId('hilos-page-tab-hilos_daemon_cron')
      await expect(cron).toHaveAttribute(
        'href',
        '/hilos/daemon/standalone/cron',
      )
      await cron.focus()
      await cron.press('Enter')
      await expectDaemonPage(expect, page, 'hilos_daemon_cron')
      await expect(page.getByRole('heading', { level: 1 })).toHaveText(
        DAEMON_STANDALONE_NODE_NAME,
      )
      const schedule = page.getByRole('table')
      await expect(schedule).toHaveAccessibleName(DAEMON_STANDALONE_NODE_NAME)
      await expectStandaloneCron(expect, page)
      await page.getByTestId(DAEMON_ROOT_CRUMB).click()
      await expectStandaloneOverview(
        expect,
        page,
        DATABASE_HOST,
        DAEMON_STANDALONE_NODE_NAME,
      )
      const reports = watchDaemonWebsocketsFrames(page)
      try {
        await gotoPage(
          expect,
          page,
          '/hilos/daemon/standalone/websockets',
          PAGE_READY,
        )
        await expectStandaloneWebsockets(expect, page, reports.frames, {
          nodeName: DAEMON_STANDALONE_NODE_NAME,
        })
      } finally {
        reports.stop()
      }
    })
  }

  for (const viewer of [false, true]) {
    test(
      `reads Agents and HTTP with keyboard navigation as ${viewer ? 'a viewer' : 'an admin'}`,
      viewer ? {} : { tag: '@quarantine:F-021' },
      async ({ page }) => {
        // FIXME(F-021, 2026-10-23): restart confirmation does not return focus · чужой · попытка 3 · HIL-1641
        // It follows real page subscriptions and an agent's live log.
        test.slow()
        await setAdminViewMode(viewer)
        if (!viewer) await grantAdminToSelf(expect, page)
        await page.setViewportSize({ width: 1280, height: 812 })
        await gotoPage(expect, page, DAEMON_ROOT_PATH, PAGE_READY)
        await expectStandaloneOverview(
          expect,
          page,
          DATABASE_HOST,
          DAEMON_STANDALONE_NODE_NAME,
        )
        await page.getByTestId('hilos-daemon-node-standalone').click()
        await expectStandaloneNodeOverview(expect, page, {
          nodeName: DAEMON_STANDALONE_NODE_NAME,
        })
        const agents = page.getByTestId('hilos-page-tab-hilos_daemon_agents')
        await expect(agents).toHaveAttribute(
          'href',
          '/hilos/daemon/standalone/agents',
        )
        await agents.focus()
        await agents.press('Enter')
        await expectDaemonPage(expect, page, 'hilos_daemon_agents')
        await expect(page.getByRole('heading', { level: 1 })).toHaveText(
          DAEMON_STANDALONE_NODE_NAME,
        )
        const surface = page.getByRole('table')
        await expect(surface).toHaveAccessibleName(DAEMON_STANDALONE_NODE_NAME)
        await expect(page.getByTestId('hilos-daemon-node-solo')).toBeVisible()
        const search = page.getByTestId('hilos-table-search')
        await expect(search).toHaveAccessibleName('Search by agent id…')
        await typeInto(search, RESTART_AGENT)
        const row = shownByTestId(page, `hilos-table-row-${RESTART_AGENT}`)
        await expect(row).toBeVisible()
        await expect(page.getByTestId('hilos-table-loading')).toHaveCount(0)
        await expect(row).toContainText('settings')
        await expect(row).toContainText('Stays up')
        const restart = shownByTestId(
          page,
          `hilos-daemon-agent-restart-${RESTART_AGENT}`,
        )
        if (viewer) {
          await expect(page.getByTestId('view-mode-banner')).toBeVisible()
          await expect(restart).toBeDisabled()
          await expect(restart).toHaveAttribute(
            'aria-describedby',
            /(^| )hilos-view-mode-strip-text( |$)/,
          )
        } else {
          await expect(restart).toBeEnabled()
          await restart.focus()
          await restart.press('Enter')
          const modal = page.getByTestId('modal')
          await expect(modal).toBeFocused()
          await expect(modal).toHaveAccessibleName('Restart agent')
          await expect(
            page.getByTestId('hilos-daemon-restart-node'),
          ).toHaveText(`${DAEMON_STANDALONE_NODE_NAME} (standalone)`)
          await expect(page.getByTestId('hilos-daemon-restart-cli')).toHaveText(
            `php cli.php agent:restart ${RESTART_AGENT}`,
          )
          await expect(
            page.getByTestId('hilos-daemon-restart-policy'),
          ).toHaveCount(0)
          for (const target of [
            modal,
            page.getByTestId('hilos-daemon-restart-cli'),
            page.getByTestId('hilos-daemon-restart-cancel'),
            page.getByTestId('hilos-daemon-restart-confirm'),
          ]) {
            await target.scrollIntoViewIfNeeded()
            await fitsInScrollport(target)
          }
          await test.info().attach('restart', {
            body: await modal.screenshot(),
            contentType: 'image/png',
          })
          await modal.press('Enter')
          await expect(modal).toBeVisible()
          await modal.press('Escape')
          await expect(modal).toHaveCount(0)
          await expect(restart).toBeFocused()
        }
        const log = shownByTestId(
          page,
          `hilos-daemon-agent-log-${RESTART_AGENT}`,
        )
        await expect(log).toHaveAttribute(
          'href',
          /\/hilos\/logs\/view\/standalone\/live\/agent-/,
        )
        await log.focus()
        await log.press('Enter')
        await expectDaemonPage(expect, page, 'hilos_logs_view')
        await expect(page.getByTestId('hilos-log-stream')).not.toHaveValue('')
        await page.goBack()
        await expectDaemonPage(expect, page, 'hilos_daemon_agents')
        await page.getByTestId(DAEMON_ROOT_CRUMB).click()
        await expectStandaloneOverview(
          expect,
          page,
          DATABASE_HOST,
          DAEMON_STANDALONE_NODE_NAME,
        )
        await page.getByTestId('hilos-daemon-node-standalone').click()
        await expectStandaloneNodeOverview(expect, page, {
          nodeName: DAEMON_STANDALONE_NODE_NAME,
        })
        const http = page.getByTestId('hilos-page-tab-hilos_daemon_http_server')
        await expect(http).toHaveAttribute(
          'href',
          '/hilos/daemon/standalone/http',
        )
        await http.focus()
        await http.press('Enter')
        await expectDaemonPage(expect, page, 'hilos_daemon_http_server')
        await expect(page.getByRole('heading', { level: 1 })).toHaveText(
          DAEMON_STANDALONE_NODE_NAME,
        )
        const routes = page.getByRole('table')
        await expect(routes).toHaveAccessibleName(DAEMON_STANDALONE_NODE_NAME)
        await expectStandaloneHttp(expect, page)
        await expect(page.getByTestId('hilos-table-search')).toHaveCount(0)
        for (const target of [
          page.getByTestId('hilos-daemon-http-address'),
          page.getByTestId('hilos-daemon-http-period'),
        ]) {
          await target.scrollIntoViewIfNeeded()
          await fitsInScrollport(target)
        }
        await test.info().attach(`http-${viewer}`, {
          body: await page.getByTestId('hilos-admin-page').screenshot(),
          contentType: 'image/png',
        })
        await page.getByTestId(DAEMON_ROOT_CRUMB).click()
        await expectStandaloneOverview(
          expect,
          page,
          DATABASE_HOST,
          DAEMON_STANDALONE_NODE_NAME,
        )
      },
    )
  }
})

test.describe('restarting an agent', () => {
  test.afterEach(() => setAdminViewMode(false))

  // The master's 13-second caller window must be judged on the first attempt.
  test.describe.configure({ retries: 0 })

  test('restarts the settings library while two viewers and a new tab see the shared mark', async ({
    page,
    context,
  }) => {
    await grantAdminToSelf(expect, page)
    const worker = await readRestartWorker(expect, page)
    await gotoPage(expect, page, '/hilos/daemon/standalone/agents', PAGE_READY)
    await typeInto(page.getByTestId('hilos-table-search'), RESTART_AGENT)
    await expect(
      shownByTestId(page, `hilos-table-row-${RESTART_AGENT}`),
    ).toBeVisible()
    await expect(page.getByTestId('hilos-table-loading')).toHaveCount(0)
    const observer = await context.newPage()
    const newcomer = await context.newPage()
    try {
      await gotoPage(
        expect,
        observer,
        '/hilos/daemon/standalone/agents',
        PAGE_READY,
      )
      await typeInto(observer.getByTestId('hilos-table-search'), RESTART_AGENT)
      await expect(
        shownByTestId(observer, `hilos-table-row-${RESTART_AGENT}`),
      ).toBeVisible()
      await expect(observer.getByTestId('hilos-table-loading')).toHaveCount(0)
      const button = shownByTestId(
        page,
        `hilos-daemon-agent-restart-${RESTART_AGENT}`,
      )
      await button.scrollIntoViewIfNeeded()
      await expect(button).toBeVisible()
      await expect(button).toBeEnabled()
      await button.focus()
      await button.click()
      await expect(page.getByTestId('modal')).toBeFocused()
      await expect(page.getByTestId('hilos-daemon-restart-node')).toHaveText(
        `${DAEMON_STANDALONE_NODE_NAME} (standalone)`,
      )
      await expect(page.getByTestId('hilos-daemon-restart-cli')).toHaveText(
        `php cli.php agent:restart ${RESTART_AGENT}`,
      )
      const mark = `hilos-daemon-agent-restarting-${RESTART_AGENT}`
      await withStoppedRestartWorker(worker, async () => {
        const confirm = page.getByTestId('hilos-daemon-restart-confirm')
        await confirm.scrollIntoViewIfNeeded()
        await expect(confirm).toBeVisible()
        await expect(confirm).toBeEnabled()
        await confirm.focus()
        await confirm.click()
        // The click reaches the master by itself and the mark travels with a node
        // report: repeat the report until this page draws the mark.
        await reportDaemonNodeUntil(expect, async () => {
          expect(await shownByTestId(page, mark).count()).toBe(1)
        })
        await expect(shownByTestId(page, mark)).toHaveText('Restarting')
        await expect(shownByTestId(observer, mark)).toHaveText('Restarting')
        await expect(
          shownByTestId(
            observer,
            `hilos-daemon-agent-restart-${RESTART_AGENT}`,
          ),
        ).toBeDisabled()
        await gotoPage(
          expect,
          newcomer,
          '/hilos/daemon/standalone/agents',
          PAGE_READY,
        )
        await typeInto(
          newcomer.getByTestId('hilos-table-search'),
          RESTART_AGENT,
        )
        await expect(
          shownByTestId(newcomer, `hilos-table-row-${RESTART_AGENT}`),
        ).toBeVisible()
        await expect(newcomer.getByTestId('hilos-table-loading')).toHaveCount(0)
        await expect(shownByTestId(newcomer, mark)).toHaveText('Restarting')
        await expect(
          shownByTestId(
            newcomer,
            `hilos-daemon-agent-restart-${RESTART_AGENT}`,
          ),
        ).toBeDisabled()
      })
      await expect(page.getByTestId('modal')).toHaveCount(0)
      // The restarted worker reaches the master by itself: repeat the report until
      // this page has dropped the mark; the other viewers follow the same fan-out.
      await reportDaemonNodeUntil(expect, async () => {
        expect(await page.getByTestId(mark).count()).toBe(0)
      })
      for (const viewer of [page, observer, newcomer]) {
        await expect(viewer.getByTestId(mark)).toHaveCount(0)
        await expect(
          shownByTestId(viewer, `hilos-daemon-agent-restart-${RESTART_AGENT}`),
        ).toBeEnabled()
        await expect(
          viewer.getByTestId(`hilos-table-row-${RESTART_AGENT}`),
        ).toBeVisible()
      }
      await expect(
        page.getByTestId('hilos-toast-success').filter({
          hasText: `Agent ${RESTART_AGENT} restarted on node ${DAEMON_STANDALONE_NODE_NAME} (standalone).`,
        }),
      ).toBeVisible()
    } finally {
      await observer.close()
      await newcomer.close()
    }
  })
})

test.describe('daemon environment', () => {
  test.afterEach(() => setAdminViewMode(false))

  test('reads environment values, masks secrets and filters attention', async ({
    page,
  }) => {
    // Admin preparation and several server-window changes are real round trips.
    test.slow()
    await grantAdminToSelf(expect, page)
    await gotoPage(expect, page, DAEMON_ROOT_PATH, PAGE_READY)
    await expectStandaloneOverview(
      expect,
      page,
      DATABASE_HOST,
      DAEMON_STANDALONE_NODE_NAME,
    )
    await page.getByTestId('hilos-daemon-node-standalone').click()
    await expectStandaloneNodeOverview(expect, page, {
      nodeName: DAEMON_STANDALONE_NODE_NAME,
    })
    const link = page.getByTestId('hilos-daemon-environment-link')
    await link.scrollIntoViewIfNeeded()
    await link.focus()
    await link.press('Enter')
    await expectDaemonPage(expect, page, 'hilos_daemon_env')
    await expect(page).toHaveURL(/\/hilos\/daemon\/standalone\/env$/)
    await page.setViewportSize({ width: 1280, height: 812 })
    await expectStandaloneEnvironment(expect, page)
    const count = await page.getByTestId('hilos-daemon-env-keys').innerText()
    await expect(page.getByRole('heading', { level: 1 })).toHaveText(
      DAEMON_STANDALONE_NODE_NAME,
    )
    await expect(
      page.getByRole('table', { name: 'Catalog keys' }),
    ).toBeVisible()
    await expect(page.getByRole('table')).toHaveCount(1)
    await expect(
      page.getByRole('list', { name: 'Reading ladder' }),
    ).toBeVisible()
    const search = page.getByTestId('hilos-table-search')
    await expect(search).toHaveAccessibleName('Key or value')
    await typeInto(search, 'APP_ENV')
    const app = shownByTestId(page, 'hilos-table-row-APP_ENV')
    await expect(app).toContainText('test')
    await expect(app).toContainText('Launch environment')
    await expect(page.getByTestId('hilos-table-loading')).toHaveCount(0)
    const attention = page.getByTestId('hilos-table-filter-attention')
    await expect(attention).toHaveAccessibleName('Needs attention only')
    await expect(attention).not.toBeChecked()
    await attention.focus()
    await attention.press('Space')
    await expect(attention).toBeChecked()
    await expect(
      shownByTestId(page, 'hilos-daemon-env-keys-empty-attention'),
    ).toHaveText('No key needs attention.')
    await expect(page.getByTestId('hilos-table-loading')).toHaveCount(0)
    await expect(page.getByTestId('hilos-daemon-env-keys')).toHaveText(count)
    await attention.focus()
    await attention.press('Space')
    await expect(attention).not.toBeChecked()
    await expect(app).toContainText('test')
    await typeInto(search, 'DB_PASSWORD')
    const secret = shownByTestId(page, 'hilos-table-row-DB_PASSWORD')
    await expect(secret).toContainText(/Set · \d+ chars/)
    await expect(page.getByTestId('hilos-table-loading')).toHaveCount(0)
    await expect(page.locator('body')).not.toContainText('hilos_pass')
    await secret.scrollIntoViewIfNeeded()
    await fitsInScrollport(secret)
    await page.getByTestId(DAEMON_ROOT_CRUMB).click()
    await expectStandaloneOverview(
      expect,
      page,
      DATABASE_HOST,
      DAEMON_STANDALONE_NODE_NAME,
    )
    await expectStandaloneEnvOrphans(expect, page)
    await page.reload()
    await expect(page).toHaveURL(/\/hilos\/daemon\/standalone\/env\/orphans$/)
    await expect(
      page.getByTestId('hilos-page-tab-hilos_daemon_env_orphans'),
    ).toHaveAttribute('aria-current', 'page')
    await expect(page.getByRole('table')).toHaveCount(1)
    await page.goBack()
    await expectStandaloneNodeOverview(expect, page, {
      nodeName: DAEMON_STANDALONE_NODE_NAME,
    })
    await page.goForward()
    await expect(page).toHaveURL(/\/hilos\/daemon\/standalone\/env\/orphans$/)
    await gotoPage(
      expect,
      page,
      '/hilos/daemon/standalone/env/orphans',
      PAGE_READY,
    )
    await expectDaemonPage(expect, page, 'hilos_daemon_env_orphans')
    await expect(page.getByRole('heading', { level: 1 })).toHaveCount(1)
    await expect(page.getByRole('table')).toHaveCount(1)
    // A cold mount binds the catalog window with all keys selected.
    await gotoPage(expect, page, '/hilos/daemon/standalone/env', PAGE_READY)
    await expectDaemonPage(expect, page, 'hilos_daemon_env')
    await expect(page.getByTestId('hilos-table-search')).toHaveValue('')
    await expectStandaloneEnvironment(expect, page)
  })

  test('a viewer reads Cron, HTTP, environment, orphans and comparison with values hidden', async ({
    page,
  }) => {
    // Five live child subscriptions and the environment's filtered windows.
    test.slow()
    await setAdminViewMode(true)
    await gotoPage(expect, page, DAEMON_ROOT_PATH, PAGE_READY)
    await expectStandaloneOverview(
      expect,
      page,
      DATABASE_HOST,
      DAEMON_STANDALONE_NODE_NAME,
    )
    for (const child of [
      'cron',
      'http_server',
      'env',
      'env_orphans',
      'env_mismatch',
    ]) {
      const key = `hilos_daemon_${child}`
      await gotoPage(
        expect,
        page,
        child === 'env_mismatch'
          ? '/hilos/daemon/env-mismatch'
          : DAEMON_NODE_PATH,
        PAGE_READY,
      )
      if (child !== 'env_mismatch')
        await expectStandaloneNodeOverview(expect, page, {
          nodeName: DAEMON_STANDALONE_NODE_NAME,
        })
      const link = page.getByTestId(`hilos-page-tab-${key}`)
      if (child !== 'env_mismatch') {
        await link.focus()
        await link.press('Enter')
      }
      await expectDaemonPage(expect, page, key)
      await expect(page.getByTestId('view-mode-banner')).toBeVisible()
      await expect(page.getByTestId('view-mode-banner')).toHaveText(
        'View mode · You can look around, but not change anything.',
      )
      await expect(page.getByRole('heading', { level: 1 })).toHaveCount(1)
      if (child === 'cron') await expectStandaloneCron(expect, page)
      if (child === 'http_server') {
        await expectStandaloneHttp(expect, page)
        await expect(
          page.getByTestId('hilos-admin-page').getByTestId('hilos-hidden'),
        ).toHaveCount(0)
        await expect(page.getByTestId('page-error')).toHaveCount(0)
      }
      if (child === 'env') {
        await expectStandaloneEnvironment(expect, page)
        const search = page.getByTestId('hilos-table-search')
        for (const key of ['DB_HOST', 'DB_PASSWORD']) {
          await typeInto(search, key)
          const row = shownByTestId(page, `hilos-table-row-${key}`)
          await expect(row).toBeVisible()
          await expect(row.getByTestId('hilos-hidden')).toBeVisible()
          await expect(row.getByTestId('hilos-hidden')).toHaveText('Hidden')
          await expect(row).not.toContainText(/Set ·|chars|hilos_pass/)
          await expect(row).not.toContainText(DATABASE_HOST)
          await expect(page.getByTestId('hilos-table-loading')).toHaveCount(0)
        }
        await expect(page.locator('body')).not.toContainText('hilos_pass')
        await expect(page.getByTestId('page-error')).toHaveCount(0)
        await typeInto(search, DATABASE_HOST)
        await expect(page.getByTestId('hilos-table-loading')).toHaveCount(0)
        await expect(shownByTestId(page, /^hilos-table-row-/)).toHaveCount(0)
      }
      if (child === 'env_orphans') await expectViewerEnvOrphans(expect, page)
      if (child === 'env_mismatch')
        await expectStandaloneMismatch(
          expect,
          page,
          DAEMON_STANDALONE_NODE_NAME,
        )
      const returnLink = page.getByTestId(
        child === 'env_mismatch'
          ? 'hilos-page-tab-hilos_daemon'
          : DAEMON_ROOT_CRUMB,
      )
      await expect(returnLink).toHaveAttribute('href', DAEMON_ROOT_PATH)
      await returnLink.click()
      await expectStandaloneOverview(
        expect,
        page,
        DATABASE_HOST,
        DAEMON_STANDALONE_NODE_NAME,
      )
    }
  })

  test('cold loads the standalone comparison without table windows', async ({
    page,
  }) => {
    await grantAdminToSelf(expect, page)
    const windows: string[] = []
    page.on('websocket', (socket) =>
      socket.on('framesent', ({ payload }) => {
        const frame = JSON.parse(String(payload)) as {
          type?: string
          data?: { page?: string }
        }
        if (
          frame.type === 'table_viewport' &&
          frame.data?.page === 'hilos_daemon_env_mismatch'
        )
          windows.push(String(payload))
      }),
    )
    await gotoPage(expect, page, '/hilos/daemon/env-mismatch', PAGE_READY)
    await expectDaemonPage(expect, page, 'hilos_daemon_env_mismatch')
    await expectStandaloneMismatch(expect, page, DAEMON_STANDALONE_NODE_NAME)
    await expect(page.getByRole('heading', { level: 1 })).toHaveCount(1)
    expect(windows).toEqual([])
  })
})

test.describe('Daemon section cold navigation', () => {
  test.afterEach(() => setAdminViewMode(false))

  for (const viewer of [false, true]) {
    test(`cold Placement answers once, reloads and resets on history while live data preserves search as ${viewer ? 'a viewer' : 'an admin'}`, async ({
      page,
      context,
      browser,
      baseURL,
    }) => {
      // Held cold reply, reload, a real connection report and history are separate round trips.
      test.slow()
      await setAdminViewMode(viewer)
      if (!viewer) await grantAdminToSelf(expect, page)
      // The source may be null until its first full picture; prepare that fact
      // in the setup tab, leaving the cold document's subscription untouched.
      await gotoPage(expect, page, DAEMON_PLACEMENT_PATH, PAGE_READY)
      await expectDaemonPlacementSourceReady(expect, page)
      const cold = await context.newPage()
      const log = watchDeliveryFrames(cold)
      const windows = await watchTableSearch(cold, {
        page: 'hilos_daemon_placement',
        tableKey: 'hilosDaemonPlacement',
      })
      const held = await holdPageReply(cold, 'hilos_daemon_placement')
      const navigation = gotoPage(
        expect,
        cold,
        DAEMON_PLACEMENT_PATH,
        PAGE_READY,
      )
      const completed = navigation.catch((error: unknown) => error)
      const frames = () =>
        log.packets
          .flatMap((packet) => packet.frames)
          .filter((frame) => frame.socket === log.sockets())
      try {
        await held.received
        await expectDaemonPlacementPending(expect, cold)
        held.release()
        await navigation
        const initial = await expectDaemonPlacementAnswer(
          expect,
          cold,
          frames(),
        )
        expect(initial.entryPoints?.nodeId).toBe('standalone')
        await expectDaemonPlacementRequests(expect, log)
        if (viewer)
          await expect(cold.getByTestId('view-mode-banner')).toBeVisible()
        await cold.reload()
        await expectDaemonPage(expect, cold, 'hilos_daemon_placement')
        await expectDaemonPlacementAnswer(expect, cold, frames())
        await expectDaemonPlacementRequests(expect, log)
        await expectDaemonSectionAccessibility(
          expect,
          cold,
          false,
          DAEMON_STANDALONE_NODE_NAME,
        )
        await searchDaemonPlacement(expect, cold, windows)
        const live = await watchDaemonPlacementLive(expect, cold, log, 2)
        const visitor = await browser.newPage({
          baseURL,
          ignoreHTTPSErrors: true,
        })
        try {
          await gotoPage(expect, visitor, '/', PAGE_READY)
          await live.changed()
        } finally {
          await visitor.context().close()
          await live.dispose()
        }
        await cold.getByTestId('hilos-page-tab-hilos_daemon').press('Enter')
        await expectDaemonPage(expect, cold, 'hilos_daemon')
        await expectDaemonSectionHead(expect, cold, 'hilos_daemon')
        await expect(
          cold.getByTestId('hilos-daemon-placement-view'),
        ).toHaveCount(0)
        const source = await expectDaemonRootEntryPoints(expect, cold, log)
        expect(source.nodeId).toBe('standalone')
        await cold.goBack()
        await expectDaemonPlacementReset(expect, cold)
        await cold.goForward()
        await expectDaemonPage(expect, cold, 'hilos_daemon')
        await expectDaemonSectionHead(expect, cold, 'hilos_daemon')
        await cold
          .getByTestId('hilos-page-tab-hilos_daemon_placement')
          .press('Enter')
        await expectDaemonPlacementReset(expect, cold)
        // The source's metrics link is a real addressed HTTP page.
        await cold.getByTestId('hilos-page-tab-hilos_daemon').press('Enter')
        await expectDaemonPage(expect, cold, 'hilos_daemon')
        await cold.getByTestId('hilos-daemon-entry-metrics').press('Enter')
        await expectDaemonPage(expect, cold, 'hilos_daemon_http_server')
        await expect(cold).toHaveURL(
          `${DAEMON_ROOT_PATH}/${source.nodeId}/http`,
        )
        await expect(cold.getByTestId('hilos-daemon-http-address')).toHaveText(
          new RegExp(`:${source.httpPort}\\s*$`),
        )
        await gotoPage(expect, cold, '/hilos/daemon/env-mismatch', PAGE_READY)
        await expectStandaloneMismatch(
          expect,
          cold,
          DAEMON_STANDALONE_NODE_NAME,
        )
        await cold
          .getByTestId('hilos-page-tab-hilos_daemon_placement')
          .press('Enter')
        await expectDaemonPlacementReset(expect, cold)
        expectDaemonPlacementOwnership(expect, log)
      } finally {
        held.dispose()
        await completed
        await test.info().attach('daemon-section-cold-history-live', {
          body: JSON.stringify({
            packets: log.packets,
            sent: log.sent,
            search: await windows.read(),
          }),
          contentType: 'application/json',
        })
        log.stop()
        await cold.close()
      }
    })
  }
})

for (const viewer of [false, true]) {
  test(`WebSockets direct entry, F5 and live count with a second browser as ${viewer ? 'a viewer' : 'an admin'}`, async ({
    page,
    browser,
  }) => {
    // Admin preparation, reload, route recovery and two source changes cross the daemon.
    test.slow()
    await setAdminViewMode(viewer)
    if (!viewer) await grantAdminToSelf(expect, page)
    const reports = watchDaemonWebsocketsFrames(page)
    const { baseURL, ignoreHTTPSErrors } = test.info().project.use
    const visitorContext = await browser.newContext({
      baseURL,
      ignoreHTTPSErrors,
    })
    try {
      const requests = countSentFrames(page, '"type":"action"')
      const viewports = countSentFrames(page, '"type":"table_viewport"')
      await gotoPage(expect, page, `${DAEMON_NODE_PATH}/websockets`, PAGE_READY)
      await expectStandaloneWebsockets(expect, page, reports.frames, {
        nodeName: DAEMON_STANDALONE_NODE_NAME,
      })
      expect(requests()).toBe(0)
      expect(viewports()).toBe(0)
      if (viewer)
        await expect(page.getByTestId('view-mode-banner')).toBeVisible()
      reports.frames.length = 0
      await page.reload()
      await expectStandaloneWebsockets(expect, page, reports.frames, {
        nodeName: DAEMON_STANDALONE_NODE_NAME,
      })
      await page.getByTestId('hilos-page-tab-hilos_daemon_cron').click()
      await expectDaemonPage(expect, page, 'hilos_daemon_cron')
      const link = page.getByTestId('hilos-page-tab-hilos_daemon_websockets')
      await expect(link).toHaveAttribute(
        'href',
        `${DAEMON_NODE_PATH}/websockets`,
      )
      await link.focus()
      await expect(link).toBeFocused()
      reports.frames.length = 0
      await link.press('Enter')
      // This serial standalone scenario has only this page; the visitor context
      // is still empty. The closed navigation sockets reach the master by
      // themselves: repeat the node report until it carries them, before taking
      // a baseline from its sampled picture.
      await reportDaemonNodeUntil(expect, () => {
        expect(readDaemonWebsocketsCount(reports.frames)).toBe(1)
      })
      const baseline = await expectStandaloneWebsockets(
        expect,
        page,
        reports.frames,
        { nodeName: DAEMON_STANDALONE_NODE_NAME },
      )
      const room = await watchHeight(
        page.getByTestId('hilos-daemon-websockets-tile'),
      )
      const beforeOpen = reports.frames.length
      const visitor = await visitorContext.newPage()
      await gotoPage(expect, visitor, '/', PAGE_READY)
      await reportDaemonNodeUntil(expect, () => {
        expect(
          readDaemonWebsocketsCount(reports.frames.slice(beforeOpen)),
        ).toBeGreaterThan(baseline)
      })
      const opened = await expectStandaloneWebsockets(
        expect,
        page,
        reports.frames,
        { nodeName: DAEMON_STANDALONE_NODE_NAME },
      )
      expect(
        reports.frames.slice(beforeOpen).some((frame) => {
          const wire = frame.wire as {
            data: { payload: Record<string, unknown> }
          }
          return (
            Object.keys(wire.data.payload).length === 1 &&
            'data' in wire.data.payload
          )
        }),
      ).toBe(true)
      await room.unchanged()
      const beforeClose = reports.frames.length
      await visitor.close()
      await reportDaemonNodeUntil(expect, () => {
        const count = readDaemonWebsocketsCount(
          reports.frames.slice(beforeClose),
        )
        expect(count !== null && count < opened).toBe(true)
      })
      await expectStandaloneWebsockets(expect, page, reports.frames, {
        nodeName: DAEMON_STANDALONE_NODE_NAME,
      })
      await room.unchanged()
      await gotoPage(expect, page, '/hilos/daemon/unknown-node')
      await expect(page.getByTestId('page-error')).toHaveAttribute(
        'data-error-code',
        '404',
      )
      await expect(
        page.getByTestId('hilos-daemon-websockets-tile'),
      ).toHaveCount(0)
      reports.frames.length = 0
      await page.goBack()
      await expectStandaloneWebsockets(expect, page, reports.frames, {
        nodeName: DAEMON_STANDALONE_NODE_NAME,
      })
    } finally {
      await test.info().attach('daemon-websockets-frames', {
        body: JSON.stringify(reports.frames),
        contentType: 'application/json',
      })
      reports.stop()
      await visitorContext.close()
      await setAdminViewMode(false)
    }
  })
}
