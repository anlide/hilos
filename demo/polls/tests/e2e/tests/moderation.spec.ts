import { randomUUID } from 'node:crypto'
import { expect, test as base } from '../test.js'

import {
  addModerationRule,
  cleanupFixtures,
  clickSubmit,
  clickUnavailableControl,
  countSentFrames,
  deleteModerationRule,
  dismissToasts,
  editModerationRule,
  expectDataFreeDeparture,
  expectModerationBuiltInRules,
  expectModerationBuiltInRulesReturned,
  expectModerationDeleteEcho,
  expectModerationKindHead,
  expectModerationKindOverview,
  expectModerationOwnRules,
  expectModerationRemovalEcho,
  expectModerationRulesInForce,
  expectModerationRulesOpening,
  expectModerationSortReply,
  expectModerationUpdateEcho,
  expectPageReady,
  FRAMEWORK_MODERATION_KINDS,
  gotoPage,
  grantAdminToSelf,
  PAGE_REFUSED,
  reserveModerationRules,
  ROW_PREFIX,
  ROWS,
  shownByTestId,
  typeInto,
  watchClientDocument,
  watchDeliveryFrames,
  watchPageReplies,
  type ModerationKindDeclaration,
  type TestCleanup,
} from '../../../../../framework/frontend/e2e/index.js'
import { setAdminViewMode } from '../../../../../framework/frontend/scripts/adminViewMode.mjs'

// The Moderation section on Angular. Tests 1–5 carry the same titles and steps
// as chat (Vue) and tasks (React); the checks the three share are one copy in
// framework/frontend/e2e/moderation.ts, and this file keeps the scenario, its
// order and its navigation. Rules of our own are written only on
// hilos_profile_photo — the accessibility group of a11y.spec.ts holds a rule of
// its own on hilos_profile_name — and the walk of test 1 does not assert the
// state of any kind's rules.

const test = base.extend<{ cleanup: TestCleanup }>({
  cleanup: cleanupFixtures,
})

// One worker, file order: every test prepares its own rules, reserves their
// removal before Add and does not rely on a neighbor having passed.
test.describe.configure({ mode: 'default' })

// The photo kind is built-in on every kit, so it is the one rules are written on.
const PHOTO = FRAMEWORK_MODERATION_KINDS.find(
  ({ kind }) => kind === 'hilos_profile_photo',
)!
const NAME = FRAMEWORK_MODERATION_KINDS.find(
  ({ kind }) => kind === 'hilos_profile_name',
)!
const OVERVIEW_PATH = `/hilos/moderation/${PHOTO.kind}`
const RULES_PATH = `${OVERVIEW_PATH}/rules`
const VIEW_MODE_DESCRIPTION =
  'View mode · You can look around, but not change anything.'

// Polls declares no kind of its own: the section holds the framework's two.
const DECLARED_KINDS: readonly ModerationKindDeclaration[] =
  FRAMEWORK_MODERATION_KINDS

