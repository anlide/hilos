import { test, expect } from '../test.js'

import {
  createTestUser,
  uniqueEmail,
  MAINTENANCE_CIRCLE_PATH,
  MAINTENANCE_PATH,
  MANUAL_MAINTENANCE_OPERATION,
  MANUAL_STUB_MESSAGE,
  MANUAL_STUB_TITLE,
  PAGE_READY,
  addToMaintenanceCircle,
  clearMaintenanceCircle,
  dismissToasts,
  expectMaintenanceOpen,
  expectMaintenancePageCurrent,
  expectMaintenanceWords,
  expectPageReady,
  expectToastPlacement,
  maintenanceCircleRow,
  shownByTestId,
  gotoPage,
  grantAdminToSelf,
  typeInto,
  watchClientDocument,
  watchDeliveryFrames,
} from '../../../../../framework/frontend/e2e/index.js'

// Naming a verifier in Maintenance: the server decides, the row arrives over the
// live table and again in the first window of Circle's own reply, and a refusal is
// said twice - in the dialog and in the corner. The same journey walks the React
// section's pages the way a person does - a link taken with Enter or a click, Back,
// Forward, a reload and a typed address - and reads Words: the project's words for
// the manual window, and the one fallback phrase of an operation without its own.
// The other live circle seams run once in their own demos: binance-btc-tracker's
// protected-mode.spec.ts covers the freeze, the admitted circle and an absent member
// with the live presence mark; online-testing's maintenance.spec.ts covers removal
// and a dialog over a row removed in another tab. This demo's protected-mode.spec.ts
// drives the React section's manual window: on with the button, a code issued in the
// section, a second administrator let in by it and turning it off.
// The section's dialogs in all three SDKs remain covered by unit tests:
// framework/frontend/vue/src/admin/maintenance/HilosMaintenanceCirclePage.test.ts,
// framework/frontend/react/test/HilosMaintenanceCirclePage.test.tsx,
// framework/frontend/angular/test/HilosMaintenancePage.test.ts.

