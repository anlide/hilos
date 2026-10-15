import type { Locator, Page } from '@playwright/test'

import { shownByTestId } from './table.js'

// Maintenance section steps shared by all three SDKs, whose data-id controls match.
// These expect the operator's page on /hilos/maintenance, except the code's entry,
// which is the maintenance screen's own. Only an address somebody has confirmed can
// be named to the verifier circle.

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
  // under another handle, and a declared table never draws the bar twice.
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
