import { expect, test } from '@playwright/test'

import {
  CHANGE_LOG_TABLES_LEAD,
  CHANGE_LOG_TABLES_PATH,
  CHANGE_LOG_NOISY_PATH,
  expectChangeLogNoisyColumns,
  expectChangeLogReference,
  expectTrackedTablesPage,
  expectTrackedTableOverview,
  expectTrackedTableWhole,
  shownByTestId,
  typeInto,
  expectPageReady,
  gotoPage,
  signUpAdmin,
} from '../../../../../framework/frontend/e2e/index.js'

test('opens Tracked tables from Change Log, filters journal labels and reads schema metadata', async ({
  page,
}) => {
  await signUpAdmin(expect, page)
  await gotoPage(expect, page, '/hilos')
  await page.getByTestId('dashboard-card-hilos_change_log').click()
  await expect(page.getByTestId('hilos-admin-title')).toHaveText('Change Log')
  await expectPageReady(expect, page)
  // Section cards belong to HilosAdminPage; dashboard-card names only root cards.
  const card = page.getByTestId('hilos-admin-child-hilos_change_log_tables')
  await expect(card).toBeVisible()
  await expect(card).toContainText(CHANGE_LOG_TABLES_LEAD)
  await card.focus()
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
  await expect(page.getByTestId('hilos-page-tabs')).toHaveCount(0)
  await expect(page.getByTestId('hilos-page-head')).toHaveCount(0)
  const columnsLink = page.getByTestId(
    'hilos-admin-child-hilos_change_log_table_columns',
  )
  await expect(columnsLink).toHaveAttribute(
    'href',
    '/hilos/change-log/tables/hilos_user/columns',
  )
  await columnsLink.click()
  await expectPageReady(expect, page)
  await expect(page.getByTestId('hilos-admin-title')).toHaveText('Columns')
  await expect(
    page.getByTestId('hilos-change-log-table-columns'),
  ).toContainText('last_activity')
  await expect(page.getByTestId('hilos-change-log-table-triggers')).toHaveCount(
    0,
  )
  await expect(page.getByTestId('hilos-viewport-table')).toHaveCount(0)
  await page.getByTestId('hilos-change-log-table-overview').click()
  await expectTrackedTableOverview(expect, page, 'hilos_user')
  await page
    .getByTestId('hilos-admin-child-hilos_change_log_table_triggers')
    .click()
  await expectPageReady(expect, page)
  await expect(page.getByTestId('hilos-admin-title')).toHaveText('Triggers')
  await expect(
    page.getByTestId('hilos-change-log-table-triggers'),
  ).toContainText('hilos_cl_hilos_user_after_insert')
  await expect(page.getByTestId('hilos-change-log-table-columns')).toHaveCount(
    0,
  )
  await page.getByTestId('hilos-change-log-table-overview').click()
  await expectTrackedTableOverview(expect, page, 'hilos_user')
  await page
    .getByTestId('hilos-admin-child-hilos_change_log_table_history')
    .click()
  await expectTrackedTableWhole(expect, page, 'hilos_user')

  const tablePath = `${CHANGE_LOG_TABLES_PATH}/hilos_user`
  const legacyTablePages = [
    { kind: 'overview', path: tablePath },
    {
      kind: 'columns',
      path: `${tablePath}/columns`,
      title: 'Columns',
      body: 'hilos-change-log-table-columns',
      content: 'last_activity',
      otherBody: 'hilos-change-log-table-triggers',
    },
    {
      kind: 'triggers',
      path: `${tablePath}/triggers`,
      title: 'Triggers',
      body: 'hilos-change-log-table-triggers',
      content: 'hilos_cl_hilos_user_after_insert',
      otherBody: 'hilos-change-log-table-columns',
    },
    { kind: 'history', path: `${tablePath}/history` },
  ] as const
  const expectLegacyTablePage = async (
    route: (typeof legacyTablePages)[number],
  ) => {
    await expect(page).toHaveURL(route.path)
    await expectPageReady(expect, page)
    if (route.kind === 'overview') {
      await expectTrackedTableOverview(expect, page, 'hilos_user')
    } else if (route.kind === 'history') {
      await expectTrackedTableWhole(expect, page, 'hilos_user')
      await expect(page.getByTestId('hilos-viewport-table')).toHaveCount(1)
      await expect(
        page.getByTestId('hilos-change-log-table-columns'),
      ).toHaveCount(0)
      await expect(
        page.getByTestId('hilos-change-log-table-triggers'),
      ).toHaveCount(0)
    } else {
      const heading = page.getByRole('heading', { level: 1 })
      await expect(heading).toHaveCount(1)
      await expect(heading).toHaveAttribute('data-id', 'hilos-admin-title')
      await expect(heading).toHaveText(route.title)
      await expect(page.getByTestId(route.body)).toContainText(route.content)
      await expect(page.getByTestId(route.otherBody)).toHaveCount(0)
      await expect(page.getByTestId('hilos-viewport-table')).toHaveCount(0)
    }
    await expect(page.getByTestId('hilos-page-head')).toHaveCount(0)
    await expect(page.getByTestId('hilos-page-tabs')).toHaveCount(0)
    if (route.kind !== 'overview') {
      await expect(
        page.getByTestId('hilos-change-log-table-overview'),
      ).toHaveAttribute('href', tablePath)
    }
  }

  for (const route of legacyTablePages) {
    await gotoPage(expect, page, route.path)
    await expectLegacyTablePage(route)
    await page.reload()
    await expectLegacyTablePage(route)
  }
  await gotoPage(expect, page, legacyTablePages[0].path)
  await expectLegacyTablePage(legacyTablePages[0])
  await page
    .getByTestId('hilos-admin-child-hilos_change_log_table_columns')
    .click()
  await expectLegacyTablePage(legacyTablePages[1])
  await page.getByTestId('hilos-change-log-table-overview').click()
  await expectLegacyTablePage(legacyTablePages[0])
  await page
    .getByTestId('hilos-admin-child-hilos_change_log_table_triggers')
    .click()
  await expectLegacyTablePage(legacyTablePages[2])
  await page.getByTestId('hilos-change-log-table-overview').click()
  await expectLegacyTablePage(legacyTablePages[0])
  await page
    .getByTestId('hilos-admin-child-hilos_change_log_table_history')
    .click()
  await expectLegacyTablePage(legacyTablePages[3])
  for (const route of [
    legacyTablePages[0],
    legacyTablePages[2],
    legacyTablePages[0],
    legacyTablePages[1],
    legacyTablePages[0],
  ]) {
    await page.goBack()
    await expectLegacyTablePage(route)
  }
  for (const route of [
    legacyTablePages[1],
    legacyTablePages[0],
    legacyTablePages[2],
    legacyTablePages[0],
    legacyTablePages[3],
  ]) {
    await page.goForward()
    await expectLegacyTablePage(route)
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
})
