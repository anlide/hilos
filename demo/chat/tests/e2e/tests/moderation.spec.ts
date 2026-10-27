import { expect, test } from '@playwright/test'

import {
  deliveryObject,
  expectModerationKindHead,
  expectModerationKindTabs,
  expectModerationOwnRules,
  expectModerationRulesOpening,
  ROWS,
  shownByTestId,
  watchClientDocument,
  watchDeliveryFrames,
  watchPageReplies,
} from '../../../../../framework/frontend/e2e/index.js'
import { signUpAdmin } from '../helpers/adminGrant.js'
import { setAdminViewMode } from '../helpers/adminViewMode.js'
import {
  expectPageReady,
  gotoPage,
  PAGE_READY,
  PAGE_REFUSED,
} from '../helpers/page.js'

// Framework moderation kinds, read-only Overview and Rules, carried by chat.
// Expectations come from the kind declarations, moderation profile defaults
// and seed 006_framework_moderation_rules; no Rules mutations are needed.

test.use({ viewport: { width: 1440, height: 1000 }, colorScheme: 'light' })

const DECLARED_KINDS = [
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
    rules: '3 rules of your own',
    ownRules: [
      'Allow benign display names. Block: insults, threats, hate speech, sexual, spam, impersonation.',
      'Explicit text only; no inference. Uncertain→allow.',
      'Reasons: ok, insult, threat, hate_speech, sexual, spam, impersonation.',
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
    rules: 'Built-in until you add a rule of your own',
    ownRules: [],
    defaultRules: [
      'Default policy: allow ordinary photos, drawings and logos.',
      'Block only nudity or sexual content, graphic violence, and hate symbols.',
      'If uncertain, return allow=true.',
    ],
  },
  {
    kind: 'chat_message',
    title: 'Chat message',
    owner: 'Project',
    modelLabel: 'Text model',
    model: 'qwen2.5:0.5b',
    profile: 'hilos.moderation.text',
    reasons: ['insult', 'threat', 'hate_speech', 'sexual', 'spam'],
    rules: '3 rules of your own',
    ownRules: [
      "Allow benign. Block: insults, threats, hate speech, sexual, spam. Don't block greetings, names, neutral.",
      'Explicit text only; no inference. Uncertain→allow. Attachments never cause a block.',
      'Reasons: ok, insult, threat, hate_speech, sexual, spam.',
    ],
    defaultRules: [
      'Default policy: allow benign messages.',
      'Block only explicit insults, threats, hate speech, sexual content, and obvious spam.',
      'If uncertain, return allow=true.',
    ],
  },
  {
    kind: 'chat_message_picture',
    title: 'Chat message picture',
    owner: 'Project',
    modelLabel: 'Vision model',
    model: 'qwen2.5vl:3b',
    profile: 'hilos.moderation.image',
    reasons: ['nudity', 'violence', 'hate'],
    rules: 'Built-in until you add a rule of your own',
    ownRules: [],
    defaultRules: [
      'Default policy: allow ordinary pictures, screenshots and drawings.',
      'Block only nudity or sexual content, graphic violence, and hate symbols.',
      'If uncertain, return allow=true.',
    ],
  },
] as const

test.afterEach(async () => {
  await setAdminViewMode(false)
})

