import { expect, test } from '@playwright/test'

import {
  expectChangeLogSectionHead,
  CHANGE_LOG_TABLES_PATH,
  CHANGE_LOG_NOISY_PATH,
  expectChangeLogNoisyColumns,
  expectChangeLogReference,
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
  signUpAdmin,
} from '../../../../../framework/frontend/e2e/index.js'

test('opens Tracked tables from Change Log, filters journal labels and reads schema metadata', async ({
  page,
}) => {
  const frames = watchDeliveryFrames(page)
  await signUpAdmin(expect, page, { entryId: 'message-signin' })
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
    '/hilos/change-log/tables/hilos_user',
  )
  await open.click()
  await expectPageReady(expect, page)
  await expectTrackedTableOverview(expect, page, 'hilos_user')
  const columnsLink = page.getByTestId('hilos-change-log-table-link-columns')
  await expect(columnsLink).toHaveAttribute(
    'href',
    '/hilos/change-log/tables/hilos_user/columns',
  )
  await columnsLink.click()
  await expectPageReady(expect, page)
  await expectChangeLogTableHead(
    expect,
    page,
    'hilos_user',
    'hilos_change_log_table_columns',
    { owner: 'Framework', since: 85 },
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
  const columnsAnswer = frames.packets
    .flatMap(({ frames }) => frames)
    .map(({ wire }) => deliveryObject(wire))
    .find(
      (frame) =>
        frame.type === 'page_response' &&
        deliveryObject(frame.data).page === 'hilos_change_log_table_columns',
    )
  const columnsPayload = deliveryObject(
    deliveryObject(columnsAnswer?.data).payload,
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
  expect(frames.sent.map(({ wire }) => deliveryObject(wire))).toContainEqual(
    expect.objectContaining({
      type: 'table_viewport',
      page: 'hilos_change_log_table_columns',
      tableKey: 'hilosChangeLogTableColumns',
      filter: { table: 'hilos_user' },
      limit: 25,
    }),
  )
  await page.getByTestId('hilos-page-tab-hilos_change_log_table').click()
  await expectTrackedTableOverview(expect, page, 'hilos_user')
  await page.getByTestId('hilos-change-log-table-link-triggers').click()
  await expectPageReady(expect, page)
  await expectChangeLogTableHead(
    expect,
    page,
    'hilos_user',
    'hilos_change_log_table_triggers',
    { owner: 'Framework', since: 85 },
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
  const triggersAnswer = frames.packets
    .flatMap(({ frames }) => frames)
    .map(({ wire }) => deliveryObject(wire))
    .find(
      (frame) =>
        frame.type === 'page_response' &&
        deliveryObject(frame.data).page === 'hilos_change_log_table_triggers',
    )
  const triggersPayload = deliveryObject(
    deliveryObject(triggersAnswer?.data).payload,
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
  await expect(insertTrigger).toContainText('from migration 085')
  expect(frames.sent.map(({ wire }) => deliveryObject(wire))).toContainEqual(
    expect.objectContaining({
      type: 'table_viewport',
      page: 'hilos_change_log_table_triggers',
      tableKey: 'hilosChangeLogTableTriggers',
      filter: { table: 'hilos_user' },
      limit: 25,
    }),
  )
  await page.getByTestId('hilos-page-tab-hilos_change_log_table').click()
  await expectTrackedTableOverview(expect, page, 'hilos_user')
  await page.getByTestId('hilos-change-log-table-link-history').click()
  await expectTrackedTableWhole(expect, page, 'hilos_user')

  const tablePath = `${CHANGE_LOG_TABLES_PATH}/hilos_user`
  const tablePages = [
    { kind: 'overview', path: tablePath },
    {
      kind: 'columns',
      path: `${tablePath}/columns`,
      page: 'hilos_change_log_table_columns',
      body: 'hilos-change-log-table-columns',
      content: 'last_activity',
      otherBody: 'hilos-change-log-table-triggers',
      count: '1 – 9 of 9',
    },
    {
      kind: 'triggers',
      path: `${tablePath}/triggers`,
      page: 'hilos_change_log_table_triggers',
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
      await expectTrackedTableOverview(expect, page, 'hilos_user')
      return
    }
    if (route.kind === 'history') {
      await expectTrackedTableWhole(expect, page, 'hilos_user')
      await expect(
        page.getByRole('table', { name: 'hilos_user', exact: true }),
      ).toBeVisible()
      return
    }
    await expectChangeLogTableHead(expect, page, 'hilos_user', route.page, {
      owner: 'Framework',
      since: 85,
    })
    await expect(page.getByTestId(route.body)).toContainText(route.content)
    await expect(page.getByTestId(route.otherBody)).toHaveCount(0)
    await expect(page.getByTestId('hilos-viewport-table')).toHaveCount(1)
    await expect(page.getByTestId('hilos-table-count')).toHaveText(route.count)
    await expect(page.getByRole('table')).toHaveAccessibleName('hilos_user')
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
    await gotoPage(expect, page, route.path)
    await expectTablePage(route)
    await page.reload()
    await expectTablePage(route)
  }

  await gotoPage(expect, page, tablePages[0].path)
  await expectTablePage(tablePages[0])
  await shownByTestId(page, 'hilos-change-log-table-link-columns').click()
  await expectTablePage(tablePages[1])
  await page
    .getByTestId('hilos-page-tab-hilos_change_log_table_triggers')
    .click()
  await expectTablePage(tablePages[2])
  await page
    .getByTestId('hilos-page-tab-hilos_change_log_table_history')
    .click()
  await expectTablePage(tablePages[3])

  for (const route of [tablePages[2], tablePages[1], tablePages[0]]) {
    await page.goBack()
    await expectTablePage(route)
  }
  for (const route of [tablePages[1], tablePages[2], tablePages[3]]) {
    await page.goForward()
    await expectTablePage(route)
  }

  await gotoPage(expect, page, CHANGE_LOG_TABLES_PATH)

  await expectChangeLogReference(expect, page)
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
