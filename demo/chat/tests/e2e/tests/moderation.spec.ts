import { expect, test } from '@playwright/test'

import {
  shownByTestId,
  watchClientDocument,
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

// Framework moderation kinds and their read-only Overview, carried by chat.
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
      await expect(page.getByTestId('hilos-page-tabs')).toHaveCount(0)
      await expect(page.getByTestId('hilos-admin-children')).toHaveCount(0)
      await expect(
        page
          .getByTestId('hilos-admin-page')
          .locator('button, input, select, textarea, table, [role="tablist"]'),
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

    // Back to the last kind, then skip the list entry in browser history:
    // the same detail component must replace picture data with text data.
    await page.goBack()
    await expectPageReady(page)
    await expect(page.getByTestId('hilos-moderation-kind-key')).toHaveText(
      'chat_message_picture',
    )
    await page.evaluate(() => window.history.go(-2))
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

    await page.evaluate(() => window.history.go(2))
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
    await expect(page.getByTestId('hilos-page-tabs')).toHaveCount(0)
    await expect(
      page
        .getByTestId('hilos-admin-page')
        .locator('button, input, select, textarea, table'),
    ).toHaveCount(0)
    await document.unchanged()
  } finally {
    await document.dispose()
  }
})