test('opens moderation kinds from dashboard, supports direct navigation, reload and history', async ({
  page,
}) => {
  await signUpAdmin(page)
  await gotoPage(page, '/hilos')
  await expectPageReady(page)

  // Navigate to Moderation from the People group on the admin dashboard.
  const card = page
    .locator(
      '[data-id="hilos-admin-child-hilos_moderation"], [data-id="dashboard-card-hilos_moderation"]',
    )
    .first()
  await expect(card).toBeVisible()
  await card.click()
  await expectPageReady(page)
  expect(new URL(page.url()).pathname).toBe('/hilos/moderation')

  // Table and declared kind rows are visible.
  await expect(page.getByTestId('hilos-moderation-kinds-table')).toBeVisible()
  for (const { kind } of DECLARED_KINDS) {
    await expect(
      shownByTestId(page, `hilos-moderation-row-${kind}`),
    ).toBeVisible()
  }

  // Browser navigation: Back to /hilos, Forward to /hilos/moderation.
  await page.goBack()
  await expectPageReady(page)
  expect(new URL(page.url()).pathname).toBe('/hilos')

  await page.goForward()
  await expectPageReady(page)
  expect(new URL(page.url()).pathname).toBe('/hilos/moderation')
  await expect(page.getByTestId('hilos-moderation-kinds-table')).toBeVisible()

  // Page reload (F5) keeps table state over the live socket.
  await page.reload()
  await expectPageReady(page)
  expect(new URL(page.url()).pathname).toBe('/hilos/moderation')
  await expect(page.getByTestId('hilos-moderation-kinds-table')).toBeVisible()

  // Direct URL navigation.
  await gotoPage(page, '/hilos/moderation', PAGE_READY)
  await expect(page.getByTestId('hilos-moderation-kinds-table')).toBeVisible()
})

