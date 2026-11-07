import { expectBreadcrumbLabels } from './breadcrumb.js'
import type { Expect, Locator, Page } from '@playwright/test'

import type { TestCleanup } from './cleanupFixtures.js'
import { resolveHilosPath } from '../core/src/routing/hilosAdmin.js'
import { HilosPages } from '../core/src/routing/hilosPages.js'
import {
  deliveryObject as object,
  type DeliveryFrameLog,
  type DeliveryRequest,
} from './deliveryFrames.js'
import { clickSubmit, typeInto } from './input.js'
import { expectPageReady, gotoPage } from './page.js'
import { countSentFrames } from './socket.js'
import { expectDataFreeDeparture, ROWS, shownByTestId } from './table.js'
import { dismissToasts } from './toasts.js'

/** What a moderation kind declares, as its Overview and Rules pages show it. */
export interface ModerationKindDeclaration {
  readonly kind: string
  readonly title: string
  readonly owner: string
  readonly modelLabel: string
  readonly model: string
  readonly profile: string
  readonly reasons: readonly string[]
  readonly defaultRules: readonly string[]
}

/**
 * The two kinds the framework declares in every project, with the moderation
 * profile defaults they answer to. A project's own kinds and the state of the
 * rules — which rules of its own a kind holds — stay with the demo's spec.
 */
export const FRAMEWORK_MODERATION_KINDS = [
  {
    kind: 'hilos_profile_name',
    title: 'Profile name',
    owner: 'Framework',
    modelLabel: 'Text model',
    model: 'qwen2.5:0.5b',
    profile: 'hilos.moderation.text',
    reasons: [
      'insult',
      'threat',
      'hate_speech',
      'sexual',
      'spam',
      'impersonation',
    ],
    defaultRules: [
      'Default policy: allow benign display names.',
      'Block explicit insults, threats, hate speech, sexual content, spam, and impersonation.',
      'If uncertain, return allow=true.',
    ],
  },
  {
    kind: 'hilos_profile_photo',
    title: 'Profile photo',
    owner: 'Framework',
    modelLabel: 'Vision model',
    model: 'qwen2.5vl:3b',
    profile: 'hilos.moderation.image',
    reasons: ['nudity', 'violence', 'hate'],
    defaultRules: [
      'Default policy: allow ordinary photos, drawings and logos.',
      'Block only nudity or sexual content, graphic violence, and hate symbols.',
      'If uncertain, return allow=true.',
    ],
  },
] as const satisfies readonly ModerationKindDeclaration[]

/** The declaration of a framework kind, for the title its pages and dialogs carry. */
function frameworkModerationKind(kind: string): ModerationKindDeclaration {
  const declared = FRAMEWORK_MODERATION_KINDS.find(
    (candidate) => candidate.kind === kind,
  )
  if (!declared) throw new Error(`Not a framework moderation kind: ${kind}`)
  return declared
}

/** The row of the open Rules table whose rule text is exactly `rule`. */
function moderationRuleRow(page: Page, rule: string): Locator {
  return page
    .getByTestId('hilos-moderation-rules-table')
    .locator(ROWS)
    .filter({ has: page.getByText(rule, { exact: true }) })
}

/**
 * Check the exact rule text in the currently open Rules table.
 *
 * @param expect The spec's expect.
 * @param page The browser with the Rules page already open.
 * @param rule The exact text of the rule.
 * @param shown Whether the rule row should be visible or absent.
 */
export async function expectModerationRuleShown(
  expect: Expect,
  page: Page,
  rule: string,
  shown: boolean,
): Promise<void> {
  const row = moderationRuleRow(page, rule)
  if (shown) await expect(row).toBeVisible()
  else await expect(row).toHaveCount(0)
}

/** The id of the own rule whose text is exactly `rule`, as its Edit opener names it. */
async function moderationRuleId(page: Page, rule: string): Promise<number> {
  const opener = moderationRuleRow(page, rule).getByTestId(
    /^hilos-moderation-rule-edit-open-/,
  )
  const id = Number(
    (await opener.getAttribute('data-id'))?.replace(
      'hilos-moderation-rule-edit-open-',
      '',
    ),
  )
  if (!Number.isSafeInteger(id) || id <= 0)
    throw new Error(`No own rule reads exactly: ${rule}`)
  return id
}

