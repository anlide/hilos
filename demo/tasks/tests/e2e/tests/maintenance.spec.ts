import { test, expect } from '@playwright/test'

import {
  addToMaintenanceCircle,
  clearMaintenanceCircle,
  dismissToasts,
  maintenanceCircleRow,
} from '../../../../../framework/frontend/e2e/index.js'
import { grantAdminToSelf } from '../helpers/adminGrant.js'
import { gotoPage } from '../helpers/page.js'
import { signInByPhone, uniqueEmail } from '../helpers/session.js'

// Naming a verifier in Maintenance: the server decides, the row arrives over the
// live table, and a refusal is said twice - in the dialog and in the corner.
// The other live circle seams run once in their own demos: binance-btc-tracker's
// protected-mode.spec.ts covers the freeze, the admitted circle and an absent member
// with the live presence mark; polls' maintenance.spec.ts covers removal and a dialog
// over a row removed in another tab.
// The section's dialogs in all three SDKs remain covered by unit tests:
// framework/frontend/vue/src/admin/maintenance/HilosMaintenancePage.test.ts,
// framework/frontend/react/test/HilosMaintenancePage.test.tsx,
// framework/frontend/angular/test/HilosMaintenancePage.test.ts.

const MAINTENANCE_URL = '/hilos/maintenance'

test('naming a verifier shows the row, and refuses an unproven address and a repeat in the dialog and in the corner', async ({
  page,
  browser,
}) => {
  await grantAdminToSelf(page)
  await gotoPage(page, MAINTENANCE_URL)
  await clearMaintenanceCircle(page)

  // Signing in by SMS proves the phone, so the section can name this address.
  // An address nobody has confirmed is exactly what the section refuses.
  const memberContext = await browser.newContext()
  const member = await memberContext.newPage()
  const phone = await signInByPhone(member)

  await addToMaintenanceCircle(page, phone)

  // The row comes over the live table - there is no optimistic row to wait out - and
  // the ack's own sentence is the toast.
  await expect(maintenanceCircleRow(page, phone)).toBeVisible()
  await expect(
    page.getByTestId('hilos-toasts').getByText(`${phone} added to the circle.`),
  ).toBeVisible()
  await dismissToasts(page)

  // An address nobody has proven: the dialog stays, the refusal is its first line and
  // the typed address is still there; the toast in the corner is the second addressee.
  const unproven = uniqueEmail()
  await page.getByTestId('hilos-maintenance-circle-add').click()
  const dialog = page.getByTestId('modal')
  const field = dialog.getByTestId('hilos-maintenance-circle-add-field')
  const confirm = dialog.getByTestId('hilos-maintenance-circle-add-confirm')
  const refusal = dialog.getByTestId('hilos-action-error')
  await expect(field).toBeVisible()
  await field.fill('')
  await field.pressSequentially(unproven, { delay: 10 })
  await confirm.scrollIntoViewIfNeeded()
  await expect(confirm).toBeVisible()
  await expect(confirm).toBeEnabled()
  await confirm.focus()
  await confirm.click()

  await expect(refusal).toBeVisible()
  await expect(refusal).toContainText('Nobody has proven this address')
  await expect(
    page
      .getByTestId('hilos-toast-error')
      .filter({ hasText: 'Nobody has proven this address' }),
  ).toBeVisible()
  await expect(field).toHaveValue(unproven)
  await expect(confirm).toBeEnabled()

  // The member's address a second time: refused the same two ways. The first card is
  // left standing - under an open dialog the stack takes no clicks - so the second one
  // is told apart by what it says.
  await field.fill('')
  await field.pressSequentially(phone, { delay: 10 })
  await confirm.scrollIntoViewIfNeeded()
  await expect(confirm).toBeVisible()
  await expect(confirm).toBeEnabled()
  await confirm.focus()
  await confirm.click()

  await expect(refusal).toContainText('This address is already in the circle')
  await expect(
    page
      .getByTestId('hilos-toast-error')
      .filter({ hasText: 'This address is already in the circle' }),
  ).toBeVisible()
  await expect(field).toHaveValue(phone)
  await expect(confirm).toBeEnabled()

  await dialog.getByTestId('modal-close').click()
  await expect(dialog).toHaveCount(0)
  await memberContext.close()

  await gotoPage(page, MAINTENANCE_URL)
  await clearMaintenanceCircle(page)
})
