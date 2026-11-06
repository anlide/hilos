import { randomUUID } from 'node:crypto'
import { expect, test as base } from '../test.js'

import {
  addModerationRule,
  cleanupFixtures,
  clickSubmit,
  clickUnavailableControl,
  controlSocketReconnect,
  countSentFrames,
  createTestAdmin,
  deleteModerationRule,
  deliveryObject,
  dismissToasts,
  editModerationRule,
  expectDataFreeDeparture,
  expectModerationBuiltInRules,
  expectModerationBuiltInRulesAfterDelete,
  expectModerationDeleteEcho,
  expectModerationKindHead,
  expectModerationKindOverview,
  expectModerationOwnRules,
  expectModerationRemovalEcho,
  expectModerationRuleShown,
  expectModerationRulesInForce,
  expectModerationRulesOpening,
  expectModerationSortReply,
  expectModerationUpdateEcho,
  expectPageReady,
  expectWelcomedSockets,
  FRAMEWORK_MODERATION_KINDS,
  gotoPage,
  grantAdminToSelf,
  openAndSelectPhoto,
  PAGE_REFUSED,
  reserveModerationRules,
  ROW_PREFIX,
  ROWS,
  shownByTestId,
  typeInto,
  watchClientDocument,
  watchDeliveryFrames,
  watchOpenedSockets,
  watchPageReplies,
  type ModerationKindDeclaration,
  type TestCleanup,
} from '../../../../../framework/frontend/e2e/index.js'
import { HilosPages } from '../../../../../framework/frontend/core/src/routing/hilosPages.js'
import { SIGNAL_HANDSHAKE_RESPONSE } from '../../../../../framework/frontend/core/src/session/sessionScope.js'
import { setAdminViewMode } from '../../../../../framework/frontend/scripts/adminViewMode.mjs'
import {
  dictateModerationVerdict,
  forgetModelKey,
  modelKey,
  readModelRequests,
} from '../../../../../framework/frontend/scripts/standModel.mjs'
import { picture } from '../helpers/profilePhoto.js'

// spec-owner: demo — carries Chat's Vue browser journeys for these shared pages

// The Moderation section on Vue. Tests 1–5 carry the same titles and steps as
// tasks (React) and polls (Angular); the checks the three share are one copy in
// framework/frontend/e2e/moderation.ts, and this file keeps the scenario, its
// order and its navigation. Test 6 is chat's own proof: the rule saved on the
// Rules page is what the model's next photo check carries. Rules of our own are
// written only on hilos_profile_photo — seed 006_framework_moderation_rules
// holds rules of its own on hilos_profile_name and chat_message — and the walk
// of test 1 does not assert the state of any kind's rules.

const test = base.extend<{ cleanup: TestCleanup }>({
  cleanup: cleanupFixtures,
})

test.use({ viewport: { width: 1440, height: 1000 }, colorScheme: 'light' })
// One worker, file order: every test prepares its own rules, reserves their
// removal before Add and does not rely on a neighbor having passed.
test.describe.configure({ mode: 'default' })

// The photo kind is built-in on every kit, so it is the one rules are written on.
const PHOTO = FRAMEWORK_MODERATION_KINDS.find(
  ({ kind }) => kind === 'hilos_profile_photo',
)!
const OVERVIEW_PATH = `/hilos/moderation/kind/${PHOTO.kind}`
const RULES_PATH = `${OVERVIEW_PATH}/rules`
const VIEW_MODE_DESCRIPTION =
  'View mode · You can look around, but not change anything.'

