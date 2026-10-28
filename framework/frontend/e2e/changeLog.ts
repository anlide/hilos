import type { Expect, Locator, Page } from '@playwright/test'
import {
  HILOS_CHANGE_LOG_RECEIPT_READ,
  changeLogReceiptReadReplySchema,
} from '../core/src/admin/changeLog/index.js'
import { watchClientDocument } from './lazyBundles.js'
import type { SocketFrame } from './socket.js'
import { shownByTestId, tableRowKeyByText } from './table.js'

/**
 * Change Log section checks all three kits must meet alike. Tests, their order
 * and navigation stay in each demo's spec.
 */
export const CHANGE_LOG_PATH = '/hilos/change-log'
export const CHANGE_LOG_FEED_PATH = '/hilos/change-log/feed'
export const CHANGE_LOG_NOISY_PATH = '/hilos/change-log/noisy'
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

/**
 * The adopted Vue section has one owner head and four native page links.
 *
 * @param expect The spec's expect.
 * @param page The page showing the Change Log section.
 * @param currentPage The key of the current owner page.
 */
export async function expectChangeLogSectionHead(
  expect: Expect,
  page: Page,
  currentPage: string,
): Promise<void> {
  await expect(page.getByTestId('hilos-page-head')).toBeVisible()
  await expect(page.getByTestId('hilos-page-head-skeleton')).toHaveCount(0)
  const heading = page.getByRole('heading', { level: 1 })
  await expect(heading).toHaveCount(1)
  await expect(heading).toHaveAttribute('data-id', 'hilos-page-head-title')
  await expect(heading).toHaveText('Change Log')
  const block = page.getByTestId('hilos-page-head-block')
  await expect(block).toContainText(CHANGE_LOG_LEAD)
  await expect(block.locator('i.bi-clock-history')).toHaveCount(1)
  await expect(
    block.locator('[aria-hidden="true"] > i.bi-clock-history'),
  ).toHaveCount(1)

  const crumbs = page.getByTestId('hilos-breadcrumb')
  await expect(crumbs).toHaveAccessibleName('Breadcrumb')
  await expect(crumbs.getByRole('link')).toHaveText(['Hilos'])
  await expect(crumbs.getByTestId('hilos-breadcrumb-hilos')).toHaveAttribute(
    'href',
    '/hilos',
  )
  const currentCrumb = crumbs.locator('[aria-current="page"]')
  await expect(currentCrumb).toHaveCount(1)
  await expect(currentCrumb).toHaveText('Change Log')
  await expect(currentCrumb.locator('a')).toHaveCount(0)

  const tabs = page.getByTestId('hilos-page-tabs')
  await expect(tabs).toHaveRole('navigation')
  await expect(tabs).toHaveAccessibleName('Change Log pages')
  await expect(tabs.getByRole('link')).toHaveText([
    'Overview',
    'Feed',
    'Tracked tables',
    'Noisy columns',
  ])
  await expect(tabs.getByRole('link')).toHaveCount(4)
  await expect(page.getByRole('tablist')).toHaveCount(0)
  for (const [key, path] of [
    ['hilos_change_log', CHANGE_LOG_PATH],
    ['hilos_change_log_feed', CHANGE_LOG_FEED_PATH],
    ['hilos_change_log_tables', CHANGE_LOG_TABLES_PATH],
    ['hilos_change_log_noisy', CHANGE_LOG_NOISY_PATH],
  ]) {
    const tab = tabs.getByTestId(`hilos-page-tab-${key}`)
    await expect(tab).toHaveAttribute('href', path!)
    if (key === currentPage) {
      await expect(tab).toHaveAttribute('aria-current', 'page')
      await expect(tab).toHaveClass(/\bactive\b/)
    } else {
      await expect(tab).not.toHaveAttribute('aria-current')
      await expect(tab).not.toHaveClass(/\bactive\b/)
    }
  }
  await expect(tabs.locator('[aria-current]')).toHaveCount(1)
  await expect(tabs.locator('.active')).toHaveCount(1)
  await expect(page.getByTestId('hilos-admin-children')).toHaveCount(0)
  await expect(page.getByTestId(/^hilos-admin-child-/)).toHaveCount(0)
}

