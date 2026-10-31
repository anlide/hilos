import { expect, test as base } from '@playwright/test'

import {
  clickSubmit,
  countSentFrames,
  dismissToasts,
  expectPageReady,
  gotoPage,
  grantAdminToSelf,
  PAGE_READY,
  ROWS,
  shownByTestId,
  typeInto,
  uniqueEmail,
} from '../../../../../framework/frontend/e2e/index.js'
import { setAdminViewMode } from '../../../../../framework/frontend/scripts/adminViewMode.mjs'

// spec-owner: demo — proves Polls' lazy Angular moderation activation and mutation windows.

const RULES_PATH = '/hilos/moderation/hilos_profile_name/rules'
const KINDS = [
  {
    kind: 'hilos_profile_name',
    title: 'Profile name',
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
    defaults: [
      'Default policy: allow benign display names.',
      'Block explicit insults, threats, hate speech, sexual content, spam, and impersonation.',
      'If uncertain, return allow=true.',
    ],
  },
  {
    kind: 'hilos_profile_photo',
    title: 'Profile photo',
    model: 'qwen2.5vl:3b',
    profile: 'hilos.moderation.image',
    reasons: ['nudity', 'violence', 'hate'],
    defaults: [
      'Default policy: allow ordinary photos, drawings and logos.',
      'Block only nudity or sexual content, graphic violence, and hate symbols.',
      'If uncertain, return allow=true.',
    ],
  },
] as const

// Reserve the UI-created subject before Add, including cleanup after a failed
// assertion or timeout. The marker survives Edit; cleanup never touches defaults.
const test = base.extend<{ ruleMarker: string }>({
  ruleMarker: async ({ page }, use) => {
    const marker = `Polls rule ${uniqueEmail()}`
    try {
      await use(marker)
    } finally {
      await gotoPage(expect, page, RULES_PATH, PAGE_READY)
      await expect(
        page.getByTestId('hilos-moderation-rules-table'),
      ).toBeVisible()
      const owned = page
        .getByTestId('hilos-moderation-rules-table')
        .locator(ROWS)
        .filter({ hasText: marker })
      for (const row of await owned.all()) {
        await clickSubmit(
          expect,
          row.getByTestId(/^hilos-moderation-rule-delete-open-/),
        )
        await clickSubmit(
          expect,
          page.getByTestId('hilos-moderation-rule-delete-confirm'),
        )
        await expect(page.getByTestId('modal')).toBeHidden()
      }
    }
  },
})

test.describe.configure({ mode: 'default' })

test('opens both framework kinds from the dashboard and restores Rules data on cold entry, F5 and Back', async ({
  page,
}) => {
  // Two kinds each cross Overview, Rules, a cold subscription and browser history.
  test.slow()
  await grantAdminToSelf(expect, page)
  await gotoPage(expect, page, '/hilos', PAGE_READY)
  await clickSubmit(expect, page.getByTestId('dashboard-card-hilos_moderation'))
  await expectPageReady(expect, page)
  await expect(page).toHaveURL(/\/hilos\/moderation$/)
  const table = page.getByTestId('hilos-moderation-kinds-table')
  await expect(table.locator(ROWS)).toHaveCount(2)
  for (const { kind } of KINDS) {
    await expect(
      shownByTestId(table, `hilos-moderation-row-${kind}`),
    ).toBeVisible()
  }
  for (const declared of KINDS) {
    await clickSubmit(
      expect,
      shownByTestId(page, `hilos-moderation-open-${declared.kind}`),
    )
    await expectPageReady(expect, page)
    await gotoPage(
      expect,
      page,
      `/hilos/moderation/${declared.kind}`,
      PAGE_READY,
    )
    await expect(page.getByRole('heading', { level: 1 })).toHaveText(
      declared.title,
    )
    await expect(page.getByTestId('hilos-moderation-kind-key')).toHaveText(
      declared.kind,
    )
    await expect(page.getByTestId('hilos-moderation-kind-owner')).toHaveText(
      'Framework',
    )
    await expect(page.getByTestId('hilos-moderation-kind-model')).toContainText(
      declared.model,
    )
    await expect(page.getByTestId('hilos-moderation-kind-profile')).toHaveText(
      declared.profile,
    )
    for (const reason of declared.reasons) {
      await expect(
        page.getByTestId('hilos-moderation-kind-reasons'),
      ).toContainText(reason)
    }
    await expect(page.getByTestId('hilos-moderation-kind-rules')).toHaveText(
      'Built-in until you add a rule of your own',
    )
    await clickSubmit(
      expect,
      page.getByTestId('hilos-page-tab-hilos_moderation_kind_rules'),
    )
    await expectPageReady(expect, page)
    await expect(
      page.getByTestId('hilos-page-tab-hilos_moderation_kind_rules'),
    ).toHaveAttribute('aria-current', 'page')
    const path = `/hilos/moderation/${declared.kind}/rules`
    await expect(page).toHaveURL(path)
    await expect(
      page.getByTestId('hilos-moderation-rules-built-in').locator('li'),
    ).toHaveText([...declared.defaults])
    await gotoPage(expect, page, path, PAGE_READY)
    await expect(
      page.getByTestId('hilos-moderation-rules-built-in').locator('li'),
    ).toHaveText([...declared.defaults])
    await page.reload()
    await expectPageReady(expect, page)
    await expect(
      page.getByTestId('hilos-moderation-rules-built-in').locator('li'),
    ).toHaveText([...declared.defaults])
    await clickSubmit(
      expect,
      page.getByTestId('hilos-page-tab-hilos_moderation_kind'),
    )
    await expectPageReady(expect, page)
    await expect(page.getByTestId('hilos-moderation-kind-profile')).toHaveText(
      declared.profile,
    )
    await page.goBack()
    await expectPageReady(expect, page)
    await expect(page).toHaveURL(path)
    await expect(
      page.getByTestId('hilos-moderation-rules-built-in').locator('li'),
    ).toHaveText([...declared.defaults])
    await clickSubmit(
      expect,
      page.getByTestId('hilos-breadcrumb-hilos_moderation'),
    )
    await expectPageReady(expect, page)
    await expect(table.locator(ROWS)).toHaveCount(2)
  }
})