// The two kinds the chat declares on top of the framework's.
const CHAT_MESSAGE: ModerationKindDeclaration = {
  kind: 'chat_message',
  title: 'Chat message',
  owner: 'Project',
  modelLabel: 'Text model',
  model: 'qwen2.5:0.5b',
  profile: 'hilos.moderation.text',
  reasons: ['insult', 'threat', 'hate_speech', 'sexual', 'spam'],
  defaultRules: [
    'Default policy: allow benign messages.',
    'Block only explicit insults, threats, hate speech, sexual content, and obvious spam.',
    'If uncertain, return allow=true.',
  ],
}
const CHAT_MESSAGE_PICTURE: ModerationKindDeclaration = {
  kind: 'chat_message_picture',
  title: 'Chat message picture',
  owner: 'Project',
  modelLabel: 'Vision model',
  model: 'qwen2.5vl:3b',
  profile: 'hilos.moderation.image',
  reasons: ['nudity', 'violence', 'hate'],
  defaultRules: [
    'Default policy: allow ordinary pictures, screenshots and drawings.',
    'Block only nudity or sexual content, graphic violence, and hate symbols.',
    'If uncertain, return allow=true.',
  ],
}
const DECLARED_KINDS: readonly ModerationKindDeclaration[] = [
  ...FRAMEWORK_MODERATION_KINDS,
  CHAT_MESSAGE,
  CHAT_MESSAGE_PICTURE,
]

