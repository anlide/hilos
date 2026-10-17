import type { Expect, Locator, Page } from '@playwright/test'
import { shownByTestId, sidewaysOverflow, tableRowKeyByText } from './table.js'

/**
 * Change Log section checks all three kits must meet alike. Tests, their order
 * and navigation stay in each demo's spec.
 */
export const CHANGE_LOG_PATH = '/hilos/change-log'
export const CHANGE_LOG_TABLES_PATH = '/hilos/change-log/tables'
export const CHANGE_LOG_LEAD =
  'Who changed what in the tracked tables, and through which action.'
export const CHANGE_LOG_TABLES_LEAD =
  'Database tables, their journal label and column rules.'

/**
 * The person card's block action
 * (framework/frontend/core/src/admin/users/hilosUsers.ts:368).
 */
export const CHANGE_LOG_BLOCK_ACTION = 'hilos_user_block_set'

/** Choose a journaled table by its complete name. */
export async function chooseChangeLogFeedTable(
  page: Page,
  table: string,
): Promise<void> {
  const filter = page.getByTestId('hilos-table-filter-table')
  await filter.getByTestId('hilos-dropdown-toggle').click()
  // Options are live journaled names in alphabetical order. Their numbers
  // belong to each demo: chat's first option is bot.
  await filter
    .getByTestId(/^hilos-dropdown-option-\d+$/)
    .filter({ hasText: new RegExp(`^\\s*${escapeRegExp(table)}\\s*$`) })
    .click()
}

/** The dashboard card carries the section's catalog lead. */
export async function expectChangeLogDashboardCard(
  expect: Expect,
  page: Page,
): Promise<void> {
  const card = page.getByTestId('dashboard-card-hilos_change_log')
  await expect(card).toBeVisible()
  await expect(card).toContainText(CHANGE_LOG_LEAD)
}

/** The overview names the section and shows its totals and recent actions. */
export async function expectChangeLogOverview(
  expect: Expect,
  page: Page,
): Promise<void> {
  await expect(page.getByTestId('hilos-admin-title')).toHaveText('Change Log')
  await expect(page.getByTestId('hilos-admin-page')).toContainText(
    CHANGE_LOG_LEAD,
  )
  await expect(page.getByTestId('hilos-change-log-tile-journal')).toHaveText(
    /[1-9]/,
  )
  await expect(page.getByTestId('hilos-change-log-tile-tracked')).toHaveText(
    /\d+ of \d+/,
  )
  await expect(page.getByTestId('hilos-table-title')).toHaveText(
    'Recent actions',
  )
  await expect(
    page.locator('table [data-id^="hilos-table-row-"]').first(),
  ).toBeVisible()
}

/** Read the unique feed row and its receipt number, checking the action. */
export async function expectChangeLogFeedRow(
  expect: Expect,
  page: Page,
  text: string,
  action: string,
): Promise<{ rowKey: string; receiptId: string }> {
  const rowKey = await tableRowKeyByText(expect, page, text)
  await expect(shownByTestId(page, `hilos-table-row-${rowKey}`)).toContainText(
    action,
  )
  expect(rowKey).toMatch(/^receipt:\d+$/)

  return { rowKey, receiptId: rowKey.slice('receipt:'.length) }
}

/** The open modal names the requested receipt. */
export async function expectChangeLogReceiptOpen(
  expect: Expect,
  page: Page,
  receiptId: string,
): Promise<void> {
  const modal: Locator = page.getByTestId('modal')
  await expect(modal).toBeVisible()
  await expect(modal).toHaveAccessibleName(`Receipt #${receiptId}`)
}

/** Check the receipt's writer and retain its complete attribution header. */
export async function expectChangeLogReceiptWriter(
  expect: Expect,
  page: Page,
  actorLabel: string,
  action: string,
  agentId: string,
): Promise<string> {
  const header = page
    .getByTestId('hilos-change-log-detail')
    .locator(':scope > dl')
    .first()
  await expect(header).toBeVisible()
  await expect(
    header.locator('dt').filter({ hasText: /^Who$/ }).locator('+ dd'),
  ).toHaveText(actorLabel)
  await expect(
    header
      .locator('dt')
      .filter({ hasText: /^Action$/ })
      .locator('+ dd'),
  ).toHaveText(action)
  await expect(
    header
      .locator('dt')
      .filter({ hasText: /^Agent$/ })
      .locator('+ dd'),
  ).toHaveText(agentId)

  return (await header.innerText()).replace(/\s+/g, ' ').trim()
}

/** The receipt shows both values of the changed field. */
export async function expectChangeLogValues(
  expect: Expect,
  page: Page,
  field: string,
  before: string,
  after: string,
): Promise<void> {
  const detail = page.getByTestId('hilos-change-log-detail')
  await expect(
    detail.getByTestId(
      new RegExp(`^hilos-change-log-value-\\d+-${escapeRegExp(field)}-old$`),
    ),
  ).toHaveText(before)
  await expect(
    detail.getByTestId(
      new RegExp(`^hilos-change-log-value-\\d+-${escapeRegExp(field)}-new$`),
    ),
  ).toHaveText(after)
}