/** Delete only reserved exact texts from the currently open Rules table. */
export async function deleteModerationOwnRules(
  expect: Expect,
  page: Page,
  rules: readonly string[],
): Promise<void> {
  const table = page.getByTestId('hilos-moderation-rules-table')
  await expect(table).toBeVisible()
  for (const text of rules) {
    // Read the complete DOM text: Playwright's string text matchers normalize
    // whitespace, and the visible table text may be clipped by its height cap.
    const matches = await table
      .locator(ROWS)
      .evaluateAll(
        (rows, reserved) =>
          rows
            .filter(
              (row) =>
                row.querySelector('.hilos-table-text[id]')?.textContent ===
                reserved,
            )
            .map((row) => row.getAttribute('data-id')!),
        text,
      )
    if (matches.length === 0) continue
    expect(matches, 'The reserved raw rule must identify one row').toHaveLength(
      1,
    )
    const row = table.getByTestId(matches[0])
    await clickSubmit(
      expect,
      row.getByTestId(/^hilos-moderation-rule-delete-open-/),
    )
    const named = page.getByTestId('hilos-moderation-rule-delete-rule')
    await expect(named).toBeVisible()
    // The held rule, compared exactly: Angular pads the interpolated text node
    // with the template's spaces, which the page never renders, while every
    // kit carries the rule untouched in the title.
    await expect(named).toHaveAttribute('title', text)
    await clickSubmit(
      expect,
      page.getByTestId('hilos-moderation-rule-delete-confirm'),
    )
    await expect(page.getByTestId('modal')).toBeHidden()
    // A removed row may retain its data-free departure placeholder.
    await expect(row.locator('.hilos-table-text[id]')).toHaveCount(0)
  }
}

/**
 * Reserve the texts a test may persist before it opens Add: teardown opens
 * the kind's Rules and deletes exactly these, whichever of them are there.
 *
 * @param expect The calling spec's Playwright expect.
 * @param page The administrator's page, still signed in at teardown.
 * @param cleanup The test's cleanup fixture.
 * @param declared The kind whose Rules the texts may be saved on.
 * @param rules Every exact text the test may save.
 */
export function reserveModerationRules(
  expect: Expect,
  page: Page,
  cleanup: TestCleanup,
  declared: { kind: string },
  rules: readonly string[],
): void {
  cleanup.defer(async () => {
    await gotoPage(
      expect,
      page,
      resolveHilosPath(HilosPages.MODERATION_KIND_RULES, {
        kind: declared.kind,
      }),
    )
    await dismissToasts(page)
    await deleteModerationOwnRules(expect, page, rules)
  })
}

/**
 * Add a rule of its own on the open Rules page through the Add dialog and
 * wait for the write to settle: the closed dialog is the acknowledgement.
 * Notices standing from an earlier step are swept before the opener is clicked.
 *
 * @param expect The calling spec's Playwright expect.
 * @param page The page with the kind's Rules open.
 * @param declared The kind, for the title its dialog carries.
 * @param rule The exact text the rule is saved with.
 * @returns The id of the new row, as its Edit opener names it.
 */
export async function addModerationRule(
  expect: Expect,
  page: Page,
  declared: { title: string },
  rule: string,
): Promise<number> {
  await dismissToasts(page)
  const dialog = page.getByTestId('modal')
  await clickSubmit(
    expect,
    page
      .getByTestId('hilos-moderation-rules-table')
      .getByTestId('hilos-table-main-action'),
  )
  await expect(dialog).toHaveAccessibleName(`Add rule · ${declared.title}`)
  await typeInto(page.getByTestId('hilos-moderation-rule-add-rule'), rule)
  await clickSubmit(
    expect,
    page.getByTestId('hilos-moderation-rule-add-submit'),
  )
  await expect(dialog).toBeHidden()
  return moderationRuleId(page, rule)
}

/**
 * Edit the own rule `id` on the open Rules page to `rule` through the Edit
 * dialog and wait for the write to settle: the closed dialog is the
 * acknowledgement. Notices standing from an earlier step are swept first.
 *
 * @param expect The calling spec's Playwright expect.
 * @param page The page with the kind's Rules open.
 * @param declared The kind, for the title its dialog carries.
 * @param id The row's id, as its openers name it.
 * @param rule The exact text the rule is saved with.
 */
export async function editModerationRule(
  expect: Expect,
  page: Page,
  declared: { title: string },
  id: number,
  rule: string,
): Promise<void> {
  await dismissToasts(page)
  const dialog = page.getByTestId('modal')
  await clickSubmit(
    expect,
    shownByTestId(page, `hilos-moderation-rule-edit-open-${id}`),
  )
  await expect(dialog).toHaveAccessibleName(`Edit rule · ${declared.title}`)
  await typeInto(page.getByTestId('hilos-moderation-rule-edit-rule'), rule)
  await clickSubmit(
    expect,
    page.getByTestId('hilos-moderation-rule-edit-submit'),
  )
  await expect(dialog).toBeHidden()
}

/**
 * Delete the own rule `id` on the open Rules page through the Delete dialog,
 * which names the held `rule`, and wait for the row to leave without its
 * data: the closed dialog is the acknowledgement. Notices standing from an
 * earlier step are swept first.
 *
 * @param expect The calling spec's Playwright expect.
 * @param page The page with the kind's Rules open.
 * @param id The row's id, as its openers name it.
 * @param rule The exact text the row holds.
 */