test.afterEach(async () => {
  await setAdminViewMode(false)
})

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
        `/hilos/moderation/kind/${declared.kind}`,
      )
      await expect(open).toHaveAccessibleName(`Open ${declared.title}`)
      await clickSubmit(expect, open)
      await expectPageReady(expect, page)
      await expect(page).toHaveURL(`/hilos/moderation/kind/${declared.kind}`)
      await expectModerationKindHead(expect, page, declared, 'overview')
      await expectModerationKindOverview(expect, page, declared)
      await document.unchanged()

      await clickSubmit(
        expect,
        page.getByTestId('hilos-page-tab-hilos_moderation_kind_rules'),
      )
      await expectPageReady(expect, page)
      await expect(page).toHaveURL(
        `/hilos/moderation/kind/${declared.kind}/rules`,
      )
      await expectModerationKindHead(expect, page, declared, 'rules')
      await expect(rulesTable).toBeVisible()
      await document.unchanged()

      await clickSubmit(
        expect,
        page.getByTestId('hilos-page-tab-hilos_moderation_kind'),
      )
      await expectPageReady(expect, page)
      await expect(page).toHaveURL(`/hilos/moderation/kind/${declared.kind}`)
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
    await expect(page).toHaveURL(
      `/hilos/moderation/kind/${CHAT_MESSAGE_PICTURE.kind}`,
    )
    await expectModerationKindHead(
      expect,
      page,
      CHAT_MESSAGE_PICTURE,
      'overview',
    )
    // Over the picture kind's Rules and Overview entries and the section visit
    // between the two kinds: the same detail screen must swap picture data for
    // text data, and back.
    await page.evaluate(() => window.history.go(-4))
    await expect(page).toHaveURL(`/hilos/moderation/kind/${CHAT_MESSAGE.kind}`)
    await expectPageReady(expect, page)
    await expectModerationKindHead(expect, page, CHAT_MESSAGE, 'overview')
    await expectModerationKindOverview(expect, page, CHAT_MESSAGE)
    await document.unchanged()
    await page.evaluate(() => window.history.go(4))
    await expect(page).toHaveURL(
      `/hilos/moderation/kind/${CHAT_MESSAGE_PICTURE.kind}`,
    )
    await expectPageReady(expect, page)
    await expectModerationKindHead(
      expect,
      page,
      CHAT_MESSAGE_PICTURE,
      'overview',
    )
    await expectModerationKindOverview(expect, page, CHAT_MESSAGE_PICTURE)
    await document.unchanged()
  })

  await test.step('Cold entry and F5 on Overview and Rules answer with the same data', async () => {
    for (const declared of DECLARED_KINDS) {
      await gotoPage(expect, page, `/hilos/moderation/kind/${declared.kind}`)
      await expectModerationKindHead(expect, page, declared, 'overview')
      await expectModerationKindOverview(expect, page, declared)
      await page.reload()
      await expectPageReady(expect, page)
      await expect(page).toHaveURL(`/hilos/moderation/kind/${declared.kind}`)
      await expectModerationKindHead(expect, page, declared, 'overview')
      await expectModerationKindOverview(expect, page, declared)

      await gotoPage(
        expect,
        page,
        `/hilos/moderation/kind/${declared.kind}/rules`,
      )
      await expectModerationKindHead(expect, page, declared, 'rules')
      await expect(rulesTable).toBeVisible()
      await page.reload()
      await expectPageReady(expect, page)
      await expect(page).toHaveURL(
        `/hilos/moderation/kind/${declared.kind}/rules`,
      )
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
    ['hilos_moderation_kind', '/hilos/moderation/kind/unknown_kind'],
    [
      'hilos_moderation_kind_rules',
      '/hilos/moderation/kind/unknown_kind/rules',
    ],
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

test('adds, finds, sorts, edits and deletes own rules while built-in rules remain in force', async ({
  page,
  cleanup,
}) => {
  // Proves: the Rules page writes to the server and reads back what it wrote —
  // the one reply on a cold entry, the count, the search and the sort answered
  // by the server, the row that leaves without its data, and the built-in
  // rules that remain alongside own rules through every change.
  const marker = randomUUID()
  const firstRule = `Allow ordinary profile photos. Chat policy alpha ${marker}.`
  const secondRule = `Allow ordinary profile photos. Chat policy beta ${marker}.`
  const editedRule = `Allow ordinary profile photos and drawings. Chat policy alpha ${marker}.`
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
    await expectModerationRulesInForce(expect, page, PHOTO, 0)
    await clickSubmit(expect, rulesTab)
    await expectPageReady(expect, page)
    await expectModerationKindHead(expect, page, PHOTO, 'rules')
  })

  await test.step('Add two rules: their rows and built-ins remain; Overview counts both layers', async () => {
    firstId = await addModerationRule(expect, page, PHOTO, firstRule)
    await expectModerationOwnRules(expect, page, PHOTO, [firstRule])
    secondId = await addModerationRule(expect, page, PHOTO, secondRule)
    await expectModerationOwnRules(expect, page, PHOTO, [firstRule, secondRule])
    expect(adds()).toBe(2)
    await clickSubmit(expect, overviewTab)
    await expectPageReady(expect, page)
    await expectModerationRulesInForce(expect, page, PHOTO, 2)
    await clickSubmit(expect, rulesTab)
    await expectPageReady(expect, page)
    await expectModerationOwnRules(expect, page, PHOTO, [firstRule, secondRule])
  })

  await test.step('Search narrows to one row, nonsense finds nothing, reset restores both', async () => {
    const search = shownByTestId(table, 'hilos-table-search')
    await typeInto(search, 'policy beta')
    await expectModerationOwnRules(expect, page, PHOTO, [secondRule])
    await typeInto(search, 'no_such_own_rule')
    await expect(shownByTestId(table, 'hilos-table-no-matches')).toContainText(
      'Nothing found',
    )
    await expect(
      shownByTestId(table, 'hilos-table-no-matches-terms'),
    ).toHaveText('No rows match “no_such_own_rule”')
    const builtIn = page.getByTestId('hilos-moderation-rules-built-in')
    await expect(builtIn).toBeVisible()
    await expect(builtIn.locator('li')).toHaveText([...PHOTO.defaultRules])
    await expect(table.locator(ROWS)).toHaveCount(0)
    await clickSubmit(
      expect,
      shownByTestId(table, 'hilos-table-no-matches-reset'),
    )
    await expect(search).toHaveValue('')
    await expectModerationOwnRules(expect, page, PHOTO, [firstRule, secondRule])
  })

  await test.step('Sort by Rule: ascending answered by the server, descending reversed, none restored', async () => {
    const sort = table.getByTestId('hilos-table-sort-rule')
    const header = table.locator('th:has([data-id="hilos-table-sort-rule"])')
    await expect(header).toHaveAttribute('aria-sort', 'none')
    const sentBefore = log.sent.length
    await clickSubmit(expect, sort)
    await expect(header).toHaveAttribute('aria-sort', 'ascending')
    await expectModerationOwnRules(expect, page, PHOTO, [firstRule, secondRule])
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
    await expectModerationOwnRules(expect, page, PHOTO, [secondRule, firstRule])
    await ascending.answeredOnce()
    await clickSubmit(expect, sort)
    await expect(header).toHaveAttribute('aria-sort', 'none')
    await expectModerationOwnRules(expect, page, PHOTO, [firstRule, secondRule])
  })

  await test.step('Edit the first rule; F5 reads both from one reply', async () => {
    await editModerationRule(expect, page, PHOTO, firstId, editedRule)
    await expectModerationOwnRules(expect, page, PHOTO, [
      editedRule,
      secondRule,
    ])
    expect(updates()).toBe(1)
    await page.reload()
    await expectPageReady(expect, page)
    await expectModerationOwnRules(expect, page, PHOTO, [
      editedRule,
      secondRule,
    ])
    await expectModerationRulesOpening(expect, log, {
      ...PHOTO,
      ownRules: [editedRule, secondRule],
    })
  })

  await test.step('Delete each own rule: its row leaves without data and built-ins remain', async () => {
    await deleteModerationRule(expect, page, secondId, secondRule)
    await expect(
      table.locator(ROWS).locator('.hilos-table-text[id]'),
    ).toHaveText([editedRule])
    await expect(table.getByTestId('hilos-table-count')).toHaveText(
      '1 – 1 of 1',
    )
    const builtIn = page.getByTestId('hilos-moderation-rules-built-in')
    await expect(builtIn).toBeVisible()
    await expect(builtIn.locator('li')).toHaveText([...PHOTO.defaultRules])
    expect(deletes()).toBe(1)

    await deleteModerationRule(expect, page, firstId, editedRule)
    await expectModerationBuiltInRulesAfterDelete(expect, page, PHOTO)
    expect(deletes()).toBe(2)
    await clickSubmit(expect, overviewTab)
    await expectPageReady(expect, page)
    await expectModerationRulesInForce(expect, page, PHOTO, 0)
    await clickSubmit(expect, rulesTab)
    await expectPageReady(expect, page)
    await page.reload()
    await expectPageReady(expect, page)
    await expectModerationKindHead(expect, page, PHOTO, 'rules')
    await expectModerationBuiltInRules(expect, page, PHOTO)
    await expectModerationRulesOpening(expect, log, { ...PHOTO, ownRules: [] })
  })
})

// FIXME(F-061, 2026-11-05): reconnect sends two Rules replies instead of one · чужой · попытка 1 · HIL-1832
test(
  'resolves an Edit conflict from a second tab and shows a Delete there live through Apply',
  { tag: '@quarantine:F-061' },
  async ({ page, cleanup }) => {
    // Proves: the User-scope fan-out between two tabs of one administrator — the
    // conflict the first tab is shown, the frames each tab sends and gets back,
    // the removal the second tab holds until Apply — none of which one fake
    // connection can show.
    const marker = randomUUID()
    const initialRule = `Allow ordinary profile photos. Chat conflict ${marker}.`
    const otherRule = `Allow ordinary profile photos and drawings. Another tab saved this ${marker}.`
    const mineRule = `Allow ordinary profile photos. Edited here ${marker}.`
    const recoveryRule = `Allow ordinary profile photos. Missed Add ${marker}.`
    reserveModerationRules(expect, page, cleanup, PHOTO, [
      initialRule,
      otherRule,
      mineRule,
      recoveryRule,
    ])
    const updates = countSentFrames(page, 'hilos_moderation_rule_update')
    const deletes = countSentFrames(page, 'hilos_moderation_rule_delete')
    const reconnect = await controlSocketReconnect(page)
    try {
      await grantAdminToSelf(expect, page)
      const own = watchDeliveryFrames(page)
      cleanup.defer(() => own.stop())
      const sockets = watchOpenedSockets(page)
      const replies = watchPageReplies(page)
      await gotoPage(expect, page, RULES_PATH)
      const id = await addModerationRule(expect, page, PHOTO, initialRule)
      await expectModerationOwnRules(expect, page, PHOTO, [initialRule])

      const editor = await page.context().newPage()
      cleanup.defer(() => editor.close())
      const foreign = watchDeliveryFrames(editor)
      cleanup.defer(() => foreign.stop())
      const otherUpdates = countSentFrames(
        editor,
        'hilos_moderation_rule_update',
      )
      await gotoPage(expect, editor, RULES_PATH)
      await expectModerationOwnRules(expect, editor, PHOTO, [initialRule])
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
        await expectModerationOwnRules(expect, page, PHOTO, [otherRule])
        await expectModerationOwnRules(expect, editor, PHOTO, [otherRule])

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
        await expectModerationOwnRules(expect, page, PHOTO, [mineRule])
        await expectModerationOwnRules(expect, editor, PHOTO, [mineRule])
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
        await expectDataFreeDeparture(
          expect,
          page,
          String(id),
          'Removed',
          retired,
        )
        await expectModerationBuiltInRulesAfterDelete(expect, page, PHOTO)

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
        await expectModerationBuiltInRulesAfterDelete(expect, editor, PHOTO)

        // Neither the own echo nor Apply may borrow a later fetched window.
        for (const [log, after, isOwn] of [
          [own, ownBefore, true],
          [foreign, foreignBefore, false],
        ] as const) {
          await expectModerationRemovalEcho(expect, log, after, id, isOwn)
        }
      })

      await test.step('Rules and Overview recover an Add missed while disconnected in their original documents', async () => {
        const overview = await page.context().newPage()
        cleanup.defer(() => overview.close())
        const overviewReconnect = await controlSocketReconnect(overview)
        try {
          const overviewLog = watchDeliveryFrames(overview)
          cleanup.defer(() => overviewLog.stop())
          const overviewSockets = watchOpenedSockets(overview)
          const overviewReplies = watchPageReplies(overview)
          await gotoPage(expect, overview, OVERVIEW_PATH)
          await expectModerationRulesInForce(expect, overview, PHOTO, 0)
          const opening = overviewLog.packets
            .flatMap(({ frames }) => frames)
            .map(({ wire }) => deliveryObject(wire))
            .find((wire) => wire.type === 'page_response')!
          const openingWindows = deliveryObject(
            deliveryObject(deliveryObject(opening.data).payload).windows,
          )
          expect(openingWindows.hilosModerationKinds).toMatchObject({
            totalCount: 1,
            totalExact: true,
            rows: [{ slots: { kind: { kind: PHOTO.kind, ownRuleCount: 0 } } }],
          })
          const table = page.getByTestId('hilos-moderation-rules-table')
          await expect(
            table.locator(ROWS).locator('.hilos-table-text[id]'),
          ).toHaveCount(0)
          await expect(
            page.getByTestId('hilos-moderation-rules-loading'),
          ).toHaveCount(0)
          await expect(page.getByTestId('hilos-table-apply')).toHaveCount(0)

          const readers = [
            {
              page,
              control: reconnect,
              log: own,
              sockets,
              replies,
              pageKey: HilosPages.MODERATION_KIND_RULES,
            },
            {
              page: overview,
              control: overviewReconnect,
              log: overviewLog,
              sockets: overviewSockets,
              replies: overviewReplies,
              pageKey: HilosPages.MODERATION_KIND,
            },
          ]
          const documents = []
          for (const reader of readers) {
            await expect(reader.page.getByTestId('conn-state')).toHaveText(
              'connected',
            )
            await expectWelcomedSockets(expect, reader.sockets, 1)
            const document = await watchClientDocument(reader.page)
            cleanup.defer(() => document.dispose())
            documents.push(document)
            await reader.control.drop()
            await expect(reader.page.getByTestId('conn-state')).toHaveAttribute(
              'title',
              /^(connecting|reconnecting|disconnected)\b/,
            )
            await expect
              .poll(() => reader.log.connections[0]!.closed)
              .toBeDefined()
          }
          const beforeAdd = readers.map(({ log }) => ({
            packets: log.packets.length,
            sent: log.sent.length,
          }))
          const writerBefore = foreign.sent.length
          const recoveryId = await addModerationRule(
            expect,
            editor,
            PHOTO,
            recoveryRule,
          )
          await expectModerationRuleShown(expect, editor, recoveryRule, true)
          const add = foreign.sent
            .slice(writerBefore)
            .map(({ wire }) => deliveryObject(wire))
            .find((wire) => wire.action === 'hilos_moderation_rule_add')!
          expect(add).toMatchObject({
            data: { kind: PHOTO.kind, rule: recoveryRule },
          })
          expect(
            foreign.packets
              .flatMap(({ frames }) => frames)
              .map(({ wire }) => deliveryObject(wire))
              .filter((wire) => wire.requestId === add.requestId),
          ).toEqual([
            expect.objectContaining({
              type: 'action_success',
              outcome: 'success',
            }),
          ])

          // The acknowledged Add crossed no reader socket: their next full answers
          // must supply it. This barrier is the writer's reply, not a quiet interval.
          for (const [index, reader] of readers.entries()) {
            expect(reader.log.packets).toHaveLength(beforeAdd[index]!.packets)
            expect(reader.log.sent).toHaveLength(beforeAdd[index]!.sent)
            expect(reader.sockets).toHaveLength(1)
            expect(JSON.stringify(reader.log.packets)).not.toContain(
              recoveryRule,
            )
          }
          await expectModerationRuleShown(expect, page, recoveryRule, false)
          await expectModerationRulesInForce(expect, overview, PHOTO, 0)
          const fullAnswers = () =>
            readers.map(({ log }) =>
              log.packets
                .filter(({ socket }) => socket === 2)
                .flatMap(({ frames }) => frames)
                .map(({ wire }) => deliveryObject(wire))
                .filter((wire) => wire.type === 'page_response')
                .map((wire) => deliveryObject(wire.data)),
            )
          const expectFreshAnswer = (
            answer: Record<string, unknown>,
            pageKey: string,
          ) => {
            expect(answer).toMatchObject({ page: pageKey })
            const windows = deliveryObject(
              deliveryObject(answer.payload).windows,
            )
            expect(windows.hilosModerationKinds).toMatchObject({
              totalCount: 1,
              totalExact: true,
              rows: [
                {
                  rowKey: PHOTO.kind,
                  slots: { kind: { kind: PHOTO.kind, ownRuleCount: 1 } },
                },
              ],
            })
            if (pageKey === HilosPages.MODERATION_KIND_RULES) {
              expect(Object.keys(windows)).toEqual([
                'hilosModerationKinds',
                'hilosModerationRules',
              ])
              expect(windows.hilosModerationRules).toMatchObject({
                totalCount: 1,
                totalExact: true,
                rows: [
                  {
                    slots: {
                      rule: {
                        id: recoveryId,
                        kind: PHOTO.kind,
                        rule: recoveryRule,
                      },
                    },
                  },
                ],
              })
            } else {
              expect(Object.keys(windows)).toEqual(['hilosModerationKinds'])
            }
          }

          // Both readers missed the Add. Restore Rules first so its subscription
          // exists when Overview's session frame rechecks the same user's pages.
          // That second full answer has a separate cause from the one subscribe.
          await reconnect.release()
          await expectWelcomedSockets(expect, sockets, 2)
          await expect
            .poll(() => fullAnswers().map((answers) => answers.length))
            .toEqual([1, 0])
          expectFreshAnswer(
            fullAnswers()[0]![0]!,
            HilosPages.MODERATION_KIND_RULES,
          )
          await expectPageReady(expect, page)
          await expectModerationRuleShown(expect, page, recoveryRule, true)
          expect(overviewLog.packets).toHaveLength(beforeAdd[1]!.packets)
          expect(overviewLog.sent).toHaveLength(beforeAdd[1]!.sent)
          expect(overviewSockets).toHaveLength(1)
          const beforeRecheck = own.packets.length
          await overviewReconnect.release()
          await expectWelcomedSockets(expect, overviewSockets, 2)
          await expect
            .poll(() => fullAnswers().map((answers) => answers.length))
            .toEqual([2, 1])
          const recheck = own.packets
            .slice(beforeRecheck)
            .flatMap(({ frames }) => frames)
            .map(({ wire }) => deliveryObject(wire))
          expect(
            recheck
              .filter(
                (wire) =>
                  wire.type === SIGNAL_HANDSHAKE_RESPONSE ||
                  wire.type === 'page_response',
              )
              .map((wire) => wire.type),
          ).toEqual([SIGNAL_HANDSHAKE_RESPONSE, 'page_response'])

          for (const [index, reader] of readers.entries()) {
            // release only opens the route; wait for the real welcome and page reply.
            await expectWelcomedSockets(expect, reader.sockets, 2)
            await expect
              .poll(() => reader.replies.replies)
              .toContainEqual({
                socket: 2,
                type: 'page_response',
                page: reader.pageKey,
              })
            const packets = reader.log.packets.filter(
              ({ socket }) => socket === 2,
            )
            const frames = packets.flatMap(({ frames }) => frames)
            expect(
              frames.filter(({ failure }) => failure !== undefined),
            ).toEqual([])
            const session = packets.find(({ frames }) =>
              frames.some(
                ({ wire }) =>
                  deliveryObject(wire).type === SIGNAL_HANDSHAKE_RESPONSE,
              ),
            )
            expect(session).toBeDefined()
            const subscriptions = reader.log.sent.filter(
              ({ socket, wire }) =>
                socket === 2 && deliveryObject(wire).type === 'page_subscribe',
            )
            expect(subscriptions.map(({ wire }) => wire)).toMatchObject([
              { page: reader.pageKey, params: { kind: PHOTO.kind } },
            ])
            expect(session!.at).toBeLessThanOrEqual(subscriptions[0]!.at)
            const answers = fullAnswers()[index]!
            expect(answers).toHaveLength(index === 0 ? 2 : 1)
            for (const answer of answers)
              expectFreshAnswer(answer, reader.pageKey)
            await expectPageReady(expect, reader.page)
            await expect(reader.page.getByTestId('conn-state')).toHaveText(
              'connected',
            )
          }
          // Only own rows/counts are under test; built-in policy is a separate contract.
          for (const rulesPage of [page, editor]) {
            await expectModerationRuleShown(
              expect,
              rulesPage,
              recoveryRule,
              true,
            )
            const rulesTable = rulesPage.getByTestId(
              'hilos-moderation-rules-table',
            )
            await expect(
              rulesTable.locator(ROWS).locator('.hilos-table-text[id]'),
            ).toHaveText([recoveryRule])
            await expect(
              rulesTable.getByTestId('hilos-table-count'),
            ).toHaveText('1 – 1 of 1')
          }
          await expectModerationRulesInForce(expect, overview, PHOTO, 1)

          // Recovery and every full snapshot are proved before this later request.
          // Recount behind its answer, after the queued rechecks have crossed the
          // same page owner's queue and Rules socket.
          const beforeFence = own.sent.length
          await clickSubmit(expect, table.getByTestId('hilos-table-sort-rule'))
          await expectModerationSortReply(
            expect,
            own,
            beforeFence,
            PHOTO,
            'asc',
          )
          expect(fullAnswers().map((answers) => answers.length)).toEqual([2, 1])
          for (const [index, reader] of readers.entries()) {
            for (const answer of fullAnswers()[index]!)
              expectFreshAnswer(answer, reader.pageKey)
            expect(
              reader.log.packets
                .filter(({ socket }) => socket === 2)
                .flatMap(({ frames }) => frames)
                .filter(({ failure }) => failure !== undefined),
            ).toEqual([])
            expect(
              reader.log.sent.filter(
                ({ socket, wire }) =>
                  socket === 2 &&
                  deliveryObject(wire).type === 'page_subscribe',
              ),
            ).toHaveLength(1)
          }
          for (const document of documents) await document.unchanged()
        } finally {
          await overviewReconnect.dispose()
        }
      })
    } finally {
      await reconnect.dispose()
    }
  },
)

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
  await expectModerationOwnRules(expect, page, PHOTO, [rule])

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
    await expectModerationRulesInForce(expect, viewer, PHOTO, 1)

    await clickSubmit(
      expect,
      viewer.getByTestId('hilos-page-tab-hilos_moderation_kind_rules'),
    )
    await expectPageReady(expect, viewer)
    await expectModerationKindHead(expect, viewer, PHOTO, 'rules')
    await expect(banner).toBeVisible()
    await expectModerationOwnRules(expect, viewer, PHOTO, [rule])
    const search = shownByTestId(viewerTable, 'hilos-table-search')
    await typeInto(search, marker)
    await expectModerationOwnRules(expect, viewer, PHOTO, [rule])
    await typeInto(search, 'no_such_own_rule')
    await expect(
      shownByTestId(viewerTable, 'hilos-table-no-matches-terms'),
    ).toHaveText('No rows match “no_such_own_rule”')
    await expect(viewerTable.locator(ROWS)).toHaveCount(0)
    const builtIn = viewer.getByTestId('hilos-moderation-rules-built-in')
    await expect(builtIn).toBeVisible()
    await expect(builtIn.locator('li')).toHaveText([...PHOTO.defaultRules])
    await clickSubmit(
      expect,
      shownByTestId(viewerTable, 'hilos-table-no-matches-reset'),
    )
    await expect(search).toHaveValue('')
    await expectModerationOwnRules(expect, viewer, PHOTO, [rule])
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
    await expectModerationOwnRules(expect, viewer, PHOTO, [rule])
    await viewer.reload()
    await expectPageReady(expect, viewer)
    await expectModerationOwnRules(expect, viewer, PHOTO, [rule])
    expect(sentWrites()).toEqual([0, 0, 0])
  })
})

