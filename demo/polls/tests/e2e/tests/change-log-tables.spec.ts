import { expect, test } from '../test.js'

import {
  CHANGE_LOG_TABLES_PATH,
  CHANGE_LOG_NOISY_PATH,
  expectChangeLogNoisyColumns,
  expectTrackedTablesPage,
  expectTrackedTableOverview,
  expectTrackedTableWhole,
  shownByTestId,
  typeInto,
  expectPageReady,
  gotoPage,
  grantAdminToSelf,
} from '../../../../../framework/frontend/e2e/index.js'

// spec-owner: demo — carries Polls' Angular browser journeys for these shared pages

test('opens Tracked tables from Change Log, filters journal labels and reads schema metadata', async ({
  page,
}) => {
  await grantAdminToSelf(expect, page)
  await gotoPage(expect, page, '/hilos')
  await page.getByTestId('dashboard-card-hilos_change_log').click()
  await expectPageReady(expect, page)
  const sectionHeading = page.getByRole('heading', { level: 1 })
  await expect(sectionHeading).toHaveCount(1)
  await expect(sectionHeading).toHaveText('Change Log')
  const tablesLink = page.getByTestId('hilos-page-tab-hilos_change_log_tables')
  await expect(tablesLink).toHaveAttribute('href', CHANGE_LOG_TABLES_PATH)
  await tablesLink.focus()
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
  await expectTrackedTableOverview(expect, page, 'hilos_user', 51)

  const tablePath = `/hilos/change-log/table/hilos_user`
  const tablePages = [
    { kind: 'overview', path: tablePath },
    {
      kind: 'columns',
      path: `${tablePath}/columns`,
      body: 'hilos-change-log-table-columns',
      content: 'last_activity',
      otherBody: 'hilos-change-log-table-triggers',
    },
    {
      kind: 'triggers',
      path: `${tablePath}/triggers`,
      body: 'hilos-change-log-table-triggers',
      content: 'hilos_cl_hilos_user_after_insert',
      otherBody: 'hilos-change-log-table-columns',
    },
    { kind: 'history', path: `${tablePath}/history` },
  ] as const
  const expectTablePage = async (route: (typeof tablePages)[number]) => {
    await expect(page).toHaveURL(route.path)
    await expectPageReady(expect, page)
    if (route.kind === 'overview') {
      await expectTrackedTableOverview(expect, page, 'hilos_user', 51)
    } else if (route.kind === 'history') {
      await expectTrackedTableWhole(expect, page, 'hilos_user', 51)
    } else {
      const heading = page.getByRole('heading', { level: 1 })
      await expect(heading).toHaveCount(1)
      await expect(heading).toHaveText('hilos_user')
      await expect(page.getByTestId(route.body)).toContainText(route.content)
      await expect(page.getByTestId(route.otherBody)).toHaveCount(0)
    }
  }

  const columnsLink = page.getByTestId('hilos-change-log-table-link-columns')
  await expect(columnsLink).toHaveAttribute('href', tablePages[1].path)
  await columnsLink.click()
  await expectTablePage(tablePages[1])
  const overviewTab = page.getByTestId('hilos-page-tab-hilos_change_log_table')
  await overviewTab.click()
  await expectTablePage(tablePages[0])

  const triggersLink = page.getByTestId('hilos-change-log-table-link-triggers')
  await expect(triggersLink).toHaveAttribute('href', tablePages[2].path)
  await triggersLink.click()
  await expectTablePage(tablePages[2])
  await overviewTab.click()
  await expectTablePage(tablePages[0])

  const historyLink = page.getByTestId('hilos-change-log-table-link-history')
  await expect(historyLink).toHaveAttribute('href', tablePages[3].path)
  await historyLink.click()
  await expectTablePage(tablePages[3])

  for (const route of tablePages) {
    await gotoPage(expect, page, route.path)
    await expectTablePage(route)
    await page.reload()
    await expectTablePage(route)
  }
  await gotoPage(expect, page, tablePages[0].path)
  await expectTablePage(tablePages[0])
  await page.getByTestId('hilos-change-log-table-link-columns').click()
  await expectTablePage(tablePages[1])
  await overviewTab.click()
  await expectTablePage(tablePages[0])
  await page.getByTestId('hilos-change-log-table-link-triggers').click()
  await expectTablePage(tablePages[2])
  await overviewTab.click()
  await expectTablePage(tablePages[0])
  await page.getByTestId('hilos-change-log-table-link-history').click()
  await expectTablePage(tablePages[3])
  for (const route of [
    tablePages[0],
    tablePages[2],
    tablePages[0],
    tablePages[1],
    tablePages[0],
  ]) {
    await page.goBack()
    await expectTablePage(route)
  }
  for (const route of [
    tablePages[1],
    tablePages[0],
    tablePages[2],
    tablePages[0],
    tablePages[3],
  ]) {
    await page.goForward()
    await expectTablePage(route)
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
})