test('opens every declared kind from the dashboard through Overview and Rules, with search, cold entry, F5 and history', async ({
  page,
  cleanup,
}) => {
  // Proves: the routes, the page subscription by kind, the restore on F5 and
  // in history — what a unit test with a fake connection cannot see.
  await grantAdminToSelf(expect, page)
  await gotoPage(expect, page, '/hilos')
  const document = await watchClientDocument(page)
  cleanup.defer(() => document.dispose())
  const kinds = page.getByTestId('hilos-moderation-kinds-table')
  const rulesTable = page.getByTestId('hilos-moderation-rules-table')

  await test.step('The dashboard card opens the section with exactly the declared kinds', async () => {
    await clickSubmit(
      expect,
      page.getByTestId('dashboard-card-hilos_moderation'),
    )
    await expectPageReady(expect, page)
    await expect(page).toHaveURL('/hilos/moderation')
    await expect(kinds.locator(ROWS)).toHaveCount(DECLARED_KINDS.length)
    for (const { kind } of DECLARED_KINDS) {
      await expect(
        shownByTestId(kinds, `hilos-moderation-row-${kind}`),
      ).toBeVisible()
    }
    await document.unchanged()
  })

  await test.step('Search narrows the kinds, nonsense finds nothing, reset brings them all back', async () => {
    const search = shownByTestId(kinds, 'hilos-table-search')
    await typeInto(search, 'photo')
    await expect(kinds.locator(ROWS)).toHaveCount(1)
    await expect(
      shownByTestId(kinds, `hilos-moderation-row-${PHOTO.kind}`),
    ).toBeVisible()
    await typeInto(search, 'no_such_kind')
    await expect(shownByTestId(kinds, 'hilos-table-no-matches')).toContainText(
      'Nothing found',
    )
    await expect(kinds.locator(ROWS)).toHaveCount(0)
    await clickSubmit(
      expect,
      shownByTestId(kinds, 'hilos-table-no-matches-reset'),
    )
    await expect(search).toHaveValue('')
    await expect(kinds.locator(ROWS)).toHaveCount(DECLARED_KINDS.length)
  })

  for (const declared of DECLARED_KINDS) {
    await test.step(`Open ${declared.kind}: Overview, Rules, Overview, back to the section`, async () => {
      const open = shownByTestId(
        kinds,
        `hilos-moderation-open-${declared.kind}`,
      )
      await expect(open).toHaveAttribute(
        'href',
        `/hilos/moderation/${declared.kind}`,
      )
      await expect(open).toHaveAccessibleName(`Open ${declared.title}`)
      await clickSubmit(expect, open)
      await expectPageReady(expect, page)
      await expect(page).toHaveURL(`/hilos/moderation/${declared.kind}`)
      await expectModerationKindHead(expect, page, declared, 'overview')
      await expectModerationKindOverview(expect, page, declared)
      await document.unchanged()

      await clickSubmit(
        expect,
        page.getByTestId('hilos-page-tab-hilos_moderation_kind_rules'),
      )
      await expectPageReady(expect, page)
      await expect(page).toHaveURL(`/hilos/moderation/${declared.kind}/rules`)
      await expectModerationKindHead(expect, page, declared, 'rules')
      await expect(rulesTable).toBeVisible()
      await document.unchanged()

      await clickSubmit(
        expect,
        page.getByTestId('hilos-page-tab-hilos_moderation_kind'),
      )
      await expectPageReady(expect, page)
      await expect(page).toHaveURL(`/hilos/moderation/${declared.kind}`)
      await expectModerationKindHead(expect, page, declared, 'overview')
      await expect(rulesTable).toHaveCount(0)
      await document.unchanged()

      await clickSubmit(
        expect,
        page
          .getByTestId('hilos-breadcrumb')
          .getByTestId('hilos-breadcrumb-hilos_moderation'),
      )
      await expectPageReady(expect, page)
      await expect(page).toHaveURL('/hilos/moderation')
      await expect(kinds).toBeVisible()
      await document.unchanged()
    })
  }

  await test.step('Back and Forward between two kinds swap the data on the same screen', async () => {
    await page.goBack()
    await expectPageReady(expect, page)
    await expect(page).toHaveURL(`/hilos/moderation/${PHOTO.kind}`)
    await expectModerationKindHead(expect, page, PHOTO, 'overview')
    // Over the photo kind's Rules and Overview entries and the section visit
    // between the two kinds: the same detail screen must swap photo data for
    // name data, and back.
    await page.evaluate(() => window.history.go(-4))
    await expect(page).toHaveURL(`/hilos/moderation/${NAME.kind}`)
    await expectPageReady(expect, page)
    await expectModerationKindHead(expect, page, NAME, 'overview')
    await expectModerationKindOverview(expect, page, NAME)
    await document.unchanged()
    await page.evaluate(() => window.history.go(4))
    await expect(page).toHaveURL(`/hilos/moderation/${PHOTO.kind}`)
    await expectPageReady(expect, page)
    await expectModerationKindHead(expect, page, PHOTO, 'overview')
    await expectModerationKindOverview(expect, page, PHOTO)
    await document.unchanged()
  })

  await test.step('Cold entry and F5 on Overview and Rules answer with the same data', async () => {
    for (const declared of DECLARED_KINDS) {
      await gotoPage(expect, page, `/hilos/moderation/${declared.kind}`)
      await expectModerationKindHead(expect, page, declared, 'overview')
      await expectModerationKindOverview(expect, page, declared)
      await page.reload()
      await expectPageReady(expect, page)
      await expect(page).toHaveURL(`/hilos/moderation/${declared.kind}`)
      await expectModerationKindHead(expect, page, declared, 'overview')
      await expectModerationKindOverview(expect, page, declared)

      await gotoPage(expect, page, `/hilos/moderation/${declared.kind}/rules`)
      await expectModerationKindHead(expect, page, declared, 'rules')
      await expect(rulesTable).toBeVisible()
      await page.reload()
      await expectPageReady(expect, page)
      await expect(page).toHaveURL(`/hilos/moderation/${declared.kind}/rules`)
      await expectModerationKindHead(expect, page, declared, 'rules')
      await expect(rulesTable).toBeVisible()
    }
  })
})