test('a rule edited on the Rules page governs the next photo check of the model', async ({
  page,
  cleanup,
}) => {
  // Proves on the server: the rule saved from the screen is what the model's
  // request carries — the first key is edited away before the check, so only
  // the second reaches the model, once, with the one picture.
  const firstKey = modelKey()
  const nextKey = modelKey()
  const firstRule = `Allow ordinary profile photos. Policy marker ${firstKey}.`
  const nextRule = `Allow ordinary profile photos. Edited policy marker ${nextKey}.`
  cleanup.defer(() => forgetModelKey(firstKey))
  cleanup.defer(() => forgetModelKey(nextKey))
  reserveModerationRules(expect, page, cleanup, PHOTO, [firstRule, nextRule])
  await createTestAdmin(expect, page)
  await gotoPage(expect, page, RULES_PATH)
  const id = await addModerationRule(expect, page, PHOTO, firstRule)
  await expectModerationOwnRules(expect, page, PHOTO, [firstRule])
  await editModerationRule(expect, page, PHOTO, id, nextRule)
  await expectModerationOwnRules(expect, page, PHOTO, [nextRule])

  // Only the edited policy carries the key: the name, the picture and its
  // file name cannot match it. Dictate BEFORE the action that calls the model.
  await dictateModerationVerdict(nextKey, true, 'ok')
  await gotoPage(expect, page, '/profile')
  await expect(page.getByTestId('profile-name')).not.toContainText(nextKey)
  await openAndSelectPhoto(
    expect,
    page,
    await picture(page, 'image/jpeg'),
    'image/jpeg',
  )
  await clickSubmit(expect, page.getByTestId('profile-photo-save'))
  await expect(page.getByTestId('modal')).toBeHidden()
  // Publication consumes the sole dictated answer through the real photo check.
  await expect(
    page.getByTestId('profile-identity').getByTestId('hilos-avatar-photo'),
  ).toBeVisible()
  await expect(
    page.getByTestId('nav-profile').getByTestId('hilos-avatar-photo'),
  ).toBeVisible()
  const arrivals = await readModelRequests(nextKey)
  expect(arrivals.map(({ imageHashes }) => imageHashes.length)).toEqual([1])
  expect(await readModelRequests(firstKey)).toEqual([])
})