export async function deleteModerationRule(
  expect: Expect,
  page: Page,
  id: number,
  rule: string,
): Promise<void> {
  await dismissToasts(page)
  const dialog = page.getByTestId('modal')
  await clickSubmit(
    expect,
    shownByTestId(page, `hilos-moderation-rule-delete-open-${id}`),
  )
  await expect(dialog).toHaveAccessibleName('Delete rule')
  await expect(
    page.getByTestId('hilos-moderation-rule-delete-rule'),
  ).toHaveText(rule)
  await clickSubmit(
    expect,
    page.getByTestId('hilos-moderation-rule-delete-confirm'),
  )
  await expect(dialog).toBeHidden()
  await expectDataFreeDeparture(expect, page, String(id), 'Removed', [
    `hilos-moderation-rule-edit-open-${id}`,
    `hilos-moderation-rule-delete-open-${id}`,
  ])
}

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
  for (const [key, label] of [
    [HilosPages.MODERATION_KIND, 'Overview'],
    [HilosPages.MODERATION_KIND_RULES, 'Rules'],
  ] as const) {
    const link = tabs.getByTestId(`hilos-page-tab-${key}`)
    await expect(link).toHaveRole('link')
    await expect(link).toHaveAccessibleName(label)
    await expect(link).toHaveAttribute('href', resolveHilosPath(key, { kind }))
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
  // React and Angular draw the separator as an aria-hidden span inside each
  // item: the labels are read without that decoration, the last one current.
  await expectBreadcrumbLabels(
    expect,
    crumbs.locator('li'),
    ['Hilos', 'Moderation', declared.title],
    (label) => label.replace(/\s+/g, ' '),
  )
  await expect
    .poll(() =>
      crumbs
        .locator('li')
        .evaluateAll((items) =>
          items.map((item) => item.getAttribute('aria-current')),
        ),
    )
    .toEqual([null, null, 'page'])
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
  await expect(currentCrumb.locator('a')).toHaveCount(0)
  await expectModerationKindTabs(expect, page, declared.kind, current)
}

/** Overview reads the kind's declaration and offers nothing to write with. */
export async function expectModerationKindOverview(
  expect: Expect,
  page: Page,
  declared: ModerationKindDeclaration,
): Promise<void> {
  await expect(page.getByTestId('hilos-moderation-kind-model')).toHaveText(
    `${declared.modelLabel} · ${declared.model} — profile ${declared.profile}`,
  )
  await expect(page.getByTestId('hilos-moderation-kind-profile')).toHaveText(
    declared.profile,
  )
  const reasons = page.getByTestId('hilos-moderation-kind-reasons')
  for (const reason of declared.reasons) {
    await expect(reasons).toContainText(reason)
  }
  await expectModerationKindTabs(expect, page, declared.kind, 'overview')
  await expect(
    page
      .getByTestId('hilos-admin-page')
      .locator('button, input, select, textarea, table, [role="tablist"]'),
  ).toHaveCount(0)
}

/** An empty own table still leaves the kind's exact built-in rules visible. */
export async function expectModerationBuiltInRules(
  expect: Expect,
  page: Page,
  declared: ModerationKindDeclaration,
): Promise<void> {
  await expect(shownByTestId(page, 'hilos-table-empty')).toBeVisible()
  const builtIn = page.getByTestId('hilos-moderation-rules-built-in')
  await expect(builtIn).toBeVisible()
  await expect(builtIn.getByRole('heading', { level: 2 })).toHaveText(
    'Built-in rules',
  )
  await expect(builtIn.locator('li')).toHaveText([...declared.defaultRules])
  await expect(
    page.getByTestId('hilos-moderation-rules-table').locator(ROWS),
  ).toHaveCount(0)
  await expect(page.getByTestId('hilos-table-no-matches')).toHaveCount(0)
}

/**
 * After the last own rule departed, the built-in rules remain on the open
 * Rules page without a reload; the departed row may still hold its
 * data-free placeholder, and no window is pending Apply.
 *
 * @param expect The calling spec's Playwright expect.
 * @param page The page with the kind's Rules open.
 * @param declared The kind whose built-in rules are listed.
 */
export async function expectModerationBuiltInRulesAfterDelete(
  expect: Expect,
  page: Page,
  declared: ModerationKindDeclaration,
): Promise<void> {
  const table = page.getByTestId('hilos-moderation-rules-table')
  await expect(
    table.locator(ROWS).locator('.hilos-table-text[id]'),
  ).toHaveCount(0)
  await expect(table.getByTestId('hilos-table-count')).toHaveText('0 – 0 of 0')
  await expect(page.getByTestId('hilos-table-apply')).toHaveCount(0)
  const builtIn = page.getByTestId('hilos-moderation-rules-built-in')
  await expect(builtIn).toBeVisible()
  await expect(builtIn.locator('li')).toHaveText([...declared.defaultRules])
}

