import { test, expect, type Page } from '@playwright/test'

import {
  clickSubmit,
  enterIdentifierAndPassword,
  logout,
  openSignIn,
  PASSWORD,
  createTestUser,
  analyticsPersonLink,
  expectAnalyticsOverview,
  expectAnalyticsPeopleAccessibility,
  expectAnalyticsPeopleWindow,
  expectAnalyticsPersonNavigation,
  expectCommunicationsAccessibility,
  expectDesktopShell,
  DAEMON_NODE_PATH,
  DAEMON_NODE_TABS,
  expectLicenseDependenciesAccessibility,
  expectDaemonNodeHead,
  expectDaemonNodeTableName,
  expectStandaloneWebsockets,
  watchDaemonWebsocketsFrames,
  expectLanguagePageHead,
  expectPasswordVisibility,
  openProfileTimeZone,
  shownByTestId,
  watchClientDocument,
  DAEMON_ROOT_PATH,
  expectDaemonPage,
  expectDaemonSectionHead,
  expectDaemonSectionAccessibility,
  expectPageReady,
  gotoPage,
  grantAdminToSelf,
  expectProfileHeadAccessibility,
  login,
  uniqueEmail,
  waitAnalyticsBrowserSession,
  watchDeliveryFrames,
  watchProfileRenameStatus,
  typeInto,
} from '../../../../../framework/frontend/e2e/index.js'

import { setAdminViewMode } from '../../../../../framework/frontend/scripts/adminViewMode.mjs'
import { createStandCommandChannel } from '../../../../../framework/frontend/scripts/standCommand.mjs'
import { seedTestUser } from '../../../../../framework/frontend/scripts/testUser.mjs'
import { AUTH_ACTION_LOGIN } from '../../../../../framework/frontend/core/src/index.js'

// Hilos accessibility (a11y) e2e — the rarely-run a11y category (see
// docs/agents/testing.md "Selective testing"). Asserts the framework viewport
// table exposes a correct accessibility tree over the live socket: an accessible
// name from the page heading it stands under, a labeled search box, and a sortable
// header that reports aria-sort and is operable from the keyboard. The tasks
// (React) layer checks this on the users table; the file grows as the a11y arc
// lands its later steps (modals, app-shell, focus).

/** Open the users admin and wait for the live table. */
async function openUsers(page: Page): Promise<void> {
  await gotoPage(expect, page, '/hilos/users')
  await expect(page.getByTestId('conn-state')).toHaveText('connected')
  await expect(page.getByTestId('hilos-viewport-table')).toBeVisible()
}

test('public Dependencies exposes a named table and keyboard navigation', async ({
  page,
}) => {
  await gotoPage(expect, page, '/license')
  const dependencies = page.getByTestId(
    'hilos-page-tab-hilos_license_dependencies',
  )
  await dependencies.focus()
  await expect(dependencies).toBeFocused()
  await dependencies.press('Enter')
  await expect(page).toHaveURL('/license/dependencies')
  await expectPageReady(expect, page)
  await expectLicenseDependenciesAccessibility(expect, page, 'Hilos Tasks')
})

test('the viewport table has an accessible name and a labelled search', async ({
  page,
}) => {
  await grantAdminToSelf(expect, page)

  await openUsers(page)

  // The page heading is the table's accessible name: a table carries no
  // title of its own, and points at the heading that already names it.
  await expect(page.getByRole('table', { name: 'Users' })).toBeVisible()

  // The search box is reachable by an accessible name, not just a placeholder.
  await expect(
    page.getByRole('searchbox', { name: 'Search users…' }),
  ).toBeVisible()
})

test('a sortable header reports aria-sort and sorts from the keyboard', async ({
  page,
}) => {
  await grantAdminToSelf(expect, page)

  await openUsers(page)

  const header = page.locator('th:has([data-id^="hilos-table-sort-"])').first()
  const sortButton = header.locator('[data-id^="hilos-table-sort-"]')

  // The sortable header announces a sort state for assistive tech.
  await expect(header).toHaveAttribute(
    'aria-sort',
    /^(none|ascending|descending)$/,
  )
  const before = await header.getAttribute('aria-sort')

  // Its sort control is operable from the keyboard: focusing it and pressing
  // Enter re-sorts the column and the header announces the new direction. The
  // table is server-windowed, so wait for the announced state to change rather
  // than snapshot it (the new descriptor round-trips to the backend).
  await sortButton.focus()
  await expect(sortButton).toBeFocused()
  await page.keyboard.press('Enter')
  await expect(header).not.toHaveAttribute('aria-sort', before ?? 'none')
  await expect(header).toHaveAttribute('aria-sort', /^(ascending|descending)$/)
})

