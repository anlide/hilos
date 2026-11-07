import { typeInto } from './input.js'
import type { Expect, Locator, Page } from '@playwright/test'

import { deliveryObject, type DeliveryFrameLog } from './deliveryFrames.js'
import { ROW_PREFIX, ROWS, shownByTestId, tableRowKeys } from './table.js'

// Maintenance section steps shared by all three SDKs, whose data-id controls match,
// and the manual-window checks all three kits must meet alike; the scenarios stay
// in each demo's spec. Circle steps require Circle already open, manual steps
// require Overview, and the Words checks require Words; code entry uses the
// maintenance screen itself. No step navigates, so a spec that clears the circle
// and then turns the window on goes from one address to the other itself. Only an
// address somebody has confirmed can be named to the circle.

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
 * The Circle tab's address, copied verbatim from HilosPages.MAINTENANCE_CIRCLE in
 * core/src/routing/hilosPages.ts.
 */
export const MAINTENANCE_CIRCLE_PATH = '/hilos/maintenance/circle'

/**
 * The Words tab's address, copied verbatim from HilosPages.MAINTENANCE_WORDS in
 * core/src/routing/hilosPages.ts.
 */
export const MAINTENANCE_WORDS_PATH = '/hilos/maintenance/words'

/**
 * Which Maintenance page is on screen, read the way an assistive reader tells it: one
 * h1 naming the section, and among the section's own links only the rendered page's
 * marked current. Nothing here holds how the head is drawn or in what order its links
 * stand, so the check outlives a redraw; following the links is the spec's.
 *
 * @param expect The spec's expect.
 * @param page A page on Maintenance Overview, Circle, or Words.
 * @param currentPage The key of the page on screen.
 */
export async function expectMaintenancePageCurrent(
  expect: Expect,
  page: Page,
  currentPage:
    | 'hilos_maintenance'
    | 'hilos_maintenance_circle'
    | 'hilos_maintenance_words',
): Promise<void> {
  const heading = page.getByRole('heading', { level: 1 })
  await expect(heading).toHaveCount(1)
  await expect(heading).toHaveText('Maintenance')

  const links = page
    .getByRole('navigation', { name: 'Maintenance pages' })
    .getByRole('link')
  await expect(
    links.and(page.getByTestId(`hilos-page-tab-${currentPage}`)),
  ).toHaveAttribute('aria-current', 'page')
  await expect(links.and(page.locator('[aria-current="page"]'))).toHaveCount(1)
}

/**
 * The Words page, its table and the slot its rows ride under, copied verbatim from
 * HilosPages.MAINTENANCE_WORDS and core/src/admin/maintenance/hilosMaintenanceWords.ts.
 */
const WORDS_PAGE = 'hilos_maintenance_words'
const WORDS_TABLE = 'hilosMaintenanceWords'
const WORDS_SLOT = 'stub'
const WORDS_TABLE_ID = 'hilos-maintenance-words-table'

/**
 * What a row without words of its own says in their place, copied verbatim from
 * HILOS_MAINTENANCE_WORDS_COPY.noOwnEntry in hilosMaintenanceWords.ts.
 */
const WORDS_NO_OWN_ENTRY = 'No own entry — uses default'

/** One operation's words as the page's answer carried them. */
export interface MaintenanceWordsRow {
  readonly operation: string
  readonly title: string | null
  readonly message: string | null
  readonly bannerMessage: string | null
  readonly usesDefault: boolean
}

/**
 * The Words table drawn from the page's own answer: the first reply to the Words
 * subscription after the bookmark carries the window, and every row of it is on
 * screen with its words — or, for an operation with no entry of its own, with the
 * line saying it uses the default instead. The table offers no search, filters,
 * actions or selection, and its rows carry no controls. Requires a delivery log
 * armed before the page's socket opened.
 *
 * @param expect The spec's expect.
 * @param page A page on Maintenance Words.
 * @param log The page's real incoming deliveries.
 * @param after Packet bookmark taken before the navigation that opened Words.
 * @returns The rows of the window, as the reply carried them.
 */