/**
 * Overview counts both layers independently: built-in rules always apply,
 * with the kind's own rules on top.
 *
 * @param expect The calling spec's Playwright expect.
 * @param page The page with the kind's Overview open.
 * @param declared The kind whose built-in rules are counted.
 * @param ownRuleCount How many rules of its own the kind holds.
 */
export async function expectModerationRulesInForce(
  expect: Expect,
  page: Page,
  declared: ModerationKindDeclaration,
  ownRuleCount: number,
): Promise<void> {
  await expect(page.getByTestId('hilos-moderation-kind-rules')).toHaveText(
    `${declared.defaultRules.length} built-in + ${ownRuleCount} of your own`,
  )
}

/** Read both layers: the exact built-ins and own rows with their server count. */
export async function expectModerationOwnRules(
  expect: Expect,
  page: Page,
  declared: ModerationKindDeclaration,
  rules: readonly string[],
): Promise<void> {
  const table = page.getByTestId('hilos-moderation-rules-table')
  await expect(table).toBeVisible()
  await expect(table.getByRole('table')).toHaveCount(1)
  await expect(table.getByTestId('hilos-table-sort-rule')).toHaveText('Rule')
  await expect(table.locator(ROWS).locator('.hilos-table-text[id]')).toHaveText(
    [...rules],
  )
  await expect(table.getByTestId('hilos-table-count')).toHaveText(
    `1 – ${rules.length} of ${rules.length}`,
  )
  await expect(shownByTestId(table, 'hilos-table-search')).toHaveAccessibleName(
    'Search rules…',
  )
  const builtIn = page.getByTestId('hilos-moderation-rules-built-in')
  await expect(builtIn).toBeVisible()
  await expect(builtIn.locator('li')).toHaveText([...declared.defaultRules])
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
  for (const [action, label] of [
    ['edit', 'Edit rule'],
    ['delete', 'Delete rule'],
  ]) {
    const openers = table
      .locator(ROWS)
      .getByTestId(new RegExp(`^hilos-moderation-rule-${action}-open-`))
    await expect(openers).toHaveCount(rules.length)
    for (const button of await openers.all()) {
      await expect(button).toHaveAccessibleName(label)
      await expect(button).toBeEnabled()
    }
  }
  await expect(table.getByTestId('hilos-table-next')).toHaveCount(0)
  await expect(table.getByTestId('hilos-table-prev')).toHaveCount(0)
}

/**
 * From the opened section root, the kind's pages are named and reached by
 * keyboard: the one heading names the kinds table and, through
 * aria-labelledby, the Rules table; Open and the kind tabs are links that
 * follow Enter. The caller has opened `/hilos/moderation`.
 */
export async function expectModerationPagesAccessibility(
  expect: Expect,
  page: Page,
  kind: string,
): Promise<void> {
  const { title } = frameworkModerationKind(kind)
  const heading = page.getByRole('heading', { level: 1 })
  await expect(heading).toHaveCount(1)
  await expect(heading).toHaveAccessibleName('Moderation')
  await expect(page).toHaveTitle(/^Moderation · /)
  const kinds = page.getByTestId('hilos-moderation-kinds-table')
  await expect(kinds.getByRole('table')).toHaveAccessibleName('Moderation')
  const search = kinds.getByTestId('hilos-table-search')
  await expect(search).toHaveRole('searchbox')
  await expect(search).toHaveAccessibleName('Search kinds…')
  const open = shownByTestId(kinds, `hilos-moderation-open-${kind}`)
  await expect(open).toHaveRole('link')
  await expect(open).toHaveAccessibleName(`Open ${title}`)
  await open.focus()
  await open.press('Enter')
  await expectPageReady(expect, page)
  await expect(page).toHaveURL(
    resolveHilosPath(HilosPages.MODERATION_KIND, { kind }),
  )
  await expect(heading).toHaveCount(1)
  await expect(heading).toHaveAccessibleName(title)
  await expectModerationKindTabs(expect, page, kind, 'overview')

  const rules = page.getByTestId('hilos-page-tab-hilos_moderation_kind_rules')
  await rules.focus()
  await rules.press('Enter')
  await expectPageReady(expect, page)
  await expect(page).toHaveURL(
    resolveHilosPath(HilosPages.MODERATION_KIND_RULES, { kind }),
  )
  await expect(heading).toHaveCount(1)
  await expect(heading).toHaveAccessibleName(title)
  await expectModerationKindTabs(expect, page, kind, 'rules')
  const table = page.getByTestId('hilos-moderation-rules-table')
  await expect(table.getByRole('table')).toHaveAccessibleName(title)
  const headingId = await heading.getAttribute('id')
  expect(headingId).toBeTruthy()
  await expect(table.getByRole('table')).toHaveAttribute(
    'aria-labelledby',
    headingId!,
  )
  await expect(table.getByTestId('hilos-table-search')).toHaveRole('searchbox')
  await expect(table.getByTestId('hilos-table-search')).toHaveAccessibleName(
    'Search rules…',
  )

  const overview = page.getByTestId('hilos-page-tab-hilos_moderation_kind')
  await overview.focus()
  await overview.press('Enter')
  await expectPageReady(expect, page)
  await expectModerationKindTabs(expect, page, kind, 'overview')
  await rules.focus()
  await rules.press('Enter')
  await expectPageReady(expect, page)
  await expectModerationKindTabs(expect, page, kind, 'rules')
}