test('opens each declared kind Overview and keeps its head, data and history in the SPA', async ({
  page,
}) => {
  await signUpAdmin(page)
  await gotoPage(page, '/hilos/moderation', PAGE_READY)
  await expect(page.getByTestId('hilos-moderation-kinds-table')).toBeVisible()

  const document = await watchClientDocument(page)
  try {
    for (const declared of DECLARED_KINDS) {
      const { kind, title, owner, modelLabel, model, profile, reasons, rules } =
        declared
      const openLink = shownByTestId(page, `hilos-moderation-open-${kind}`)
      await expect(openLink).toBeVisible()
      await expect(openLink).toHaveAttribute(
        'href',
        `/hilos/moderation/${kind}`,
      )
      await expect(openLink).toHaveAttribute('aria-label', `Open ${title}`)
      await openLink.click()
      await expectPageReady(page)
      await expect(page).toHaveURL(`/hilos/moderation/${kind}`)

      const heading = page.getByRole('heading', { level: 1 })
      await expect(heading).toHaveCount(1)
      await expect(heading).toHaveAttribute('data-id', 'hilos-page-head-title')
      await expect(heading).toHaveText(title)
      await expect(page).toHaveTitle(`${title} · Hilos Chat`)
      await expect(page.getByTestId('hilos-page-head-skeleton')).toHaveCount(0)
      await expect(page.getByTestId('hilos-moderation-kind-owner')).toHaveText(
        owner,
      )
      await expect(page.getByTestId('hilos-moderation-kind-key')).toHaveText(
        kind,
      )

      const crumbs = page.getByTestId('hilos-breadcrumb')
      await expect(crumbs).toHaveAccessibleName('Breadcrumb')
      await expect(crumbs.locator('li')).toHaveText([
        'Hilos',
        'Moderation',
        title,
      ])
      await expect(crumbs.getByRole('link')).toHaveText(['Hilos', 'Moderation'])
      await expect(
        crumbs.getByTestId('hilos-breadcrumb-hilos'),
      ).toHaveAttribute('href', '/hilos')
      await expect(
        crumbs.getByTestId('hilos-breadcrumb-hilos_moderation'),
      ).toHaveAttribute('href', '/hilos/moderation')
      const currentCrumb = crumbs.locator('[aria-current="page"]')
      await expect(currentCrumb).toHaveCount(1)
      await expect(currentCrumb).toHaveText(title)
      await expect(currentCrumb.locator('a')).toHaveCount(0)

      const overview = page.getByTestId('hilos-moderation-kind-overview')
      await expect(overview).toBeVisible()
      await expect(overview.locator('dl dt')).toHaveText([
        'Checked by',
        'Can refuse for',
        'Rules in force',
      ])
      await expect(overview.locator('dl dd')).toHaveCount(3)
      await expect(page.getByTestId('hilos-moderation-kind-model')).toHaveText(
        `${modelLabel} · ${model} — profile ${profile}`,
      )
      await expect(
        page.getByTestId('hilos-moderation-kind-profile'),
      ).toHaveText(profile)
      await expect(
        page.getByTestId('hilos-moderation-kind-reasons').locator('.badge'),
      ).toHaveText([...reasons])
      await expect(page.getByTestId('hilos-moderation-kind-rules')).toHaveText(
        rules,
      )
      await expect(
        page.getByTestId('hilos-moderation-kind-loading'),
      ).toHaveCount(0)
      await expectModerationKindTabs(expect, page, kind, 'overview')
      await expect(page.getByTestId('hilos-admin-children')).toHaveCount(0)
      await expect(
        page
          .getByTestId('hilos-admin-page')
          .locator('button, input, select, textarea, table, [role="tablist"]'),
      ).toHaveCount(0)
      await document.unchanged()

      await page
        .getByTestId('hilos-page-tab-hilos_moderation_kind_rules')
        .click()
      await expectPageReady(page)
      await expect(page).toHaveURL(`/hilos/moderation/${kind}/rules`)
      await expectModerationKindHead(expect, page, declared, 'rules')
      await expect(page).toHaveTitle(`${title} · Hilos Chat`)
      await expect(
        page.getByTestId('hilos-moderation-kind-overview'),
      ).toHaveCount(0)
      await expect(
        page.getByTestId('hilos-moderation-rules-table'),
      ).toBeVisible()
      if (declared.ownRules.length > 0) {
        await expectModerationOwnRules(expect, page, declared.ownRules)
      } else {
        const empty = shownByTestId(page, 'hilos-table-empty')
        await expect(empty.locator('p')).toHaveText([
          'No rules of your own yet',
          `${title} is checked by its built-in rules below.`,
        ])
        const builtIn = page.getByTestId('hilos-moderation-rules-built-in')
        await expect(builtIn).toBeVisible()
        await expect(builtIn).toHaveClass(/bg-body-tertiary/)
        await expect(builtIn.getByRole('heading', { level: 2 })).toHaveText(
          'Built-in rules',
        )
        await expect(builtIn.locator('.badge')).toHaveText('Built-in')
        await expect(builtIn.locator('p')).toHaveText(
          'They come with the kind and apply until you add a rule of your own. Your first rule replaces them all.',
        )
        await expect(builtIn.locator('ul')).toHaveClass(/text-body-secondary/)
        await expect(builtIn.locator('li')).toHaveText([
          ...declared.defaultRules,
        ])
        await expect(
          page.getByTestId('hilos-moderation-rules-table').locator(ROWS),
        ).toHaveCount(0)
        await expect(page.getByTestId('hilos-table-no-matches')).toHaveCount(0)
      }
      await document.unchanged()
      await page.getByTestId('hilos-page-tab-hilos_moderation_kind').click()
      await expectPageReady(page)
      await expect(page).toHaveURL(`/hilos/moderation/${kind}`)
      await expectModerationKindHead(expect, page, declared, 'overview')
      await expect(overview).toBeVisible()
      await expect(page.getByTestId('hilos-moderation-kind-rules')).toHaveText(
        rules,
      )
      await expect(
        page.getByTestId('hilos-moderation-rules-table'),
      ).toHaveCount(0)
      await expect(
        page.getByTestId('hilos-moderation-rules-built-in'),
      ).toHaveCount(0)
      await document.unchanged()

      await crumbs.getByTestId('hilos-breadcrumb-hilos_moderation').click()
      await expectPageReady(page)
      await expect(page).toHaveURL('/hilos/moderation')
      await expect(
        page.getByTestId('hilos-moderation-kinds-table'),
      ).toBeVisible()
      await expect(
        page.getByTestId('hilos-moderation-kind-overview'),
      ).toHaveCount(0)
      await document.unchanged()
    }

    // Back to the last kind, then skip its Rules and Overview history entries:
    // the same detail component must replace picture data with text data.
    await page.goBack()
    await expectPageReady(page)
    await expect(page.getByTestId('hilos-moderation-kind-key')).toHaveText(
      'chat_message_picture',
    )
    await page.evaluate(() => window.history.go(-4))
    await expect(page).toHaveURL('/hilos/moderation/chat_message')
    await expectPageReady(page)
    await expect(page.getByTestId('hilos-moderation-kind-key')).toHaveText(
      'chat_message',
    )
    await expect(page.getByTestId('hilos-page-head-title')).toHaveText(
      'Chat message',
    )
    await expect(page).toHaveTitle('Chat message · Hilos Chat')
    await expect(page.getByTestId('hilos-moderation-kind-model')).toHaveText(
      'Text model · qwen2.5:0.5b — profile hilos.moderation.text',
    )
    await expect(page.getByTestId('hilos-moderation-kind-rules')).toHaveText(
      '3 rules of your own',
    )
    await document.unchanged()

    await page.evaluate(() => window.history.go(4))
    await expect(page).toHaveURL('/hilos/moderation/chat_message_picture')
    await expectPageReady(page)
    await expect(page.getByTestId('hilos-moderation-kind-key')).toHaveText(
      'chat_message_picture',
    )
    await expect(page.getByTestId('hilos-page-head-title')).toHaveText(
      'Chat message picture',
    )
    await expect(page).toHaveTitle('Chat message picture · Hilos Chat')
    await expect(page.getByTestId('hilos-moderation-kind-model')).toHaveText(
      'Vision model · qwen2.5vl:3b — profile hilos.moderation.image',
    )
    await expect(page.getByTestId('hilos-moderation-kind-rules')).toHaveText(
      'Built-in until you add a rule of your own',
    )

    await page.getByTestId('hilos-breadcrumb-hilos').click()
    await expectPageReady(page)
    await expect(page).toHaveURL('/hilos')
    await document.unchanged()
  } finally {
    await document.dispose()
  }
})

