import { test, expect } from '@playwright/test'

import { dismissToasts } from '../../../../../framework/frontend/e2e/index.js'
import { signUpAdmin } from '../helpers/adminGrant'
import { gotoPage } from '../helpers/page'
import {
  addToMaintenanceCircle,
  clearMaintenanceCircle,
  confirmMaintenanceCircleRemoval,
} from '../helpers/protectedMode'
import { signUpWithVerifiedEmail, uniqueEmail } from '../helpers/session'

// Maintenance section e2e (/hilos/maintenance, HIL-1119, HIL-1120, HIL-1121): what the
// section promises on top of the list itself. The mark of whether a named person is
// signed in is live - the seam no unit test crosses: the member's socket closes in one
// process, the section is served by the hilos index agent in another, and the row has
// to be re-drawn on the administrator's open page from that closing alone. A person is
// named here: the row arrives over the live table, and a refusal is said twice - in the
// dialog and in the corner. And a person is taken out here: the row leaves over the
// live table, and a dialog open over a row another tab took out says so before anybody
// presses Remove.

const MAINTENANCE_URL = '/hilos/maintenance'

// The two words of the section's presence column.
const CIRCLE_ONLINE = 'signed in'
const CIRCLE_OFFLINE = 'not signed in'

test('the signed-in mark of a circle member follows their tab live', async ({
  page,
  browser,
}) => {
  // The circle is a durable list that outlives a case, so the case owns it first: a
  // member some other case left named would be one more row to read around.
  await signUpAdmin(page)
  await gotoPage(page, MAINTENANCE_URL)
  await clearMaintenanceCircle(page)

  // Named with a PROVEN address: naming resolves the address against the confirmed
  // identities, and plain registration leaves it unverified.
  const memberContext = await browser.newContext()
  const member = await memberContext.newPage()
  const { email: memberEmail } = await signUpWithVerifiedEmail(member)
  await addToMaintenanceCircle(page, memberEmail)

  await gotoPage(member, '/')
  await expect(member.getByTestId('conn-state')).toHaveText('connected')

  await gotoPage(page, MAINTENANCE_URL)
  await expect(
    page.getByTestId('hilos-maintenance-circle-panel'),
  ).toBeVisible()
  await expect(
    page.getByTestId(`hilos-maintenance-circle-row-${memberEmail}`),
  ).toBeVisible()
  const mark = page.getByTestId(
    `hilos-maintenance-circle-online-${memberEmail}`,
  )
  await expect(mark).toHaveText(CIRCLE_ONLINE)

  // The member leaves, and the administrator's page is not touched at all: no
  // navigation and no reload. Whatever turns the mark now arrived over the socket,
  // re-drawn from the closing of the member's connection.
  await memberContext.close()
  await expect(mark).toHaveText(CIRCLE_OFFLINE)

  await gotoPage(page, MAINTENANCE_URL)
  await clearMaintenanceCircle(page)
})

test('naming a verifier shows the row, and refuses an unproven address and a repeat in the dialog and in the corner', async ({
  page,
  browser,
}) => {
  await signUpAdmin(page)
  await gotoPage(page, MAINTENANCE_URL)
  await clearMaintenanceCircle(page)

  // Named with a PROVEN address: plain registration leaves it unverified, and an
  // unproven address is exactly what the section refuses.
  const memberContext = await browser.newContext()
  const member = await memberContext.newPage()
  const { email: memberEmail } = await signUpWithVerifiedEmail(member)

  await addToMaintenanceCircle(page, memberEmail)

  // The row comes over the live table - there is no optimistic row to wait out - and
  // the ack's own sentence is the toast.
  await expect(
    page.getByTestId(`hilos-maintenance-circle-row-${memberEmail}`),
  ).toBeVisible()
  await expect(
    page.getByTestId('hilos-toasts').getByText(`${memberEmail} added to the circle.`),
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
  await field.pressSequentially(memberEmail, { delay: 10 })
  await confirm.scrollIntoViewIfNeeded()
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
  await memberContext.close()

  await gotoPage(page, MAINTENANCE_URL)
  await clearMaintenanceCircle(page)
})

test('taking a verifier out removes the row, and a dialog over a row taken out in another tab says so and locks Remove', async ({
  page,
  browser,
}) => {
  await signUpAdmin(page)
  await gotoPage(page, MAINTENANCE_URL)
  await clearMaintenanceCircle(page)

  const memberContext = await browser.newContext()
  const member = await memberContext.newPage()
  const { email: memberEmail } = await signUpWithVerifiedEmail(member)
  await addToMaintenanceCircle(page, memberEmail)
  await dismissToasts(page)

  // A second tab of the same administrator, on the same section and the same row.
  const tabB = await page.context().newPage()
  await gotoPage(tabB, MAINTENANCE_URL)
  await expect(
    tabB.getByTestId(`hilos-maintenance-circle-row-${memberEmail}`),
  ).toBeVisible()

  // A opens the dialog over the row: it names the address and Remove is live.
  await page
    .getByTestId('hilos-maintenance-circle-table')
    .getByTestId(`hilos-maintenance-circle-remove-${memberEmail}`)
    .click()
  const dialogA = page.getByTestId('modal')
  const confirmA = page.getByTestId('hilos-maintenance-circle-remove-confirm')
  await expect(confirmA).toBeEnabled()
  await expect(dialogA).toContainText(memberEmail)

  // B takes the member out: the row leaves B's table over the live table, and the
  // ack's own sentence is B's toast.
  await tabB
    .getByTestId('hilos-maintenance-circle-table')
    .getByTestId(`hilos-maintenance-circle-remove-${memberEmail}`)
    .click()
  await confirmMaintenanceCircleRemoval(tabB)
  await expect(
    tabB.getByTestId(`hilos-maintenance-circle-row-${memberEmail}`),
  ).toHaveCount(0)
  await expect(
    tabB
      .getByTestId('hilos-toasts')
      .getByText(`${memberEmail} removed from the circle.`),
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
