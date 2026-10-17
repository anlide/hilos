import type { Expect, Locator, Page } from '@playwright/test'

import { shownByTestId } from './table.js'

// Maintenance section steps shared by all three SDKs, whose data-id controls match,
// and the manual-window checks all three kits must meet alike; the scenarios stay
// in each demo's spec. The steps expect the operator's page on /hilos/maintenance,
// except the code's entry, which is the maintenance screen's own. Only an address
// somebody has confirmed can be named to the verifier circle.

// How many rows the circle is emptied of before the attempt is called broken. Every
// removal takes one row out, so the count is what ends that loop; this only makes a
// removal surface that stopped removing fail where it broke instead of hanging until
// the test times out.
const CIRCLE_CLEAR_LIMIT = 25

/**
 * The two words of the maintenance section's presence column, copied verbatim from
 * core/src/admin/maintenance/hilosMaintenance.ts. The e2e package builds against no
 * framework source, so these literals are kept beside the locators that read them.
 */
export const CIRCLE_ONLINE = 'signed in'
export const CIRCLE_OFFLINE = 'not signed in'

/**
 * The section's address, copied verbatim from HilosPages.MAINTENANCE in
 * core/src/routing/hilosPages.ts. The e2e package builds against no framework
 * source, so the literal is kept beside the steps and checks that expect it.
 */
export const MAINTENANCE_PATH = '/hilos/maintenance'

/**
 * The operation the manual window stands under, copied verbatim from
 * ProtectedModeRuntime::OPERATION_MANUAL_MAINTENANCE
 * (framework/backend/Runtime/State/Item/ProtectedModeRuntime.php). The e2e package
 * builds against no framework source, so the literal is kept beside the check that
 * reads it off the maintenance screen.
 */
export const MANUAL_MAINTENANCE_OPERATION = 'manual_maintenance'

/**
 * The title and message of the manual window's maintenance screen, copied verbatim
 * from that operation's entry in Hilos::PROTECTED_MODE_STUB
 * (framework/backend/Hilos.php), which none of the demos overrides. The e2e package
 * builds against no framework source, so the literals are kept beside the check that
 * reads them.
 */
export const MANUAL_STUB_TITLE = 'Closed for maintenance'
export const MANUAL_STUB_MESSAGE =
  'The application is closed for maintenance. Please check back later.'

/**
 * The notice the section raises once the manual window is on, copied verbatim from
 * framework/backend/Pages/Maintenance/AbstractHilosMaintenancePage.php. The e2e
 * package builds against no framework source, so the literal is kept beside the
 * check that reads it.
 */
export const MAINTENANCE_ON_NOTICE =
  'Maintenance is on — the system is closed to visitors'

/**
 * Names one address to the verifier circle through the maintenance section's own modal.
 *
 * @param page The operator's page, already on the maintenance section.
 * @param identifier The address to name.
 */
export async function addToMaintenanceCircle(
  page: Page,
  identifier: string,
): Promise<void> {
  // The bar's button, the one on screen: the empty state offers the same press
  // under another handle, and a table never draws the bar twice.
  await shownByTestId(
    page.getByTestId('hilos-maintenance-circle-table'),
    'hilos-table-main-action',
  ).click()

  const field = page.getByTestId('hilos-maintenance-circle-add-field')
  await field.waitFor({ state: 'visible' })
  await field.fill('')
  await field.pressSequentially(identifier, { delay: 10 })

  const submit = page.getByTestId('hilos-maintenance-circle-add-confirm')
  await submit.scrollIntoViewIfNeeded()
  await submit.waitFor({ state: 'visible' })
  await submit.focus()
  await submit.click()
  // The modal closes on the ack and stays open on a refusal, so waiting for the field
  // to go is waiting for the write to have landed rather than for a fixed moment.
  await field.waitFor({ state: 'detached' })
}

/**
 * Drives the confirmation of the maintenance section's removal modal that is already open.
 *
 * Its own step because a case taking one named person out and the clearing every circle
 * case starts with both open that modal. The confirmation contract belongs in one place.
 *
 * @param page The operator's page, with the section's removal modal open.
 */
export async function confirmMaintenanceCircleRemoval(
  page: Page,
): Promise<void> {
  const submit = page.getByTestId('hilos-maintenance-circle-remove-confirm')
  await submit.scrollIntoViewIfNeeded()
  await submit.waitFor({ state: 'visible' })
  await submit.focus()
  await submit.click()
  // The modal closes on the ack and stays open on a refusal, so waiting for the button
  // to go is waiting for the removal to have landed rather than for a fixed moment.
  await submit.waitFor({ state: 'detached' })
}

