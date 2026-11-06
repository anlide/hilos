import { randomUUID } from 'node:crypto'
import type { Page } from '@playwright/test'
import { test, expect } from '../test.js'

import {
  clickSubmit,
  cleanupFixtures,
  type TestCleanup,
  deleteModerationOwnRules,
  dismissToasts,
  enterIdentifierAndPassword,
  logout,
  openSignIn,
  PASSWORD,
  createTestUser,
  analyticsBrowserSessionPath,
  analyticsPersonLink,
  analyticsSessionLink,
  ANALYTICS_SESSION_LIST_HANDLE,
  ANALYTICS_SESSION_LIST_PATH,
  expectAnalyticsBrowserSessionHead,
  expectAnalyticsOverview,
  expectAnalyticsPeopleAccessibility,
  expectAnalyticsPeoplePerson,
  expectAnalyticsPeopleWindow,
  expectAnalyticsPersonNavigation,
  expectAnalyticsSessionListAccessibility,
  expectAnalyticsSessionListWindow,
  expectCommunicationsAccessibility,
  expectDesktopShell,
  DAEMON_NODE_PATH,
  DAEMON_NODE_TABS,
  DAEMON_STANDALONE_NODE_NAME,
  expectLicenseDependenciesAccessibility,
  expectDaemonNodeHead,
  expectDaemonNodeTableName,
  expectStandaloneWebsockets,
  watchDaemonWebsocketsFrames,
  expectLanguagePageHead,
  MAINTENANCE_CIRCLE_PATH,
  MAINTENANCE_PATH,
  MAINTENANCE_WORDS_PATH,
  expectMaintenancePageCurrent,
  expectModerationAddDialogAccessibility,
  expectModerationPagesAccessibility,
  expectModerationRowDialogsAccessibility,
  expectModerationViewerAccessibility,
  expectPasswordVisibility,
  openProfileTimeZone,
  ROWS,
  shownByTestId,
  watchClientDocument,
  DAEMON_ROOT_PATH,
  expectDaemonPage,
  expectDaemonSectionHead,
  expectDaemonSectionAccessibility,
  expectPageReady,
  gotoPage,
  readI18nRowId,
  PAGE_READY,
  grantAdminToSelf,
  expectProfileHeadAccessibility,
  login,
  uniqueEmail,
  waitAnalyticsBrowserSession,
  watchDeliveryFrames,
  watchProfileRenameStatus,
  typeInto,
  STATUS_COMPONENTS_PATH,
  STATUS_PATH,
  STATUS_WORKS_PATH,
  deliveryObject,
  expectPublicStatusAccessibility,
  expectPublicStatusPair,
  expectPublicStatusReadOnly,
  expectStatusSubscribeDialog,
  expectStatusWorkScheduleDialog,
  expectStatusWorksAccessibility,
  gotoPublicStatus,
  watchPublicStatus,
  watchStatusFrames,
} from '../../../../../framework/frontend/e2e/index.js'

import { setAdminViewMode } from '../../../../../framework/frontend/scripts/adminViewMode.mjs'
import { createStandCommandChannel } from '../../../../../framework/frontend/scripts/standCommand.mjs'
import { seedTestUser } from '../../../../../framework/frontend/scripts/testUser.mjs'
import { AUTH_ACTION_LOGIN } from '../../../../../framework/frontend/core/src/index.js'

