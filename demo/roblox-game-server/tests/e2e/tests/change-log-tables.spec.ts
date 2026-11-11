import { expect, test } from '../test.js'

import {
  expectChangeLogSectionHead,
  CHANGE_LOG_TABLES_PATH,
  CHANGE_LOG_NOISY_PATH,
  expectChangeLogNoisyColumns,
  expectTrackedTablesPage,
  expectTrackedTableOverview,
  expectChangeLogTableHead,
  expectTrackedTableWhole,
  deliveryObject,
  watchDeliveryFrames,
  shownByTestId,
  typeInto,
  expectPageReady,
  gotoPage,
  grantAdminToSelf,
} from '../../../../../framework/frontend/e2e/index.js'

function expectMetadataEntry(
  frames: ReturnType<typeof watchDeliveryFrames>,
  packetStart: number,
  sentStart: number,
  pageKey: string,
  tableKey: string,
) {
  const response = frames.packets
    .slice(packetStart)
    .flatMap(({ frames: packetFrames }) => packetFrames)
    .map(({ wire }) => deliveryObject(wire))
    .find(
      (frame) =>
        frame.type === 'page_response' &&
        deliveryObject(frame.data).page === pageKey,
    )
  expect(response).toBeDefined()
  const payload = deliveryObject(deliveryObject(response).data).payload
  const window = deliveryObject(
    deliveryObject(deliveryObject(payload).windows)[tableKey],
  )
  expect(window.filter).toEqual({ table: 'hilos_user' })
  expect(
    frames.sent
      .slice(sentStart)
      .map(({ wire }) => deliveryObject(wire))
      .filter(
        (frame) =>
          frame.type === 'table_viewport' &&
          frame.page === pageKey &&
          frame.tableKey === tableKey,
      ),
  ).toEqual([])
  return deliveryObject(payload)
}