test('refuses an unknown kind on Overview and Rules as 404 from the server subscription', async ({
  page,
}) => {
  // Proves: the refusal comes from the server's subscription, not from the
  // client's router — the one reply per page is the error itself.
  const observed = watchPageReplies(page)
  await grantAdminToSelf(expect, page)
  for (const [pageKey, path] of [
    ['hilos_moderation_kind', '/hilos/moderation/unknown_kind'],
    ['hilos_moderation_kind_rules', '/hilos/moderation/unknown_kind/rules'],
  ] as const) {
    await test.step(`${path} is refused`, async () => {
      await gotoPage(expect, page, path, PAGE_REFUSED)
      await expect(page).toHaveURL(path)
      const error = page.getByTestId('page-error')
      await expect(error).toHaveAttribute('data-error-code', '404')
      await expect(error).toContainText('Page Not Found')
      expect(
        observed.replies.filter((reply) => reply.page === pageKey),
      ).toEqual([
        expect.objectContaining({
          type: 'subscription_page_error',
          httpCode: 404,
        }),
      ])
      for (const absent of [
        'hilos-page-head-title',
        'hilos-page-tabs',
        'hilos-moderation-kind-owner',
        'hilos-moderation-kind-key',
        'hilos-moderation-kind-overview',
        'hilos-moderation-kind-loading',
        'hilos-moderation-rules-table',
        'hilos-moderation-rules-built-in',
        'hilos-moderation-rules-loading',
      ]) {
        await expect(page.getByTestId(absent)).toHaveCount(0)
      }
    })
  }
})