/**
 * On the open Rules page, Add names its dialog and field, traps focus between
 * Save and Close, keeps the draft when its discard is canceled and returns
 * focus to its opener. It ends with the one save that creates `rule`, the
 * row the Edit and Delete dialogs are then inspected on.
 */
export async function expectModerationAddDialogAccessibility(
  expect: Expect,
  page: Page,
  kind: string,
  rule: string,
): Promise<void> {
  const { title } = frameworkModerationKind(kind)
  const add = page
    .getByTestId('hilos-moderation-rules-table')
    .getByTestId('hilos-table-main-action')
  const dialog = page.getByTestId('modal')
  await expect(add).toHaveRole('button')
  await expect(add).toHaveAccessibleName('Add rule')
  await add.focus()
  await add.press('Enter')
  await expect(dialog).toHaveRole('dialog')
  await expect(dialog).toHaveAttribute('aria-modal', 'true')
  await expect(dialog).toHaveAccessibleName(`Add rule · ${title}`)
  const field = page.getByTestId('hilos-moderation-rule-add-rule')
  await expect(field).toHaveRole('textbox')
  await expect(field).toHaveAccessibleName('Rule')
  await expect(field).toBeFocused()
  await expect(field).toHaveValue('')
  await field.press('Escape')
  await expect(dialog).toBeHidden()
  await expect(add).toBeFocused()

  await add.press('Enter')
  await expect(field).toBeFocused()
  await typeInto(field, rule)
  const save = page.getByTestId('hilos-moderation-rule-add-submit')
  const close = dialog.getByTestId('modal-close')
  await expect(close).toHaveAccessibleName('Close')
  await expect(save).toHaveAccessibleName('Save')
  await expect(save).toBeEnabled()
  await close.focus()
  await close.press('Shift+Tab')
  await expect(save).toBeFocused()
  await save.press('Tab')
  await expect(close).toBeFocused()
  await close.press('Escape')
  const confirmation = page.getByTestId('modal-confirm')
  await expect(confirmation).toHaveRole('alertdialog')
  await expect(confirmation).toHaveAccessibleName('Discard changes?')
  await expect(confirmation).toBeFocused()
  const keepEditing = page.getByTestId('modal-confirm-cancel')
  await expect(keepEditing).toHaveAccessibleName('Keep editing')
  await keepEditing.focus()
  await keepEditing.press('Enter')
  await expect(confirmation).toBeHidden()
  await expect(field).toBeFocused()
  await expect(field).toHaveValue(rule)
  // This one save prepares the owned row for Edit and Delete inspection.
  await clickSubmit(expect, save)
  await expect(dialog).toBeHidden()
  await expect(add).toBeFocused()
  await expect(moderationRuleRow(page, rule)).toBeVisible()
}

/**
 * On the open Rules page, the Edit and Delete dialogs of the row holding
 * `rule` are named, focus their field or themselves, and return focus to
 * their opener after Escape, after a discarded draft and after an explicit
 * keyboard close; the row stays.
 */