test('persists Add and Edit, lets a viewer inspect without writing, and returns built-ins after Delete', async ({
  page,
  browser,
  baseURL,
  ruleMarker,
}) => {
  // Three writes, cold re-entry and a separate viewer inspect all three dialogs.
  test.slow()
  await grantAdminToSelf(expect, page)
  await gotoPage(expect, page, RULES_PATH, PAGE_READY)
  const dialog = page.getByTestId('modal')
  const table = page.getByTestId('hilos-moderation-rules-table')
  const ownRow = table.locator(ROWS).filter({ hasText: ruleMarker })
  const edited = `${ruleMarker}: allow ordinary names.`

  await test.step('Add and Edit settle on the real write acknowledgement and survive cold entry', async () => {
    await clickSubmit(expect, page.getByTestId('hilos-table-main-action'))
    await expect(dialog).toHaveAccessibleName('Add rule · Profile name')
    await expect(
      page.getByTestId('hilos-moderation-rule-add-submit'),
    ).toBeDisabled()
    await typeInto(
      page.getByTestId('hilos-moderation-rule-add-rule'),
      ruleMarker,
    )
    await clickSubmit(
      expect,
      page.getByTestId('hilos-moderation-rule-add-submit'),
    )
    await expect(dialog).toBeHidden()
    await expect(ownRow).toContainText(ruleMarker)
    await expect(
      page.getByTestId('hilos-moderation-rules-built-in'),
    ).toHaveCount(0)
    await gotoPage(expect, page, RULES_PATH, PAGE_READY)
    await expect(ownRow).toContainText(ruleMarker)
    await dismissToasts(page)
    await clickSubmit(
      expect,
      ownRow.getByTestId(/^hilos-moderation-rule-edit-open-/),
    )
    await expect(dialog).toHaveAccessibleName('Edit rule · Profile name')
    await expect(
      page.getByTestId('hilos-moderation-rule-edit-rule'),
    ).toHaveValue(ruleMarker)
    await typeInto(page.getByTestId('hilos-moderation-rule-edit-rule'), edited)
    await clickSubmit(
      expect,
      page.getByTestId('hilos-moderation-rule-edit-submit'),
    )
    await expect(dialog).toBeHidden()
    await expect(ownRow).toContainText(edited)
    await page.reload()
    await expectPageReady(expect, page)
    await expect(ownRow).toContainText(edited)
    await clickSubmit(
      expect,
      page.getByTestId('hilos-page-tab-hilos_moderation_kind'),
    )
    await expectPageReady(expect, page)
    await expect(page.getByTestId('hilos-moderation-kind-rules')).toHaveText(
      '1 rule of your own',
    )
  })

  await test.step('admin view mode keeps local drafts and blocks Save, Delete and form submission', async () => {
    const context = await browser.newContext({
      baseURL,
      ignoreHTTPSErrors: true,
    })
    try {
      await setAdminViewMode(true)
      const viewer = await context.newPage()
      const writes = ['add', 'update', 'delete'].map((action) =>
        countSentFrames(viewer, `hilos_moderation_rule_${action}`),
      )
      await gotoPage(
        expect,
        viewer,
        '/hilos/moderation/hilos_profile_name',
        PAGE_READY,
      )
      await expect(viewer.getByTestId('view-mode-banner')).toBeVisible()
      // The moderation catalog declares its model settings view-visible.
      await expect(
        viewer.getByTestId('hilos-moderation-kind-model'),
      ).toHaveText('Text model · qwen2.5:0.5b — profile hilos.moderation.text')
      await clickSubmit(
        expect,
        viewer.getByTestId('hilos-page-tab-hilos_moderation_kind_rules'),
      )
      await expectPageReady(expect, viewer)
      const viewerDialog = viewer.getByTestId('modal')
      const row = viewer
        .getByTestId('hilos-moderation-rules-table')
        .locator(ROWS)
        .filter({ hasText: ruleMarker })
      await expect(row).toContainText(edited)
      for (const mode of ['add', 'edit'] as const) {
        await clickSubmit(
          expect,
          mode === 'add'
            ? viewer.getByTestId('hilos-table-main-action')
            : row.getByTestId(/^hilos-moderation-rule-edit-open-/),
        )
        const field = viewer.getByTestId(`hilos-moderation-rule-${mode}-rule`)
        await typeInto(field, `${ruleMarker}: viewer draft`)
        await expect(
          viewer.getByTestId(`hilos-moderation-rule-${mode}-submit`),
        ).toBeDisabled()
        await field.evaluate((textarea) =>
          textarea.closest('form')!.requestSubmit(),
        )
        await expect(viewerDialog).toBeVisible()
        await expect(field).toHaveValue(`${ruleMarker}: viewer draft`)
        await clickSubmit(
          expect,
          viewer.getByTestId(`hilos-moderation-rule-${mode}-cancel`),
        )
        await clickSubmit(expect, viewer.getByTestId('modal-confirm-discard'))
        await expect(viewerDialog).toBeHidden()
      }
      await clickSubmit(
        expect,
        row.getByTestId(/^hilos-moderation-rule-delete-open-/),
      )
      await expect(
        viewer.getByTestId('hilos-moderation-rule-delete-rule'),
      ).toHaveText(edited)
      await expect(
        viewer.getByTestId('hilos-moderation-rule-delete-confirm'),
      ).toBeDisabled()
      await clickSubmit(
        expect,
        viewer.getByTestId('hilos-moderation-rule-delete-cancel'),
      )
      await expect(viewerDialog).toBeHidden()
      await viewer.reload()
      await expectPageReady(expect, viewer)
      await expect(row).toContainText(edited)
      expect(writes.map((count) => count())).toEqual([0, 0, 0])
    } finally {
      try {
        await context.close()
      } finally {
        await setAdminViewMode(false)
      }
    }
  })

  await test.step('Delete restores the effective built-in policy', async () => {
    await gotoPage(expect, page, RULES_PATH, PAGE_READY)
    await clickSubmit(
      expect,
      ownRow.getByTestId(/^hilos-moderation-rule-delete-open-/),
    )
    await expect(
      page.getByTestId('hilos-moderation-rule-delete-rule'),
    ).toHaveText(edited)
    await clickSubmit(
      expect,
      page.getByTestId('hilos-moderation-rule-delete-confirm'),
    )
    await expect(dialog).toBeHidden()
    await expect(table.getByTestId('hilos-table-count')).toHaveText(
      '0 – 0 of 0',
    )
    await expect(
      page.getByTestId('hilos-moderation-rules-built-in').locator('li'),
    ).toHaveText([...KINDS[0].defaults])
    await page.reload()
    await expectPageReady(expect, page)
    await expect(
      page.getByTestId('hilos-moderation-rules-built-in').locator('li'),
    ).toHaveText([...KINDS[0].defaults])
    await clickSubmit(
      expect,
      page.getByTestId('hilos-page-tab-hilos_moderation_kind'),
    )
    await expectPageReady(expect, page)
    await expect(page.getByTestId('hilos-moderation-kind-rules')).toHaveText(
      'Built-in until you add a rule of your own',
    )
  })
})
