import { expect, test as base } from '../test.js'
import {
  HILOS_STATUS_COMPONENTS_COPY,
  HILOS_STATUS_COPY,
  STATUS_COMPONENT_NAME_FIELD,
  STATUS_COMPONENT_STATE_FIELD,
  statusComponentSchema,
  statusHistoryDataSchema,
  statusWorksDataSchema,
} from '../../../../../framework/frontend/core/src/admin/status/index.js'
import {
  armSocketDrop,
  cleanupFixtures,
  controlSocketReconnect,
  deliveryObject,
  dropSocket,
  expectPageReady,
  expectPublicStatusPair,
  expectPublicStatusReadOnly,
  expectStatusHistoryFact,
  expectStatusSubscriberCounts,
  expectWelcomedSockets,
  gotoPage,
  gotoPublicStatus,
  grantAdminToSelf,
  holdPublicStatusFiles,
  PAGE_READY,
  PUBLIC_STATUS_FILES,
  shownByTestId,
  STATUS_COMPONENTS_PATH,
  STATUS_HISTORY_PATH,
  STATUS_PATH,
  STATUS_SUBSCRIBERS_PATH,
  STATUS_WORKS_PATH,
  statusHistoryDeliveries,
  statusPagePayload,
  statusTableWindow,
  tableRowKeys,
  typeInto,
  type StatusHistoryFact,
  type TestCleanup,
  watchDeliveryFrames,
  watchOpenedSockets,
  watchPublicStatus,
  watchStatusFrames,
} from '../../../../../framework/frontend/e2e/index.js'
import { beatStatusHeartbeat } from '../../../../../framework/frontend/scripts/statusMoments.mjs'

const test = base.extend<{ cleanup: TestCleanup }>({ cleanup: cleanupFixtures })
test.use({ colorScheme: 'light', timezoneId: 'Pacific/Johnston' })

