import { randomUUID } from 'node:crypto'
import { expect, test as base } from '@playwright/test'
import {
  armViewerSubmit,
  clickSubmit,
  cleanupFixtures,
  type TestCleanup,
  countSentFrames,
  deliveryObject,
  deleteModerationOwnRules,
  dismissToasts,
  expectDataFreeDeparture,
  expectModerationOwnRules,
  expectModerationRulesOpening,
  expectPageReady,
  gotoPage,
  grantAdminToSelf,
  ROWS,
  shownByTestId,
  typeInto,
  watchDeliveryFrames,
} from '../../../../../framework/frontend/e2e/index.js'
import { setAdminViewMode } from '../../../../../framework/frontend/scripts/adminViewMode.mjs'

// spec-owner: demo — Tasks' React outlets connect the moderation pages and forms to the backend.

const KIND = 'hilos_profile_name'
const RULES_PATH = `/hilos/moderation/${KIND}/rules`
const DEFAULT_RULES = [
  'Default policy: allow benign display names.',
  'Block explicit insults, threats, hate speech, sexual content, spam, and impersonation.',
  'If uncertain, return allow=true.',
]

// Reserve every possible persisted text before Add; teardown owns only these
// UUID rules, including the close raw neighbor used by the cleanup regression.
const test = base.extend<{
  cleanup: TestCleanup
  ownedRules: readonly string[]
}>({
  cleanup: cleanupFixtures,
  ownedRules: async ({ page, cleanup }, use) => {
    const marker = randomUUID()
    const exact = `Allow names. Tasks cleanup ${marker}.`
    const texts = [
      `Allow ordinary names. Tasks policy ${marker}.`,
      `Allow ordinary names and initials. Tasks policy ${marker}.`,
      exact,
      ` ${exact} `,
    ]
    for (const text of texts)
      cleanup.defer(async () => {
        await gotoPage(expect, page, RULES_PATH)
        await dismissToasts(page)
        await deleteModerationOwnRules(expect, page, [text])
      })
    await grantAdminToSelf(expect, page)
    await use(texts)
  },
})