test('the shell exposes a skip link and marks the active nav item', async ({
  page,
}) => {
  await gotoPage(expect, page, '/hilos')
  await expect(page.getByTestId('conn-state')).toHaveText('connected')

  // The skip link targets the main landmark.
  await expect(page.getByTestId('skip-to-content')).toHaveAttribute(
    'href',
    '#hilos-main-content',
  )
  await expect(page.locator('main#hilos-main-content')).toBeVisible()

  // No admin entry for a plain visitor: since HIL-610 a visitor carries no account
  // at all, so the shell draws no way into a surface the page access gate would
  // refuse anyway.
  await expect(page.getByTestId('nav-admin')).toHaveCount(0)

  // Granted, the same shell grows the entry — and marks it as the active nav
  // item, which is the half of this test's subject the gear had to arrive for:
  // aria-current is how assistive tech reads "you are here" off a nav.
  await grantAdminToSelf(expect, page)
  await gotoPage(expect, page, '/hilos')
  await expect(page.getByTestId('nav-admin')).toHaveAttribute(
    'aria-current',
    'page',
  )

  await expectPageReady(expect, page)
  const build = await expectDesktopShell(expect, page, 'nav-profile-name')
  const document = await watchClientDocument(page)
  try {
    await page.getByTestId('nav-brand').click()
    await expect(page).toHaveURL('/')
    await expectPageReady(expect, page)
    await document.unchanged()
    expect(await expectDesktopShell(expect, page, 'nav-profile-name')).toBe(
      build,
    )
  } finally {
    await document.dispose()
  }
})

test('each page titles the tab and announces the page on navigation', async ({
  page,
}) => {
  await grantAdminToSelf(expect, page)

  await openUsers(page)

  // The users admin titles the browser tab (framework label + app name), and the
  // live region carries the same title for a screen-reader announcement.
  await expect(page).toHaveTitle('Users · Hilos Tasks')
  await expect(page.getByTestId('page-title')).toHaveText('Users · Hilos Tasks')

  // A no-refresh navigation (the brand → home) updates both the tab title and
  // the announcement.
  await page.getByTestId('nav-brand').click()
  await expect(page).toHaveTitle('Tasks · Hilos Tasks')
  await expect(page.getByTestId('page-title')).toHaveText('Tasks · Hilos Tasks')
})

test('the home page exposes a single top-level heading', async ({ page }) => {
  await gotoPage(expect, page, '/')
  await expect(page.getByTestId('conn-state')).toHaveText('connected')
  await expect(page.getByRole('heading', { level: 1 })).toHaveText('Tasks')
})

test('password visibility toggle operates from keyboard on sign-in', async ({
  page,
}) => {
  const user = await createTestUser(expect, page)
  await logout(expect, page)
  await openSignIn(expect, page)
  await expect(page.getByTestId('auth-surface')).toBeVisible()
  await enterIdentifierAndPassword(expect, page, user.email, PASSWORD)
  await expectPasswordVisibility(
    expect,
    page,
    'auth-password',
    'auth-password-toggle',
  )
  // Ensure the surface remains open: toggling password visibility does not submit the form.
  await expect(page.getByTestId('auth-surface')).toBeVisible()
})

test('Communications exposes one named head and keyboard links between channel pages', async ({
  page,
}) => {
  await grantAdminToSelf(expect, page)
  await gotoPage(expect, page, '/hilos/communications/email')
  await expectCommunicationsAccessibility(
    expect,
    page,
    'overview',
    'Enable Email',
  )

  const configuration = page.getByTestId(
    'hilos-page-tab-hilos_communications_channel_configuration',
  )
  await configuration.focus()
  await expect(configuration).toBeFocused()
  await configuration.press('Enter')
  await expectPageReady(expect, page)
  await expectCommunicationsAccessibility(
    expect,
    page,
    'configuration',
    'Enable Email',
  )

  const deliveries = page.getByTestId(
    'hilos-page-tab-hilos_communications_deliveries',
  )
  await deliveries.focus()
  await expect(deliveries).toBeFocused()
  await deliveries.press('Enter')
  await expectPageReady(expect, page)
  await expectCommunicationsAccessibility(
    expect,
    page,
    'deliveries',
    'Enable Email',
  )

  const overview = page.getByTestId(
    'hilos-page-tab-hilos_communications_channel',
  )
  await overview.focus()
  await expect(overview).toBeFocused()
  await overview.press('Enter')
  await expectPageReady(expect, page)
  await expectCommunicationsAccessibility(
    expect,
    page,
    'overview',
    'Enable Email',
  )
})