/**
 * Select the shared head on adopted Vue pages, keeping the exact legacy heading
 * on React and Angular until their owner redraws land.
 *
 * @param expect The spec's expect.
 * @param page The owner page on screen.
 * @param currentPage The key of the current owner page.
 * @param legacyTitle The catalog heading of the unadopted page.
 */
async function expectChangeLogOwnerHead(
  expect: Expect,
  page: Page,
  currentPage: string,
  legacyTitle: string,
): Promise<void> {
  const heading = page.getByRole('heading', { level: 1 })
  await expect(heading).toHaveCount(1)
  await expect(heading).toHaveText(
    new RegExp(`^(${legacyTitle}|Change Log)$`),
    { useInnerText: true },
  )
  if ((await heading.getAttribute('data-id')) === 'hilos-page-head-title') {
    await expectChangeLogSectionHead(expect, page, currentPage)
  } else {
    await expect(heading).toHaveAttribute('data-id', 'hilos-admin-title')
    await expect(heading).toHaveText(legacyTitle, { useInnerText: true })
  }
}

/** The overview names the section and shows its journal totals. */
export async function expectChangeLogOverview(
  expect: Expect,
  page: Page,
): Promise<void> {
  await expectChangeLogOwnerHead(expect, page, 'hilos_change_log', 'Change Log')
  await expect(page.getByTestId('hilos-admin-page')).toContainText(
    CHANGE_LOG_LEAD,
  )
  await expect(page.getByTestId('hilos-change-log-tile-journal')).toHaveText(
    /[1-9]/,
  )
  await expect(page.getByTestId('hilos-change-log-tile-tracked')).toHaveText(
    /\d+ of \d+/,
  )
  await expect(page.getByTestId('hilos-viewport-table')).toHaveCount(0)
  await expect(page.getByTestId('hilos-change-log-feed-empty')).toHaveCount(0)
  await expect(page.getByTestId('modal')).toHaveCount(0)
}

