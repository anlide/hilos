import { test, expect } from '@playwright/test'

import {
  createTestUser,
  MAINTENANCE_CIRCLE_PATH,
  MAINTENANCE_PATH,
  MAINTENANCE_WORDS_PATH,
  MANUAL_MAINTENANCE_OPERATION,
  PAGE_READY,
  addToMaintenanceCircle,
  clearMaintenanceCircle,
  confirmMaintenanceCircleRemoval,
  dismissToasts,
  expectMaintenancePageHead,
  expectMaintenanceWatchdogThresholds,
  expectMaintenanceWordsInitial,
  expectPageReady,
  maintenanceCircleRow,
  shownByTestId,
  gotoPage,
  grantAdminToSelf,
  watchDeliveryFrames,
} from '../../../../../framework/frontend/e2e/index.js'

// The Angular Maintenance section walked by an administrator: Overview shows the
// watchdog thresholds its state frame carries; the tabs, Back and Forward, F5 and
// a direct address reach Circle and Words through the ordinary page subscription;
// Words draws the registry rows the page's own answer carried. On Circle, taking a
// verifier out removes the row over the live table, and a dialog open over a row
// removed in another tab says so before anybody presses Remove. The other live
// circle seams run once in their own demos: binance-btc-tracker's
// protected-mode.spec.ts covers the freeze, the admitted circle and an absent
// member with the live presence mark; ecommerce-shop's maintenance.spec.ts covers
// naming and both refusals in the dialog and in the corner. This demo's
// protected-mode.spec.ts drives the Angular section's manual window: on with the
// button, a code issued in the section, a second administrator let in by it and
// turning it off.
// The section's dialogs in all three SDKs remain covered by unit tests:
// framework/frontend/vue/src/admin/maintenance/HilosMaintenanceCirclePage.test.ts,
// framework/frontend/react/test/HilosMaintenancePage.test.tsx,
// framework/frontend/angular/test/HilosMaintenanceCirclePage.test.ts.

/**
 * The operations every project's Words table lists, copied verbatim from
 * ProtectedModeStubConstants::DEFAULT_OPERATION and BackupAgent::RESTORE_OPERATION.
 * This project keeps the framework's registry, which has an entry for the default
 * and the manual window and none of its own for restore.
 */
const DEFAULT_OPERATION = 'default'
const RESTORE_OPERATION = 'restore'

const OVERVIEW_URL = new RegExp(`${MAINTENANCE_PATH}$`)
const CIRCLE_URL = new RegExp(`${MAINTENANCE_CIRCLE_PATH}$`)
const WORDS_URL = new RegExp(`${MAINTENANCE_WORDS_PATH}$`)