/**
 * Empties the verifier circle through the maintenance section's own removal modal.
 *
 * Every case that counts the circle starts here: the database list outlives a case,
 * so a neighbour that failed before cleanup may leave a member named. Without a clear,
 * circleAdmitted can count somebody this case never named. The first window is waited
 * for rather than assumed: an empty list and rows not yet delivered look alike. The
 * search is scoped to the circle table, and to the copy on screen, so the modal's
 * own confirm button, and the hidden card's, cannot be taken for a row's.
 *
 * @param page The operator's page, already on the maintenance section.
 */
export async function clearMaintenanceCircle(page: Page): Promise<void> {
  const table = page.getByTestId('hilos-maintenance-circle-table')
  await table.waitFor({ state: 'visible' })
  // The skeleton stands in the table and in the cards at once. The visible one
  // is the branch on screen; both leave together when the window arrives.
  await shownByTestId(table, 'hilos-table-loading').waitFor({ state: 'hidden' })

  const removals = shownByTestId(table, /^hilos-maintenance-circle-remove-/)
  for (let guard = 0; guard < CIRCLE_CLEAR_LIMIT; guard++) {
    if ((await removals.count()) === 0) {
      break
    }
    await removals.first().click()
    await confirmMaintenanceCircleRemoval(page)
  }

  const remaining = await removals.count()
  if (remaining !== 0) {
    throw new Error(
      `The verifier circle still has ${remaining} rows after ${CIRCLE_CLEAR_LIMIT} removals`,
    )
  }
}

/**
 * The circle row of one address, named by the handle its own row carries.
 *
 * Keyed by the address rather than bare, because the circle is a list: a bare handle
 * names every row at once the moment a second person is in it, which is every run after
 * the first.
 *
 * @param page The operator's page, on the maintenance section.
 * @param identifier The address the member was named by.
 */
export function maintenanceCircleRow(page: Page, identifier: string): Locator {
  // The visible copy. A count of zero — the row gone from both branches — is
  // the plain getByTestId, which sees the hidden copy too (table.ts).
  return shownByTestId(page, `hilos-maintenance-circle-row-${identifier}`)
}

/**
 * The presence mark of one circle row, named by the handle its own cell carries.
 *
 * @param page The operator's page, on the maintenance section.
 * @param identifier The address the member was named by.
 */
export function maintenanceCircleOnline(
  page: Page,
  identifier: string,
): Locator {
  return shownByTestId(page, `hilos-maintenance-circle-online-${identifier}`)
}

/**
 * Presses a control the way a person does: brought into view, shown, focused, clicked.
 *
 * @param control The control to press.
 */
async function press(control: Locator): Promise<void> {
  await control.scrollIntoViewIfNeeded()
  await control.waitFor({ state: 'visible' })
  await control.focus()
  await control.click()
}

/**
 * Closes the system to visitors from the section's open banner, confirmed in its dialog.
 *
 * @param page The operator's page, on the maintenance section of an open node.
 */
export async function turnOnMaintenance(page: Page): Promise<void> {
  await press(page.getByTestId('hilos-maintenance-enable'))
  await press(page.getByTestId('hilos-maintenance-enable-confirm'))
  // The dialog closes on the ack and stays open on a refusal or an unconfirmed
  // outcome, so its going is the window having opened rather than a fixed moment.
  await page
    .getByTestId('hilos-maintenance-enable-modal')
    .waitFor({ state: 'detached' })
}

/**
 * Issues a code from the manual card, reads it off its one showing, and closes the
 * result — which forgets the code on the page.
 *
 * @param page The operator's page, on the maintenance section of the manual window.
 * @returns The code, as the result dialog showed it.
 */
export async function issueMaintenanceCode(page: Page): Promise<string> {
  await press(page.getByTestId('hilos-maintenance-pass'))
  await press(page.getByTestId('hilos-maintenance-pass-confirm'))
  // The value is drawn only from the action's own reply.
  const value = page.getByTestId('hilos-maintenance-pass-value')
  await value.waitFor({ state: 'visible' })
  const code = (await value.innerText()).trim()
  if (code === '') {
    throw new Error('The code result showed no code')
  }
  await press(page.getByTestId('hilos-maintenance-pass-cancel'))
  await page
    .getByTestId('hilos-maintenance-pass-modal')
    .waitFor({ state: 'detached' })

  return code
}

/**
 * Opens the system to everyone from the manual card, confirmed in its dialog.
 *
 * Does not wait for the dialog to close: the lift reloads every open page, this one
 * too, and the document may go before the ack lands. A spec waits for the state it
 * expects on the reloaded page instead.
 *
 * @param page An admitted operator's page, on the maintenance section of the manual window.
 */
export async function turnOffMaintenance(page: Page): Promise<void> {
  await press(page.getByTestId('hilos-maintenance-disable'))
  await press(page.getByTestId('hilos-maintenance-disable-confirm'))
}

