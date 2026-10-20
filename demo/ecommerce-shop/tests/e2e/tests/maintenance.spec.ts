import { test, expect } from '@playwright/test'

import {
  MAINTENANCE_PATH,
  addToMaintenanceCircle,
  clearMaintenanceCircle,
  dismissToasts,
  expectToastPlacement,
  maintenanceCircleRow,
  shownByTestId,
  sidewaysOverflow,
} from '../../../../../framework/frontend/e2e/index.js'
import { grantAdminToSelf } from '../helpers/adminGrant.js'
import { gotoPage } from '../helpers/page.js'
import { signUp, uniqueEmail } from '../helpers/session.js'

// Naming a verifier in Maintenance: the server decides, the row arrives over the
// live table, and a refusal is said twice - in the dialog and in the corner.
// The other live circle seams run once in their own demos: binance-btc-tracker's
// protected-mode.spec.ts covers the freeze, the admitted circle and an absent member
// with the live presence mark; online-testing's maintenance.spec.ts covers removal
// and a dialog over a row removed in another tab. This demo's protected-mode.spec.ts
// drives the React section's manual window: on with the button, a code issued in the
// section, a second administrator let in by it and turning it off.
// The section's dialogs in all three SDKs remain covered by unit tests:
// framework/frontend/vue/src/admin/maintenance/HilosMaintenancePage.test.ts,
// framework/frontend/react/test/HilosMaintenancePage.test.tsx,
// framework/frontend/angular/test/HilosMaintenancePage.test.ts.
// The same notices prove where the stack stands: on a narrow screen at the bottom
// across the width, at the top while the bottom-sheet dialog is open, and in the
// corner from the md breakpoint up.

test('naming a verifier shows the row, and refuses an unproven address and a repeat in the dialog and in the corner', async ({
  page,
  browser,
}) => {
  await grantAdminToSelf(page)
  await gotoPage(page, MAINTENANCE_PATH)
  await clearMaintenanceCircle(page)

  // The registration code proves the address (HIL-825). This demo signs in by
  // password only. An address nobody has confirmed is exactly what the section
  // refuses.
  const memberContext = await browser.newContext()
  const member = await memberContext.newPage()
  const memberEmail = await signUp(member)

  // Named on a phone: the dialog is a bottom sheet there.
  const desktop = page.viewportSize() ?? { width: 1280, height: 720 }
  const narrow = { width: 375, height: desktop.height }
  await page.setViewportSize(narrow)
  await addToMaintenanceCircle(page, memberEmail)

  // The row comes over the live table - there is no optimistic row to wait out - and
  // the ack's own sentence is the toast. Cards are the narrow shape of the same
  // table. Both branches are mounted; only the cards are on screen, and the page
  // does not scroll sideways.
  await expect(maintenanceCircleRow(page, memberEmail)).toBeVisible()
  await expect(
    page
      .getByTestId('hilos-table-cards')
      .getByTestId(`hilos-maintenance-circle-row-${memberEmail}`),
  ).toBeVisible()
  expect(await sidewaysOverflow(page)).toEqual([0, 0])
  await expect(
    page
      .getByTestId('hilos-toasts')
      .getByText(`${memberEmail} added to the circle.`),
  ).toBeVisible()

  // The sheet has closed, so the bottom is free: the notice stands there across the
  // width, up to the md breakpoint, and keeps its corner from it on.
  await expectToastPlacement(expect, page, { edge: 'bottom', fullWidth: true })
  for (const width of [600, 767]) {
    await page.setViewportSize({ width, height: desktop.height })
    await expectToastPlacement(expect, page, {
      edge: 'bottom',
      fullWidth: true,
    })
  }
  await page.setViewportSize({ width: 768, height: desktop.height })
  await expectToastPlacement(expect, page, { edge: 'bottom', fullWidth: false })
  await page.setViewportSize(desktop)
  await expectToastPlacement(expect, page, { edge: 'bottom', fullWidth: false })
  await expect(
    page
      .locator('table')
      .getByTestId(`hilos-maintenance-circle-row-${memberEmail}`),
  ).toBeVisible()
  await expect(page.getByTestId('hilos-table-count')).toBeVisible()

  // Back on the phone the sheet takes the bottom, and the notice still standing
  // moves to the top for as long as the sheet is open.
  await page.setViewportSize(narrow)
  await expect(
    page
      .getByTestId('hilos-toasts')
      .getByText(`${memberEmail} added to the circle.`),
  ).toBeVisible()

  // An address nobody has proven: the dialog stays, the refusal is its first line and
  // the typed address is still there; the toast above the sheet is the second
  // addressee.
  const unproven = uniqueEmail()
  await shownByTestId(
    page.getByTestId('hilos-maintenance-circle-table'),
    'hilos-table-main-action',
  ).click()
  const dialog = page.getByTestId('modal')
  const field = dialog.getByTestId('hilos-maintenance-circle-add-field')
  const confirm = dialog.getByTestId('hilos-maintenance-circle-add-confirm')
  const refusal = dialog.getByTestId('hilos-action-error')
  await expect(field).toBeVisible()
  await expectToastPlacement(expect, page, { edge: 'top', fullWidth: true })
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
  await expectToastPlacement(expect, page, { edge: 'top', fullWidth: true })
  await expect(
    page
      .getByTestId('hilos-toast-error')
      .filter({ hasText: 'Nobody has proven this address' }),
  ).toHaveCSS('pointer-events', 'none')

  // The member's address a second time: refused the same two ways. The first card is
  // left standing - under an open dialog the stack takes no clicks - so the second one
  // is told apart by what it says.
  await field.fill('')
  await field.pressSequentially(memberEmail, { delay: 10 })
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
  await expect(field).toHaveValue(memberEmail)
  await expect(confirm).toBeEnabled()

  await dialog.getByTestId('modal-close').click()
  await expect(dialog).toHaveCount(0)
  // The last dialog has gone, and the refusals left standing go back to the bottom.
  await expectToastPlacement(expect, page, { edge: 'bottom', fullWidth: true })
  await page.setViewportSize(desktop)
  await dismissToasts(page)
  await memberContext.close()

  await gotoPage(page, MAINTENANCE_PATH)
  await clearMaintenanceCircle(page)
})