test('adds, finds, sorts, edits and deletes own rules until the built-in policy returns', async ({
  page,
  cleanup,
}) => {
  // Proves: the Rules page writes to the server and reads back what it wrote —
  // the one reply on a cold entry, the count, the search and the sort answered
  // by the server, the row that leaves without its data, and the built-in
  // policy that returns once the last own rule is gone.
  const marker = randomUUID()
  const firstRule = `Allow ordinary profile photos. Polls policy alpha ${marker}.`
  const secondRule = `Allow ordinary profile photos. Polls policy beta ${marker}.`
  const editedRule = `Allow ordinary profile photos and drawings. Polls policy alpha ${marker}.`
  reserveModerationRules(expect, page, cleanup, PHOTO, [
    firstRule,
    secondRule,
    editedRule,
  ])
  const adds = countSentFrames(page, 'hilos_moderation_rule_add')
  const updates = countSentFrames(page, 'hilos_moderation_rule_update')
  const deletes = countSentFrames(page, 'hilos_moderation_rule_delete')
  await grantAdminToSelf(expect, page)
  const log = watchDeliveryFrames(page)
  cleanup.defer(() => log.stop())
  const table = page.getByTestId('hilos-moderation-rules-table')
  const overviewTab = page.getByTestId('hilos-page-tab-hilos_moderation_kind')
  const rulesTab = page.getByTestId(
    'hilos-page-tab-hilos_moderation_kind_rules',
  )
  let firstId: number
  let secondId: number

  await test.step('A cold entry shows the built-in rules from one reply; Overview names them', async () => {
    await gotoPage(expect, page, RULES_PATH)
    await expectModerationKindHead(expect, page, PHOTO, 'rules')
    await expectModerationBuiltInRules(expect, page, PHOTO)
    await expectModerationRulesOpening(expect, log, { ...PHOTO, ownRules: [] })
    await clickSubmit(expect, overviewTab)
    await expectPageReady(expect, page)
    await expectModerationRulesInForce(expect, page, 0)
    await clickSubmit(expect, rulesTab)
    await expectPageReady(expect, page)
    await expectModerationKindHead(expect, page, PHOTO, 'rules')
  })

  await test.step('Add two rules: their rows, no built-ins, the count; Overview counts them', async () => {
    firstId = await addModerationRule(expect, page, PHOTO, firstRule)
    secondId = await addModerationRule(expect, page, PHOTO, secondRule)
    await expectModerationOwnRules(expect, page, [firstRule, secondRule])
    expect(adds()).toBe(2)
    await clickSubmit(expect, overviewTab)
    await expectPageReady(expect, page)
    await expectModerationRulesInForce(expect, page, 2)
    await clickSubmit(expect, rulesTab)
    await expectPageReady(expect, page)
    await expectModerationOwnRules(expect, page, [firstRule, secondRule])
  })

  await test.step('Search narrows to one row, nonsense finds nothing, reset restores both', async () => {
    const search = shownByTestId(table, 'hilos-table-search')
    await typeInto(search, 'policy beta')
    await expectModerationOwnRules(expect, page, [secondRule])
    await typeInto(search, 'no_such_own_rule')
    await expect(shownByTestId(table, 'hilos-table-no-matches')).toContainText(
      'Nothing found',
    )
    await expect(
      shownByTestId(table, 'hilos-table-no-matches-terms'),
    ).toHaveText('No rows match “no_such_own_rule”')
    await expect(
      page.getByTestId('hilos-moderation-rules-built-in'),
    ).toHaveCount(0)
    await expect(table.locator(ROWS)).toHaveCount(0)
    await clickSubmit(
      expect,
      shownByTestId(table, 'hilos-table-no-matches-reset'),
    )
    await expect(search).toHaveValue('')
    await expectModerationOwnRules(expect, page, [firstRule, secondRule])
  })

  await test.step('Sort by Rule: ascending answered by the server, descending reversed, none restored', async () => {
    const sort = table.getByTestId('hilos-table-sort-rule')
    const header = table.locator('th:has([data-id="hilos-table-sort-rule"])')
    await expect(header).toHaveAttribute('aria-sort', 'none')
    const sentBefore = log.sent.length
    await clickSubmit(expect, sort)
    await expect(header).toHaveAttribute('aria-sort', 'ascending')
    await expectModerationOwnRules(expect, page, [firstRule, secondRule])
    // Ascending matches the insertion order, so DOM text alone cannot prove
    // its reply: the one viewport request and its one answer do.
    const ascending = await expectModerationSortReply(
      expect,
      log,
      sentBefore,
      PHOTO,
      'asc',
    )
    await clickSubmit(expect, sort)
    await expect(header).toHaveAttribute('aria-sort', 'descending')
    await expectModerationOwnRules(expect, page, [secondRule, firstRule])
    await ascending.answeredOnce()
    await clickSubmit(expect, sort)
    await expect(header).toHaveAttribute('aria-sort', 'none')
    await expectModerationOwnRules(expect, page, [firstRule, secondRule])
  })

  await test.step('Edit the first rule; F5 reads both from one reply', async () => {
    await editModerationRule(expect, page, PHOTO, firstId, editedRule)
    await expectModerationOwnRules(expect, page, [editedRule, secondRule])
    expect(updates()).toBe(1)
    await page.reload()
    await expectPageReady(expect, page)
    await expectModerationOwnRules(expect, page, [editedRule, secondRule])
    await expectModerationRulesOpening(expect, log, {
      ...PHOTO,
      ownRules: [editedRule, secondRule],
    })
  })

  await test.step('Delete one rule: its row leaves without data; delete the last: the built-in policy returns', async () => {
    await deleteModerationRule(expect, page, secondId, secondRule)
    await expect(
      table.locator(ROWS).locator('.hilos-table-text[id]'),
    ).toHaveText([editedRule])
    await expect(
      page.getByTestId('hilos-moderation-rules-built-in'),
    ).toHaveCount(0)
    expect(deletes()).toBe(1)

    await deleteModerationRule(expect, page, firstId, editedRule)
    await expectModerationBuiltInRulesReturned(expect, page, PHOTO)
    expect(deletes()).toBe(2)
    await clickSubmit(expect, overviewTab)
    await expectPageReady(expect, page)
    await expectModerationRulesInForce(expect, page, 0)
    await clickSubmit(expect, rulesTab)
    await expectPageReady(expect, page)
    await page.reload()
    await expectPageReady(expect, page)
    await expectModerationKindHead(expect, page, PHOTO, 'rules')
    await expectModerationBuiltInRules(expect, page, PHOTO)
    await expectModerationRulesOpening(expect, log, { ...PHOTO, ownRules: [] })
  })
})