/** The receipt fits inside its scrolling body on a narrow screen. */
export async function expectChangeLogReceiptFits(
  expect: Expect,
  page: Page,
): Promise<void> {
  // Bootstrap row gutters reach into the body's padding. Measure the body
  // that owns scrolling, rather than the unpadded content wrapper inside it.
  expect(
    await page
      .getByTestId('modal')
      .locator('.modal-body')
      .evaluate((node) => node.scrollWidth - node.clientWidth),
  ).toBe(0)
}

/** Registration journals the person and identity as facts without values. */
export async function expectRegistrationReceiptFacts(
  expect: Expect,
  page: Page,
  userId: number,
): Promise<void> {
  const detail = page.getByTestId('hilos-change-log-detail')
  await expect(detail).toContainText(`hilos_user #${userId}`)
  await expect(detail).toContainText('hilos_identity #')
  const creations = detail.getByTestId(/^hilos-change-log-entry-\d+$/)
  await expect(creations).toHaveCount(2)
  for (const creation of await creations.all()) {
    await expect(creation).toContainText(
      'Created as a fact; values belong to the record itself.',
    )
    await expect(creation.getByTestId(/^hilos-change-log-value-/)).toHaveCount(
      0,
    )
    await expect(creation).not.toContainText('@example.test')
  }
}

/** Tracked tables has one heading and an accessible table of tables. */
export async function expectTrackedTablesPage(
  expect: Expect,
  page: Page,
): Promise<void> {
  await expect(page.getByTestId('hilos-admin-title')).toHaveText(
    'Tracked tables',
  )
  await expect(page.getByTestId('hilos-admin-page')).toContainText(
    CHANGE_LOG_TABLES_LEAD,
  )
  await expect(page.getByRole('heading', { level: 1 })).toHaveCount(1)
  await expect(
    page.getByRole('table', { name: 'Tables', exact: true }),
  ).toBeVisible()
}

/** The schema reference explains noisy columns and journal column modes. */
export async function expectChangeLogReference(
  expect: Expect,
  page: Page,
): Promise<void> {
  const noiseTable = page.getByRole('table', {
    name: 'Noisy columns',
    exact: true,
  })
  await expect(noiseTable).toBeVisible()
  await expect(noiseTable).toContainText('hilos_user.last_activity')
  await expect(noiseTable).toContainText('Changes on every user request')
  const modes = page.getByTestId('hilos-change-log-column-modes')
  await expect(modes.getByRole('heading', { level: 2 })).toHaveText(
    'How a column enters the journal',
  )
  await expect(modes).toContainText('Personal — only a fact')
  await expect(modes).toContainText('Binary — only a fact')
  await expect(modes).toContainText('Record key')
}

/** The record history is ready at its own address and names its filter. */
export async function expectTrackedTableRecord(
  expect: Expect,
  page: Page,
  table: string,
  recordKey: string,
): Promise<void> {
  await expect(page).toHaveURL(
    new RegExp(
      `${CHANGE_LOG_TABLES_PATH}/${escapeRegExp(table)}/${escapeRegExp(recordKey)}$`,
    ),
  )
  await expect(page.getByTestId('hilos-page-state')).toHaveAttribute(
    'data-state',
    'ready',
  )
  await expect(page.getByTestId('hilos-admin-title')).toHaveText(
    'Tracked table',
  )
  await expect(page.getByTestId('hilos-change-log-table-header')).toContainText(
    table,
  )
  await expect(
    page.getByTestId('hilos-change-log-record-filter'),
  ).toContainText(`Only record #${recordKey}`)
}

/** Clearing the record filter opens the ready history of the whole table. */
export async function expectTrackedTableWhole(
  expect: Expect,
  page: Page,
  table: string,
): Promise<void> {
  await expect(page).toHaveURL(
    new RegExp(`${CHANGE_LOG_TABLES_PATH}/${escapeRegExp(table)}$`),
  )
  await expect(page.getByTestId('hilos-page-state')).toHaveAttribute(
    'data-state',
    'ready',
  )
  await expect(page.getByTestId('hilos-change-log-record-filter')).toHaveCount(
    0,
  )
  await expect(page.getByTestId('hilos-viewport-table')).toContainText(
    'All records of this table',
  )
}

/** Columns become cards on a phone and leave no horizontal page overflow. */
export async function expectNarrowColumns(
  expect: Expect,
  page: Page,
  column: string,
  storage: string,
): Promise<void> {
  await expect(
    page.getByRole('table', { name: 'Columns', exact: true }),
  ).toBeHidden()
  const columns = page.getByRole('list', { name: 'Columns', exact: true })
  await expect(columns).toBeVisible()
  await expect(columns).toContainText(column)
  await expect(columns).toContainText(storage)
  expect(await sidewaysOverflow(page)).toEqual([0, 0])
}

/** Treat supplied table names, record keys and field names as literal text. */
function escapeRegExp(value: string): string {
  return value.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')
}