test('opens Tracked tables from Change Log, filters journal labels and reads schema metadata', async ({
  page,
}) => {
  const frames = watchDeliveryFrames(page)
  await grantAdminToSelf(expect, page)
  await gotoPage(expect, page, '/hilos')
  await page.getByTestId('dashboard-card-hilos_change_log').click()
  await expectChangeLogSectionHead(expect, page, 'hilos_change_log')
  await expectPageReady(expect, page)
  const tab = page.getByTestId('hilos-page-tab-hilos_change_log_tables')
  await expect(tab).toBeVisible()
  await expect(tab).toHaveText('Tracked tables')
  await expect(tab).toHaveAttribute('href', CHANGE_LOG_TABLES_PATH)
  await tab.focus()
  await page.keyboard.press('Enter')
  await expectPageReady(expect, page)
  await expectTrackedTablesPage(expect, page)
  const search = shownByTestId(page, 'hilos-table-search')
  // The live table set is paginated; search by its complete name to reach the row.
  await typeInto(search, 'hilos_user')
  await expect(shownByTestId(page, 'hilos-table-row-hilos_user')).toContainText(
    'Framework',
  )
  const open = shownByTestId(page, 'hilos-change-log-open-table-hilos_user')
  await expect(open).toHaveAttribute(
    'href',
    '/hilos/change-log/table/hilos_user',
  )
  await open.click()
  await expectPageReady(expect, page)
  await expectTrackedTableOverview(expect, page, 'hilos_user', 1)
  const columnsLink = page.getByTestId('hilos-change-log-table-link-columns')
  await expect(columnsLink).toHaveAttribute(
    'href',
    '/hilos/change-log/table/hilos_user/columns',
  )
  const columnsPacketStart = frames.packets.length
  const columnsSentStart = frames.sent.length
  await columnsLink.click()
  await expectPageReady(expect, page)
  await expectChangeLogTableHead(
    expect,
    page,
    'hilos_user',
    'hilos_change_log_table_columns',
    { owner: 'Framework', since: 1 },
  )
  await expect(
    page.getByTestId('hilos-change-log-table-columns'),
  ).toContainText('last_activity')
  await expect(page.getByTestId('hilos-change-log-table-triggers')).toHaveCount(
    0,
  )
  await expect(page.getByTestId('hilos-viewport-table')).toHaveCount(1)
  await expect(page.getByTestId('hilos-table-count')).toHaveText('1 – 9 of 9')
  await expect(page.getByRole('table')).toHaveAccessibleName('hilos_user')
  const columnsPayload = expectMetadataEntry(
    frames,
    columnsPacketStart,
    columnsSentStart,
    'hilos_change_log_table_columns',
    'hilosChangeLogTableColumns',
  )
  expect(columnsPayload).toMatchObject({
    data: { changeLogTable: { name: 'hilos_user' } },
    windows: {
      hilosChangeLogTableColumns: {
        limit: 25,
        totalCount: 9,
        totalExact: true,
        rowsBefore: 0,
        rows: expect.arrayContaining([
          expect.objectContaining({
            rowKey: 'last_activity',
            slots: {
              column: expect.objectContaining({
                name: 'last_activity',
                mode: 'noise',
              }),
            },
          }),
        ]),
      },
    },
  })
  expect(deliveryObject(columnsPayload.refusedWindows)).not.toHaveProperty(
    'hilosChangeLogTableColumns',
  )
  await expect(
    shownByTestId(page, 'hilos-table-row-last_activity'),
  ).toContainText('Noisy — not written')
  await page.getByTestId('hilos-page-tab-hilos_change_log_table').click()
  await expectTrackedTableOverview(expect, page, 'hilos_user', 1)
  const triggersPacketStart = frames.packets.length
  const triggersSentStart = frames.sent.length
  await page.getByTestId('hilos-change-log-table-link-triggers').click()
  await expectPageReady(expect, page)
  await expectChangeLogTableHead(
    expect,
    page,
    'hilos_user',
    'hilos_change_log_table_triggers',
    { owner: 'Framework', since: 1 },
  )
  await expect(
    page.getByTestId('hilos-change-log-table-triggers'),
  ).toContainText('hilos_cl_hilos_user_after_insert')
  await expect(page.getByTestId('hilos-change-log-table-columns')).toHaveCount(
    0,
  )
  await expect(page.getByTestId('hilos-viewport-table')).toHaveCount(1)
  await expect(page.getByTestId('hilos-table-count')).toHaveText('1 – 3 of 3')
  await expect(page.getByRole('table')).toHaveAccessibleName('hilos_user')
  const triggersPayload = expectMetadataEntry(
    frames,
    triggersPacketStart,
    triggersSentStart,
    'hilos_change_log_table_triggers',
    'hilosChangeLogTableTriggers',
  )
  expect(triggersPayload).toMatchObject({
    data: { changeLogTable: { name: 'hilos_user' } },
    windows: {
      hilosChangeLogTableTriggers: {
        limit: 25,
        totalCount: 3,
        totalExact: true,
        rowsBefore: 0,
        rows: expect.arrayContaining([
          expect.objectContaining({
            rowKey: 'hilos_cl_hilos_user_after_insert',
          }),
        ]),
      },
    },
  })
  expect(deliveryObject(triggersPayload.refusedWindows)).not.toHaveProperty(
    'hilosChangeLogTableTriggers',
  )
  const insertTrigger = shownByTestId(
    page,
    'hilos-table-row-hilos_cl_hilos_user_after_insert',
  )
  await expect(insertTrigger).toContainText('Service')
  await expect(insertTrigger).toContainText('AFTER INSERT on hilos_user')
  await expect(insertTrigger).toContainText('Journal')
  await expect(insertTrigger).toContainText('from migration 001')
  await page.getByTestId('hilos-page-tab-hilos_change_log_table').click()
  await expectTrackedTableOverview(expect, page, 'hilos_user', 1)
  await page.getByTestId('hilos-change-log-table-link-history').click()
  await expectTrackedTableWhole(expect, page, 'hilos_user', 1)

  const tablePath = `/hilos/change-log/table/hilos_user`
  const tablePages = [
    { kind: 'overview', path: tablePath },
    {
      kind: 'columns',
      path: `${tablePath}/columns`,
      page: 'hilos_change_log_table_columns',
      tableKey: 'hilosChangeLogTableColumns',
      body: 'hilos-change-log-table-columns',
      content: 'last_activity',
      otherBody: 'hilos-change-log-table-triggers',
      count: '1 – 9 of 9',
    },
    {
      kind: 'triggers',
      path: `${tablePath}/triggers`,
      page: 'hilos_change_log_table_triggers',
      tableKey: 'hilosChangeLogTableTriggers',
      body: 'hilos-change-log-table-triggers',
      content: 'hilos_cl_hilos_user_after_insert',
      otherBody: 'hilos-change-log-table-columns',
      count: '1 – 3 of 3',
    },
    { kind: 'history', path: `${tablePath}/history` },
  ] as const
  const expectTablePage = async (route: (typeof tablePages)[number]) => {
    await expect(page).toHaveURL(route.path)
    await expectPageReady(expect, page)
    if (route.kind === 'overview') {
      await expectTrackedTableOverview(expect, page, 'hilos_user', 1)
      return
    }
    if (route.kind === 'history') {
      await expectTrackedTableWhole(expect, page, 'hilos_user', 1)
      await expect(
        page.getByRole('table', { name: 'hilos_user', exact: true }),
      ).toBeVisible()
      return
    }
    await expectChangeLogTableHead(expect, page, 'hilos_user', route.page, {
      owner: 'Framework',
      since: 1,
    })
    await expect(page.getByTestId(route.body)).toContainText(route.content)
    await expect(page.getByTestId(route.otherBody)).toHaveCount(0)
    await expect(page.getByTestId('hilos-viewport-table')).toHaveCount(1)
    await expect(page.getByTestId('hilos-table-count')).toHaveText(route.count)
    await expect(page.getByRole('table')).toHaveAccessibleName('hilos_user')
  }
  const expectNavigation = async (
    route: (typeof tablePages)[number],
    navigate: () => Promise<unknown>,
  ) => {
    const packetStart = frames.packets.length
    const sentStart = frames.sent.length
    await navigate()
    await expectTablePage(route)
    if (route.kind === 'columns' || route.kind === 'triggers')
      expectMetadataEntry(
        frames,
        packetStart,
        sentStart,
        route.page,
        route.tableKey,
      )
  }

  const overviewViewportStart = frames.sent.length
  await gotoPage(expect, page, tablePages[0].path)
  await expectTablePage(tablePages[0])
  const tableMetadataViewports = [
    'hilosChangeLogTableColumns',
    'hilosChangeLogTableTriggers',
    'hilosChangeLogHistory',
  ]
  expect(
    frames.sent
      .slice(overviewViewportStart)
      .map(({ wire }) => deliveryObject(wire))
      .filter(
        (frame) =>
          frame.type === 'table_viewport' &&
          tableMetadataViewports.includes(String(frame.tableKey)),
      ),
  ).toEqual([])

  for (const route of tablePages.slice(1)) {
    await expectNavigation(route, () => gotoPage(expect, page, route.path))
    await expectNavigation(route, () => page.reload())
  }

  await gotoPage(expect, page, tablePages[0].path)
  await expectTablePage(tablePages[0])
  await expectNavigation(tablePages[1], () =>
    shownByTestId(page, 'hilos-change-log-table-link-columns').click(),
  )
  await expectNavigation(tablePages[2], () =>
    page.getByTestId('hilos-page-tab-hilos_change_log_table_triggers').click(),
  )
  await expectNavigation(tablePages[3], () =>
    page.getByTestId('hilos-page-tab-hilos_change_log_table_history').click(),
  )

  for (const route of [tablePages[2], tablePages[1], tablePages[0]]) {
    await expectNavigation(route, () => page.goBack())
  }
  for (const route of [tablePages[1], tablePages[2], tablePages[3]]) {
    await expectNavigation(route, () => page.goForward())
  }

  await gotoPage(expect, page, CHANGE_LOG_TABLES_PATH)

  await typeInto(search, 'hilos_session')
  const journal = page.getByTestId('hilos-table-filter-journal')
  await journal.getByTestId('hilos-dropdown-toggle').click()
  const untracked = journal.getByTestId('hilos-dropdown-option-1')
  await expect(untracked).toHaveText('Untracked')
  await untracked.click()
  const session = shownByTestId(page, 'hilos-table-row-hilos_session')
  await expect(session).toContainText('No journal label — not written')
  await expect(session).toContainText('Framework')
  await expect(
    page.getByTestId('hilos-change-log-open-table-hilos_session'),
  ).toHaveCount(0)

  await journal.getByTestId('hilos-dropdown-toggle').click()
  const tracked = journal.getByTestId('hilos-dropdown-option-0')
  await expect(tracked).toHaveText('Tracked')
  await tracked.click()
  await expect(shownByTestId(page, 'hilos-table-no-matches')).toContainText(
    'Nothing found',
  )
  await expect(page.getByTestId('hilos-table-row-hilos_session')).toHaveCount(0)
  await shownByTestId(page, 'hilos-table-filter-reset').click()
  await expect(search).toHaveValue('')
  await expect(journal.getByTestId('hilos-dropdown-toggle')).toHaveText(
    'Journal label: All',
  )
  await expect(page.getByTestId('hilos-table-no-matches')).toHaveCount(0)
  await expect(
    page.locator('table [data-id^="hilos-table-row-"]').first(),
  ).toBeVisible()
  await gotoPage(expect, page, CHANGE_LOG_NOISY_PATH)
  await expectChangeLogNoisyColumns(expect, page)
  frames.stop()
})
