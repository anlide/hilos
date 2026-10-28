import type { Expect, Page } from '@playwright/test'

import {
  deliveryObject as object,
  type DeliveryFrameLog,
} from './deliveryFrames.js'
import { ROWS, shownByTestId } from './table.js'

/** The two addressed pages share the kind's identity and link navigation. */
export async function expectModerationKindTabs(
  expect: Expect,
  page: Page,
  kind: string,
  current: 'overview' | 'rules',
): Promise<void> {
  const tabs = page.getByTestId('hilos-page-tabs')
  await expect(tabs).toHaveCount(1)
  await expect(tabs).toHaveRole('navigation')
  await expect(tabs).toHaveAccessibleName('Kind pages')
  await expect(tabs.getByRole('link')).toHaveText(['Overview', 'Rules'])
  await expect(tabs.locator('[aria-current="page"]')).toHaveCount(1)
  await expect(page.getByRole('tablist')).toHaveCount(0)
  for (const [key, label, suffix] of [
    ['hilos_moderation_kind', 'Overview', ''],
    ['hilos_moderation_kind_rules', 'Rules', '/rules'],
  ] as const) {
    const link = tabs.getByTestId(`hilos-page-tab-${key}`)
    await expect(link).toHaveRole('link')
    await expect(link).toHaveAccessibleName(label)
    await expect(link).toHaveAttribute(
      'href',
      `/hilos/moderation/${kind}${suffix}`,
    )
    if (label.toLowerCase() === current) {
      await expect(link).toHaveAttribute('aria-current', 'page')
    } else {
      await expect(link).not.toHaveAttribute('aria-current')
    }
  }
}

/** The owner supplies the sole heading, breadcrumb and title on both pages. */
export async function expectModerationKindHead(
  expect: Expect,
  page: Page,
  declared: { kind: string; title: string; owner: string },
  current: 'overview' | 'rules',
): Promise<void> {
  await expect(page.getByTestId('hilos-page-state')).toHaveAttribute(
    'data-state',
    'ready',
  )
  const heading = page.getByRole('heading', { level: 1 })
  await expect(heading).toHaveCount(1)
  await expect(heading).toHaveAttribute('data-id', 'hilos-page-head-title')
  await expect(heading).toHaveText(declared.title)
  await expect(page).toHaveTitle(new RegExp(`^${declared.title} · `))
  await expect(page.getByTestId('hilos-page-head-skeleton')).toHaveCount(0)
  await expect(page.getByTestId('hilos-moderation-kind-owner')).toHaveText(
    declared.owner,
  )
  await expect(page.getByTestId('hilos-moderation-kind-key')).toHaveText(
    declared.kind,
  )
  const crumbs = page.getByTestId('hilos-breadcrumb')
  await expect(crumbs).toHaveRole('navigation')
  await expect(crumbs).toHaveAccessibleName('Breadcrumb')
  await expect(crumbs.locator('li')).toHaveText([
    'Hilos',
    'Moderation',
    declared.title,
  ])
  await expect(crumbs.getByRole('link')).toHaveText(['Hilos', 'Moderation'])
  await expect(crumbs.getByTestId('hilos-breadcrumb-hilos')).toHaveAttribute(
    'href',
    '/hilos',
  )
  await expect(
    crumbs.getByTestId('hilos-breadcrumb-hilos_moderation'),
  ).toHaveAttribute('href', '/hilos/moderation')
  const currentCrumb = crumbs.locator('[aria-current="page"]')
  await expect(currentCrumb).toHaveCount(1)
  await expect(currentCrumb).toHaveText(declared.title)
  await expect(currentCrumb.locator('a')).toHaveCount(0)
  await expectModerationKindTabs(expect, page, declared.kind, current)
  await expect(page.getByTestId('hilos-admin-children')).toHaveCount(0)
}