test('an administrator saves, reloads and deletes a rule while a viewer can only inspect it', async ({
  page,
  browser,
  ownedRules,
  cleanup,
}) => {
  const [initialRule, editedRule] = ownedRules
  const wire = watchDeliveryFrames(page)
  cleanup.defer(() =>
    test.info().attach('moderation-browser-wire', {
      body: JSON.stringify(wire, null, 2),
      contentType: 'application/json',
    }),
  )
  cleanup.defer(() => wire.stop())
  const adds = countSentFrames(page, 'hilos_moderation_rule_add')
  const updates = countSentFrames(page, 'hilos_moderation_rule_update')
  const deletes = countSentFrames(page, 'hilos_moderation_rule_delete')
  const table = page.getByTestId('hilos-moderation-rules-table')
  const dialog = page.getByTestId('modal')
  let id: number
  await test.step('The dashboard card opens the declared kind and its Rules', async () => {
    await gotoPage(expect, page, '/hilos')
    await clickSubmit(
      expect,
      page.getByTestId('dashboard-card-hilos_moderation'),
    )
    await expectPageReady(expect, page)
    await expect(page).toHaveURL('/hilos/moderation')
    const kinds = page.getByTestId('hilos-moderation-kinds-table')
    await expect(kinds.locator(ROWS)).toHaveCount(2)
    for (const kind of [KIND, 'hilos_profile_photo']) {
      await expect(
        shownByTestId(kinds, `hilos-moderation-row-${kind}`),
      ).toBeVisible()
    }
    await clickSubmit(
      expect,
      shownByTestId(kinds, `hilos-moderation-open-${KIND}`),
    )
    await expectPageReady(expect, page)
    await expect(page).toHaveURL(`/hilos/moderation/${KIND}`)
    await expect(page.getByRole('heading', { level: 1 })).toHaveText(
      'Profile name',
    )
    await expect(page.getByTestId('hilos-moderation-kind-key')).toHaveText(KIND)
    await clickSubmit(
      expect,
      page.getByTestId('hilos-page-tab-hilos_moderation_kind_rules'),
    )
    await expectPageReady(expect, page)
    await expect(page).toHaveURL(RULES_PATH)
    await expect(
      page.getByTestId('hilos-moderation-rules-built-in').locator('li'),
    ).toHaveText(DEFAULT_RULES)
    await expectModerationRulesOpening(expect, wire, {
      kind: KIND,
      ownRules: [],
      defaultRules: DEFAULT_RULES,
    })
  })

  await test.step('Add persists the rule and replaces the built-in policy', async () => {
    await clickSubmit(expect, table.getByTestId('hilos-table-main-action'))
    await expect(dialog).toHaveAccessibleName('Add rule · Profile name')
    await typeInto(
      page.getByTestId('hilos-moderation-rule-add-rule'),
      initialRule,
    )
    await clickSubmit(
      expect,
      page.getByTestId('hilos-moderation-rule-add-submit'),
    )
    await expect(dialog).toBeHidden()
    await expectModerationOwnRules(expect, page, [initialRule])
    expect(adds()).toBe(1)
    const row = table.locator(ROWS)
    const opener = row.getByTestId(/^hilos-moderation-rule-edit-open-/)
    id = Number(
      (await opener.getAttribute('data-id'))!.replace(
        'hilos-moderation-rule-edit-open-',
        '',
      ),
    )
    expect(Number.isSafeInteger(id) && id > 0).toBe(true)
  })

  await test.step('Edit persists the new text and F5 reads it from the first reply', async () => {
    await dismissToasts(page)
    await clickSubmit(
      expect,
      shownByTestId(table, `hilos-moderation-rule-edit-open-${id}`),
    )
    await expect(dialog).toHaveAccessibleName('Edit rule · Profile name')
    await expect(
      page.getByTestId('hilos-moderation-rule-edit-rule'),
    ).toHaveValue(initialRule)
    await typeInto(
      page.getByTestId('hilos-moderation-rule-edit-rule'),
      editedRule,
    )
    await clickSubmit(
      expect,
      page.getByTestId('hilos-moderation-rule-edit-submit'),
    )
    await expect(dialog).toBeHidden()
    await expectModerationOwnRules(expect, page, [editedRule])
    expect(updates()).toBe(1)
    await page.reload()
    await expectPageReady(expect, page)
    await expectModerationOwnRules(expect, page, [editedRule])
    await expectModerationRulesOpening(expect, wire, {
      kind: KIND,
      ownRules: [editedRule],
      defaultRules: DEFAULT_RULES,
    })
  })

  await test.step('A separate viewer drafts forms but cannot submit or forge a write', async () => {
    const { baseURL, ignoreHTTPSErrors } = test.info().project.use
    const context = await browser.newContext({ baseURL, ignoreHTTPSErrors })
    cleanup.defer(() => context.close())
    cleanup.defer(() => setAdminViewMode(false))
    await setAdminViewMode(true)
    const viewer = await context.newPage()
    const forged = await armViewerSubmit(viewer)
    cleanup.defer(() => forged.dispose())
    const writes = ['add', 'update', 'delete'].map((action) =>
      countSentFrames(viewer, `hilos_moderation_rule_${action}`),
    )
    await gotoPage(expect, viewer, RULES_PATH)
    await expect(viewer.getByTestId('view-mode-banner')).toBeVisible()
    await expectModerationOwnRules(expect, viewer, [editedRule])
    for (const action of ['add', 'edit', 'delete'] as const) {
      await clickSubmit(
        expect,
        shownByTestId(
          viewer,
          action === 'add'
            ? 'hilos-table-main-action'
            : `hilos-moderation-rule-${action}-open-${id}`,
        ),
      )
      if (action === 'delete') {
        await expect(
          viewer.getByTestId('hilos-moderation-rule-delete-rule'),
        ).toHaveText(editedRule)
      } else {
        const field = viewer.getByTestId(`hilos-moderation-rule-${action}-rule`)
        await typeInto(field, 'Viewer draft only')
        await field.evaluate((element) => {
          element
            .closest('form')!
            .dispatchEvent(
              new Event('submit', { bubbles: true, cancelable: true }),
            )
        })
        await expect(field).toHaveValue('Viewer draft only')
      }
      const save = viewer.getByTestId(
        `hilos-moderation-rule-${action}-${action === 'delete' ? 'confirm' : 'submit'}`,
      )
      await expect(save).toBeDisabled()
      expect(writes.map((sent) => sent())).toEqual([0, 0, 0])
      await clickSubmit(
        expect,
        viewer.getByTestId(`hilos-moderation-rule-${action}-cancel`),
      )
      if (action !== 'delete')
        await clickSubmit(expect, viewer.getByTestId('modal-confirm-discard'))
      await expect(viewer.getByTestId('modal')).toBeHidden()
    }
    for (const action of ['add', 'update', 'delete']) {
      const requestId = `viewer-${action}-${id}`
      const reply = await forged.submit(
        JSON.stringify({
          type: 'action',
          action: `hilos_moderation_rule_${action}`,
          data: {
            kind: KIND,
            id,
            rule: 'Viewer forged rule',
            expectedRule: editedRule,
          },
          requestId,
        }),
        requestId,
      )
      expect(reply.failure).toBeUndefined()
      const frame = deliveryObject(reply.wire)
      expect(frame.type).toBe('action_error')
      expect(deliveryObject(frame.data)).toMatchObject({
        errorCode: 'view_mode',
        reason: 'View mode: you can look around, but not change anything.',
      })
    }
    await viewer.reload()
    await expectPageReady(expect, viewer)
    await expectModerationOwnRules(expect, viewer, [editedRule])
  })

  await test.step('Deleting the last own rule restores the built-in policy', async () => {
    await dismissToasts(page)
    await clickSubmit(
      expect,
      shownByTestId(table, `hilos-moderation-rule-delete-open-${id}`),
    )
    await expect(
      page.getByTestId('hilos-moderation-rule-delete-rule'),
    ).toHaveText(editedRule)
    await clickSubmit(
      expect,
      page.getByTestId('hilos-moderation-rule-delete-confirm'),
    )
    await expect(dialog).toBeHidden()
    await expectDataFreeDeparture(expect, page, String(id), 'Removed', [
      `hilos-moderation-rule-edit-open-${id}`,
      `hilos-moderation-rule-delete-open-${id}`,
    ])
    await expect(
      page.getByTestId('hilos-moderation-rules-built-in').locator('li'),
    ).toHaveText(DEFAULT_RULES)
    expect(deletes()).toBe(1)
    await page.reload()
    await expectPageReady(expect, page)
    await expect(table.locator(ROWS)).toHaveCount(0)
    await expectModerationRulesOpening(expect, wire, {
      kind: KIND,
      ownRules: [],
      defaultRules: DEFAULT_RULES,
    })
  })
  await test.step('Cleanup preserves a raw neighbor when the reserved rule is absent or present', async () => {
    const [, , exact, neighbor] = ownedRules
    for (const rule of [neighbor, exact]) {
      await dismissToasts(page)
      await clickSubmit(expect, table.getByTestId('hilos-table-main-action'))
      await typeInto(page.getByTestId('hilos-moderation-rule-add-rule'), rule)
      await clickSubmit(
        expect,
        page.getByTestId('hilos-moderation-rule-add-submit'),
      )
      await expect(dialog).toBeHidden()
      await expect
        .poll(() =>
          table
            .locator(ROWS)
            .locator('.hilos-table-text[id]')
            .allTextContents(),
        )
        .toEqual(rule === neighbor ? [neighbor] : [neighbor, exact])
      await dismissToasts(page)
      await deleteModerationOwnRules(expect, page, [exact])
      await expect
        .poll(() =>
          table
            .locator(ROWS)
            .locator('.hilos-table-text[id]')
            .allTextContents(),
        )
        .toEqual([neighbor])
      expect(deletes()).toBe(rule === neighbor ? 1 : 2)
    }
  })
})