test('language pages expose one named head and name their tables through it', async ({
  page,
}) => {
  await grantAdminToSelf(expect, page)
  await gotoPage(expect, page, '/hilos/i18n/languages/en')
  await expectPageReady(expect, page)
  await expectLanguagePageHead(expect, page, {
    code: 'en',
    nativeName: 'English',
    currentPage: 'hilos_i18n_language',
    appName: 'Hilos Tasks',
  })
  await expect(page.getByTestId('language-head-default')).toHaveText(
    'Default language',
  )
  await expect(page.getByTestId('language-head-default')).toHaveAttribute(
    'title',
    'Default language',
  )
  await expect(
    page.getByTestId('language-head-default').locator('i'),
  ).toHaveAttribute('aria-hidden', 'true')
  await expect(page.getByTestId('language-head-direction')).toHaveText(
    'Left to right',
  )

  for (const currentPage of [
    'hilos_i18n_language_names',
    'hilos_i18n_language_locales',
  ]) {
    const link = page.getByTestId(`hilos-page-tab-${currentPage}`)
    await link.focus()
    await expect(link).toBeFocused()
    await link.press('Enter')
    await expectPageReady(expect, page)
    await expectLanguagePageHead(expect, page, {
      code: 'en',
      nativeName: 'English',
      currentPage,
      appName: 'Hilos Tasks',
    })
    await expect(page.getByRole('table')).toHaveAccessibleName('English')
  }
})

test('the default language deletion explanation is named, keyboard readable and returns focus', async ({
  page,
}) => {
  await grantAdminToSelf(expect, page)
  await gotoPage(expect, page, '/hilos/i18n/languages')
  await expectPageReady(expect, page)
  const row = shownByTestId(page, /^hilos-table-row-/).filter({
    has: page.getByTestId('i18n-languages-delete-en'),
  })
  const trash = row.getByTestId('i18n-languages-delete-en')
  await expect(trash).toBeDisabled()
  await expect(trash).toHaveAccessibleDescription(
    'Cannot delete: this is the default language.',
  )
  const why = row.getByTestId('hilos-action-reason')
  await expect(why).toHaveAccessibleName('Why this action is unavailable')
  await why.focus()
  await why.press('Enter')
  const dialog = page.getByTestId('modal')
  await expect(dialog).toHaveRole('dialog')
  await expect(dialog).toHaveAttribute('aria-modal', 'true')
  await expect(dialog).toHaveAccessibleName('Action unavailable')
  await expect(dialog).toBeFocused()
  await expect(page.getByTestId('hilos-action-reason-body')).toHaveText(
    'Cannot delete: this is the default language.',
  )
  await dialog.press('Tab')
  await expect(page.getByTestId('modal-close')).toBeFocused()
  await page.getByTestId('modal-close').press('Tab')
  await expect(page.getByTestId('hilos-action-reason-close')).toBeFocused()
  await page.getByTestId('hilos-action-reason-close').press('Tab')
  await expect(page.getByTestId('modal-close')).toBeFocused()
  await dialog.press('Escape')
  await expect(dialog).toHaveCount(0)
  await expect(why).toBeFocused()
  await expect(trash).toBeDisabled()
  await expect(page.getByTestId('i18n-language-delete-window')).toHaveCount(0)
})

test('the Time zone dialog is named, keyboard operable and returns focus on Escape and Close', async ({
  page,
}) => {
  await createTestUser(expect, page)
  await gotoPage(expect, page, '/profile')
  const change = page.getByTestId('profile-time-zone-change')
  await expect(change).toHaveAccessibleName('Change')
  await change.focus()
  await change.press('Enter')

  const dialog = page.getByTestId('modal')
  const search = page.getByTestId('profile-time-zone-search')
  await expect(dialog).toHaveRole('dialog')
  await expect(dialog).toHaveAttribute('aria-modal', 'true')
  await expect(dialog).toHaveAccessibleName('Time zone')
  await expect(search).toHaveAccessibleName('Search time zones')
  await expect(search).toBeFocused()
  await typeInto(search, 'new york')
  const option = page.getByTestId('profile-time-zone-option-America/New_York')
  await expect(option).toBeVisible()
  await search.press('Tab')
  await expect(option).toBeFocused()
  await option.press('Tab')
  const close = page.getByTestId('profile-time-zone-close')
  await expect(close).toHaveAccessibleName('Close')
  await expect(close).toBeFocused()
  await close.press('Tab')
  await expect(page.getByTestId('modal-close')).toBeFocused()
  await page.getByTestId('modal-close').press('Tab')
  await expect(search).toBeFocused()
  await search.press('Escape')
  await expect(dialog).toBeHidden()
  await expect(change).toBeFocused()

  await openProfileTimeZone(expect, page)
  await close.focus()
  await close.press('Enter')
  await expect(dialog).toBeHidden()
  await expect(change).toBeFocused()
})