test('loads a kind Overview directly and after F5 with the same resolved data', async ({
  page,
}) => {
  await signUpAdmin(page)

  for (const {
    kind,
    title,
    owner,
    modelLabel,
    model,
    profile,
    rules,
  } of DECLARED_KINDS) {
    await gotoPage(page, `/hilos/moderation/${kind}`, PAGE_READY)
    const overview = page.getByTestId('hilos-moderation-kind-overview')
    await expect(overview).toBeVisible()
    const directText = await overview.innerText()

    await page.reload()
    await expectPageReady(page)
    await expect(page).toHaveURL(`/hilos/moderation/${kind}`)
    await expect(page.getByTestId('hilos-page-head-title')).toHaveText(title)
    await expect(page).toHaveTitle(`${title} · Hilos Chat`)
    await expect(page.getByTestId('hilos-moderation-kind-key')).toHaveText(kind)
    await expect(page.getByTestId('hilos-moderation-kind-owner')).toHaveText(
      owner,
    )
    await expect(page.getByTestId('hilos-moderation-kind-model')).toHaveText(
      `${modelLabel} · ${model} — profile ${profile}`,
    )
    await expect(page.getByTestId('hilos-moderation-kind-rules')).toHaveText(
      rules,
    )
    await expect(overview).toHaveText(directText, { useInnerText: true })
  }
})

test('loads Rules directly and after F5 from one reply with both canonical windows', async ({
  page,
}) => {
  const log = watchDeliveryFrames(page)
  await signUpAdmin(page)
  try {
    for (const declared of DECLARED_KINDS) {
      await gotoPage(
        page,
        `/hilos/moderation/${declared.kind}/rules`,
        PAGE_READY,
      )
      await expectModerationKindHead(expect, page, declared, 'rules')
      const table = page.getByTestId('hilos-moderation-rules-table')
      await expect(table).toBeVisible()
      if (declared.ownRules.length > 0) {
        await expectModerationOwnRules(expect, page, declared.ownRules)
      } else {
        await expect(
          page.getByTestId('hilos-moderation-rules-built-in').locator('li'),
        ).toHaveText([...declared.defaultRules])
      }
      await expectModerationRulesOpening(expect, log, declared)
      const directText = await table.innerText()

      await page.reload()
      await expectPageReady(page)
      await expect(page).toHaveURL(`/hilos/moderation/${declared.kind}/rules`)
      await expectModerationKindHead(expect, page, declared, 'rules')
      await expect(table).toHaveText(directText, { useInnerText: true })
      await expectModerationRulesOpening(expect, log, declared)
    }
  } finally {
    log.stop()
  }
})