test('resolves an Edit conflict from a second tab and shows a Delete there live through Apply', async ({
  page,
  cleanup,
}) => {
  // Proves: the User-scope fan-out between two tabs of one administrator — the
  // conflict the first tab is shown, the frames each tab sends and gets back,
  // the removal the second tab holds until Apply — none of which one fake
  // connection can show.
  const marker = randomUUID()
  const initialRule = `Allow ordinary profile photos. Polls conflict ${marker}.`
  const otherRule = `Allow ordinary profile photos and drawings. Another tab saved this ${marker}.`
  const mineRule = `Allow ordinary profile photos. Edited here ${marker}.`
  reserveModerationRules(expect, page, cleanup, PHOTO, [
    initialRule,
    otherRule,
    mineRule,
  ])
  const updates = countSentFrames(page, 'hilos_moderation_rule_update')
  const deletes = countSentFrames(page, 'hilos_moderation_rule_delete')
  await grantAdminToSelf(expect, page)
  const own = watchDeliveryFrames(page)
  cleanup.defer(() => own.stop())
  await gotoPage(expect, page, RULES_PATH)
  const id = await addModerationRule(expect, page, PHOTO, initialRule)
  await expectModerationOwnRules(expect, page, [initialRule])

  const editor = await page.context().newPage()
  cleanup.defer(() => editor.close())
  const foreign = watchDeliveryFrames(editor)
  cleanup.defer(() => foreign.stop())
  const otherUpdates = countSentFrames(editor, 'hilos_moderation_rule_update')
  await gotoPage(expect, editor, RULES_PATH)
  await expectModerationOwnRules(expect, editor, [initialRule])
  await expectModerationRulesOpening(expect, foreign, {
    ...PHOTO,
    ownRules: [initialRule],
  })
  const dialog = page.getByTestId('modal')
  const ownBefore = own.sent.length
  const foreignBefore = foreign.sent.length

  await test.step('The second tab saves first; the first tab is shown the conflict and accepts its own text', async () => {
    await dismissToasts(page)
    const field = page.getByTestId('hilos-moderation-rule-edit-rule')
    const save = page.getByTestId('hilos-moderation-rule-edit-submit')
    await clickSubmit(
      expect,
      shownByTestId(page, `hilos-moderation-rule-edit-open-${id}`),
    )
    await expect(dialog).toHaveAccessibleName(`Edit rule · ${PHOTO.title}`)
    await expect(field).toHaveValue(initialRule)
    await typeInto(field, mineRule)
    await expect(save).toBeEnabled()
    expect(updates()).toBe(0)

    await editModerationRule(expect, editor, PHOTO, id, otherRule)
    expect(otherUpdates()).toBe(1)
    await expect(page.getByTestId('conflict-choices')).toBeVisible()
    await expect(
      page.getByTestId('hilos-moderation-rule-edit-notice'),
    ).toHaveText(`Rule changed elsewhere to "${otherRule}".`)
    await expect(field).toHaveValue(mineRule)
    await expect(save).toBeDisabled()
    expect(updates()).toBe(0)
    await expectModerationOwnRules(expect, page, [otherRule])
    await expectModerationOwnRules(expect, editor, [otherRule])

    await clickSubmit(expect, page.getByTestId('conflict-accept-mine'))
    await expect(page.getByTestId('conflict-choices')).toHaveCount(0)
    await expect(
      page.getByTestId('hilos-moderation-rule-edit-notice'),
    ).toHaveCount(0)
    await expect(field).toHaveValue(mineRule)
    await clickSubmit(expect, save)
    // Closing follows this tab's correlated acknowledgement, before table checks.
    await expect(dialog).toBeHidden()
    expect(updates()).toBe(1)
    expect(otherUpdates()).toBe(1)
    await expectModerationOwnRules(expect, page, [mineRule])
    await expectModerationOwnRules(expect, editor, [mineRule])
  })

  await test.step('Each tab sent one update with its expectedRule and got its action_success; no refetch, no reconnect', async () => {
    for (const [log, after, expectedRule, savedRule] of [
      [foreign, foreignBefore, initialRule, otherRule],
      [own, ownBefore, otherRule, mineRule],
    ] as const) {
      await expectModerationUpdateEcho(expect, log, after, PHOTO, {
        id,
        rule: savedRule,
        expectedRule,
      })
    }
  })

  await test.step('Delete in the first tab: the second holds the removal until Apply; row_removed is own only for the author', async () => {
    await dismissToasts(page)
    await dismissToasts(editor)
    const ownBefore = own.sent.length
    const foreignBefore = foreign.sent.length
    const openerId = `hilos-moderation-rule-delete-open-${id}`
    const retired = [`hilos-moderation-rule-edit-open-${id}`, openerId]
    await expect(shownByTestId(editor, openerId)).toBeVisible()
    await clickSubmit(expect, shownByTestId(page, openerId))
    await expect(dialog).toHaveAccessibleName('Delete rule')
    await expect(
      page.getByTestId('hilos-moderation-rule-delete-rule'),
    ).toHaveText(mineRule)
    await clickSubmit(
      expect,
      page.getByTestId('hilos-moderation-rule-delete-confirm'),
    )
    // The tracked acknowledgement closes the modal before we inspect the echo.
    await expect(dialog).toBeHidden()
    expect(deletes()).toBe(1)
    await expectModerationDeleteEcho(expect, own, ownBefore, PHOTO, {
      id,
      expectedRule: mineRule,
    })
    await expectDataFreeDeparture(expect, page, String(id), 'Removed', retired)
    await expectModerationBuiltInRulesReturned(expect, page, PHOTO)

    await expect(
      shownByTestId(editor, `hilos-table-pending-remove-${id}`),
    ).toBeVisible()
    await expect(editor.getByTestId(`${ROW_PREFIX}${id}`)).toContainText(
      mineRule,
    )
    await clickSubmit(expect, editor.getByTestId('hilos-table-apply'))
    await expect(editor.getByTestId('hilos-table-apply')).toHaveCount(0)
    await expectDataFreeDeparture(
      expect,
      editor,
      String(id),
      'Removed',
      retired,
    )
    await expectModerationBuiltInRulesReturned(expect, editor, PHOTO)

    // Neither the own echo nor Apply may borrow a later fetched window.
    for (const [log, after, isOwn] of [
      [own, ownBefore, true],
      [foreign, foreignBefore, false],
    ] as const) {
      await expectModerationRemovalEcho(expect, log, after, id, isOwn)
    }
  })
})