export async function expectModerationRowDialogsAccessibility(
  expect: Expect,
  page: Page,
  kind: string,
  rule: string,
): Promise<void> {
  const { title } = frameworkModerationKind(kind)
  const row = moderationRuleRow(page, rule)
  const edit = row.getByTestId(/^hilos-moderation-rule-edit-open-/)
  const remove = row.getByTestId(/^hilos-moderation-rule-delete-open-/)
  const dialog = page.getByTestId('modal')
  await expect(edit).toHaveAccessibleName('Edit rule')
  await edit.focus()
  await edit.press('Enter')
  await expect(dialog).toHaveRole('dialog')
  await expect(dialog).toHaveAttribute('aria-modal', 'true')
  await expect(dialog).toHaveAccessibleName(`Edit rule · ${title}`)
  const field = page.getByTestId('hilos-moderation-rule-edit-rule')
  await expect(field).toHaveRole('textbox')
  await expect(field).toHaveAccessibleName('Rule')
  await expect(field).toBeFocused()
  await expect(field).toHaveValue(rule)
  await field.press('Escape')
  await expect(dialog).toBeHidden()
  await expect(edit).toBeFocused()

  await edit.press('Enter')
  await expect(field).toBeFocused()
  await typeInto(field, 'Local draft to discard')
  const close = dialog.getByTestId('modal-close')
  await expect(close).toHaveAccessibleName('Close')
  await close.focus()
  await close.press('Enter')
  const confirmation = page.getByTestId('modal-confirm')
  await expect(confirmation).toHaveRole('alertdialog')
  await expect(confirmation).toHaveAccessibleName('Discard changes?')
  await expect(confirmation).toBeFocused()
  const discard = page.getByTestId('modal-confirm-discard')
  await expect(discard).toHaveAccessibleName('Discard')
  await discard.focus()
  await discard.press('Enter')
  await expect(confirmation).toBeHidden()
  await expect(dialog).toBeHidden()
  await expect(edit).toBeFocused()

  await expect(remove).toHaveAccessibleName('Delete rule')
  await remove.focus()
  await remove.press('Enter')
  await expect(dialog).toHaveRole('dialog')
  await expect(dialog).toHaveAttribute('aria-modal', 'true')
  await expect(dialog).toHaveAccessibleName('Delete rule')
  await expect(dialog).toBeFocused()
  await expect(
    page.getByTestId('hilos-moderation-rule-delete-rule'),
  ).toHaveText(rule)
  await dialog.press('Escape')
  await expect(dialog).toBeHidden()
  await expect(remove).toBeFocused()
  await remove.press('Enter')
  await expect(dialog).toBeFocused()
  await close.focus()
  await close.press('Enter')
  await expect(dialog).toBeHidden()
  await expect(remove).toBeFocused()
  await expect(row).toBeVisible()
}

/**
 * A viewer reads and drafts the named Add, Edit and Delete dialogs of the row
 * holding `rule`, while every submit is disabled and explained, Tab moves
 * between Cancel and Close only, and no write frame leaves the page.
 *
 * The check opens the Rules page itself: the write frames are counted from
 * the socket's birth, and a socket opened earlier is not listened to. The
 * caller supplies a page of a context in view mode before its first
 * navigation, and keeps the context and the view mode switch.
 *
 * @param expect The calling spec's Playwright expect.
 * @param viewer The viewer's page, not yet navigated.
 * @param kind The framework kind whose Rules page is opened.
 * @param rule The rule of its own the administrator created.
 * @returns A reader of the write frames the viewer has sent so far, for the
 *   add, update and delete actions in that order.
 */