/** Read real rule text in the desktop table, including its server count. */
export async function expectModerationOwnRules(
  expect: Expect,
  page: Page,
  rules: readonly string[],
): Promise<void> {
  const table = page.getByTestId('hilos-moderation-rules-table')
  await expect(table).toBeVisible()
  await expect(table.getByRole('table')).toHaveCount(1)
  // Rule, its Delete action and the SDK's permanent row-state column.
  await expect(table.locator('thead th')).toHaveCount(3)
  await expect(table.getByTestId('hilos-table-sort-rule')).toHaveText('Rule')
  await expect(table.locator(ROWS).locator('.hilos-table-text[id]')).toHaveText(
    [...rules],
  )
  await expect(table.getByTestId('hilos-table-count')).toHaveText(
    `1 – ${rules.length} of ${rules.length}`,
  )
  await expect(shownByTestId(table, 'hilos-table-search')).toHaveAttribute(
    'placeholder',
    'Search rules…',
  )
  await expect(page.getByTestId('hilos-moderation-rules-built-in')).toHaveCount(
    0,
  )
  await expect(page.getByTestId('hilos-moderation-rules-loading')).toHaveCount(
    0,
  )
  await expect(table.getByTestId('hilos-table-placeholder')).toHaveCount(0)
  await expect(table.getByTestId('hilos-table-select-page')).toHaveCount(0)
  const add = table.getByTestId('hilos-table-main-action')
  await expect(add).toHaveCount(1)
  await expect(add).toHaveRole('button')
  await expect(add).toHaveAccessibleName('Add rule')
  await expect(add).toBeEnabled()
  const buttons = table.locator(ROWS).getByRole('button')
  await expect(buttons).toHaveCount(rules.length)
  await expect(
    table.locator(ROWS).getByTestId(/^hilos-moderation-rule-delete-open-/),
  ).toHaveCount(rules.length)
  for (const button of await buttons.all()) {
    await expect(button).toHaveAccessibleName('Delete rule')
    await expect(button).toBeEnabled()
  }
  await expect(table.getByTestId('hilos-table-next')).toHaveCount(0)
  await expect(table.getByTestId('hilos-table-prev')).toHaveCount(0)
}

/** A cold Rules subscription supplies both windows before any mount request. */
export async function expectModerationRulesOpening(
  expect: Expect,
  log: DeliveryFrameLog,
  declared: {
    kind: string
    ownRules: readonly string[]
    defaultRules: readonly string[]
  },
): Promise<void> {
  const socket = log.connections.at(-1)!.socket
  const replies = log.packets
    .filter((packet) => packet.socket === socket)
    .flatMap((packet) => packet.frames)
    .map((frame) => object(frame.wire))
    .filter((wire) => wire.type === 'page_response')
    .map((wire) => object(wire.data))
    .filter((data) => data.page === 'hilos_moderation_kind_rules')
  expect(replies).toHaveLength(1)
  const payload = object(replies[0]!.payload)
  expect(object(payload.data)).toMatchObject({
    pageLabel: 'Rules',
    pageHeadOwner: { page: 'hilos_moderation_kind' },
    pageTabs: [
      { page: 'hilos_moderation_kind', label: 'Overview' },
      { page: 'hilos_moderation_kind_rules', label: 'Rules' },
    ],
  })
  const windows = object(payload.windows)
  expect(Object.keys(windows)).toEqual([
    'hilosModerationKinds',
    'hilosModerationRules',
  ])
  expect(windows.hilosModerationKinds).toMatchObject({
    limit: 1,
    totalCount: 1,
    totalExact: true,
    rows: [
      {
        rowKey: declared.kind,
        slots: {
          kind: {
            kind: declared.kind,
            ownRuleCount: declared.ownRules.length,
            defaultRules: [...declared.defaultRules],
          },
        },
      },
    ],
  })
  expect(windows.hilosModerationRules).toMatchObject({
    limit: 25,
    sort: [{ field: 'id', direction: 'asc' }],
    totalCount: declared.ownRules.length,
    totalExact: true,
    rows: declared.ownRules.map((rule) => ({
      slots: { rule: { kind: declared.kind, rule } },
    })),
  })
  expect(
    log.sent.filter((request) => {
      const wire = object(request.wire)
      return (
        request.socket === socket &&
        (wire.type === 'action' || wire.type === 'table_viewport')
      )
    }),
  ).toEqual([])
}