test('the profile name dialog exposes accessible focus and dirty-close behavior', async ({
  page,
}) => {
  const user = await createTestUser(expect, page)
  await gotoPage(expect, page, '/profile')
  await expect(page.getByTestId('conn-state')).toHaveText('connected')
  await expect(page.getByTestId('profile-name')).toHaveText(user.name)

  await expectProfileHeadAccessibility(expect, page, user.name)
  const opener = page.getByTestId('profile-edit')
  await clickSubmit(expect, opener)
  await expect(page.getByTestId('modal')).toHaveAccessibleName(
    "Confirm it's you",
  )
  await typeInto(page.getByTestId('step-up-password'), PASSWORD)
  await clickSubmit(expect, page.getByTestId('profile-name-step-up-confirm'))

  const dialog = page.getByTestId('modal')
  const field = page.getByTestId('profile-name-input')
  await expect(dialog).toHaveRole('dialog')
  await expect(dialog).toHaveAttribute('aria-modal', 'true')
  await expect(dialog).toHaveAccessibleName('Change name')
  await expect(field).toHaveAccessibleName('Display name')
  await expect(field).toBeFocused()
  const status = await watchProfileRenameStatus(expect, page)
  await status.unchanged(false)

  const draft = 'Accessible draft'
  await typeInto(field, draft)
  const save = page.getByTestId('profile-rename-save')
  const cancel = page.getByTestId('profile-rename-cancel')
  const close = dialog.getByTestId('modal-close')
  const confirmation = page.getByTestId('modal-confirm')
  const keepEditing = page.getByTestId('modal-confirm-cancel')
  const discard = page.getByTestId('modal-confirm-discard')
  await expect(cancel).toHaveAccessibleName('Cancel')
  await expect(close).toHaveAccessibleName('Close')
  await expect(save).toHaveAccessibleName('Save')
  await expect(save).toBeEnabled()
  await status.unchanged(false)

  // The last enabled control wraps back to the close button in either direction.
  await close.focus()
  await close.press('Shift+Tab')
  await expect(save).toBeFocused()
  await save.press('Tab')
  await expect(close).toBeFocused()

  // Escape and every explicit close route raise the same discard confirmation.
  await page.keyboard.press('Escape')
  await expect(confirmation).toHaveRole('alertdialog')
  await expect(confirmation).toHaveAccessibleName('Discard changes?')
  await expect(confirmation).toBeFocused()
  await clickSubmit(expect, keepEditing)
  await expect(confirmation).toHaveCount(0)
  await expect(field).toHaveValue(draft)

  await clickSubmit(expect, cancel)
  await expect(confirmation).toBeVisible()
  await clickSubmit(expect, keepEditing)
  await expect(confirmation).toHaveCount(0)
  await expect(field).toHaveValue(draft)

  await clickSubmit(expect, close)
  await expect(confirmation).toBeVisible()
  await clickSubmit(expect, keepEditing)
  await expect(confirmation).toHaveCount(0)
  await expect(field).toHaveValue(draft)
  await status.unchanged(false)

  await dialog.click({ position: { x: 2, y: 2 } })
  await expect(confirmation).toBeVisible()
  await clickSubmit(expect, discard)
  await expect(dialog).toHaveCount(0)
  await expect(opener).toBeFocused()
})