for (const declared of [DECLARED_KINDS[0], DECLARED_KINDS[2]]) {
  test(`reads, searches and sorts ${declared.kind} own rules without showing built-ins`, async ({
    page,
  }) => {
    const log = watchDeliveryFrames(page)
    await signUpAdmin(page)
    await gotoPage(page, `/hilos/moderation/${declared.kind}/rules`, PAGE_READY)
    await expectModerationOwnRules(expect, page, declared.ownRules)
    const document = await watchClientDocument(page)
    try {
      const search = shownByTestId(page, 'hilos-table-search')
      await search.fill('')
      await search.pressSequentially('Explicit text only', { delay: 10 })
      await expectModerationOwnRules(expect, page, [declared.ownRules[1]])

      await search.fill('')
      await search.pressSequentially('no_such_own_rule', { delay: 10 })
      const empty = shownByTestId(page, 'hilos-table-no-matches')
      await expect(empty).toContainText('Nothing found')
      await expect(
        shownByTestId(page, 'hilos-table-no-matches-terms'),
      ).toHaveText('No rows match “no_such_own_rule”')
      await expect(
        page.getByTestId('hilos-moderation-rules-built-in'),
      ).toHaveCount(0)
      await expect(page.getByTestId('hilos-table-empty')).toHaveCount(0)
      await expect(
        page.getByTestId('hilos-moderation-rules-table').locator(ROWS),
      ).toHaveCount(0)
      await shownByTestId(page, 'hilos-table-no-matches-reset').click()
      await expect(search).toHaveValue('')
      await expectModerationOwnRules(expect, page, declared.ownRules)

      const sort = page.getByTestId('hilos-table-sort-rule')
      const header = page.locator('th:has([data-id="hilos-table-sort-rule"])')
      await expect(header).toHaveAttribute('aria-sort', 'none')
      await sort.click()
      await expect(header).toHaveAttribute('aria-sort', 'ascending')
      // Ascending matches the seed order, so DOM text alone cannot prove its reply.
      await expect
        .poll(() => {
          const request = log.sent
            .map((frame) => deliveryObject(frame.wire))
            .filter(
              (wire) =>
                wire.type === 'table_viewport' &&
                wire.tableKey === 'hilosModerationRules',
            )
            .at(-1)
          const replies = log.packets
            .flatMap((packet) => packet.frames)
            .map((frame) => deliveryObject(frame.wire))
            .filter((wire) => wire.type === 'table_window')
            .map((wire) => deliveryObject(wire.data))
            .filter(
              (data) =>
                data.tableKey === 'hilosModerationRules' &&
                data.requestId === request?.requestId,
            )
          return {
            sort: request?.sort,
            kind: deliveryObject(request?.filter).kind,
            replies: replies.length,
          }
        })
        .toEqual({
          sort: [{ field: 'rule', direction: 'asc' }],
          kind: declared.kind,
          replies: 1,
        })
      await sort.click()
      await expect(header).toHaveAttribute('aria-sort', 'descending')
      await expectModerationOwnRules(
        expect,
        page,
        [...declared.ownRules].reverse(),
      )
      await sort.click()
      await expect(header).toHaveAttribute('aria-sort', 'none')
      await expectModerationOwnRules(expect, page, declared.ownRules)

      await page.getByTestId('hilos-page-tab-hilos_moderation_kind').click()
      await expectPageReady(page)
      await expectModerationKindHead(expect, page, declared, 'overview')
      await expect(
        page.getByTestId('hilos-moderation-rules-table'),
      ).toHaveCount(0)
      await page.goBack()
      await expectPageReady(page)
      await expect(page).toHaveURL(`/hilos/moderation/${declared.kind}/rules`)
      await expectModerationKindHead(expect, page, declared, 'rules')
      await expectModerationOwnRules(expect, page, declared.ownRules)
      await page.goForward()
      await expectPageReady(page)
      await expect(page).toHaveURL(`/hilos/moderation/${declared.kind}`)
      await expectModerationKindHead(expect, page, declared, 'overview')
      await expect(
        page.getByTestId('hilos-moderation-rules-table'),
      ).toHaveCount(0)
      await document.unchanged()
    } finally {
      log.stop()
      await document.dispose()
    }
  })
}