export async function expectMaintenanceWords(
  expect: Expect,
  page: Page,
  log: DeliveryFrameLog,
  after: number,
): Promise<MaintenanceWordsRow[]> {
  const replies = () =>
    log.packets
      .slice(after)
      .flatMap((packet) => packet.frames)
      .filter(({ wire }) => {
        const frame = deliveryObject(wire)
        return (
          frame.type === 'page_response' &&
          deliveryObject(frame.data).page === WORDS_PAGE
        )
      })
  await expect.poll(() => replies().length).toBeGreaterThan(0)
  const reply = replies()[0]!
  expect(reply.failure).toBeUndefined()
  const payload = deliveryObject(
    deliveryObject(deliveryObject(reply.wire).data).payload,
  )
  const window = deliveryObject(deliveryObject(payload.windows)[WORDS_TABLE])
  expect(window.rows).toEqual(expect.any(Array))
  const rows = (window.rows as unknown[]).map((wire): MaintenanceWordsRow => {
    const row = deliveryObject(wire)
    const words = deliveryObject(deliveryObject(row.slots)[WORDS_SLOT])
    expect(words.operation).toEqual(expect.any(String))
    expect(row.rowKey).toBe(words.operation)
    for (const field of [words.title, words.message, words.bannerMessage]) {
      expect(field === null || typeof field === 'string').toBe(true)
    }
    expect(words.usesDefault).toEqual(expect.any(Boolean))
    return {
      operation: words.operation as string,
      title: words.title as string | null,
      message: words.message as string | null,
      bannerMessage: words.bannerMessage as string | null,
      usesDefault: words.usesDefault as boolean,
    }
  })

  const table = page.getByTestId(WORDS_TABLE_ID)
  await expect(table).toBeVisible()
  await expect(table.getByTestId('hilos-table-loading')).toHaveCount(0)
  await expect
    .poll(async () => (await tableRowKeys(page, WORDS_TABLE_ID)).sort())
    .toEqual(rows.map((row) => row.operation).sort())
  for (const row of rows) {
    const drawn = table
      .locator(ROWS)
      .and(table.getByTestId(`${ROW_PREFIX}${row.operation}`))
    await expect(drawn).toBeVisible()
    await expect(
      drawn.getByTestId(`hilos-maintenance-words-row-${row.operation}`),
    ).toHaveText(row.operation)
    const instead = drawn.getByTestId('hilos-table-row-message')
    if (row.usesDefault) {
      await expect(instead).toHaveText(WORDS_NO_OWN_ENTRY)
      continue
    }
    await expect(instead).toHaveCount(0)
    for (const words of [row.title, row.message, row.bannerMessage]) {
      if (words !== null) await expect(drawn).toContainText(words)
    }
  }
  await expect(
    table.getByTestId(
      /^hilos-table-(search|filters-open|main-action|actions|selection)$/,
    ),
  ).toHaveCount(0)
  await expect(
    table
      .getByTestId(/^hilos-table-row-/)
      .locator('button, input, select, textarea, a, [contenteditable]'),
  ).toHaveCount(0)
  return rows
}

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
 * @param page The operator's page, already on this SDK's circle table page.
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
  await typeInto(field, identifier)

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
 * @param page The operator's page, already on this SDK's circle table page.
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
 * @param page The operator's page, on this SDK's circle table page.
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
 * @param page The operator's page, on this SDK's circle table page.
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
 * @param page The operator's page, on Maintenance Overview of an open node.
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
 * @param page The operator's page, on Maintenance Overview of the manual window.
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
 * @param page An admitted operator's page, on Maintenance Overview of the manual window.
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
  await typeInto(field, code)
  await press(page.getByTestId('maintenance-pass-submit'))
}

/**
 * The operator's Overview right after {@link turnOnMaintenance}: the notice raised
 * once, the manual card on screen, no way to turn it on again, and the page still
 * live on its socket — the operator stays inside the window they opened. Circle
 * presence is checked separately on this SDK's table page.
 *
 * @param expect The spec's expect.
 * @param page The page that turned the manual window on, on Maintenance Overview.
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
 * @param page The page that issued the code, on Maintenance Overview.
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
 * @param page The page the code was presented on, on the Overview address.
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
 * @param page An administrator's page on Maintenance Overview.
 */
export async function expectMaintenanceOpen(
  expect: Expect,
  page: Page,
): Promise<void> {
  await expect(page.getByTestId('hilos-maintenance-state-open')).toBeVisible()
  await expect(page.getByTestId('hilos-maintenance-disable')).toHaveCount(0)
}