test('Node pages expose one entity heading, link tabs and tables named through the Shell', async ({
  page,
}) => {
  // Each addressed page has a real subscription and keyboard navigation.
  test.slow()
  const reports = watchDaemonWebsocketsFrames(page)
  try {
    await grantAdminToSelf(expect, page)
    await gotoPage(expect, page, DAEMON_NODE_PATH)
    await expectDaemonNodeHead(expect, page, 'hilos_daemon_node')
    for (const tab of DAEMON_NODE_TABS.slice(1)) {
      const link = page.getByTestId(`hilos-page-tab-${tab.key}`)
      await link.focus()
      await expect(link).toBeFocused()
      await link.press('Enter')
      await expect(page).toHaveURL(`${DAEMON_NODE_PATH}${tab.path}`)
      await expectDaemonNodeHead(expect, page, tab.key)
      if (tab.key === 'hilos_daemon_websockets') {
        await expectStandaloneWebsockets(expect, page, reports.frames)
      } else if (tab.key !== 'hilos_daemon_env')
        await expectDaemonNodeTableName(expect, page)
      await page.getByTestId('hilos-page-tab-hilos_daemon_node').click()
      await expectDaemonNodeHead(expect, page, 'hilos_daemon_node')
    }
  } finally {
    reports.stop()
  }
})

test('Analytics overview exposes one heading, breadcrumb landmark and semantic visitor tiles', async ({
  page,
}) => {
  await grantAdminToSelf(expect, page)
  await gotoPage(expect, page, '/hilos/analytics')
  await expectPageReady(expect, page)
  await expectAnalyticsOverview(expect, page, 'react')

  const head = page.getByTestId('hilos-page-head')
  await expect(page.getByRole('heading', { level: 1 })).toHaveText('Analytics')
  await expect(page.locator('h1')).toHaveCount(1)

  const breadcrumb = head.getByTestId('hilos-breadcrumb')
  await expect(breadcrumb).toHaveRole('navigation')
  await expect(breadcrumb).toHaveAccessibleName('Breadcrumb')
  await expect(breadcrumb.getByRole('link')).toHaveText(['Hilos'])
  const currentCrumb = breadcrumb.locator('li').last()
  await expect(currentCrumb).toContainText('Analytics')
  await expect(currentCrumb).toHaveAttribute('aria-current', 'page')
  await expect(currentCrumb.getByRole('link')).toHaveCount(0)

  const tiles = page.getByTestId('hilos-analytics-overview-tiles')
  await expect(tiles.locator('dl')).toHaveCount(3)
  for (const period of ['week', 'day', 'hour'] as const) {
    const dl = tiles.getByTestId(`hilos-analytics-visitors-${period}`)
    await expect(dl).toBeVisible()
    await expect(dl.locator('dt')).toHaveCount(1)
    await expect(dl.locator('dd')).toHaveCount(2)
    await expect(dl.locator('dt')).toBeVisible()
    await expect(
      dl.getByTestId(`hilos-analytics-visitors-${period}-count`),
    ).toBeVisible()
    await expect(
      dl.getByTestId(`hilos-analytics-visitors-${period}-since`),
    ).toBeVisible()
  }

  const icon = head
    .locator('span[aria-hidden="true"]')
    .filter({ has: page.locator('i') })
  await expect(icon).toBeVisible()
  await expect(icon).toHaveAttribute('aria-hidden', 'true')
  await expect(
    page.getByTestId('hilos-analytics-overview-skeleton'),
  ).toHaveCount(0)

  await expect(tiles.getByRole('link')).toHaveCount(0)
  await expect(tiles.getByRole('button')).toHaveCount(0)
  for (const period of ['week', 'day', 'hour'] as const) {
    const count = tiles.getByTestId(`hilos-analytics-visitors-${period}-count`)
    await expect(count.getByRole('link')).toHaveCount(0)
    await expect(count.getByRole('button')).toHaveCount(0)
  }
})