// These journeys exercise the built Angular lazy mounts and their real bindings.
test('Status tabs render server facts through links, direct entry, reload and history', async ({
  page,
}) => {
  await grantAdminToSelf(expect, page)
  const status = watchStatusFrames(page)
  const log = watchDeliveryFrames(page)
  try {
    await gotoPage(expect, page, '/hilos', PAGE_READY)
    await page.getByTestId('dashboard-card-hilos_status').click()
    await expect(page).toHaveURL(new RegExp(`${STATUS_PATH}$`))
    await expect.poll(() => status.latest()).toBeDefined()
    await expectPageReady(expect, page)
    const overview = status.latest()!
    expect(overview.healthKnown).toBe(true)
    expect(overview.components.totalCount).toBeGreaterThan(0)
    await expect(
      page.getByTestId('hilos-status-components-link'),
    ).toContainText(
      `${overview.components.operationalCount} / ${overview.components.totalCount} operational`,
    )
    await expect(page.getByTestId('hilos-status-works-count')).toHaveText(
      String(overview.scheduledWorks!.count),
    )
    await expect(page.getByTestId('hilos-status-history-count')).toHaveText(
      String(overview.historyCount),
    )

    await test.step('Components renders its own window and applies real search and state filters', async () => {
      await page.getByTestId('hilos-page-tab-hilos_status_components').click()
      await expect(page).toHaveURL(new RegExp(`${STATUS_COMPONENTS_PATH}$`))
      await expect.poll(() => status.components.length).toBeGreaterThan(0)
      await expectPageReady(expect, page)
      await expect(page.getByRole('heading', { level: 1 })).toHaveText('Status')
      await expect(
        page.getByTestId('hilos-page-tab-hilos_status_components'),
      ).toHaveAttribute('aria-current', 'page')
      const window = status.components.at(-1)!
      const rows = (window.rows as unknown[]).map((value) => {
        const row = deliveryObject(value)
        return {
          key: String(row.rowKey),
          component: statusComponentSchema.parse(
            deliveryObject(row.slots).component,
          ),
        }
      })
      expect(rows).toHaveLength(overview.components.totalCount)
      for (const { key, component } of rows) {
        await expect(
          shownByTestId(page, `hilos-status-component-${key}`),
        ).toContainText(component[STATUS_COMPONENT_NAME_FIELD])
        await expect(
          shownByTestId(page, `hilos-status-component-state-${key}`),
        ).toHaveText(
          HILOS_STATUS_COMPONENTS_COPY.states[
            component[STATUS_COMPONENT_STATE_FIELD]
          ],
        )
      }
      await expect(page.getByTestId(/^hilos-table-sort-/)).toHaveCount(0)
      await expect(
        page.getByTestId('hilos-status-components-table').getByRole('button', {
          name: /add|edit|remove|delete|save|publish/i,
        }),
      ).toHaveCount(0)
      const search = page.getByTestId('hilos-table-search')
      let since = log.sent.length
      await typeInto(search, 'no-matching-status-component')
      await expect
        .poll(
          () =>
            statusTableWindow(
              log,
              'hilos_status_components',
              'hilosStatusComponents',
              since,
            )?.rows,
        )
        .toEqual([])
      await expect(shownByTestId(page, /^hilos-status-component-/)).toHaveCount(
        0,
      )
      since = log.sent.length
      await typeInto(search, rows[0].component[STATUS_COMPONENT_NAME_FIELD])
      await expect
        .poll(
          () =>
            statusTableWindow(
              log,
              'hilos_status_components',
              'hilosStatusComponents',
              since,
            )?.totalCount,
        )
        .toBe(1)
      await expect(
        shownByTestId(page, `hilos-status-component-${rows[0].key}`),
      ).toBeVisible()
      const filter = page.getByTestId('hilos-table-filter-state')
      since = log.sent.length
      await filter.getByTestId('hilos-dropdown-toggle').click()
      await filter.getByTestId('hilos-dropdown-option-3').click()
      await expect
        .poll(
          () =>
            statusTableWindow(
              log,
              'hilos_status_components',
              'hilosStatusComponents',
              since,
            )?.rows,
        )
        .toEqual([])
      await expect(shownByTestId(page, /^hilos-status-component-/)).toHaveCount(
        0,
      )
      since = log.sent.length
      await filter.getByTestId('hilos-dropdown-toggle').click()
      await filter.getByTestId('hilos-dropdown-option-0').click()
      await expect
        .poll(
          () =>
            statusTableWindow(
              log,
              'hilos_status_components',
              'hilosStatusComponents',
              since,
            )?.totalCount,
        )
        .toBe(1)
      await expect(
        shownByTestId(page, `hilos-status-component-${rows[0].key}`),
      ).toBeVisible()
    })

    let historyFacts: StatusHistoryFact[] = []
    await test.step('The other three tables have authoritative page data and windows', async () => {
      for (const [key, path, table, dataKey] of [
        [
          'hilos_status_works',
          STATUS_WORKS_PATH,
          'hilosStatusWorks',
          'statusWorks',
        ],
        [
          'hilos_status_subscribers',
          STATUS_SUBSCRIBERS_PATH,
          'hilosStatusSubscribers',
          'statusSubscribers',
        ],
        [
          'hilos_status_history',
          STATUS_HISTORY_PATH,
          'hilosStatusHistory',
          'statusHistory',
        ],
      ] as const) {
        const since = log.packets.length
        await page.getByTestId(`hilos-page-tab-${key}`).click()
        await expect(page).toHaveURL(new RegExp(`${path}$`))
        await expect
          .poll(() => statusPagePayload(log, key, since))
          .toBeDefined()
        await expectPageReady(expect, page)
        await expect(page.getByTestId(`hilos-page-tab-${key}`)).toHaveAttribute(
          'aria-current',
          'page',
        )
        const payload = statusPagePayload(log, key, since)!
        const data = deliveryObject(payload.data)[dataKey]
        expect(data).toBeDefined()
        const window = deliveryObject(deliveryObject(payload.windows)[table])
        if (dataKey === 'statusHistory') {
          const history = statusHistoryDeliveries(log, since).find(
            ({ frame }) => deliveryObject(frame.wire).type === 'page_response',
          )!
          expect(history).toBeDefined()
          expect(
            deliveryObject(deliveryObject(history.frame.wire).data).payload,
          ).toBe(payload)
          historyFacts = history.rows
          expect(window).toMatchObject({
            totalCount: overview.historyCount,
            totalExact: true,
          })
          expect(historyFacts).toHaveLength(
            Math.min(Number(window.limit), overview.historyCount!),
          )
          await expect
            .poll(() => tableRowKeys(page, 'hilos-status-history-table'))
            .toEqual(historyFacts.map((fact) => fact.id))
          for (const fact of historyFacts)
            await expectStatusHistoryFact(expect, page, fact)
          if (historyFacts.length === 0)
            await expect(shownByTestId(page, 'hilos-table-empty')).toBeVisible()
          else
            await expect(shownByTestId(page, 'hilos-table-empty')).toBeHidden()
        } else {
          expect(window).toMatchObject({
            rows: [],
            totalCount: 0,
            totalExact: true,
          })
          await expect(shownByTestId(page, 'hilos-table-empty')).toBeVisible()
        }
        if (dataKey === 'statusWorks')
          expect(statusWorksDataSchema.parse(data)?.components.length).toBe(
            overview.components.totalCount,
          )
        if (dataKey === 'statusHistory')
          expect(statusHistoryDataSchema.parse(data)?.components.length).toBe(
            overview.components.totalCount,
          )
        if (dataKey === 'statusSubscribers') {
          await expectStatusSubscriberCounts(expect, page, log, 0, 0)
        }
      }
    })

    let historySince = log.packets.length
    await page.goBack()
    await expect(page).toHaveURL(new RegExp(`${STATUS_SUBSCRIBERS_PATH}$`))
    await expect
      .poll(() =>
        statusPagePayload(log, 'hilos_status_subscribers', historySince),
      )
      .toBeDefined()
    await expectPageReady(expect, page)
    await expectStatusSubscriberCounts(expect, page, log, 0, 0)
    historySince = log.packets.length
    await page.goForward()
    await expect(page).toHaveURL(new RegExp(`${STATUS_HISTORY_PATH}$`))
    await expect
      .poll(() => statusPagePayload(log, 'hilos_status_history', historySince))
      .toBeDefined()
    await expectPageReady(expect, page)
    const restoredHistory = statusHistoryDeliveries(log, historySince)[0]
    expect(restoredHistory.rows).toEqual(historyFacts)
    expect(
      deliveryObject(
        deliveryObject(
          statusPagePayload(log, 'hilos_status_history', historySince)!.windows,
        ).hilosStatusHistory,
      ),
    ).toMatchObject({ totalCount: overview.historyCount, totalExact: true })
    await expect
      .poll(() => tableRowKeys(page, 'hilos-status-history-table'))
      .toEqual(historyFacts.map((fact) => fact.id))
    for (const fact of restoredHistory.rows)
      await expectStatusHistoryFact(expect, page, fact)
    if (restoredHistory.rows.length === 0)
      await expect(shownByTestId(page, 'hilos-table-empty')).toBeVisible()
    else await expect(shownByTestId(page, 'hilos-table-empty')).toBeHidden()
    await test.info().attach('angular-status-history-navigation', {
      body: JSON.stringify({ historyFacts, restoredHistory, log }),
      contentType: 'application/json',
    })
    const since = log.packets.length
    await gotoPage(expect, page, STATUS_PATH, PAGE_READY)
    expect(statusPagePayload(log, 'hilos_status', since)).toBeDefined()
    const beforeReload = status.overviews.length
    await page.reload()
    await expectPageReady(expect, page)
    expect(status.overviews.length).toBeGreaterThan(beforeReload)
    await expect(page.getByTestId('hilos-status-operational-label')).toHaveText(
      HILOS_STATUS_COPY.allOperational,
    )
  } finally {
    status.stop()
    log.stop()
  }
})