/** The standalone feed owns its first window and uses the page heading. */
export async function expectChangeLogFeed(
  expect: Expect,
  page: Page,
): Promise<void> {
  await expectChangeLogOwnerHead(expect, page, 'hilos_change_log_feed', 'Feed')
  await expect(page.getByTestId('hilos-table-title')).toHaveCount(0)
  await expect(
    page.getByRole('table', {
      name: (await page.getByTestId('hilos-page-head-title').count())
        ? 'Change Log'
        : 'Feed',
      exact: true,
    }),
  ).toBeVisible()
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

/** Read the one completed real receipt reply; a second read fails this proof. */
export function expectChangeLogReceiptMoment(
  expect: Expect,
  frames: readonly SocketFrame[],
  receiptId: string,
): string {
  const moments: string[] = []
  for (const frame of frames) {
    const wire = frame.wire
    if (
      wire === null ||
      typeof wire !== 'object' ||
      !('type' in wire) ||
      wire.type !== 'action_success' ||
      !('data' in wire) ||
      wire.data === null ||
      typeof wire.data !== 'object' ||
      !('action' in wire.data) ||
      wire.data.action !== HILOS_CHANGE_LOG_RECEIPT_READ
    ) {
      continue
    }
    expect(frame.failure).toBeUndefined()
    const reply = changeLogReceiptReadReplySchema.parse(
      'reply' in wire.data ? wire.data.reply : undefined,
    )
    expect(String(reply.receipt.receiptId)).toBe(receiptId)
    moments.push(reply.receipt.createdAt)
  }
  expect(moments).toHaveLength(1)
  const moment = moments[0]!
  expect(moment).toMatch(/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?Z$/)
  expect(Number.isFinite(Date.parse(moment))).toBe(true)
  return moment
}

/** Check both rendered times with an independent, explicitly zoned Intl oracle. */
export async function expectChangeLogTimes(
  expect: Expect,
  page: Page,
  receiptId: string,
  createdAt: string,
  timeZone: string,
): Promise<string> {
  const expected = await page.evaluate(
    ({ createdAt, timeZone }) =>
      new Intl.DateTimeFormat(undefined, {
        dateStyle: 'medium',
        timeStyle: 'medium',
        timeZone,
      }).format(new Date(createdAt)),
    { createdAt, timeZone },
  )
  await expectChangeLogReceiptOpen(expect, page, receiptId)
  await expect(page.getByTestId('hilos-change-log-receipt-time')).toHaveText(
    expected,
  )
  await expect(
    shownByTestId(page, `hilos-table-row-receipt:${receiptId}`),
  ).toContainText(expected)
  return expected
}

/** Bookmark the open receipt and visible feed row before a session restate. */
export async function watchChangeLogDisplay(
  expect: Expect,
  page: Page,
  receiptId: string,
) {
  const documentWatch = await watchClientDocument(page)
  const modal = await page.getByTestId('modal').elementHandle()
  const row = await shownByTestId(
    page,
    `hilos-table-row-receipt:${receiptId}`,
  ).elementHandle()
  if (modal === null || row === null) {
    throw new Error('The receipt and feed must be open before bookmarking.')
  }
  return {
    async unchanged(): Promise<void> {
      await documentWatch.unchanged()
      expect(
        await modal.evaluate(
          (original) =>
            original === document.querySelector('[data-id="modal"]'),
        ),
      ).toBe(true)
      expect(await row.evaluate((original) => original.isConnected)).toBe(true)
    },
    async dispose(): Promise<void> {
      await modal.dispose()
      await row.dispose()
      await documentWatch.dispose()
    },
  }
}

/** Reopen the receipt on every attempt until a fresh snapshot names its writer. */
export async function expectFreshChangeLogReceiptWriter(
  expect: Expect,
  page: Page,
  rowKey: string,
  receiptId: string,
  agentId: string,
): Promise<void> {
  const modal = page.getByTestId('modal')
  const detail = page.getByTestId('hilos-change-log-detail')
  const header = detail.locator(':scope > dl').first()
  const error = page.getByTestId('hilos-change-log-detail-error')
  await expect
    .poll(async () => {
      if (await modal.isVisible()) {
        await modal.getByTestId('modal-close').click()
        await expect(modal).toBeHidden()
      }
      await shownByTestId(page, `hilos-change-log-open-${rowKey}`).click()
      await expectChangeLogReceiptOpen(expect, page, receiptId)
      // A refusal settles the read too, and must fail instead of being retried.
      await expect(header.or(error)).toBeVisible()
      await expect(error).toHaveCount(0)
      return (
        await header
          .locator('dt')
          .filter({ hasText: /^Agent$/ })
          .locator('+ dd')
          .innerText()
      ).trim()
    })
    .toBe(agentId)
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

/**
 * A person's history opens with the registration: the creation as a fact, and
 * the time zone the browser named at sign-up as a fact without its value, both
 * on the registration's receipt. Reads that receipt's number.
 *
 * @param expect The spec's expect.
 * @param page The page showing one person's history.
 * @returns The registration's receipt number.
 */
export async function expectRegistrationHistory(
  expect: Expect,
  page: Page,
): Promise<string> {
  const creationKey = await tableRowKeyByText(expect, page, 'Created as a fact')
  const zoneKey = await tableRowKeyByText(
    expect,
    page,
    'last_browser_time_zone: Personal — no value',
  )
  await expect(shownByTestId(page, `hilos-table-row-${zoneKey}`)).toContainText(
    'updated',
  )
  const creation = shownByTestId(
    page,
    `hilos-change-log-history-receipt-${creationKey}`,
  )
  await expect(creation).toHaveAccessibleName(/^Receipt #\d+$/)
  const receiptId = ((await creation.getAttribute('aria-label')) ?? '').slice(
    'Receipt #'.length,
  )
  await expect(
    shownByTestId(page, `hilos-change-log-history-receipt-${zoneKey}`),
  ).toHaveAccessibleName(`Receipt #${receiptId}`)

  return receiptId
}

/**
 * The feed row of a registration's receipt names the action and sums the
 * person's two entries beside the identity's creation. Reads the row key.
 *
 * @param expect The spec's expect.
 * @param page The page showing the feed.
 * @param receiptId The registration's receipt number.
 * @returns The feed row key.
 */
export async function expectRegistrationFeedRow(
  expect: Expect,
  page: Page,
  receiptId: string,
): Promise<string> {
  const rowKey = `receipt:${receiptId}`
  const row = shownByTestId(page, `hilos-table-row-${rowKey}`)
  await expect(row).toContainText('hilos_complete_registration')
  await expect(row).toContainText(
    /hilos_user · 2 records, hilos_identity #\d+ · created/,
  )

  return rowKey
}

/**
 * Registration journals the person and identity as facts without values; the
 * time zone the browser named at sign-up rides the same receipt as a fact
 * without its value.
 */
export async function expectRegistrationReceiptFacts(
  expect: Expect,
  page: Page,
  userId: number,
): Promise<void> {
  const detail = page.getByTestId('hilos-change-log-detail')
  await expect(detail).toContainText(`hilos_user #${userId}`)
  await expect(detail).toContainText('hilos_identity #')
  const entries = detail.getByTestId(/^hilos-change-log-entry-\d+$/)
  await expect(entries).toHaveCount(3)
  await expect(
    entries.filter({
      hasText: 'Created as a fact; values belong to the record itself.',
    }),
  ).toHaveCount(2)
  const zone = entries.filter({ hasText: 'last_browser_time_zone' })
  await expect(zone).toHaveCount(1)
  await expect(zone).toContainText(`hilos_user #${userId}`)
  await expect(zone).toContainText('updated')
  await expect(zone).toContainText('Personal — no value')
  const browserTimeZone = await page.evaluate(
    () => Intl.DateTimeFormat().resolvedOptions().timeZone,
  )
  await expect(zone).not.toContainText(browserTimeZone)
  for (const entry of await entries.all()) {
    await expect(entry.getByTestId(/^hilos-change-log-value-/)).toHaveCount(0)
    await expect(entry).not.toContainText('@example.test')
  }
}

/** Tracked tables has one heading and an accessible table of tables. */
export async function expectTrackedTablesPage(
  expect: Expect,
  page: Page,
): Promise<void> {
  await expectChangeLogOwnerHead(
    expect,
    page,
    'hilos_change_log_tables',
    'Tracked tables',
  )
  if (await page.getByTestId('hilos-admin-title').count()) {
    await expect(page.getByTestId('hilos-admin-page')).toContainText(
      CHANGE_LOG_TABLES_LEAD,
    )
  }
  await expect(page.getByTestId('hilos-table-title')).toHaveCount(0)
  await expect(page.getByRole('heading', { level: 1 })).toHaveCount(1)
  await expect(
    page.getByRole('table', {
      name: (await page.getByTestId('hilos-page-head-title').count())
        ? 'Change Log'
        : 'Tracked tables',
      exact: true,
    }),
  ).toBeVisible()
}

/** The tracked-tables reference explains the journal column modes. */
export async function expectChangeLogReference(
  expect: Expect,
  page: Page,
): Promise<void> {
  const modes = page.getByTestId('hilos-change-log-column-modes')
  await expect(modes.getByRole('heading', { level: 2 })).toHaveText(
    'How a column enters the journal',
  )
  await expect(modes).toContainText('Personal — only a fact')
  await expect(modes).toContainText('Binary — only a fact')
  await expect(modes).toContainText('Record key')
}

/** The schema declarations have their own address and standard heading. */
export async function expectChangeLogNoisyColumns(
  expect: Expect,
  page: Page,
): Promise<void> {
  await expectChangeLogOwnerHead(
    expect,
    page,
    'hilos_change_log_noisy',
    'Noisy columns',
  )
  const noiseTable = page.getByRole('table', {
    name: (await page.getByTestId('hilos-page-head-title').count())
      ? 'Change Log'
      : 'Noisy columns',
    exact: true,
  })
  await expect(noiseTable).toBeVisible()
  await expect(noiseTable).toContainText('hilos_user.last_activity')
  await expect(noiseTable).toContainText('Changes on every user request')
}

/**
 * Check the adopted Vue table owner, including its declaration and native links.
 *
 * @param expect The spec's expect.
 * @param page The current table page.
 * @param table The live table name.
 * @param currentPage The current owner page key.
 * @param declaration Expected schema metadata from the scenario's fixture.
 * @param recordKey The optional record route context retained by the History tab.
 */
export async function expectChangeLogTableHead(
  expect: Expect,
  page: Page,
  table: string,
  currentPage: string,
  declaration: {
    owner: 'Framework' | 'Project'
    since: number | null
    journaled?: boolean
  },
  recordKey?: string,
): Promise<void> {
  const journaled = declaration.journaled !== false
  await expect(page.getByTestId('hilos-page-head')).toBeVisible()
  await expect(page.getByTestId('hilos-page-head-skeleton')).toHaveCount(0)
  const heading = page.getByRole('heading', { level: 1 })
  await expect(heading).toHaveCount(1)
  await expect(heading).toHaveAttribute('data-id', 'hilos-page-head-title')
  await expect(heading).toHaveText(table)
  await expect(heading).toHaveClass(/\bfont-monospace\b/)
  await expect(heading).toHaveClass(/\bfs-5\b/)
  await expect(page).toHaveTitle(
    new RegExp(`^${escapeRegExp(table)}(?: · .+)?$`),
  )
  const icon = page.getByTestId('hilos-change-log-table-head-icon')
  await expect(icon).toHaveAttribute('aria-hidden', 'true')
  await expect(icon).toHaveClass(/\brounded-circle\b/)
  await expect(icon.locator('i.bi-table')).toHaveCount(1)
  await expect(
    page.getByTestId('hilos-change-log-table-head-badges'),
  ).toHaveText(journaled ? `${declaration.owner} Journaled` : declaration.owner)
  await expect(
    page.getByTestId('hilos-change-log-table-head-description'),
  ).toHaveText(
    !journaled
      ? "The entity has no journal label — this table's history is not written."
      : declaration.since === null
        ? ''
        : `Journaled since migration ${String(declaration.since).padStart(3, '0')}`,
  )
  const crumbs = page.getByTestId('hilos-breadcrumb')
  await expect(crumbs).toHaveAccessibleName('Breadcrumb')
  await expect(crumbs.getByRole('link')).toHaveText([
    'Hilos',
    'Change Log',
    'Tracked tables',
  ])
  for (const [key, href] of [
    ['hilos', '/hilos'],
    ['hilos_change_log', CHANGE_LOG_PATH],
    ['hilos_change_log_tables', CHANGE_LOG_TABLES_PATH],
  ]) {
    await expect(crumbs.getByTestId(`hilos-breadcrumb-${key}`)).toHaveAttribute(
      'href',
      href!,
    )
  }
  await expect(crumbs.locator('[aria-current="page"]')).toHaveText(table)
  await expect(crumbs.locator('[aria-current="page"] a')).toHaveCount(0)
  const tabs = page.getByTestId('hilos-page-tabs')
  await expect(tabs).toHaveAccessibleName('Table pages')
  await expect(tabs).toHaveRole('navigation')
  const entries = journaled
    ? [
        ['', 'Overview'],
        ['columns', 'Columns'],
        ['triggers', 'Triggers'],
        ['history', 'History'],
      ]
    : [
        ['', 'Overview'],
        ['triggers', 'Triggers'],
      ]
  await expect(tabs.getByRole('link')).toHaveText(
    entries.map(([, label]) => label!),
  )
  for (const [suffix] of entries) {
    const key = `hilos_change_log_table${suffix ? `_${suffix}` : ''}`
    const link = tabs.getByTestId(`hilos-page-tab-${key}`)
    const recordSuffix =
      suffix === 'history' && recordKey !== undefined
        ? `/${encodeURIComponent(recordKey)}`
        : ''
    await expect(link).toHaveAttribute(
      'href',
      `${CHANGE_LOG_TABLES_PATH}/${encodeURIComponent(table)}${suffix ? `/${suffix}` : ''}${recordSuffix}`,
    )
    if (key === currentPage) {
      await expect(link).toHaveAttribute('aria-current', 'page')
      await expect(link).toHaveClass(/\bactive\b/)
    } else {
      await expect(link).not.toHaveAttribute('aria-current')
      await expect(link).not.toHaveClass(/\bactive\b/)
    }
  }
  await expect(tabs.locator('[aria-current]')).toHaveCount(1)
  await expect(page.getByRole('tablist')).toHaveCount(0)
  await expect(page.getByTestId('hilos-admin-title')).toHaveCount(0)
  await expect(page.getByTestId('hilos-change-log-table-header')).toHaveCount(0)
  await expect(page.getByTestId('hilos-admin-children')).toHaveCount(0)
}

/**
 * Keep exact legacy headings while checking all head metadata in adopted Vue.
 *
 * @param expect The spec's expect.
 * @param page The current page.
 * @param table The scenario's table fixture.
 * @param currentPage The current table page key.
 * @param legacyTitle The legacy page's exact catalog heading.
 * @param recordKey The optional record route context retained by the History tab.
 */
async function expectTrackedTableOwnerHead(
  expect: Expect,
  page: Page,
  table: string,
  currentPage: string,
  legacyTitle: string,
  recordKey?: string,
): Promise<void> {
  const heading = page.getByRole('heading', { level: 1 })
  await expect(heading).toHaveCount(1)
  if ((await heading.getAttribute('data-id')) === 'hilos-page-head-title') {
    // These two schema fixtures are used by the shared table reading scenarios.
    const declarations = {
      hilos_user: { owner: 'Framework', since: 85 },
      bot: { owner: 'Project', since: 95 },
    } as const
    const declaration = declarations[table as keyof typeof declarations]
    if (!declaration) throw new Error(`No table head fixture for ${table}.`)
    await expectChangeLogTableHead(
      expect,
      page,
      table,
      currentPage,
      declaration,
      recordKey,
    )
  } else {
    await expect(heading).toHaveAttribute('data-id', 'hilos-admin-title')
    await expect(heading).toHaveText(legacyTitle)
    if (currentPage === 'hilos_change_log_table') {
      await expect(
        page.getByTestId('hilos-change-log-table-header'),
      ).toContainText(table)
    }
  }
}

/** Overview keeps the live-table summary and no history window. */
export async function expectTrackedTableOverview(
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
  await expectTrackedTableOwnerHead(
    expect,
    page,
    table,
    'hilos_change_log_table',
    'Tracked table',
  )
  await expect(page.getByTestId('hilos-viewport-table')).toHaveCount(0)
  await expect(page.getByTestId('hilos-change-log-table-columns')).toHaveCount(
    0,
  )
  await expect(page.getByTestId('hilos-change-log-table-triggers')).toHaveCount(
    0,
  )
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
      `${CHANGE_LOG_TABLES_PATH}/${escapeRegExp(table)}/history/${escapeRegExp(recordKey)}$`,
    ),
  )
  await expect(page.getByTestId('hilos-page-state')).toHaveAttribute(
    'data-state',
    'ready',
  )
  await expectTrackedTableOwnerHead(
    expect,
    page,
    table,
    'hilos_change_log_table_history',
    'History',
    recordKey,
  )
  await expect(page.getByTestId('hilos-viewport-table')).toBeVisible()
  await expect(page.getByTestId('hilos-change-log-table-columns')).toHaveCount(
    0,
  )
  await expect(page.getByTestId('hilos-change-log-table-triggers')).toHaveCount(
    0,
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
    new RegExp(`${CHANGE_LOG_TABLES_PATH}/${escapeRegExp(table)}/history$`),
  )
  await expect(page.getByTestId('hilos-page-state')).toHaveAttribute(
    'data-state',
    'ready',
  )
  await expectTrackedTableOwnerHead(
    expect,
    page,
    table,
    'hilos_change_log_table_history',
    'History',
  )
  await expect(page.getByTestId('hilos-change-log-record-filter')).toHaveCount(
    0,
  )
  if (await page.getByTestId('hilos-page-head-title').count()) {
    await expect(page.getByTestId('hilos-table-subtitle')).toHaveText(
      'All records of this table',
    )
    await expect(
      page.getByRole('table', { name: table, exact: true }),
    ).toBeVisible()
  } else {
    await expect(page.getByTestId('hilos-viewport-table')).toContainText(
      'All records of this table',
    )
  }
}

/** Treat supplied table names, record keys and field names as literal text. */
function escapeRegExp(value: string): string {
  return value.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')
}
