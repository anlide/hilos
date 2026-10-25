import { expect, test } from '@playwright/test'

import { shownByTestId } from '../../../../../framework/frontend/e2e/index.js'
import { signUpAdmin } from '../helpers/adminGrant.js'
import { setAdminViewMode } from '../helpers/adminViewMode.js'
import { expectPageReady, gotoPage, PAGE_READY } from '../helpers/page.js'

// HIL-1629: Moderation kinds root page e2e (/hilos/moderation).
// Published moderation kinds are server-windowed in HilosModerationKindsTable
// and accessible through the admin dashboard under People.

test.use({ viewport: { width: 1440, height: 1000 }, colorScheme: 'light' })

const DECLARED_KINDS = [
  {
    kind: 'hilos_profile_name',
    title: 'Profile name',
  },
  {
    kind: 'hilos_profile_photo',
    title: 'Profile photo',
  },
  {
    kind: 'chat_message',
    title: 'Chat message',
  },
  {
    kind: 'chat_message_picture',
    title: 'Chat message picture',
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

test('displays Open links with correct href and aria-label attributes', async ({
  page,
}) => {
  await signUpAdmin(page)
  await gotoPage(page, '/hilos/moderation', PAGE_READY)
  await expect(page.getByTestId('hilos-moderation-kinds-table')).toBeVisible()

  for (const { kind, title } of DECLARED_KINDS) {
    const openLink = shownByTestId(page, `hilos-moderation-open-${kind}`)
    await expect(openLink).toBeVisible()
    await expect(openLink).toHaveAttribute('href', `/hilos/moderation/${kind}`)
    await expect(openLink).toHaveAttribute('aria-label', `Open ${title}`)
  }
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
})
