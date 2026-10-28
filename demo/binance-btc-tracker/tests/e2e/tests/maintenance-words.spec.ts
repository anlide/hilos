import { test, expect } from '@playwright/test'

import {
  expectMaintenanceOpen,
  expectMaintenancePageHead,
  expectPageReady,
  gotoPage,
  grantAdminToSelf,
  MAINTENANCE_PATH,
  MAINTENANCE_WORDS_PATH,
  MANUAL_MAINTENANCE_OPERATION,
  MANUAL_STUB_MESSAGE,
  MANUAL_STUB_TITLE,
  PAGE_READY,
  shownByTestId,
} from '../../../../../framework/frontend/e2e/index.js'

// Maintenance Words e2e (Alexander Baranov <alexander.baranov@anlide.online>, @ticket HIL-1677):
// Verifies the Words tab in Vue Maintenance: opening from Overview,
// verifying default fallback stub rowMessage and manual_maintenance copy,
// direct navigation and reload, and returning to Overview and Circle tabs.

const RESTORE_OPERATION = 'restore'
const NO_OWN_ENTRY = 'No own entry — uses default'

test('admin opens overview, navigates to words tab, verifies stub copy and fallback row message, and returns to circle and overview', async ({
  page,
}) => {
  await grantAdminToSelf(expect, page)
  await gotoPage(expect, page, MAINTENANCE_PATH, PAGE_READY)
  await expectMaintenancePageHead(expect, page, 'hilos_maintenance')
  await expectMaintenanceOpen(expect, page)

  // Navigate to Words tab from Overview
  const wordsTab = page.getByTestId('hilos-page-tab-hilos_maintenance_words')
  await wordsTab.click()
  await expect(page).toHaveURL(/\/hilos\/maintenance\/words$/)
  await expectPageReady(expect, page)
  await expectMaintenancePageHead(expect, page, 'hilos_maintenance_words')

  const table = page.getByTestId('hilos-maintenance-words-table')
  await expect(table).toBeVisible()
  await shownByTestId(table, 'hilos-table-loading').waitFor({ state: 'hidden' })

  // manual_maintenance row has backend copy
  const manualRow = shownByTestId(
    table,
    `hilos-table-row-${MANUAL_MAINTENANCE_OPERATION}`,
  )
  await expect(manualRow).toBeVisible()
  await expect(
    shownByTestId(
      manualRow,
      `hilos-maintenance-words-row-${MANUAL_MAINTENANCE_OPERATION}`,
    ),
  ).toHaveText(MANUAL_MAINTENANCE_OPERATION)
  await expect(manualRow).toContainText(MANUAL_STUB_TITLE)
  await expect(manualRow).toContainText(MANUAL_STUB_MESSAGE)

  // restore row has fallback rowMessage ("No own entry — uses default")
  const restoreRow = shownByTestId(
    table,
    `hilos-table-row-${RESTORE_OPERATION}`,
  )
  await expect(restoreRow).toBeVisible()
  await expect(
    shownByTestId(
      restoreRow,
      `hilos-maintenance-words-row-${RESTORE_OPERATION}`,
    ),
  ).toHaveText(RESTORE_OPERATION)
  const restoreMessage = shownByTestId(restoreRow, 'hilos-table-row-message')
  await expect(restoreMessage).toHaveCount(1)
  await expect(restoreMessage).toHaveText(NO_OWN_ENTRY)

  // Reload (F5) on Words tab
  await page.reload()
  await expectPageReady(expect, page)
  await expectMaintenancePageHead(expect, page, 'hilos_maintenance_words')
  await expect(table).toBeVisible()
  await expect(manualRow).toBeVisible()
  await expect(restoreRow).toBeVisible()

  // Direct entry via gotoPage
  await gotoPage(expect, page, MAINTENANCE_WORDS_PATH, PAGE_READY)
  await expectPageReady(expect, page)
  await expectMaintenancePageHead(expect, page, 'hilos_maintenance_words')
  await expect(table).toBeVisible()

  // Return to Circle tab
  const circleTab = page.getByTestId('hilos-page-tab-hilos_maintenance_circle')
  await circleTab.click()
  await expect(page).toHaveURL(/\/hilos\/maintenance\/circle$/)
  await expectPageReady(expect, page)
  await expectMaintenancePageHead(expect, page, 'hilos_maintenance_circle')
  await expect(page.getByTestId('hilos-maintenance-circle-table')).toBeVisible()

  // Return to Overview tab
  const overviewTab = page.getByTestId('hilos-page-tab-hilos_maintenance')
  await overviewTab.click()
  await expect(page).toHaveURL(/\/hilos\/maintenance$/)
  await expectPageReady(expect, page)
  await expectMaintenancePageHead(expect, page, 'hilos_maintenance')
  await expectMaintenanceOpen(expect, page)
})
