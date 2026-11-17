import { randomUUID } from 'node:crypto'
import type { Page } from '@playwright/test'
import { test, expect } from '../test.js'
import { holdLegalRevision } from '../helpers/legalHold.js'

import {
  cleanupFixtures,
  type TestCleanup,
  deleteModerationOwnRules,
  dismissToasts,
  login,
  openSignIn,
  PASSWORD,
  createTestUser,
  analyticsBrowserSessionPath,
  ANALYTICS_SESSION_LIST_HANDLE,
  ANALYTICS_SESSION_LIST_PATH,
  analyticsPersonLink,
  analyticsSessionListCell,
  deliveryObject,
  expectAnalyticsBrowserSessionHead,
  expectAnalyticsOverview,
  expectAnalyticsOverviewTiles,
  expectAnalyticsPeopleAccessibility,
  expectAnalyticsPeoplePerson,
  expectAnalyticsPeopleWindow,
  expectAnalyticsPersonNavigation,
  expectAnalyticsPersonSession,
  expectAnalyticsSessionListAccessibility,
  expectAnalyticsSessionListWindow,
  readAnalyticsOverviewSnapshot,
  uniqueEmail,
  waitAnalyticsBrowserSession,
  watchDeliveryFrames,
  watchSocketFrames,
  type SocketFrame,
  expectDesktopShell,
  expectLicenseDependenciesAccessibility,
  expectLanguagePageHead,
  expectModerationAddDialogAccessibility,
  expectModerationPagesAccessibility,
  expectModerationRowDialogsAccessibility,
  expectModerationViewerAccessibility,
  ROWS,
  shownByTestId,
  watchClientDocument,
  clickSubmit,
  typeInto,
  expectPageReady,
  gotoPage,
  readI18nRowId,
  grantAdminToSelf,
  expectProfileTimeZone,
  expectTimeZoneEnvironment,
  expectStatusWorksAccessibility,
  expectStatusWorkScheduleDialog,
  STATUS_COMPONENTS_PATH,
  STATUS_WORKS_PATH,
  statusPagePayload,
  statusTableWindow,
} from '../../../../../framework/frontend/e2e/index.js'

import { setAdminViewMode } from '../../../../../framework/frontend/scripts/adminViewMode.mjs'
import { createStandCommandChannel } from '../../../../../framework/frontend/scripts/standCommand.mjs'
import { seedTestUser } from '../../../../../framework/frontend/scripts/testUser.mjs'
import { AUTH_ACTION_LOGIN } from '../../../../../framework/frontend/core/src/auth/authProtocol.js'

// spec-owner: demo — carries Polls' Angular browser journeys for these shared pages

// Hilos accessibility (a11y) e2e — the rarely-run a11y category (see
// docs/agents/testing.md "Selective testing"). Asserts the framework viewport
// table exposes a correct accessibility tree over the live socket: an accessible
// name from the page heading it stands under, a labeled search box, and a sortable
// header that reports aria-sort and is operable from the keyboard. The polls
// (Angular) layer checks this on the users table; the file grows as the a11y arc
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
  await expectLicenseDependenciesAccessibility(expect, page, 'Hilos Polls')
})