test('refuses an unknown kind through the server subscription as 404', async ({
  page,
}) => {
  const observed = watchPageReplies(page)
  await signUpAdmin(page)
  await gotoPage(page, '/hilos/moderation/unknown_kind', PAGE_REFUSED)
  await expect(page).toHaveURL('/hilos/moderation/unknown_kind')
  await expect(page.getByTestId('page-error')).toHaveAttribute(
    'data-error-code',
    '404',
  )
  await expect(page.getByTestId('page-error')).toContainText('Page Not Found')
  expect(
    observed.replies.filter((reply) => reply.page === 'hilos_moderation_kind'),
  ).toEqual([
    expect.objectContaining({
      type: 'subscription_page_error',
      httpCode: 404,
    }),
  ])
  await expect(page.getByTestId('hilos-moderation-kind-overview')).toHaveCount(
    0,
  )
  await expect(page.getByTestId('hilos-moderation-kind-loading')).toHaveCount(0)
  await expect(page.getByTestId('hilos-page-head-title')).toHaveCount(0)
})

test('refuses an unknown Rules kind through the server subscription as 404', async ({
  page,
}) => {
  const observed = watchPageReplies(page)
  await signUpAdmin(page)
  await gotoPage(page, '/hilos/moderation/unknown_kind/rules', PAGE_REFUSED)
  await expect(page).toHaveURL('/hilos/moderation/unknown_kind/rules')
  await expect(page.getByTestId('page-error')).toHaveAttribute(
    'data-error-code',
    '404',
  )
  await expect(page.getByTestId('page-error')).toContainText('Page Not Found')
  expect(
    observed.replies.filter(
      (reply) => reply.page === 'hilos_moderation_kind_rules',
    ),
  ).toEqual([
    expect.objectContaining({ type: 'subscription_page_error', httpCode: 404 }),
  ])
  await expect(page.getByTestId('hilos-page-head-title')).toHaveCount(0)
  await expect(page.getByTestId('hilos-moderation-rules-table')).toHaveCount(0)
  await expect(page.getByTestId('hilos-moderation-rules-built-in')).toHaveCount(
    0,
  )
  await expect(page.getByTestId('hilos-moderation-rules-loading')).toHaveCount(
    0,
  )
})

test('filters moderation kinds by search query and shows empty state when no rows match', async ({
  page,
}) => {
  await signUpAdmin(page)
  await gotoPage(page, '/hilos/moderation', PAGE_READY)
  await expect(page.getByTestId('hilos-moderation-kinds-table')).toBeVisible()

  const search = shownByTestId(page, 'hilos-table-search')

  // Search filtering by kind prefix.
  await search.fill('')
  await search.pressSequentially('profile', { delay: 10 })
  await expect(
    shownByTestId(page, 'hilos-moderation-row-hilos_profile_name'),
  ).toBeVisible()
  await expect(
    shownByTestId(page, 'hilos-moderation-row-hilos_profile_photo'),
  ).toBeVisible()
  await expect(
    shownByTestId(page, 'hilos-moderation-row-chat_message'),
  ).toHaveCount(0)
  await expect(
    shownByTestId(page, 'hilos-moderation-row-chat_message_picture'),
  ).toHaveCount(0)

  // Non-matching search displays empty state.
  await search.fill('')
  await search.pressSequentially('nonexistent_kind', { delay: 10 })
  await expect(
    shownByTestId(page, 'hilos-moderation-row-hilos_profile_name'),
  ).toHaveCount(0)
  await expect(
    shownByTestId(page, 'hilos-moderation-row-hilos_profile_photo'),
  ).toHaveCount(0)
  await expect(
    shownByTestId(page, 'hilos-moderation-row-chat_message'),
  ).toHaveCount(0)
  await expect(
    shownByTestId(page, 'hilos-moderation-row-chat_message_picture'),
  ).toHaveCount(0)

  const emptyState = shownByTestId(
    page,
    /^(hilos-table-no-matches|hilos-table-empty)$/,
  )
  await expect(emptyState).toBeVisible()
  await expect(emptyState).toContainText(
    /Nothing found|No moderation kinds declared/,
  )

  // Resetting search restores all declared kinds.
  await search.fill('')
  for (const { kind } of DECLARED_KINDS) {
    await expect(
      shownByTestId(page, `hilos-moderation-row-${kind}`),
    ).toBeVisible()
  }
})