/**
 * The threshold names, verbatim from HILOS_MAINTENANCE_WATCHDOG_COPY in
 * core/src/admin/maintenance/hilosMaintenance.ts, each read beside its value. The e2e
 * package builds against no framework source; these literals stay beside the check
 * that reads them.
 */
const WATCHDOG_THRESHOLD_LABELS = [
  'Wait for nodes to quiesce',
  'Consider silent after',
  'Repeat alert',
  'Send mail to',
  'Restart under manual maintenance',
]

/**
 * An administrator's Overview thresholds, compared with the real published
 * frame: every value beside the threshold it belongs to, and nothing on the card
 * to change. An administrator is shown the real addresses and the real restart
 * choice, so a field the server kept hidden fails here instead of passing as a
 * value. Waits out unpublished startup values; never supplies mock
 * configuration. Requires a delivery log armed before the page's socket opened.
 * The returned values can also prove that navigation or a maintenance window
 * kept the config.
 *
 * @param expect The spec's expect.
 * @param page An administrator's page on Maintenance Overview.
 * @param log The page's real incoming deliveries, including live updates.
 * @returns The five displayed values in label order.
 */
export async function expectMaintenanceWatchdogThresholds(
  expect: Expect,
  page: Page,
  log: DeliveryFrameLog,
): Promise<string[]> {
  const latest = () =>
    log.packets
      .flatMap((packet) => packet.frames)
      .filter(
        ({ wire }) =>
          deliveryObject(wire).type === 'subscription_page_hilos_maintenance',
      )
      .at(-1)
  await expect
    .poll(() => deliveryObject(latest()?.wire).data)
    .toMatchObject({
      watchdogQuiesceTimeoutSeconds: expect.any(Number),
      watchdogSilenceTimeoutSeconds: expect.any(Number),
      watchdogAlertIntervalSeconds: expect.any(Number),
      watchdogAlertEmails: expect.any(Array),
      watchdogManualRestartIsNormal: expect.any(Boolean),
    })
  const frame = latest()!
  expect(frame.failure).toBeUndefined()
  expect(frame.raw).toEqual(expect.any(String))
  const state = deliveryObject(deliveryObject(frame.wire).data)
  const seconds = [
    state.watchdogQuiesceTimeoutSeconds,
    state.watchdogSilenceTimeoutSeconds,
    state.watchdogAlertIntervalSeconds,
  ]
  for (const value of seconds) expect(Number.isInteger(value)).toBe(true)
  const recipients = state.watchdogAlertEmails as unknown[]
  for (const recipient of recipients) {
    expect(recipient).toEqual(expect.any(String))
  }
  // Value words are also copied from HILOS_MAINTENANCE_WATCHDOG_COPY above.
  const values = [
    ...seconds.map((value) => `${value} ${value === 1 ? 'second' : 'seconds'}`),
    recipients.length === 0
      ? 'No recipients configured'
      : recipients.join(', '),
    state.watchdogManualRestartIsNormal
      ? 'No mail — a log entry'
      : 'Send an alert',
  ]
  const thresholds = page.getByTestId('hilos-maintenance-watchdog-thresholds')
  await expect(thresholds).toBeVisible()
  await expect(
    thresholds.getByRole('heading', { level: 2 }),
  ).toHaveAccessibleName('Watchdog thresholds')
  // Each term with the definition that follows it, compared as pairs: which value
  // stands beside which threshold is the claim, the order of the rows is not.
  const byLabel = (pairs: Array<[string | null, string | null]>) =>
    pairs.sort(([a], [b]) => String(a).localeCompare(String(b)))
  const words = (texts: string[]) =>
    texts.map((text) => text.replace(/\s+/g, ' ').trim())
  await expect
    .poll(async () => {
      const terms = words(await thresholds.getByRole('term').allInnerTexts())
      const definitions = words(
        await thresholds.getByRole('definition').allInnerTexts(),
      )
      return byLabel(
        Array.from(
          { length: Math.max(terms.length, definitions.length) },
          (_, index): [string | null, string | null] => [
            terms[index] ?? null,
            definitions[index] ?? null,
          ],
        ),
      )
    })
    .toEqual(
      byLabel(
        WATCHDOG_THRESHOLD_LABELS.map((label, index) => [
          label,
          values[index] ?? null,
        ]),
      ),
    )
  await expect(
    thresholds.locator('input, select, textarea, button, a, [contenteditable]'),
  ).toHaveCount(0)
  return values
}