test('guest Status keeps reading real files through socket loss and immediately on reconnect', async ({
  page,
  cleanup,
}) => {
  const log = watchPublicStatus(page)
  cleanup.defer(() => log.stop())
  const sockets = watchOpenedSockets(page)
  await armSocketDrop(page)
  const reconnect = await controlSocketReconnect(page)
  cleanup.defer(() => reconnect.dispose())
  await gotoPublicStatus(expect, page)
  await expectWelcomedSockets(expect, sockets, 1)
  await expect(page.getByTestId('conn-state')).toHaveText('connected')
  const initial = await expectPublicStatusPair(expect, page, log)
  expect(initial.status.components.length).toBeGreaterThan(0)
  await expect(page.getByTestId('public-status-component-state')).toHaveText(
    initial.status.components.map(
      (component) => HILOS_STATUS_COMPONENTS_COPY.states[component.state],
    ),
  )
  const reads = log.reads.map((files) => files.length)
  const held = await holdPublicStatusFiles(page)
  cleanup.defer(() => held.dispose())
  const duringLoss = await beatStatusHeartbeat()
  await expect
    .poll(() => [...new Set(held.requested)].sort())
    .toEqual([...PUBLIC_STATUS_FILES].sort())
  await reconnect.hold()
  await dropSocket(page)
  await reconnect.drop()
  await expect.poll(() => log.wire.connections[0]?.closed).toBeDefined()
  held.release()
  for (const [index, path] of PUBLIC_STATUS_FILES.entries()) {
    await expect
      .poll(() => log.reads[index].length, path)
      .toBeGreaterThan(reads[index])
  }
  await expectPublicStatusPair(
    expect,
    page,
    log,
    (pair) => pair.heartbeat.aliveAt >= duringLoss.aliveAt,
  )
  await expect(page.getByTestId('public-status-connection')).toHaveText(
    'Your live connection to the site is lost — the system is up',
  )
  await expect(page.getByTestId('public-status-components')).toBeVisible()
  await expect(page.getByTestId('public-status-subscribe')).toHaveCount(0)
  const { aliveAt } = await beatStatusHeartbeat()
  const beforeRecovery = log.reads.map((files) => files.length)
  await reconnect.release()
  await expectWelcomedSockets(expect, sockets, 2)
  await expect(page.getByTestId('conn-state')).toHaveText('connected')
  await expectPublicStatusPair(
    expect,
    page,
    log,
    (pair) => pair.heartbeat.aliveAt >= aliveAt,
  )
  for (const [index, path] of PUBLIC_STATUS_FILES.entries()) {
    expect(log.reads[index].length, path).toBeGreaterThan(beforeRecovery[index])
  }
  await expect(page.getByTestId('public-status-connection')).toHaveCount(0)
  await expect(page.getByTestId('public-status-subscribe')).toBeEnabled()
  expectPublicStatusReadOnly(expect, log)
})