test.describe('Viewport table accessibility', () => {
  test.use({ timezoneId: 'Atlantic/Canary', colorScheme: 'light' })

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

    const log = watchDeliveryFrames(page)
    try {
      await test.step('Status names its table and filters real rows from the keyboard', async () => {
        await gotoPage(expect, page, STATUS_COMPONENTS_PATH)
        await expect(page.getByRole('table', { name: 'Status' })).toBeVisible()
        await expect
          .poll(() => statusPagePayload(log, 'hilos_status_components'))
          .toBeDefined()
        const payload = statusPagePayload(log, 'hilos_status_components')!
        const window = deliveryObject(
          deliveryObject(payload.windows).hilosStatusComponents,
        )
        expect(window).toMatchObject({ totalCount: 1, totalExact: true })
        await expect(
          shownByTestId(page, 'hilos-status-component-service'),
        ).toContainText('Service')
        const search = page.getByTestId('hilos-table-search')
        await expect(search).toHaveAccessibleName(
          'Search by name, description or agent…',
        )
        await search.focus()
        await expect(search).toBeFocused()
        let since = log.sent.length
        await typeInto(search, 'no-matching-status-component')
        await expect
          .poll(
            () =>
              statusTableWindow(
                log,
                'hilos_status_components',
                'hilosStatusComponents',
                since,
              )?.rows,
          )
          .toEqual([])
        await expect(
          shownByTestId(page, 'hilos-status-component-service'),
        ).toHaveCount(0)
        await expect(search).toBeFocused()
        since = log.sent.length
        await typeInto(search, 'Service')
        await expect
          .poll(
            () =>
              statusTableWindow(
                log,
                'hilos_status_components',
                'hilosStatusComponents',
                since,
              )?.totalCount,
          )
          .toBe(1)
        await expect(
          shownByTestId(page, 'hilos-status-component-service'),
        ).toContainText('Service')
        await expect(search).toBeFocused()
      })

      await test.step('The Works link and Schedule dialog support keyboard focus', async () => {
        const tab = page.getByTestId('hilos-page-tab-hilos_status_works')
        await tab.focus()
        await tab.press('Enter')
        await expect(page).toHaveURL(STATUS_WORKS_PATH)
        await expectPageReady(expect, page)
        await expect(page.getByRole('heading', { level: 1 })).toHaveCount(1)
        await expect(page.getByRole('heading', { level: 1 })).toHaveText(
          'Status',
        )
        await expect(tab).toHaveAttribute('aria-current', 'page')
        await expectStatusWorksAccessibility(expect, page, log)
        await expectStatusWorkScheduleDialog(expect, page)
      })
    } finally {
      log.stop()
    }
  })
})

