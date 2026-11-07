import { test, expect } from '../test.js'

import {
  expectMaintenanceOpen,
  expectMaintenancePageCurrent,
  expectMaintenanceWords,
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
  watchDeliveryFrames,
} from '../../../../../framework/frontend/e2e/index.js'

// Maintenance Words e2e (Alexander Baranov <alexander.baranov@anlide.online>, @ticket HIL-1677):
// Verifies the Words tab in Vue Maintenance: opening from Overview,
// verifying default fallback stub rowMessage and manual_maintenance copy,
// direct navigation and reload, and returning to Overview and Circle tabs.

const RESTORE_OPERATION = 'restore'

test('admin opens overview, navigates to words tab, verifies stub copy and fallback row message, and returns to circle and overview', async ({
  page,
}) => {
  const frames = watchDeliveryFrames(page)
  try {
    await grantAdminToSelf(expect, page)
    await gotoPage(expect, page, MAINTENANCE_PATH, PAGE_READY)
    await expectMaintenancePageCurrent(expect, page, 'hilos_maintenance')
    await expectMaintenanceOpen(expect, page)

    // Navigate to Words tab from Overview
    const wordsTab = page.getByTestId('hilos-page-tab-hilos_maintenance_words')
    const after = frames.packets.length
    await wordsTab.click()
    await expect(page).toHaveURL(/\/hilos\/maintenance\/words$/)
    await expectPageReady(expect, page)
    await expectMaintenancePageCurrent(expect, page, 'hilos_maintenance_words')

    const rows = await expectMaintenanceWords(expect, page, frames, after)
    expect(
      rows.find((row) => row.operation === MANUAL_MAINTENANCE_OPERATION),
    ).toMatchObject({
      title: MANUAL_STUB_TITLE,
      message: MANUAL_STUB_MESSAGE,
      usesDefault: false,
    })
    expect(
      rows.find((row) => row.operation === RESTORE_OPERATION),
    ).toMatchObject({
      usesDefault: true,
    })

    const table = page.getByTestId('hilos-maintenance-words-table')
    const manualRow = shownByTestId(
      table,
      `hilos-table-row-${MANUAL_MAINTENANCE_OPERATION}`,
    )
    const restoreRow = shownByTestId(
      table,
      `hilos-table-row-${RESTORE_OPERATION}`,
    )

    // Reload (F5) on Words tab
    await page.reload()
    await expectPageReady(expect, page)
    await expectMaintenancePageCurrent(expect, page, 'hilos_maintenance_words')
    await expect(table).toBeVisible()
    await expect(manualRow).toBeVisible()
    await expect(restoreRow).toBeVisible()

    // Direct entry via gotoPage
    await gotoPage(expect, page, MAINTENANCE_WORDS_PATH, PAGE_READY)
    await expectPageReady(expect, page)
    await expectMaintenancePageCurrent(expect, page, 'hilos_maintenance_words')
    await expect(table).toBeVisible()

    // Return to Circle tab
    const circleTab = page.getByTestId(
      'hilos-page-tab-hilos_maintenance_circle',
    )
    await circleTab.click()
    await expect(page).toHaveURL(/\/hilos\/maintenance\/circle$/)
    await expectPageReady(expect, page)
    await expectMaintenancePageCurrent(expect, page, 'hilos_maintenance_circle')
    await expect(
      page.getByTestId('hilos-maintenance-circle-table'),
    ).toBeVisible()

    // Return to Overview tab
    const overviewTab = page.getByTestId('hilos-page-tab-hilos_maintenance')
    await overviewTab.click()
    await expect(page).toHaveURL(/\/hilos\/maintenance$/)
    await expectPageReady(expect, page)
    await expectMaintenancePageCurrent(expect, page, 'hilos_maintenance')
    await expectMaintenanceOpen(expect, page)
  } finally {
    frames.stop()
  }
})