export async function expectModerationViewerAccessibility(
  expect: Expect,
  viewer: Page,
  kind: string,
  rule: string,
): Promise<() => number[]> {
  const { title } = frameworkModerationKind(kind)
  const writes = ['add', 'update', 'delete'].map((action) =>
    countSentFrames(viewer, `hilos_moderation_rule_${action}`),
  )
  const sentWrites = (): number[] => writes.map((sent) => sent())
  await gotoPage(
    expect,
    viewer,
    resolveHilosPath(HilosPages.MODERATION_KIND_RULES, { kind }),
  )
  await expect(viewer.getByTestId('view-mode-banner')).toBeVisible()
  const viewerTable = viewer.getByTestId('hilos-moderation-rules-table')
  const viewerRow = moderationRuleRow(viewer, rule)
  for (const action of ['add', 'edit', 'delete'] as const) {
    const opener =
      action === 'add'
        ? viewerTable.getByTestId('hilos-table-main-action')
        : viewerRow.getByTestId(
            new RegExp(`^hilos-moderation-rule-${action}-open-`),
          )
    const label = {
      add: 'Add rule',
      edit: 'Edit rule',
      delete: 'Delete rule',
    }[action]
    await expect(opener).toHaveAccessibleName(label)
    await opener.focus()
    await opener.press('Enter')
    const viewerDialog = viewer.getByTestId('modal')
    await expect(viewerDialog).toHaveRole('dialog')
    await expect(viewerDialog).toHaveAttribute('aria-modal', 'true')
    await expect(viewerDialog).toHaveAccessibleName(
      action === 'delete' ? label : `${label} · ${title}`,
    )
    if (action === 'delete') {
      await expect(viewerDialog).toBeFocused()
      await expect(
        viewer.getByTestId('hilos-moderation-rule-delete-rule'),
      ).toHaveText(rule)
      await viewerDialog.press('Enter')
    } else {
      const field = viewer.getByTestId(`hilos-moderation-rule-${action}-rule`)
      await expect(field).toHaveAccessibleName('Rule')
      await expect(field).toBeFocused()
      await expect(field).toBeEditable()
      await expect(field).toHaveValue(action === 'add' ? '' : rule)
      await typeInto(field, 'Viewer draft only')
      await field.press('Enter')
      await expect(field).toHaveValue('Viewer draft only\n')
    }
    const submit = viewer.getByTestId(
      `hilos-moderation-rule-${action}-${action === 'delete' ? 'confirm' : 'submit'}`,
    )
    await expect(submit).toHaveRole('button')
    await expect(submit).toHaveAccessibleName(
      action === 'delete' ? 'Delete' : 'Save',
    )
    await expect(submit).toBeDisabled()
    await expect(submit).toHaveAccessibleDescription(
      'View mode · You can look around, but not change anything.',
    )
    const cancel = viewer.getByTestId(`hilos-moderation-rule-${action}-cancel`)
    const close = viewerDialog.getByTestId('modal-close')
    await expect(cancel).toHaveAccessibleName('Cancel')
    await expect(close).toHaveAccessibleName('Close')
    await cancel.focus()
    await cancel.press('Tab')
    await expect(close).toBeFocused()
    await close.press('Shift+Tab')
    await expect(cancel).toBeFocused()
    await cancel.press('Enter')
    if (action !== 'delete') {
      const confirmation = viewer.getByTestId('modal-confirm')
      await expect(confirmation).toHaveRole('alertdialog')
      await expect(confirmation).toHaveAccessibleName('Discard changes?')
      await expect(confirmation).toBeFocused()
      const discard = viewer.getByTestId('modal-confirm-discard')
      await discard.focus()
      await discard.press('Enter')
      await expect(confirmation).toBeHidden()
    }
    await expect(viewerDialog).toBeHidden()
    await expect(opener).toBeFocused()
    expect(sentWrites()).toEqual([0, 0, 0])
  }
  return sentWrites
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

/** The wire objects `log` received on the socket of `sent` after it was sent. */
function framesAfter(
  log: DeliveryFrameLog,
  sent: DeliveryRequest,
): Record<string, unknown>[] {
  return log.packets
    .filter(({ socket, at }) => socket === sent.socket && at >= sent.at)
    .flatMap(({ frames }) => frames.map(({ wire }) => object(wire)))
}

/** Since `sentBefore`, `log` asked for no window or page again, on its one connection. */
function expectModerationNoRefetch(
  expect: Expect,
  log: DeliveryFrameLog,
  sentBefore: number,
): void {
  expect(
    log.sent
      .slice(sentBefore)
      .filter(({ wire }) =>
        ['table_viewport', 'page_subscribe', 'page_resubscribe'].includes(
          String(object(wire).type),
        ),
      ),
  ).toEqual([])
  expect(log.connections).toHaveLength(1)
}

/**
 * Since `sentBefore`, `log` sent exactly one action of the rules family
 * `hilos_moderation_rule_*` — the write `action`, with `data` — and its only
 * reply is the `action_success` carrying `message`. Actions outside the
 * family — the toast stack reports its reading — are not counted.
 */
function expectModerationWriteEcho(
  expect: Expect,
  log: DeliveryFrameLog,
  sentBefore: number,
  action: 'update' | 'delete',
  data: Record<string, unknown>,
  message: string,
): void {
  const family = 'hilos_moderation_rule_'
  const name = `${family}${action}`
  const requests = log.sent.slice(sentBefore).filter(({ wire }) => {
    const request = object(wire)
    return (
      request.type === 'action' && String(request.action).startsWith(family)
    )
  })
  expect(requests.map(({ wire }) => object(wire).action)).toEqual([name])
  const sent = requests[0]!
  const request = object(sent.wire)
  expect(request).toMatchObject({
    action: name,
    requestId: expect.any(String),
    data,
  })
  expect(
    framesAfter(log, sent).filter(
      (wire) => wire.requestId === request.requestId,
    ),
  ).toEqual([
    expect.objectContaining({
      type: 'action_success',
      outcome: 'success',
      data: expect.objectContaining({ action: name, message }),
    }),
  ])
}

/**
 * The click on the Rule header sent exactly one window request for the
 * kind's rules, sorted by rule in `direction`, and the server answered it.
 * The rows may settle before the window lands — ascending is the insertion
 * order, so the DOM shows no sign of the reply — so the first answer is
 * awaited, and the request count and the answer count are read once it is
 * there. A second answer may still be on its way then: call `answeredOnce()`
 * of the returned bookmark after the next sort's rows have settled.
 *
 * @param expect The calling spec's Playwright expect.
 * @param log The frame log of the page, armed before its navigation.
 * @param sentBefore How many requests `log.sent` held before the click.
 * @param declared The kind whose Rules are sorted.
 * @param direction The direction the click asked for.
 * @returns `answeredOnce()` waits until a later window request of the kind's
 *   rules is answered — on the one socket its answer comes after any answer
 *   to this request — and then counts exactly one answer to this request.
 */
export async function expectModerationSortReply(
  expect: Expect,
  log: DeliveryFrameLog,
  sentBefore: number,
  declared: { kind: string },
  direction: 'asc' | 'desc',
): Promise<{ answeredOnce(): Promise<void> }> {
  const requests = (): DeliveryRequest[] =>
    log.sent.slice(sentBefore).filter(({ wire }) => {
      const request = object(wire)
      return (
        request.type === 'table_viewport' &&
        request.tableKey === 'hilosModerationRules'
      )
    })
  const windows = (sent: DeliveryRequest): Record<string, unknown>[] => {
    const { requestId } = object(sent.wire)
    return framesAfter(log, sent)
      .filter((wire) => wire.type === 'table_window')
      .map((wire) => object(wire.data))
      .filter(
        (data) =>
          data.tableKey === 'hilosModerationRules' &&
          data.requestId === requestId,
      )
  }
  await expect
    .poll(() => {
      const [sent] = requests()
      return sent ? windows(sent).length : 0
    })
    .toBeGreaterThan(0)
  const sent = requests()
  expect(sent).toHaveLength(1)
  const answered = sent[0]!
  const request = object(answered.wire)
  expect(request.sort).toEqual([{ field: 'rule', direction }])
  expect(object(request.filter).kind).toBe(declared.kind)
  expect(windows(answered)).toHaveLength(1)
  return {
    async answeredOnce(): Promise<void> {
      await expect
        .poll(() =>
          requests()
            .slice(1)
            .some((later) => windows(later).length > 0),
        )
        .toBe(true)
      expect(windows(answered)).toHaveLength(1)
    },
  }
}

/**
 * Since `sentBefore`, the tab behind `log` sent one action of the rules
 * family — the update of the row with its `expectedRule` — and got its
 * `action_success` back, and asked for no window or page again on its one
 * connection.
 *
 * @param expect The calling spec's Playwright expect.
 * @param log The frame log of the tab, armed before its navigation.
 * @param sentBefore How many requests `log.sent` held before the step.
 * @param declared The kind, for the action's data and the message's title.
 * @param saved The row's id, the text the tab saved and the text it expected.
 */
export async function expectModerationUpdateEcho(
  expect: Expect,
  log: DeliveryFrameLog,
  sentBefore: number,
  declared: { kind: string; title: string },
  saved: { id: number; rule: string; expectedRule: string },
): Promise<void> {
  expectModerationWriteEcho(
    expect,
    log,
    sentBefore,
    'update',
    {
      id: saved.id,
      kind: declared.kind,
      rule: saved.rule,
      expectedRule: saved.expectedRule,
    },
    `Rule in "${declared.title}" saved.`,
  )
  expectModerationNoRefetch(expect, log, sentBefore)
}

/**
 * Since `sentBefore`, the tab behind `log` sent one action of the rules
 * family — the delete of the row with its `expectedRule` — and got its
 * `action_success` back.
 *
 * @param expect The calling spec's Playwright expect.
 * @param log The frame log of the tab, armed before its navigation.
 * @param sentBefore How many requests `log.sent` held before the step.
 * @param declared The kind, for the action's data and the message's title.
 * @param removed The row's id and the text the tab expected it to hold.
 */
export async function expectModerationDeleteEcho(
  expect: Expect,
  log: DeliveryFrameLog,
  sentBefore: number,
  declared: { kind: string; title: string },
  removed: { id: number; expectedRule: string },
): Promise<void> {
  expectModerationWriteEcho(
    expect,
    log,
    sentBefore,
    'delete',
    { id: removed.id, kind: declared.kind, expectedRule: removed.expectedRule },
    `Rule removed from "${declared.title}".`,
  )
}

/**
 * The tab behind `log` received exactly one `row_removed` for the row `id`
 * of the kind's Rules, tagged `own` only when this tab deleted it, and since
 * `sentBefore` asked for no window or page again on its one connection.
 *
 * @param expect The calling spec's Playwright expect.
 * @param log The frame log of the tab, armed before its navigation.
 * @param sentBefore How many requests `log.sent` held before the delete.
 * @param id The id of the deleted row.
 * @param own Whether this tab is the one that deleted the row.
 */
export async function expectModerationRemovalEcho(
  expect: Expect,
  log: DeliveryFrameLog,
  sentBefore: number,
  id: number,
  own: boolean,
): Promise<void> {
  const removals = log.packets
    .flatMap(({ frames }) => frames.map(({ wire }) => object(wire)))
    .filter((wire) => wire.type === 'table_viewport_delta')
    .map((wire) => object(wire.data))
    .filter(
      (data) =>
        data.tableKey === 'hilosModerationRules' && data.kind === 'row_removed',
    )
  expect(removals).toEqual([
    expect.objectContaining({
      page: 'hilos_moderation_kind_rules',
      tableKey: 'hilosModerationRules',
      kind: 'row_removed',
      rowKey: id,
      reason: 'deleted',
    }),
  ])
  // The DTO emits the own tag only for the originating tab.
  expect(removals[0]!.own).toBe(own ? true : undefined)
  expectModerationNoRefetch(expect, log, sentBefore)
}
