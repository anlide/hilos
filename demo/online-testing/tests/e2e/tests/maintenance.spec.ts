import { test, expect } from '@playwright/test'

import {
  addToMaintenanceCircle,
  clearMaintenanceCircle,
  confirmMaintenanceCircleRemoval,
  dismissToasts,
  maintenanceCircleRow,
  shownByTestId,
} from '../../../../../framework/frontend/e2e/index.js'
import { grantAdminToSelf } from '../helpers/adminGrant.js'
import { gotoPage } from '../helpers/page.js'
import { signUp } from '../helpers/session.js'

// Taking a verifier out in Maintenance: the row leaves over the live table, and a
// dialog open over a row removed in another tab says so before anybody presses
// Remove. The other live circle seams run once in their own demos:
// binance-btc-tracker's protected-mode.spec.ts covers the freeze, the admitted circle
// and an absent member with the live presence mark; ecommerce-shop's
// maintenance.spec.ts covers naming and both refusals in the dialog and in the
// corner.
// The section's dialogs in all three SDKs remain covered by unit tests:
// framework/frontend/vue/src/admin/maintenance/HilosMaintenancePage.test.ts,
// framework/frontend/react/test/HilosMaintenancePage.test.tsx,
// framework/frontend/angular/test/HilosMaintenancePage.test.ts.

const MAINTENANCE_URL = '/hilos/maintenance'

test('taking a verifier out removes the row, and a dialog over a row taken out in another tab says so and locks Remove', async ({
  page,
  browser,
}) => {
  await grantAdminToSelf(page)
  await gotoPage(page, MAINTENANCE_URL)
  await clearMaintenanceCircle(page)

  const memberContext = await browser.newContext()
  const member = await memberContext.newPage()
  const email = await signUp(member)
  await addToMaintenanceCircle(page, email)
  await dismissToasts(page)

  // A second tab of the same administrator, on the same section and the same row.
  const tabB = await page.context().newPage()
  await gotoPage(tabB, MAINTENANCE_URL)
  await expect(maintenanceCircleRow(tabB, email)).toBeVisible()

  // A opens the dialog over the row: it names the address and Remove is live.
  await shownByTestId(
    page.getByTestId('hilos-maintenance-circle-table'),
    `hilos-maintenance-circle-remove-${email}`,
  ).click()
  const dialogA = page.getByTestId('modal')
  const confirmA = page.getByTestId('hilos-maintenance-circle-remove-confirm')
  await expect(confirmA).toBeEnabled()
  await expect(dialogA).toContainText(email)

  // B takes the member out: the row leaves B's table over the live table, and the
  // ack's own sentence is B's toast.
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

  // A's dialog heard it before anybody pressed anything: Remove reads Removed and is
  // locked, and the message line says why. Cancel is all that is left to press.
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