test('displays moderation kinds in admin view mode with view-mode banner', async ({
  page,
}) => {
  await setAdminViewMode(true)
  await gotoPage(page, '/hilos/moderation', PAGE_READY)
  await expectPageReady(page)

  // View-mode strip is visible.
  const banner = page.getByTestId('view-mode-banner')
  await expect(banner).toBeVisible()

  // In demo/chat catalog, moderation settings are open in view mode (ADMIN_VIEW_VISIBLE=true),
  // so no hilos-hidden badge is rendered for open settings.
  await expect(
    shownByTestId(
      page.getByTestId('hilos-moderation-kinds-table'),
      'hilos-hidden',
    ),
  ).toHaveCount(0)

  // A guest viewer follows the same Open link and sees the catalog's visible
  // model value on Overview as well as on the root list.
  const document = await watchClientDocument(page)
  try {
    await shownByTestId(
      page,
      'hilos-moderation-open-hilos_profile_name',
    ).click()
    await expectPageReady(page)
    await expect(page).toHaveURL('/hilos/moderation/hilos_profile_name')
    await expect(banner).toBeVisible()
    await expect(
      page.getByTestId('hilos-moderation-kind-overview'),
    ).toBeVisible()
    await expect(page.getByTestId('hilos-page-head-title')).toHaveText(
      'Profile name',
    )
    await expect(page).toHaveTitle('Profile name · Hilos Chat')
    await expect(page.getByTestId('hilos-moderation-kind-owner')).toHaveText(
      'Framework',
    )
    await expect(page.getByTestId('hilos-moderation-kind-key')).toHaveText(
      'hilos_profile_name',
    )
    await expect(page.getByTestId('hilos-moderation-kind-model')).toHaveText(
      'Text model · qwen2.5:0.5b — profile hilos.moderation.text',
    )
    await expect(page.getByTestId('hilos-moderation-kind-rules')).toHaveText(
      '3 rules of your own',
    )
    await expect(
      page.getByTestId('hilos-admin-page').getByTestId('hilos-hidden'),
    ).toHaveCount(0)
    await expectModerationKindTabs(
      expect,
      page,
      'hilos_profile_name',
      'overview',
    )
    await expect(
      page
        .getByTestId('hilos-admin-page')
        .locator('button, input, select, textarea, table'),
    ).toHaveCount(0)
    await document.unchanged()

    await page.getByTestId('hilos-page-tab-hilos_moderation_kind_rules').click()
    await expectPageReady(page)
    await expectModerationKindHead(expect, page, DECLARED_KINDS[0], 'rules')
    await expect(banner).toBeVisible()
    await expectModerationOwnRules(expect, page, DECLARED_KINDS[0].ownRules)
    const search = shownByTestId(page, 'hilos-table-search')
    await search.fill('')
    await search.pressSequentially('impersonation', { delay: 10 })
    await expectModerationOwnRules(expect, page, [
      DECLARED_KINDS[0].ownRules[0],
      DECLARED_KINDS[0].ownRules[2],
    ])
    await search.fill('')
    await expectModerationOwnRules(expect, page, DECLARED_KINDS[0].ownRules)
    await expect(
      page.getByTestId('hilos-admin-page').getByTestId('hilos-hidden'),
    ).toHaveCount(0)
    await expect(
      page.getByTestId('hilos-admin-page').locator('input, select, textarea'),
    ).toHaveCount(1)
    await expect(
      page.getByTestId('hilos-admin-page').locator('input'),
    ).toHaveAttribute('type', 'search')
    await document.unchanged()
  } finally {
    await document.dispose()
  }
})