test('an administrator walks Maintenance by its tabs, history, F5 and a direct address over the real thresholds and words, and a verifier taken out in another tab leaves the table and locks the dialog over its row', async ({
  page,
  browser,
}) => {
  // Armed before the first socket opens: the thresholds and the words are read
  // against the frames that really arrived.
  const frames = watchDeliveryFrames(page)
  try {
    await grantAdminToSelf(expect, page)

    const thresholds =
      await test.step('Overview shows the thresholds of its state frame', async () => {
        await gotoPage(expect, page, MAINTENANCE_PATH, PAGE_READY)
        await expectMaintenancePageHead(expect, page, 'hilos_maintenance')
        return expectMaintenanceWatchdogThresholds(expect, page, frames)
      })

    await test.step('the Circle tab, pressed from the keyboard, opens the circle', async () => {
      const circle = page.getByTestId('hilos-page-tab-hilos_maintenance_circle')
      await circle.focus()
      await expect(circle).toBeFocused()
      await circle.press('Enter')
      await expect(page).toHaveURL(CIRCLE_URL)
      await expectPageReady(expect, page)
      await expectMaintenancePageHead(expect, page, 'hilos_maintenance_circle')
      await clearMaintenanceCircle(page)
    })

    await test.step('a verifier taken out in another tab leaves the table and locks the dialog over its row', async () => {
      const memberContext = await browser.newContext()
      const member = await memberContext.newPage()
      const { email } = await createTestUser(expect, member)
      await addToMaintenanceCircle(page, email)
      await dismissToasts(page)

      // A second tab of the same administrator, on the same page and the same row.
      const tabB = await page.context().newPage()
      await gotoPage(expect, tabB, MAINTENANCE_CIRCLE_PATH)
      await expect(maintenanceCircleRow(tabB, email)).toBeVisible()

      // A opens the dialog over the row: it names the address and Remove is live.
      await shownByTestId(
        page.getByTestId('hilos-maintenance-circle-table'),
        `hilos-maintenance-circle-remove-${email}`,
      ).click()
      const dialogA = page.getByTestId('modal')
      const confirmA = page.getByTestId(
        'hilos-maintenance-circle-remove-confirm',
      )
      await expect(confirmA).toBeEnabled()
      await expect(dialogA).toContainText(email)

      // B takes the member out: the row leaves B's table over the live table, and
      // the ack's own sentence is B's toast.
      await shownByTestId(
        tabB.getByTestId('hilos-maintenance-circle-table'),
        `hilos-maintenance-circle-remove-${email}`,
      ).click()
      await confirmMaintenanceCircleRemoval(tabB)
      // Both copies, the hidden one included: a visible-only count would pass
      // while the other branch still drew the row.
      await expect(
        tabB.getByTestId(`hilos-maintenance-circle-row-${email}`),
      ).toHaveCount(0)
      await expect(
        tabB
          .getByTestId('hilos-toasts')
          .getByText(`${email} removed from the circle.`),
      ).toBeVisible()

      // A's dialog heard it before anybody pressed anything: Remove reads Removed
      // and is locked, and the message line says why. Cancel is all that is left
      // to press.
      await expect(confirmA).toHaveText('Removed')
      await expect(confirmA).toBeDisabled()
      await expect(
        page.getByTestId('hilos-maintenance-circle-remove-notice'),
      ).toContainText('Removed from the circle elsewhere.')
      await dialogA.getByTestId('modal-close').click()
      await expect(confirmA).toHaveCount(0)

      await tabB.close()
      await memberContext.close()
    })

    const words =
      await test.step('the Words tab draws the rows its page answered with', async () => {
        const after = frames.packets.length
        await page.getByTestId('hilos-page-tab-hilos_maintenance_words').click()
        await expect(page).toHaveURL(WORDS_URL)
        await expectPageReady(expect, page)
        await expectMaintenancePageHead(expect, page, 'hilos_maintenance_words')
        const rows = await expectMaintenanceWordsInitial(
          expect,
          page,
          frames,
          after,
        )
        const byOperation = new Map(rows.map((row) => [row.operation, row]))
        for (const operation of [
          DEFAULT_OPERATION,
          MANUAL_MAINTENANCE_OPERATION,
        ]) {
          expect(byOperation.get(operation)).toEqual({
            operation,
            title: expect.any(String),
            message: expect.any(String),
            bannerMessage: expect.any(String),
            usesDefault: false,
          })
        }
        expect(byOperation.get(RESTORE_OPERATION)).toEqual({
          operation: RESTORE_OPERATION,
          title: null,
          message: null,
          bannerMessage: null,
          usesDefault: true,
        })
        return rows
      })

    await test.step('Back returns to Circle and Forward to Words', async () => {
      await page.goBack()
      await expect(page).toHaveURL(CIRCLE_URL)
      await expectPageReady(expect, page)
      await expectMaintenancePageHead(expect, page, 'hilos_maintenance_circle')
      await expect(
        page.getByTestId('hilos-maintenance-circle-table'),
      ).toBeVisible()

      const after = frames.packets.length
      await page.goForward()
      await expect(page).toHaveURL(WORDS_URL)
      await expectPageReady(expect, page)
      await expectMaintenancePageHead(expect, page, 'hilos_maintenance_words')
      expect(
        await expectMaintenanceWordsInitial(expect, page, frames, after),
      ).toEqual(words)
    })

    await test.step('F5 on Words draws the same rows from a fresh answer', async () => {
      const after = frames.packets.length
      await page.reload()
      await expectPageReady(expect, page)
      await expectMaintenancePageHead(expect, page, 'hilos_maintenance_words')
      expect(
        await expectMaintenanceWordsInitial(expect, page, frames, after),
      ).toEqual(words)
    })

    await test.step('the Overview tab keeps the thresholds, and a direct address opens Words', async () => {
      await page.getByTestId('hilos-page-tab-hilos_maintenance').click()
      await expect(page).toHaveURL(OVERVIEW_URL)
      await expectPageReady(expect, page)
      await expectMaintenancePageHead(expect, page, 'hilos_maintenance')
      expect(
        await expectMaintenanceWatchdogThresholds(expect, page, frames),
      ).toEqual(thresholds)

      const after = frames.packets.length
      await gotoPage(expect, page, MAINTENANCE_WORDS_PATH, PAGE_READY)
      await expectMaintenancePageHead(expect, page, 'hilos_maintenance_words')
      expect(
        await expectMaintenanceWordsInitial(expect, page, frames, after),
      ).toEqual(words)
    })
  } finally {
    frames.stop()
  }
})