/**
 * Types a code into the maintenance screen and submits it.
 *
 * @param page A page showing the maintenance screen of the verification window.
 * @param code The code to present.
 */
export async function presentMaintenanceCode(
  page: Page,
  code: string,
): Promise<void> {
  const field = page.getByTestId('maintenance-pass')
  await field.waitFor({ state: 'visible' })
  await field.fill('')
  await field.pressSequentially(code, { delay: 10 })
  await press(page.getByTestId('maintenance-pass-submit'))
}

/**
 * The operator's page right after {@link turnOnMaintenance}: the notice raised once,
 * the manual card and the circle on screen, no way to turn it on again, and the page
 * still live on its socket — the operator stays inside the window they opened.
 *
 * @param expect The spec's expect.
 * @param page The page that turned the manual window on, on the maintenance section.
 */
export async function expectManualMaintenanceOn(
  expect: Expect,
  page: Page,
): Promise<void> {
  const toasts = page.getByTestId('hilos-toasts')
  const notice = toasts.getByText(MAINTENANCE_ON_NOTICE, { exact: true })
  await expect(notice).toHaveCount(1)
  await expect(notice).toBeVisible()
  await expect(toasts.getByTestId('hilos-toast-repeats')).toHaveCount(0)
  await expect(page.getByTestId('maintenance')).toBeHidden()
  await expect(page.getByTestId('hilos-page-state')).toHaveAttribute(
    'data-state',
    'ready',
  )
  await expect(page.getByTestId('hilos-maintenance-state-manual')).toBeVisible()
  await expect(page.getByTestId('hilos-maintenance-circle-panel')).toBeVisible()
  await expect(page.getByTestId('hilos-maintenance-pass-count')).toHaveText('0')
  await expect(page.getByTestId('hilos-maintenance-enable')).toHaveCount(0)
  await expect(page.getByTestId('conn-state')).toHaveText('connected')
}

/**
 * A page held at the door by the manual window — a visitor, or an administrator on
 * the section's address who has not been let in. The screen draws the title and the
 * message whatever else it offers, so the code's field is not part of this check.
 *
 * @param expect The spec's expect.
 * @param page A page outside the manual window.
 */
export async function expectHeldByManualMaintenance(
  expect: Expect,
  page: Page,
): Promise<void> {
  await expect(page.getByTestId('maintenance')).toHaveAttribute(
    'data-operation',
    MANUAL_MAINTENANCE_OPERATION,
  )
  await expect(page.getByTestId('maintenance-title')).toHaveText(
    MANUAL_STUB_TITLE,
  )
  await expect(page.getByTestId('maintenance-message')).toHaveText(
    MANUAL_STUB_MESSAGE,
  )
}

/**
 * The operator's page after {@link issueMaintenanceCode}: the count has moved, the
 * code is gone from the page with its result, and no notice ever carried it.
 *
 * @param expect The spec's expect.
 * @param page The page that issued the code, on the maintenance section.
 * @param code The code the step returned.
 * @param passes How many codes the window has issued so far.
 */
export async function expectMaintenanceCodeIssued(
  expect: Expect,
  page: Page,
  code: string,
  passes: number,
): Promise<void> {
  await expect(page.getByTestId('hilos-maintenance-pass-count')).toHaveText(
    String(passes),
  )
  await expect(page.getByTestId('hilos-maintenance-pass-value')).toHaveCount(0)
  await expect(page.getByTestId('hilos-toasts')).not.toContainText(code)
}

/**
 * A page let into the manual window by a code: the maintenance screen gone, the
 * section settled on its manual card.
 *
 * @param expect The spec's expect.
 * @param page The page the code was presented on, on the section's address.
 * @param passes How many codes the window has issued so far; omitted when the
 *   scenario does not count them.
 */
export async function expectAdmittedToManualMaintenance(
  expect: Expect,
  page: Page,
  passes?: number,
): Promise<void> {
  await expect(page.getByTestId('maintenance')).toBeHidden()
  await expect(page.getByTestId('hilos-page-state')).toHaveAttribute(
    'data-state',
    'ready',
  )
  await expect(page.getByTestId('hilos-maintenance-state-manual')).toBeVisible()
  if (passes !== undefined) {
    await expect(page.getByTestId('hilos-maintenance-pass-count')).toHaveText(
      String(passes),
    )
  }
}

/**
 * A section page on an open node — after {@link turnOffMaintenance} that is the
 * page the lift reloaded: the open banner, and no way to turn the window off.
 *
 * @param expect The spec's expect.
 * @param page An administrator's page on the maintenance section.
 */
export async function expectMaintenanceOpen(
  expect: Expect,
  page: Page,
): Promise<void> {
  await expect(page.getByTestId('hilos-maintenance-state-open')).toBeVisible()
  await expect(page.getByTestId('hilos-maintenance-disable')).toHaveCount(0)
}