test('Analytics People and Person name their tables and support keyboard navigation and sorting', async ({
  page,
}) => {
  // A real sign-in and journal-writer wait precede the keyboard journey.
  test.slow()
  const user = await seedTestUser({ email: uniqueEmail() })
  await gotoPage(expect, page, '/')
  await openSignIn(expect, page)
  await login(expect, page, user.email, PASSWORD)
  await grantAdminToSelf(expect, page)
  await waitAnalyticsBrowserSession(
    expect,
    createStandCommandChannel({ timeoutMs: 5_000 }),
    user.userId,
    AUTH_ACTION_LOGIN,
  )
  const frames = watchDeliveryFrames(page)
  try {
    await gotoPage(expect, page, '/hilos/analytics')
    await expectAnalyticsOverview(expect, page, 'react')
    const people = page.getByTestId('hilos-page-tab-hilos_analytics_people')
    await people.focus()
    await expect(people).toBeFocused()
    await people.press('Enter')
    await expect(page).toHaveURL('/hilos/analytics/people')
    await expectPageReady(expect, page)
    const today = await expectAnalyticsPeopleWindow(
      expect,
      page,
      frames,
      'today',
    )
    await expect(page.getByRole('heading', { level: 1 })).toHaveCount(1)
    await expectAnalyticsPeopleAccessibility(
      expect,
      page,
      'descending',
      'react',
    )
    const sorting = frames.sent.length
    const sort = page
      .getByTestId('hilos-analytics-people-table')
      .getByTestId('hilos-table-sort-lastSeenTs')
    await sort.focus()
    await expect(sort).toBeFocused()
    await sort.press('Enter')
    const ascending = await expectAnalyticsPeopleWindow(
      expect,
      page,
      frames,
      'today',
      sorting,
    )
    expect(ascending.request.sort).toEqual([
      { field: 'lastSeenTs', direction: 'asc' },
    ])
    expect(ascending.request.filter).toEqual(today.request.filter)
    await expectAnalyticsPeopleAccessibility(expect, page, 'ascending', 'react')
    await expect(sort).toBeFocused()

    const person = analyticsPersonLink(page, user.userId)
    await person.focus()
    await expect(person).toBeFocused()
    await person.press('Enter')
    await expect(page).toHaveURL(`/hilos/analytics/people/${user.userId}`)
    await expectPageReady(expect, page)
    await expectAnalyticsPersonNavigation(expect, page, frames, user)
    const heading = page.getByRole('heading', { level: 1 })
    const label = `${user.name} (#${user.userId})`
    const table = page
      .getByTestId('hilos-analytics-browser-sessions-table')
      .getByRole('table', { name: label, exact: true })
    await expect(table).toBeVisible()
    await expect(table).toHaveAttribute(
      'aria-labelledby',
      (await heading.getAttribute('id'))!,
    )
    const head = page.getByTestId('hilos-page-head')
    const avatar = head.getByTestId('analytics-person-head-avatar')
    await expect(avatar).toHaveAttribute('aria-hidden', 'true')
    await expect(avatar.locator('img:not([alt=""])')).toHaveCount(0)
    await expect(head.getByRole('img')).toHaveCount(0)
    const breadcrumb = head.getByTestId('hilos-breadcrumb')
    await expect(breadcrumb).toHaveRole('navigation')
    await expect(breadcrumb).toHaveAccessibleName('Breadcrumb')
    const current = breadcrumb.locator('[aria-current="page"]')
    await expect(current).toHaveCount(1)
    await expect
      .poll(() =>
        current.evaluate((crumb) => {
          const words = crumb.cloneNode(true) as Element
          for (const hidden of words.querySelectorAll('[aria-hidden="true"]'))
            hidden.remove()
          return words.textContent?.replace(/\s+/g, ' ').trim() ?? ''
        }),
      )
      .toBe(label)
    await expect(current.getByRole('link')).toHaveCount(0)
    await expect(head.getByTestId('hilos-page-tabs')).toHaveCount(0)
    await expect(page.getByRole('tablist')).toHaveCount(0)
  } finally {
    await test.info().attach('analytics-people-accessibility', {
      body: JSON.stringify(
        { sent: frames.sent, packets: frames.packets },
        null,
        2,
      ),
      contentType: 'application/json',
    })
    frames.stop()
  }
})

test.describe('Daemon section accessibility', () => {
  test.afterEach(() => setAdminViewMode(false))

  for (const viewer of [false, true]) {
    test(`Daemon section links, shared heading and Placement keyboard controls as ${viewer ? 'a viewer' : 'an admin'}`, async ({
      page,
    }) => {
      await setAdminViewMode(viewer)
      if (!viewer) await grantAdminToSelf(expect, page)
      await gotoPage(expect, page, DAEMON_ROOT_PATH)
      await expectDaemonPage(expect, page, 'hilos_daemon')
      await expectDaemonSectionHead(expect, page, 'hilos_daemon')
      const placement = page.getByTestId(
        'hilos-page-tab-hilos_daemon_placement',
      )
      await placement.focus()
      await expect(placement).toBeFocused()
      await placement.press('Enter')
      await expectDaemonPage(expect, page, 'hilos_daemon_placement')
      await expectDaemonSectionAccessibility(expect, page)
      if (viewer)
        await expect(page.getByTestId('view-mode-banner')).toBeVisible()
      const overview = page.getByTestId('hilos-page-tab-hilos_daemon')
      await overview.focus()
      await expect(overview).toBeFocused()
      await overview.press('Enter')
      await expectDaemonPage(expect, page, 'hilos_daemon')
      await expectDaemonSectionHead(expect, page, 'hilos_daemon')
    })
  }
})