// spec-owner: demo — carries Tasks' React browser journeys for these shared pages

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
  await gotoPage(expect, page, '/hilos/communications/channel/email')
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
  const languageId = await readI18nRowId(expect, page, 'language', 'en')
  await gotoPage(expect, page, `/hilos/i18n/language/${languageId}`)
  await expectPageReady(expect, page)
  await expectLanguagePageHead(expect, page, {
    code: 'en',
    id: languageId,
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
      id: languageId,
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
    'This language cannot be deleted because it is the default language.',
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
    'This language cannot be deleted because it is the default language.',
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
    await expectDaemonNodeHead(
      expect,
      page,
      'hilos_daemon_node',
      'standalone',
      { nodeName: DAEMON_STANDALONE_NODE_NAME },
    )
    for (const tab of DAEMON_NODE_TABS.slice(1)) {
      const link = page.getByTestId(`hilos-page-tab-${tab.key}`)
      await link.focus()
      await expect(link).toBeFocused()
      await link.press('Enter')
      await expect(page).toHaveURL(`${DAEMON_NODE_PATH}${tab.path}`)
      await expectDaemonNodeHead(expect, page, tab.key, 'standalone', {
        nodeName: DAEMON_STANDALONE_NODE_NAME,
      })
      if (tab.key === 'hilos_daemon_websockets') {
        await expectStandaloneWebsockets(expect, page, reports.frames, {
          nodeName: DAEMON_STANDALONE_NODE_NAME,
        })
      } else if (tab.key !== 'hilos_daemon_env')
        await expectDaemonNodeTableName(
          expect,
          page,
          DAEMON_STANDALONE_NODE_NAME,
        )
      await page.getByTestId('hilos-page-tab-hilos_daemon_node').click()
      await expectDaemonNodeHead(
        expect,
        page,
        'hilos_daemon_node',
        'standalone',
        { nodeName: DAEMON_STANDALONE_NODE_NAME },
      )
    }
  } finally {
    reports.stop()
  }
})

