import type { Locator, Page } from '@playwright/test'

// Maintenance section steps shared by all three SDKs, whose data-id controls match.
// These expect the operator's page on /hilos/maintenance. Only an address somebody
// has confirmed can be named to the verifier circle.

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
  await page.getByTestId('hilos-maintenance-circle-add').click()

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
 * search is scoped to the circle table so the modal's own confirm button, which
 * shares the removal prefix, cannot be taken for a row's.
 *
 * @param page The operator's page, already on the maintenance section.
 */
export async function clearMaintenanceCircle(page: Page): Promise<void> {
  const table = page.getByTestId('hilos-maintenance-circle-table')
  await table.waitFor({ state: 'visible' })
  await table.getByTestId('hilos-table-loading').waitFor({ state: 'detached' })

  const removals = table.getByTestId(/^hilos-maintenance-circle-remove-/)
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
  return page.getByTestId(`hilos-maintenance-circle-row-${identifier}`)
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
  return page.getByTestId(`hilos-maintenance-circle-online-${identifier}`)
}