test('lets an admin view-mode viewer read Moderation and draft rule forms without writing', async ({
  page,
  browser,
  cleanup,
}) => {
  // Proves: the view mode's promise on this section — a viewer in a context of
  // their own reads the kinds, the catalog's model value and the rule, drafts
  // every form, and no write frame leaves their page.
  const marker = randomUUID()
  const rule = `Allow ordinary profile photos. Viewer reads this ${marker}.`
  // The draft is reserved too: were a submit to slip through, it is what the
  // Add would create and the Edit would overwrite the rule with.
  const draft = `Viewer draft only ${marker}.`
  reserveModerationRules(expect, page, cleanup, PHOTO, [rule, draft])
  await grantAdminToSelf(expect, page)
  await gotoPage(expect, page, RULES_PATH)
  const id = await addModerationRule(expect, page, PHOTO, rule)
  await expectModerationOwnRules(expect, page, [rule])

  cleanup.defer(() => setAdminViewMode(false))
  await setAdminViewMode(true)
  const { baseURL, ignoreHTTPSErrors } = test.info().project.use
  const context = await browser.newContext({ baseURL, ignoreHTTPSErrors })
  cleanup.defer(() => context.close())
  const viewer = await context.newPage()
  const writes = ['add', 'update', 'delete'].map((action) =>
    countSentFrames(viewer, `hilos_moderation_rule_${action}`),
  )
  const sentWrites = (): number[] => writes.map((sent) => sent())
  const banner = viewer.getByTestId('view-mode-banner')
  const viewerTable = viewer.getByTestId('hilos-moderation-rules-table')

  await test.step('The viewer reads the kinds, the Overview with its model value and the Rules with the rule', async () => {
    await gotoPage(expect, viewer, '/hilos/moderation')
    await expect(banner).toBeVisible()
    const kinds = viewer.getByTestId('hilos-moderation-kinds-table')
    for (const { kind } of DECLARED_KINDS) {
      await expect(
        shownByTestId(kinds, `hilos-moderation-row-${kind}`),
      ).toBeVisible()
    }
    await clickSubmit(
      expect,
      shownByTestId(kinds, `hilos-moderation-open-${PHOTO.kind}`),
    )
    await expectPageReady(expect, viewer)
    await expect(viewer).toHaveURL(OVERVIEW_PATH)
    await expect(banner).toBeVisible()
    await expectModerationKindHead(expect, viewer, PHOTO, 'overview')
    // The catalog opens the model setting to a viewer: its value is read, not hidden.
    await expectModerationKindOverview(expect, viewer, PHOTO)
    await expect(
      viewer.getByTestId('hilos-admin-page').getByTestId('hilos-hidden'),
    ).toHaveCount(0)
    await expectModerationRulesInForce(expect, viewer, 1)

    await clickSubmit(
      expect,
      viewer.getByTestId('hilos-page-tab-hilos_moderation_kind_rules'),
    )
    await expectPageReady(expect, viewer)
    await expectModerationKindHead(expect, viewer, PHOTO, 'rules')
    await expect(banner).toBeVisible()
    await expectModerationOwnRules(expect, viewer, [rule])
    const search = shownByTestId(viewerTable, 'hilos-table-search')
    await typeInto(search, marker)
    await expectModerationOwnRules(expect, viewer, [rule])
    await typeInto(search, 'no_such_own_rule')
    await expect(
      shownByTestId(viewerTable, 'hilos-table-no-matches-terms'),
    ).toHaveText('No rows match “no_such_own_rule”')
    await clickSubmit(
      expect,
      shownByTestId(viewerTable, 'hilos-table-no-matches-reset'),
    )
    await expect(search).toHaveValue('')
    await expectModerationOwnRules(expect, viewer, [rule])
  })

  await test.step('Add, Edit and Delete keep their drafts, refuse to submit and send nothing', async () => {
    const dialog = viewer.getByTestId('modal')
    for (const action of ['add', 'edit', 'delete'] as const) {
      await clickSubmit(
        expect,
        action === 'add'
          ? viewerTable.getByTestId('hilos-table-main-action')
          : shownByTestId(viewer, `hilos-moderation-rule-${action}-open-${id}`),
      )
      const submit = viewer.getByTestId(
        `hilos-moderation-rule-${action}-${action === 'delete' ? 'confirm' : 'submit'}`,
      )
      if (action === 'delete') {
        await expect(dialog).toHaveAccessibleName('Delete rule')
        await expect(
          viewer.getByTestId('hilos-moderation-rule-delete-rule'),
        ).toHaveText(rule)
        await clickUnavailableControl(viewer, submit)
      } else {
        await expect(dialog).toHaveAccessibleName(
          `${action === 'add' ? 'Add' : 'Edit'} rule · ${PHOTO.title}`,
        )
        const field = viewer.getByTestId(`hilos-moderation-rule-${action}-rule`)
        await expect(field).toHaveValue(action === 'add' ? '' : rule)
        await typeInto(field, draft)
        await clickUnavailableControl(viewer, submit)
        // A forged submit event of the form itself sends nothing either.
        await field.evaluate((textarea) => {
          textarea
            .closest('form')!
            .dispatchEvent(
              new Event('submit', { bubbles: true, cancelable: true }),
            )
        })
        await expect(dialog).toBeVisible()
        await expect(field).toHaveValue(draft)
        await expect(submit).not.toHaveAttribute('aria-busy', 'true')
      }
      await expect(submit).toBeDisabled()
      await expect(submit).toHaveAccessibleDescription(VIEW_MODE_DESCRIPTION)
      expect(sentWrites()).toEqual([0, 0, 0])
      await clickSubmit(
        expect,
        viewer.getByTestId(`hilos-moderation-rule-${action}-cancel`),
      )
      if (action !== 'delete') {
        await clickSubmit(expect, viewer.getByTestId('modal-confirm-discard'))
      }
      await expect(dialog).toBeHidden()
    }
    await expect(viewer.getByTestId('hilos-toast-success')).toHaveCount(0)
    await expectModerationOwnRules(expect, viewer, [rule])
    await viewer.reload()
    await expectPageReady(expect, viewer)
    await expectModerationOwnRules(expect, viewer, [rule])
    expect(sentWrites()).toEqual([0, 0, 0])
  })
})