test.describe('Analytics accessibility', () => {
  // Today, This week and This hour are calendar windows of the browser's zone:
  // it is stated here rather than left to the stand, whose process runs in UTC
  // and whose project zone is Europe/Warsaw.
  test.use({ timezoneId: 'Africa/Malabo' })

  test('Analytics overview names its visitor count links and opens the chosen period from the keyboard', async ({
    page,
  }) => {
    const frames = watchDeliveryFrames(page)
    try {
      await grantAdminToSelf(expect, page)
      await gotoPage(expect, page, '/hilos/analytics')
      await expectPageReady(expect, page)
      await expectAnalyticsOverview(expect, page, 'react')

      const overview = page.getByTestId('hilos-page-tab-hilos_analytics')
      for (const [tile, period] of [
        ['week', 'this-week'],
        ['day', 'today'],
        ['hour', 'this-hour'],
      ] as const) {
        const link = page
          .getByTestId(`hilos-analytics-visitors-${tile}`)
          .getByRole('link')
        await expect(link).toHaveCount(1)
        // The link is named by the very number its tile shows (WCAG 2.5.3).
        const count = page.getByTestId(`hilos-analytics-visitors-${tile}-count`)
        await expect(count).toHaveText(/\d/)
        await expect(link).toHaveAccessibleName(
          (await count.innerText()).trim(),
        )
        await expect(link).toHaveAttribute(
          'href',
          `${ANALYTICS_SESSION_LIST_PATH}/${period}`,
        )
        const opening = frames.sent.length
        await link.focus()
        await expect(link).toBeFocused()
        await link.press('Enter')
        await expect(page).toHaveURL(`${ANALYTICS_SESSION_LIST_PATH}/${period}`)
        await expectPageReady(expect, page)
        await expectAnalyticsSessionListWindow(
          expect,
          page,
          frames,
          period,
          opening,
        )

        await overview.focus()
        await expect(overview).toBeFocused()
        await overview.press('Enter')
        await expect(page).toHaveURL('/hilos/analytics')
        await expectPageReady(expect, page)
        await expectAnalyticsOverview(expect, page, 'react')
      }
    } finally {
      await test.info().attach('analytics-overview-accessibility', {
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

  test('Analytics Browser sessions, People and Person name their tables and support keyboard navigation and sorting', async ({
    page,
  }) => {
    const user = await seedTestUser({ email: uniqueEmail() })
    await gotoPage(expect, page, '/')
    await openSignIn(expect, page)
    await login(expect, page, user.email, PASSWORD)
    await grantAdminToSelf(expect, page)
    const session = await waitAnalyticsBrowserSession(
      expect,
      createStandCommandChannel({ timeoutMs: 5_000 }),
      user.userId,
      AUTH_ACTION_LOGIN,
    )
    const frames = watchDeliveryFrames(page)
    try {
      await gotoPage(expect, page, '/hilos/analytics')
      await expectAnalyticsOverview(expect, page, 'react')

      const sessions = page.getByTestId(
        'hilos-page-tab-hilos_analytics_browser_sessions',
      )
      await sessions.focus()
      await expect(sessions).toBeFocused()
      let changing = frames.sent.length
      await sessions.press('Enter')
      await expect(page).toHaveURL(ANALYTICS_SESSION_LIST_PATH)
      await expectPageReady(expect, page)
      const listed = await expectAnalyticsSessionListWindow(
        expect,
        page,
        frames,
        'today',
        changing,
      )
      await expectAnalyticsSessionListAccessibility(
        expect,
        page,
        'descending',
        'react',
      )
      changing = frames.sent.length
      const listSort = page
        .getByTestId(ANALYTICS_SESSION_LIST_HANDLE)
        .getByTestId('hilos-table-sort-lastSeenTs')
      await listSort.focus()
      await expect(listSort).toBeFocused()
      await listSort.press('Enter')
      const listAscending = await expectAnalyticsSessionListWindow(
        expect,
        page,
        frames,
        'today',
        changing,
      )
      expect(listAscending.request.sort).toEqual([
        { field: 'lastSeenTs', direction: 'asc' },
      ])
      expect(listAscending.request.filter).toEqual(listed.request.filter)
      await expectAnalyticsSessionListAccessibility(
        expect,
        page,
        'ascending',
        'react',
      )
      await expect(listSort).toBeFocused()

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
      await expectAnalyticsPeopleAccessibility(
        expect,
        page,
        'ascending',
        'react',
      )
      await expect(sort).toBeFocused()

      const descendingSort = frames.sent.length
      await sort.press('Enter')
      const descending = await expectAnalyticsPeopleWindow(
        expect,
        page,
        frames,
        'today',
        descendingSort,
      )
      expect(descending.request.sort).toEqual([
        { field: 'lastSeenTs', direction: 'desc' },
      ])
      expect(descending.request.filter).toEqual(today.request.filter)
      await expectAnalyticsPeopleAccessibility(
        expect,
        page,
        'descending',
        'react',
      )
      await expectAnalyticsPeoplePerson(expect, page, descending, user)
      await expect(sort).toBeFocused()

      const person = analyticsPersonLink(page, user.userId)
      await person.focus()
      await expect(person).toBeFocused()
      await person.press('Enter')
      await expect(page).toHaveURL(`/hilos/analytics/person/${user.userId}`)
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

      const sessionId = session.browserSessionId!
      const open = analyticsSessionLink(page, sessionId)
      await open.focus()
      await expect(open).toBeFocused()
      await open.press('Enter')
      await expect(page).toHaveURL(analyticsBrowserSessionPath(sessionId))
      await expectPageReady(expect, page)
      await expectAnalyticsBrowserSessionHead(
        expect,
        page,
        `Browser session #${sessionId}`,
      )
      const crumb = page.getByTestId(
        'hilos-breadcrumb-hilos_analytics_browser_sessions',
      )
      await crumb.focus()
      await expect(crumb).toBeFocused()
      changing = frames.sent.length
      await crumb.press('Enter')
      await expect(page).toHaveURL(ANALYTICS_SESSION_LIST_PATH)
      await expectPageReady(expect, page)
      await expectAnalyticsSessionListWindow(
        expect,
        page,
        frames,
        'today',
        changing,
      )
      await expectAnalyticsSessionListAccessibility(
        expect,
        page,
        'descending',
        'react',
      )
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
      await expectDaemonSectionAccessibility(
        expect,
        page,
        false,
        DAEMON_STANDALONE_NODE_NAME,
      )
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

test.describe('Moderation accessibility', () => {
  const kind = 'hilos_profile_name'
  const rulesPath = `/hilos/moderation/kind/${kind}/rules`
  const moderationTest = test.extend<{
    cleanup: TestCleanup
    ownedRule: string
  }>({
    cleanup: cleanupFixtures,
    ownedRule: async ({ page, cleanup }, use) => {
      // Reserve cleanup before the UI creates this scenario's only rule.
      const rule = `Allow ordinary names. Accessible policy ${randomUUID()}.`
      cleanup.defer(async () => {
        await gotoPage(expect, page, rulesPath)
        await dismissToasts(page)
        await deleteModerationOwnRules(expect, page, [rule])
      })
      await grantAdminToSelf(expect, page)
      await use(rule)
    },
  })

  moderationTest(
    'names pages and keeps keyboard dialogs safe for administrators and viewers',
    async ({ page, browser, ownedRule, cleanup }) => {
      const row = page
        .getByTestId('hilos-moderation-rules-table')
        .locator(ROWS)
        .filter({ has: page.getByText(ownedRule, { exact: true }) })

      await moderationTest.step(
        'Section and kind pages expose named tables and keyboard navigation',
        async () => {
          await gotoPage(expect, page, '/hilos/moderation')
          await expectModerationPagesAccessibility(expect, page, kind)
        },
      )

      await moderationTest.step(
        'Add names its field, traps focus and preserves a draft when discard is canceled',
        async () => {
          await expectModerationAddDialogAccessibility(
            expect,
            page,
            kind,
            ownedRule,
          )
          await dismissToasts(page)
        },
      )

      await moderationTest.step(
        'Edit and Delete return focus after Escape and explicit keyboard close',
        () =>
          expectModerationRowDialogsAccessibility(
            expect,
            page,
            kind,
            ownedRule,
          ),
      )

      await moderationTest.step(
        'A viewer can read and draft named dialogs while keyboard navigation skips every submit',
        async () => {
          const { baseURL, ignoreHTTPSErrors } = test.info().project.use
          const context = await browser.newContext({
            baseURL,
            ignoreHTTPSErrors,
          })
          cleanup.defer(() => context.close())
          cleanup.defer(() => setAdminViewMode(false))
          await setAdminViewMode(true)
          const viewer = await context.newPage()
          const sentWrites = await expectModerationViewerAccessibility(
            expect,
            viewer,
            kind,
            ownedRule,
          )
          await gotoPage(expect, page, rulesPath)
          await expect(row).toBeVisible()
          expect(sentWrites()).toEqual([0, 0, 0])
        },
      )
    },
  )
})

test.describe('Maintenance accessibility', () => {
  // An explicit browser zone, picked at random when the group was written.
  test.use({ timezoneId: 'Asia/Tomsk' })

  test('Maintenance links open their pages from the keyboard, the tables are named and the Circle Add dialog returns focus', async ({
    page,
  }) => {
    await grantAdminToSelf(expect, page)
    await gotoPage(expect, page, MAINTENANCE_PATH, PAGE_READY)
    await expectMaintenancePageCurrent(expect, page, 'hilos_maintenance')

    /**
     * Takes one of the section's links with Enter and lands on its page: the
     * address, one h1 and the current mark move together.
     */
    const follow = async (
      pageKey:
        | 'hilos_maintenance'
        | 'hilos_maintenance_circle'
        | 'hilos_maintenance_words',
      path: string,
    ): Promise<void> => {
      const link = page.getByTestId(`hilos-page-tab-${pageKey}`)
      await link.focus()
      await expect(link).toBeFocused()
      await link.press('Enter')
      await expect(page).toHaveURL(new RegExp(`${path}$`))
      await expectPageReady(expect, page)
      await expectMaintenancePageCurrent(expect, page, pageKey)
    }

    await test.step('Circle opens from the keyboard and names its table', async () => {
      await follow('hilos_maintenance_circle', MAINTENANCE_CIRCLE_PATH)
      await expect(
        page.getByTestId('hilos-maintenance-circle-table').getByRole('table'),
      ).toHaveAccessibleName('Who will check the system')
    })

    await test.step('Add opens from the keyboard and returns focus on Escape and Cancel', async () => {
      // Nothing is submitted: the dialog's keyboard and focus path is the subject,
      // so the circle is left exactly as the scenario found it.
      const add = shownByTestId(
        page.getByTestId('hilos-maintenance-circle-table'),
        'hilos-table-main-action',
      )
      const dialog = page.getByTestId('modal')
      const field = dialog.getByTestId('hilos-maintenance-circle-add-field')
      await expect(add).toHaveAccessibleName('Add a verifier')
      await add.focus()
      await add.press('Enter')
      await expect(dialog).toHaveRole('dialog')
      await expect(dialog).toHaveAttribute('aria-modal', 'true')
      await expect(dialog).toHaveAccessibleName('Add a verifier')
      await expect(field).toHaveAccessibleName('Address')
      await expect(field).toBeFocused()
      await field.press('Escape')
      await expect(dialog).toBeHidden()
      await expect(add).toBeFocused()

      await add.press('Enter')
      await expect(field).toBeFocused()
      const cancel = dialog.getByTestId('hilos-maintenance-circle-add-cancel')
      await expect(cancel).toHaveAccessibleName('Cancel')
      await cancel.focus()
      await cancel.press('Enter')
      await expect(dialog).toBeHidden()
      await expect(add).toBeFocused()
    })

    await test.step('Words opens from the keyboard and names its table', async () => {
      await follow('hilos_maintenance_words', MAINTENANCE_WORDS_PATH)
      await expect(
        page.getByTestId('hilos-maintenance-words-table').getByRole('table'),
      ).toHaveAccessibleName('Words used to announce maintenance')
    })

    await test.step('Overview opens from the keyboard', () =>
      follow('hilos_maintenance', MAINTENANCE_PATH))
  })
})

// This project declares no Status components: its catalog is the framework's
// single fallback, Service. The state filter offers the declared states by their
// place; test-owned vocabulary, never read from the SDK that draws them.
const STATUS_SERVICE = 'service'
const STATUS_STATES = [
  'operational',
  'checking',
  'partial_outage',
  'major_outage',
  'maintenance',
  'unknown',
] as const
const STATUS_STATE_LABELS: Record<(typeof STATUS_STATES)[number], string> = {
  operational: 'Operational',
  checking: 'Checking',
  partial_outage: 'Partial outage',
  major_outage: 'Major outage',
  maintenance: 'Maintenance',
  unknown: 'Unknown',
}

/** The page has settled at its address under one heading, its tab alone current. */
async function expectStatusPage(
  page: Page,
  pageKey: string,
  path: string,
): Promise<void> {
  await expectPageReady(expect, page)
  await expect(page).toHaveURL(path)
  const heading = page.getByRole('heading', { level: 1 })
  await expect(heading).toHaveCount(1)
  await expect(heading).toHaveText('Status')
  const tabs = page.getByRole('navigation', { name: 'Status pages' })
  await expect(tabs.locator('[aria-current="page"]')).toHaveCount(1)
  await expect(tabs.getByTestId(`hilos-page-tab-${pageKey}`)).toHaveAttribute(
    'aria-current',
    'page',
  )
}

test.describe('Status accessibility', () => {
  // The public day strip and the maintenance form read moments in the browser's
  // zone: picked at random from the IANA list when these tests were written, and
  // stated here rather than left to the stand, whose project zone is
  // Europe/Warsaw.
  test.use({ timezoneId: 'Pacific/Norfolk' })

  test('Status pages name their tables, search, filter and sort from the keyboard, and the schedule dialog keeps focus', async ({
    page,
  }) => {
    const status = watchStatusFrames(page)
    const frames = watchDeliveryFrames(page)
    try {
      await grantAdminToSelf(expect, page)

      await test.step('the Overview titles the tab and opens Components from its live count by keyboard', async () => {
        await gotoPage(expect, page, STATUS_PATH, PAGE_READY)
        await expectStatusPage(page, 'hilos_status', STATUS_PATH)
        await expect(page).toHaveTitle('Status · Hilos Tasks')
        await expect(page.getByTestId('page-title')).toHaveText(
          'Status · Hilos Tasks',
        )
        await expect.poll(() => status.latest()).toBeDefined()
        const { components } = status.latest()!
        const link = page.getByTestId('hilos-status-components-link')
        await expect(link).toHaveRole('link')
        await expect(link).toContainText(
          `${components.operationalCount} / ${components.totalCount} operational`,
        )
        await link.focus()
        await expect(link).toBeFocused()
        await link.press('Enter')
        await expectStatusPage(
          page,
          'hilos_status_components',
          STATUS_COMPONENTS_PATH,
        )
      })

      await test.step('Components names its table, and its search and state filter answer the keyboard', async () => {
        await expect.poll(() => status.components.length).toBe(1)
        const { rows } = status.components[0]
        const [row] = (Array.isArray(rows) ? rows : []).map(deliveryObject)
        const component = deliveryObject(deliveryObject(row.slots).component)
        const state = component.state as (typeof STATUS_STATES)[number]
        const table = page.getByTestId('hilos-status-components-table')
        const service = shownByTestId(
          table,
          `hilos-status-component-${STATUS_SERVICE}`,
        )
        const noMatches = shownByTestId(table, 'hilos-table-no-matches')
        await expect(table.getByRole('table')).toHaveAccessibleName('Status')
        await expect(service).toContainText(String(component.name))

        const search = page.getByTestId('hilos-table-search')
        await expect(search).toHaveAccessibleName(
          'Search by name, description or agent…',
        )
        await typeInto(search, 'no-such-status-component')
        await expect(noMatches).toBeVisible()
        await expect(service).toHaveCount(0)
        const clear = page.getByTestId('hilos-table-search-clear')
        await expect(clear).toHaveAccessibleName('Clear search')
        await clear.focus()
        await clear.press('Enter')
        await expect(search).toHaveValue('')
        await expect(service).toBeVisible()

        const filter = page.getByTestId('hilos-table-filter-state')
        const toggle = filter.getByTestId('hilos-dropdown-toggle')
        const any = filter.getByTestId('hilos-dropdown-option--1')
        const other = STATUS_STATES.indexOf(
          state === 'major_outage' ? 'operational' : 'major_outage',
        )
        const option = filter.getByTestId(`hilos-dropdown-option-${other}`)
        await expect(toggle).toHaveAccessibleName('Now: Any')
        await toggle.focus()
        await toggle.press('ArrowDown')
        await expect(toggle).toHaveAttribute('aria-expanded', 'true')
        await expect(filter.getByRole('listbox')).toHaveAccessibleName('Now')
        await expect(any).toBeFocused()
        // Any comes first, then each declared state by its place.
        for (let place = 0; place <= other; place++)
          await page.keyboard.press('ArrowDown')
        await expect(option).toBeFocused()
        await option.press('Enter')
        await expect(toggle).toBeFocused()
        await expect(toggle).toHaveAttribute('aria-expanded', 'false')
        await expect(toggle).toHaveAccessibleName(
          `Now: ${STATUS_STATE_LABELS[STATUS_STATES[other]]}`,
        )
        await expect(noMatches).toBeVisible()
        await expect(service).toHaveCount(0)
        await toggle.press('ArrowDown')
        await expect(any).toBeFocused()
        await any.press('Enter')
        await expect(toggle).toBeFocused()
        await expect(toggle).toHaveAccessibleName('Now: Any')
        await expect(service).toBeVisible()
      })

      await test.step('Scheduled maintenance names its table, sorts from the keyboard and keeps focus in its dialog', async () => {
        const tab = page.getByTestId('hilos-page-tab-hilos_status_works')
        await tab.focus()
        await expect(tab).toBeFocused()
        await tab.press('Enter')
        await expectStatusPage(page, 'hilos_status_works', STATUS_WORKS_PATH)
        await expectStatusWorksAccessibility(expect, page, frames)
        await expectStatusWorkScheduleDialog(expect, page)
      })
    } finally {
      await test.info().attach('status-accessibility-wire', {
        body: JSON.stringify(frames),
        contentType: 'application/json',
      })
      status.stop()
      frames.stop()
    }
  })

  test('public /status names its days and opens its subscription dialog from the keyboard', async ({
    page,
  }) => {
    const log = watchPublicStatus(page)
    try {
      await gotoPublicStatus(expect, page)
      await expectPublicStatusPair(expect, page, log, ({ status }) =>
        status.components.every(
          (component) => component.state === 'operational',
        ),
      )
      await expect(page).toHaveTitle('Status · Hilos Tasks')
      await expect(page.getByTestId('page-title')).toHaveText(
        'Status · Hilos Tasks',
      )
      // The subscription control is offered over a live connection only.
      await expect(page.getByTestId('conn-state')).toHaveText('connected')
      await expectPublicStatusAccessibility(expect, page, 'Service')
      await expectStatusSubscribeDialog(expect, page)
      await page.keyboard.press('Escape')
      await expect(page.getByTestId('modal')).toBeHidden()
      expectPublicStatusReadOnly(expect, log)
    } finally {
      await log.stop()
      await test.info().attach('public-status-accessibility-files', {
        body: JSON.stringify(log),
        contentType: 'application/json',
      })
    }
  })
})