test('the language Names table takes its name from the shared head', async ({
  page,
}) => {
  await grantAdminToSelf(expect, page)
  const languageId = await readI18nRowId(expect, page, 'language', 'en')
  await gotoPage(expect, page, `/hilos/i18n/language/${languageId}/names`)
  await expectPageReady(expect, page)

  await expectLanguagePageHead(expect, page, {
    code: 'en',
    id: languageId,
    nativeName: 'English',
    currentPage: 'hilos_i18n_language_names',
    appName: 'Hilos Polls',
  })
  await expect(page.getByRole('heading', { level: 1 })).toHaveCount(1)
  await expect(page.getByRole('table', { name: 'English' })).toBeVisible()
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

test('the shell supports accessible navigation without reload or reconnect', async ({
  page,
}) => {
  await test.step('the shell exposes a skip link and marks the active nav item', async () => {
    await gotoPage(expect, page, '/hilos')
    await expect(page.getByTestId('conn-state')).toHaveText('connected')

    // The skip link targets the main landmark.
    await expect(page.getByTestId('skip-to-content')).toHaveAttribute(
      'href',
      '#hilos-main-content',
    )
    await expect(page.locator('main#hilos-main-content')).toBeVisible()

    // No admin entry for a plain visitor: since HIL-611 a visitor carries no account
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

  // No-refresh navigation e2e: the shell's gear moves to the framework dashboard
  // and the brand moves back home through the core navigator (HilosRouter),
  // without reloading the document or dropping the WebSocket. A hard navigation
  // would fire a fresh `load`; the count staying put proves every transition
  // stayed in the same live document, and `conn-state` staying `connected` proves
  // the socket was never torn down.
  await test.step('navigates main <-> dashboard with no reload or reconnect', async () => {
    let fullLoads = 0
    page.on('load', () => {
      fullLoads += 1
    })

    // Gear -> dashboard.
    await page.getByTestId('nav-admin').click()
    await expect(page.getByTestId('dashboard-view')).toBeVisible()
    // The framework dashboard renders the real admin section cards from the
    // catalog (HilosDashboardPage), not a placeholder.
    await expect(
      page.locator('[data-id^="dashboard-card-"]').first(),
    ).toBeVisible()
    expect(new URL(page.url()).pathname).toBe('/hilos')
    await expect(page.getByTestId('conn-state')).toHaveText('connected')
    expect(fullLoads).toBe(0)

    // Brand -> home.
    await page.getByTestId('nav-brand').click()
    await expect(page.getByTestId('self-user')).toBeVisible()
    expect(new URL(page.url()).pathname).toBe('/')
    await expect(page.getByTestId('conn-state')).toHaveText('connected')
    expect(fullLoads).toBe(0)
  })
})

test('each page titles the tab and announces the page on navigation', async ({
  page,
}) => {
  await grantAdminToSelf(expect, page)

  await openUsers(page)

  // The users admin titles the browser tab (framework label + app name), and the
  // live region carries the same title for a screen-reader announcement.
  await expect(page).toHaveTitle('Users · Hilos Polls')
  await expect(page.getByTestId('page-title')).toHaveText('Users · Hilos Polls')

  // A no-refresh navigation (the brand → home) updates both the tab title and
  // the announcement.
  await page.getByTestId('nav-brand').click()
  await expect(page).toHaveTitle('Polls · Hilos Polls')
  await expect(page.getByTestId('page-title')).toHaveText('Polls · Hilos Polls')
})

test('an admin screen holds the refusal region before there is a refusal', async ({
  page,
}) => {
  // The same shape one layer up: the plate of a tracked action stands in a
  // permanent live region, and the room under it is held by an invisible twin
  // (HIL-887). A region inserted together with its own text announces nothing,
  // which is why it has to be there first. Angular has no component world of
  // its own yet (HIL-848), so this is where its copy of the component is
  // proved.
  await grantAdminToSelf(expect, page)

  await gotoPage(expect, page, '/hilos/logs/settings')
  await expect(page.getByTestId('conn-state')).toHaveText('connected')
  await expect(
    page.getByTestId('hilos-setting-preset-settings-link'),
  ).toBeVisible()

  const slot = page.getByTestId('hilos-action-error-slot')
  await expect(slot).toBeAttached()
  await expect(slot).toHaveAttribute('role', 'alert')
  await expect(slot).toHaveAttribute('aria-live', 'assertive')
  await expect(page.getByTestId('hilos-action-error-idle')).toHaveAttribute(
    'aria-hidden',
    'true',
  )
  await expect(page.getByTestId('hilos-action-error')).toHaveCount(0)
})

test('Privacy comparison and erase expose names, keyboard focus and live regions', async ({
  page,
}) => {
  const person = await createTestUser(expect, page)
  await gotoPage(expect, page, '/privacy')
  const reader = page.getByTestId('privacy-reader')
  await expect(reader).toHaveAttribute('data-state', 'covered')
  await expect(reader.getByTestId('legal-document-reader-line')).toContainText(
    'You accepted the revision of 9 October 2026',
  )
  await expect(reader.locator('i')).toHaveAttribute('aria-hidden', 'true')

  try {
    await holdLegalRevision(person.userId, 'privacy', '2026-09-17')
    await expect(reader).toHaveAttribute('data-state', 'due')
    await expect(
      reader.getByTestId('legal-document-reader-plate'),
    ).toContainText('deadline passed 9 October 2026')
    const directSlot = reader.getByTestId('legal-document-accept-refusal-slot')
    await expect(directSlot).toHaveAttribute('role', 'alert')
    await expect(directSlot).toHaveAttribute('aria-live', 'assertive')
    await expect(
      reader.getByTestId('legal-document-accept-refusal-idle'),
    ).toHaveAttribute('aria-hidden', 'true')

    const open = reader.getByTestId('legal-document-changes-open')
    await expect(open).toHaveAccessibleName('What changed')
    await open.focus()
    await open.press('Enter')
    const dialog = page
      .getByTestId('modal')
      .filter({ has: page.getByTestId('privacy-changes-modal') })
    await expect(dialog).toBeVisible()
    await expect(dialog).toHaveAttribute('role', 'dialog')
    await expect(dialog).toHaveAttribute('aria-modal', 'true')
    await expect(dialog).toHaveAccessibleName(
      'What changed · 17 September 2026 → 9 October 2026',
    )
    await expect(dialog).toBeFocused()
    const refusal = dialog.getByTestId('privacy-changes-refusal-slot')
    await expect(refusal).toHaveAttribute('role', 'alert')
    await expect(refusal).toHaveAttribute('aria-live', 'assertive')
    await expect(
      dialog.getByTestId('privacy-changes-refusal-idle'),
    ).toHaveAttribute('aria-hidden', 'true')
    await expect(dialog.getByTestId('privacy-changes-refusal')).toHaveCount(0)

    // Reverse Tab at the first control wraps to the last, and Tab wraps back.
    const headerClose = dialog.getByTestId('modal-close')
    const accept = dialog.getByTestId('privacy-changes-accept')
    await headerClose.focus()
    await headerClose.press('Shift+Tab')
    await expect(accept).toBeFocused()
    await accept.press('Tab')
    await expect(headerClose).toBeFocused()
    await page.keyboard.press('Escape')
    await expect(dialog).toHaveCount(0)
    await expect(open).toBeFocused()

    await open.press('Enter')
    await expect(dialog).toBeVisible()
    await clickSubmit(expect, dialog.getByTestId('privacy-changes-close'))
    await expect(dialog).toHaveCount(0)
    await expect(open).toBeFocused()
    await open.press('Enter')
    await expect(dialog).toBeVisible()
    await clickSubmit(expect, dialog.getByTestId('modal-close'))
    await expect(dialog).toHaveCount(0)
    await expect(open).toBeFocused()

    const erase = page.getByTestId('privacy-erase')
    const status = erase.locator('..').locator('[role="status"]')
    await expect(status).toHaveAttribute('aria-live', 'polite')
    await expect(status).toBeEmpty()
    await expect(erase).toHaveAccessibleName('Erase and sign out')
    await erase.focus()
    await erase.press('Enter')
    const confirmation = page
      .getByTestId('modal')
      .filter({ has: page.getByTestId('privacy-erase-list') })
    await expect(confirmation).toHaveAccessibleName(
      'Erase everything this browser keeps?',
    )
    await expect(confirmation).toBeFocused()
    await page.keyboard.press('Escape')
    await expect(confirmation).toHaveCount(0)
    await expect(erase).toBeFocused()
    await erase.press('Enter')
    await expect(confirmation).toBeVisible()
    await clickSubmit(expect, page.getByTestId('privacy-erase-confirm'))
    await expect(confirmation).toHaveCount(0)
    await expect(page.getByTestId('privacy-erase-done')).toBeVisible()
    await expect(reader).toHaveAttribute('data-state', 'guest')
    await expect(
      page.getByTestId('privacy-erase-done').locator('..'),
    ).toHaveAttribute('role', 'status')
    await expect(
      page.getByTestId('privacy-erase-done').locator('..'),
    ).toHaveAttribute('aria-live', 'polite')
    await expect(page.getByTestId('privacy-erase-done')).not.toHaveAttribute(
      'role',
    )
  } finally {
    // Browser erase leaves the account and its held acceptance intact.
    await holdLegalRevision(person.userId, 'privacy', '2026-10-09')
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

test.describe('Profile time zone accessibility', () => {
  test.use({ timezoneId: 'Asia/Bahrain', colorScheme: 'light' })

  test('names the picker, supports keyboard search, choice and Close, and returns focus', async ({
    page,
  }) => {
    const frames: SocketFrame[] = []
    const observer = watchSocketFrames(page, (frame) => frames.push(frame))
    try {
      await createTestUser(expect, page)
      await gotoPage(expect, page, '/profile')
      await expectTimeZoneEnvironment(expect, page, frames, {
        browserTimeZone: 'Asia/Bahrain',
        projectTimeZone: 'Europe/Warsaw',
      })
    } finally {
      observer.stop()
    }
    const opener = page.getByTestId('profile-time-zone-change')
    await expect(opener).toHaveAccessibleName('Change')
    await opener.focus()
    await opener.press('Enter')
    const dialog = page.getByTestId('modal')
    const search = page.getByTestId('profile-time-zone-search')
    await expect(dialog).toHaveRole('dialog')
    await expect(dialog).toHaveAttribute('aria-modal', 'true')
    await expect(dialog).toHaveAccessibleName('Time zone')
    await expect(search).toHaveRole('searchbox')
    await expect(search).toHaveAccessibleName('Search time zones')
    await expect(search).toBeFocused()
    const automatic = page.getByTestId('profile-time-zone-automatic')
    await expect(automatic).toHaveAttribute('aria-current', 'true')
    await expect(automatic).toContainText('Automatic')
    await expect(automatic).toContainText('Asia/Bahrain — from this browser')
    await expect(page.getByTestId('profile-time-zone')).toHaveText(
      'Asia/Bahrain · automatic',
    )

    await typeInto(search, 'new york')
    const choice = page.getByTestId('profile-time-zone-option-America/New_York')
    await search.press('Tab')
    await expect(choice).toBeFocused()
    await expect(choice).toHaveAccessibleName(/^America\/New_York /)
    await choice.press('Enter')
    await expect(dialog).toBeHidden()
    await expect(opener).toBeFocused()
    await expectProfileTimeZone(
      expect,
      page,
      'America/New_York',
      'Asia/Bahrain',
    )

    await opener.press('Enter')
    await expect(search).toBeFocused()
    await typeInto(search, 'new york')
    await expect(choice).toHaveAttribute('aria-current', 'true')
    await expect(choice.locator('i')).toBeVisible()
    await expect(choice.locator('i')).toHaveAttribute('aria-hidden', 'true')
    const headerClose = dialog.getByTestId('modal-close')
    const close = page.getByTestId('profile-time-zone-close')
    await expect(close).toHaveAccessibleName('Close')
    await headerClose.focus()
    await headerClose.press('Shift+Tab')
    await expect(close).toBeFocused()
    await close.press('Tab')
    await expect(headerClose).toBeFocused()
    await headerClose.press('Shift+Tab')
    await close.press('Enter')
    await expect(dialog).toBeHidden()
    await expect(opener).toBeFocused()
    await expect(page.getByTestId('profile-time-zone')).toHaveText(
      'America/New_York',
    )
  })
})

test.describe('Analytics accessibility', () => {
  test.use({ timezoneId: 'Asia/Kathmandu' })

  test('exposes visitor data and keyboard Browser sessions/People/Person navigation, sorting and table names', async ({
    page,
    browser,
    baseURL,
    ignoreHTTPSErrors,
  }) => {
    const frames: SocketFrame[] = []
    const observer = watchSocketFrames(page, (frame) => frames.push(frame))
    const delivery = watchDeliveryFrames(page)
    const userContext = await browser.newContext({ baseURL, ignoreHTTPSErrors })
    try {
      const user = await seedTestUser({ email: `person-${uniqueEmail()}` })
      const userPage = await userContext.newPage()
      await gotoPage(expect, userPage, '/')
      await openSignIn(expect, userPage)
      await login(expect, userPage, user.email, PASSWORD)
      const browserMajor = await userPage.evaluate(
        () => navigator.userAgent.match(/Chrome\/(\d+)/)?.[1],
      )
      expect(browserMajor).toBeDefined()
      const session = await waitAnalyticsBrowserSession(
        expect,
        createStandCommandChannel({ timeoutMs: 5_000 }),
        user.userId,
        AUTH_ACTION_LOGIN,
      )
      await grantAdminToSelf(expect, page)
      const browserTimeZone = await page.evaluate(
        () => Intl.DateTimeFormat().resolvedOptions().timeZone,
      )
      const opening = frames.length
      await gotoPage(expect, page, '/hilos/analytics')
      await expectPageReady(expect, page)
      await expectAnalyticsOverview(expect, page, 'angular')
      const snapshot = readAnalyticsOverviewSnapshot(frames.slice(opening))
      await expectAnalyticsOverviewTiles(
        expect,
        page,
        snapshot,
        browserTimeZone,
      )

      const breadcrumb = page.getByRole('navigation', {
        name: 'Breadcrumb',
        exact: true,
      })
      await expect(breadcrumb).toBeVisible()
      await expect(
        breadcrumb.getByRole('link', { name: 'Hilos', exact: true }),
      ).toHaveAttribute('href', '/hilos')
      const currentCrumb = breadcrumb.locator('[aria-current="page"]')
      await expect(currentCrumb).toContainText('Analytics')
      await expect(currentCrumb).not.toHaveRole('link')
      await expect(currentCrumb.getByRole('link')).toHaveCount(0)

      const tiles = page.getByTestId('hilos-analytics-overview-tiles')
      for (const period of ['week', 'day', 'hour'] as const) {
        const dl = tiles
          .getByTestId(`hilos-analytics-visitors-${period}`)
          .and(tiles.locator('dl'))
        await expect(dl).toBeVisible()
        await expect(dl.getByRole('term')).toBeVisible()
        for (const field of ['count', 'since']) {
          const value = dl.getByTestId(
            `hilos-analytics-visitors-${period}-${field}`,
          )
          await expect(value).toHaveRole('definition')
          await expect(value).toBeVisible()
        }
      }

      await test.step('count links are named by their numbers and Enter opens their Period', async () => {
        const overview = page.getByTestId('hilos-page-tab-hilos_analytics')
        for (const [tile, period] of [
          ['week', 'this-week'],
          ['day', 'today'],
          ['hour', 'this-hour'],
        ] as const) {
          const link = page.getByTestId(`hilos-analytics-visitors-${tile}-link`)
          await expect(link).toHaveRole('link')
          // The link is named by the very number its tile shows (WCAG 2.5.3).
          const count = page.getByTestId(
            `hilos-analytics-visitors-${tile}-count`,
          )
          await expect(count).toHaveText(/\d/)
          await expect(link).toHaveAccessibleName(
            (await count.innerText()).trim(),
          )
          await expect(link).toHaveAttribute(
            'href',
            `${ANALYTICS_SESSION_LIST_PATH}/${period}`,
          )
          const opening = delivery.sent.length
          await link.focus()
          await expect(link).toBeFocused()
          await link.press('Enter')
          await expect(page).toHaveURL(
            `${ANALYTICS_SESSION_LIST_PATH}/${period}`,
          )
          await expectPageReady(expect, page)
          await expectAnalyticsSessionListWindow(
            expect,
            page,
            delivery,
            period,
            opening,
          )

          await overview.focus()
          await expect(overview).toBeFocused()
          await overview.press('Enter')
          await expect(page).toHaveURL('/hilos/analytics')
          await expectPageReady(expect, page)
          await expectAnalyticsOverview(expect, page, 'angular')
        }
      })

      await test.step('Browser sessions names its table and supports keyboard sorting and session return', async () => {
        const sessions = page.getByTestId(
          'hilos-page-tab-hilos_analytics_browser_sessions',
        )
        await sessions.focus()
        await expect(sessions).toBeFocused()
        let changing = delivery.sent.length
        await sessions.press('Enter')
        await expect(page).toHaveURL(ANALYTICS_SESSION_LIST_PATH)
        await expectPageReady(expect, page)
        const listed = await expectAnalyticsSessionListWindow(
          expect,
          page,
          delivery,
          'today',
          changing,
        )
        await expectAnalyticsSessionListAccessibility(
          expect,
          page,
          'descending',
          'angular',
        )
        changing = delivery.sent.length
        const sort = page
          .getByTestId(ANALYTICS_SESSION_LIST_HANDLE)
          .getByTestId('hilos-table-sort-lastSeenTs')
        await sort.focus()
        await expect(sort).toBeFocused()
        await sort.press('Enter')
        const ascending = await expectAnalyticsSessionListWindow(
          expect,
          page,
          delivery,
          'today',
          changing,
        )
        expect(ascending.request.sort).toEqual([
          { field: 'lastSeenTs', direction: 'asc' },
        ])
        expect(ascending.request.filter).toEqual(listed.request.filter)
        await expectAnalyticsSessionListAccessibility(
          expect,
          page,
          'ascending',
          'angular',
        )
        await expect(sort).toBeFocused()
        changing = delivery.sent.length
        await sort.press('Enter')
        const descending = await expectAnalyticsSessionListWindow(
          expect,
          page,
          delivery,
          'today',
          changing,
        )
        expect(descending.request.sort).toEqual([
          { field: 'lastSeenTs', direction: 'desc' },
        ])
        expect(descending.request.filter).toEqual(listed.request.filter)
        await expectAnalyticsSessionListAccessibility(
          expect,
          page,
          'descending',
          'angular',
        )
        await expect(sort).toBeFocused()

        const sessionId = session.browserSessionId!
        const open = analyticsSessionListCell(page, 'session', sessionId)
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
        changing = delivery.sent.length
        await crumb.press('Enter')
        await expect(page).toHaveURL(ANALYTICS_SESSION_LIST_PATH)
        await expectPageReady(expect, page)
        await expectAnalyticsSessionListWindow(
          expect,
          page,
          delivery,
          'today',
          changing,
        )
        await expectAnalyticsSessionListAccessibility(
          expect,
          page,
          'descending',
          'angular',
        )
      })

      await test.step('People link and Last seen sort work from the keyboard', async () => {
        const people = page.getByTestId('hilos-page-tab-hilos_analytics_people')
        await people.focus()
        await expect(people).toBeFocused()
        await people.press('Enter')
        await expect(page).toHaveURL('/hilos/analytics/people')
        await expectPageReady(expect, page)
        const today = await expectAnalyticsPeopleWindow(
          expect,
          page,
          delivery,
          'today',
        )
        await expectAnalyticsPeoplePerson(expect, page, today, user)
        await expectAnalyticsPeopleAccessibility(
          expect,
          page,
          'descending',
          'angular',
        )
        const sorting = delivery.sent.length
        const sort = page
          .getByTestId('hilos-analytics-people-table')
          .getByTestId('hilos-table-sort-lastSeenTs')
        await sort.focus()
        await expect(sort).toBeFocused()
        await sort.press('Enter')
        const ascending = await expectAnalyticsPeopleWindow(
          expect,
          page,
          delivery,
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
          'angular',
        )
        await expect(sort).toBeFocused()

        const descendingSort = delivery.sent.length
        await sort.press('Enter')
        const descending = await expectAnalyticsPeopleWindow(
          expect,
          page,
          delivery,
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
          'angular',
        )
        await expectAnalyticsPeoplePerson(expect, page, descending, user)
        await expect(sort).toBeFocused()
      })

      await test.step('Person is named by its data and its People return is keyboard operable', async () => {
        const person = analyticsPersonLink(page, user.userId)
        await person.focus()
        await expect(person).toBeFocused()
        await person.press('Enter')
        await expect(page).toHaveURL(`/hilos/analytics/person/${user.userId}`)
        await expectPageReady(expect, page)
        await expectAnalyticsPersonSession(
          expect,
          page,
          user,
          session,
          browserMajor!,
        )
        await expectAnalyticsPersonNavigation(expect, page, delivery, user)
        const label = `${user.name} (#${user.userId})`
        const heading = page.getByRole('heading', { level: 1 })
        const table = page
          .getByTestId('hilos-analytics-browser-sessions-table')
          .getByRole('table', { name: label, exact: true })
        await expect(table).toBeVisible()
        await expect(table).toHaveAttribute(
          'aria-labelledby',
          (await heading.getAttribute('id'))!,
        )
        const returning = delivery.sent.length
        const people = page.getByTestId(
          'hilos-breadcrumb-hilos_analytics_people',
        )
        await people.focus()
        await expect(people).toBeFocused()
        await people.press('Enter')
        await expect(page).toHaveURL('/hilos/analytics/people')
        await expectPageReady(expect, page)
        const today = await expectAnalyticsPeopleWindow(
          expect,
          page,
          delivery,
          'today',
          returning,
        )
        await expectAnalyticsPeopleAccessibility(
          expect,
          page,
          'descending',
          'angular',
        )
        await expectAnalyticsPeoplePerson(expect, page, today, user)
        const reopened = analyticsPersonLink(page, user.userId)
        await reopened.focus()
        await expect(reopened).toBeFocused()
        const requests = delivery.sent
          .map(({ wire }) => deliveryObject(wire))
          .filter(
            (wire) =>
              wire.type === 'page_subscribe' &&
              wire.page === 'hilos_analytics_person',
          )
        expect(requests).toHaveLength(1)
        expect(requests[0].params).toEqual({ userId: String(user.userId) })
      })
    } finally {
      await test.info().attach('analytics-accessibility-frames', {
        body: JSON.stringify(
          { sent: delivery.sent, packets: delivery.packets },
          null,
          2,
        ),
        contentType: 'application/json',
      })
      observer.stop()
      delivery.stop()
      await userContext.close()
    }
  })
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
