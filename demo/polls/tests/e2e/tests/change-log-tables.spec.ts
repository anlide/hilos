import { expect, test } from '@playwright/test'

import {
  CHANGE_LOG_TABLES_LEAD,
  CHANGE_LOG_TABLES_PATH,
  expectChangeLogReference,
  expectTrackedTablesPage,
  shownByTestId,
} from '../../../../../framework/frontend/e2e/index.js'
import { signUpAdmin } from '../helpers/adminGrant.js'
import { expectPageReady, gotoPage } from '../helpers/page.js'
import { typeInto } from '../helpers/session.js'

test('opens Tracked tables from Change Log, filters journal labels and reads schema metadata', async ({
  page,
}) => {
  await signUpAdmin(page)
  await gotoPage(page, '/hilos')
  await page.getByTestId('dashboard-card-hilos_change_log').click()
  await expect(page.getByTestId('hilos-admin-title')).toHaveText('Change Log')
  await expectPageReady(page)
  // Section cards belong to HilosAdminPage; dashboard-card names only root cards.
  const card = page.getByTestId('hilos-admin-child-hilos_change_log_tables')
  await expect(card).toBeVisible()
  await expect(card).toContainText(CHANGE_LOG_TABLES_LEAD)
  await card.focus()
  await page.keyboard.press('Enter')
  await expectPageReady(page)
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
  await expectPageReady(page)
  await expect(page.getByTestId('hilos-admin-title')).toHaveText(
    'Tracked table',
  )
  await expect(page.getByTestId('hilos-change-log-table-header')).toContainText(
    'hilos_user',
  )
  await gotoPage(page, CHANGE_LOG_TABLES_PATH)

  await expectChangeLogReference(expect, page)
  const noiseTable = page.getByRole('table', {
    name: 'Noisy columns',
    exact: true,
  })

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
  await expect(noiseTable).toContainText('hilos_user.last_activity')

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
})