test('a verifier named on Circle shows live and in the first window, both refusals are said twice, and Circle, Words and Overview follow links, history and reload', async ({
  page,
  browser,
}) => {
  const frames = watchDeliveryFrames(page)
  try {
    await grantAdminToSelf(expect, page)
    await gotoPage(expect, page, MAINTENANCE_PATH, PAGE_READY)
    await expectMaintenancePageCurrent(expect, page, 'hilos_maintenance')

    // Overview to Circle by the keyboard: the link is followed inside the document.
    const circleLink = page.getByTestId(
      'hilos-page-tab-hilos_maintenance_circle',
    )
    const wordsLink = page.getByTestId('hilos-page-tab-hilos_maintenance_words')
    const overviewLink = page.getByTestId('hilos-page-tab-hilos_maintenance')
    const entered = await watchClientDocument(page)
    await circleLink.focus()
    await expect(circleLink).toBeFocused()
    await circleLink.press('Enter')
    await expect(page).toHaveURL(/\/hilos\/maintenance\/circle$/)
    await expectPageReady(expect, page)
    await expectMaintenancePageCurrent(expect, page, 'hilos_maintenance_circle')
    await entered.unchanged()
    await entered.dispose()
    await clearMaintenanceCircle(page)

    // The prepared member has a verified address. This demo signs in by password
    // only. An address nobody has confirmed is exactly what the section refuses.
    const memberContext = await browser.newContext()
    const member = await memberContext.newPage()
    const memberEmail = (await createTestUser(expect, member)).email

    await addToMaintenanceCircle(page, memberEmail)

    // The row comes over the live table - there is no optimistic row to wait out - and
    // the ack's own sentence is the toast, standing in the corner.
    await expect(maintenanceCircleRow(page, memberEmail)).toBeVisible()
    await expect(
      page
        .getByTestId('hilos-toasts')
        .getByText(`${memberEmail} added to the circle.`),
    ).toBeVisible()
    await expectToastPlacement(expect, page, {
      edge: 'bottom',
      fullWidth: false,
    })
    await expect(
      page
        .locator('table')
        .getByTestId(`hilos-maintenance-circle-row-${memberEmail}`),
    ).toBeVisible()
    await expect(page.getByTestId('hilos-table-count')).toBeVisible()

    // A reload opens Circle cold: the member stands in the first window of its reply.
    await page.reload()
    await expectPageReady(expect, page)
    await expectMaintenancePageCurrent(expect, page, 'hilos_maintenance_circle')
    await expect(maintenanceCircleRow(page, memberEmail)).toBeVisible()

    // An address nobody has proven: the dialog stays, the refusal is its first line and
    // the typed address is still there; the toast is the second addressee.
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
    await typeInto(field, unproven)
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
    await expect(
      page
        .getByTestId('hilos-toast-error')
        .filter({ hasText: 'Nobody has proven this address' }),
    ).toHaveCSS('pointer-events', 'none')

    // The member's address a second time: refused the same two ways. The first card is
    // left standing - under an open dialog the stack takes no clicks - so the second one
    // is told apart by what it says.
    await typeInto(field, memberEmail)
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
    await dismissToasts(page)
    await memberContext.close()

    // Circle to Words by a click, Back to Circle and Forward to Words: all inside the
    // document, each page answering with its own first window.
    const walked = await watchClientDocument(page)
    const wordsAfter = frames.packets.length
    await wordsLink.click()
    await expect(page).toHaveURL(/\/hilos\/maintenance\/words$/)
    await expectPageReady(expect, page)
    await expectMaintenancePageCurrent(expect, page, 'hilos_maintenance_words')
    const words = await expectMaintenanceWords(expect, page, frames, wordsAfter)
    expect(
      words.find((row) => row.operation === MANUAL_MAINTENANCE_OPERATION),
    ).toMatchObject({
      title: MANUAL_STUB_TITLE,
      message: MANUAL_STUB_MESSAGE,
      usesDefault: false,
    })
    expect(words.find((row) => row.operation === 'restore')).toMatchObject({
      usesDefault: true,
    })

    await page.goBack()
    await expect(page).toHaveURL(/\/hilos\/maintenance\/circle$/)
    await expectPageReady(expect, page)
    await expectMaintenancePageCurrent(expect, page, 'hilos_maintenance_circle')
    await expect(maintenanceCircleRow(page, memberEmail)).toBeVisible()

    const forwardAfter = frames.packets.length
    await page.goForward()
    await expect(page).toHaveURL(/\/hilos\/maintenance\/words$/)
    await expectPageReady(expect, page)
    await expectMaintenancePageCurrent(expect, page, 'hilos_maintenance_words')
    expect(
      await expectMaintenanceWords(expect, page, frames, forwardAfter),
    ).toEqual(words)
    await walked.unchanged()
    await walked.dispose()

    // A reload opens Words cold, with the same words.
    const reloadAfter = frames.packets.length
    await page.reload()
    await expectPageReady(expect, page)
    await expectMaintenancePageCurrent(expect, page, 'hilos_maintenance_words')
    expect(
      await expectMaintenanceWords(expect, page, frames, reloadAfter),
    ).toEqual(words)

    // Words to Overview by a click: the section's own page, open.
    await overviewLink.click()
    await expect(page).toHaveURL(/\/hilos\/maintenance$/)
    await expectPageReady(expect, page)
    await expectMaintenancePageCurrent(expect, page, 'hilos_maintenance')
    await expectMaintenanceOpen(expect, page)

    // A typed address opens Circle with the member in it; the case leaves it empty.
    await gotoPage(expect, page, MAINTENANCE_CIRCLE_PATH, PAGE_READY)
    await expectMaintenancePageCurrent(expect, page, 'hilos_maintenance_circle')
    await expect(maintenanceCircleRow(page, memberEmail)).toBeVisible()
    await clearMaintenanceCircle(page)
  } finally {
    frames.stop()
  }
})
