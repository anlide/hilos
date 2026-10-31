import { test as base, expect, type Page } from '@playwright/test'

import { logout } from '../helpers/session.js'
import {
  enterIdentifierAndPassword,
  PASSWORD,
  ANALYTICS_SESSION_LIST_HANDLE,
  ANALYTICS_SESSION_LIST_PATH,
  expectAnalyticsSessionListAccessibility,
  expectAnalyticsSessionListWindow,
  expectAnalyticsPeopleAccessibility,
  expectAnalyticsPeopleWindow,
  watchDeliveryFrames,
  expectCommunicationsAccessibility,
  expectDesktopShell,
  DAEMON_NODE_PATH,
  DAEMON_NODE_TABS,
  expectLicenseDependenciesAccessibility,
  expectDaemonNodeHead,
  expectDaemonNodeTableName,
  expectStandaloneWebsockets,
  watchDaemonWebsocketsFrames,
  expectHilosUserOverview,
  expectChangeLogSectionHead,
  expectChangeLogTableHead,
  CHANGE_LOG_TABLES_PATH,
  expectHilosUserOverviewAccessibility,
  expectModerationKindHead,
  expectPasswordVisibility,
  expectTableTextControl,
  expectTableTextGeometry,
  openTableFixture,
  settleTableLayout,
  shownByTestId,
  tableTextToggle,
  watchClientDocument,
  DAEMON_ROOT_PATH,
  expectDaemonPage,
  expectDaemonSectionHead,
  expectDaemonSectionAccessibility,
  clickSubmit,
  typeInto,
  expectPageReady,
  gotoPage,
  createTestAdmin,
  createTestUser,
  grantAdminToSelf,
  countSentFrames,
  expectProfileNameSnapshot,
  watchProfileRenameFrames,
  expectProfileHeadAccessibility,
  watchProfileRenameStatus,
  STATUS_PATH,
  STATUS_WORKS_PATH,
  STATUS_WORK_SCHEDULE,
  STATUS_WORK_CHANGE,
  draftStatusWork,
  expectStatusWorksAccessibility,
  expectStatusWorkScheduleDialog,
  holdSocketAction,
  latestStatusWork,
  openStatusWorkSchedule,
  statusWorkRows,
  statusWorkFixtures,
  type StatusWorkFixtureScope,
  submitStatusWork,
  trackedActionReplies,
  dismissToasts,
  expectStatusHead,
  expectPublicStatusAccessibility,
  expectPublicStatusPair,
  expectPublicStatusReadOnly,
  gotoPublicStatus,
  watchPublicStatus,
  STATUS_SUBSCRIBERS_PATH,
  expectStatusSubscribersAccessibility,
  expectStatusSubscriberRemoveDialog,
  expectStatusSubscribeDialog,
  expectStatusUnsubscribeDialog,
  expectTrackedActionReply,
  openStatusMailLink,
  removeStatusSubscriber,
  statusSubscriberId,
  subscribeToStatus,
  uniqueEmail,
  watchHeight,
  deliveryObject,
  SECURITY_PATH,
  SECURITY_PROVIDER_PAGE,
  expectSecurityFirstReply,
  expectSecurityProviderFirstHead,
  expectSecurityProviderHead,
  expectSecuritySectionHead,
} from '../../../../../framework/frontend/e2e/index.js'
import { waitForMailTo } from '../../../../../framework/frontend/scripts/standMailbox.mjs'
import {
  STATUS_CONFIRM_SUBJECT,
  statusMailLinks,
} from '../../../../../framework/frontend/scripts/statusMail.mjs'

import { uniquePhone } from '../../../../../framework/frontend/scripts/standSms.mjs'
import { dictateModerationVerdict } from '../helpers/moderation.js'
import { dictateGatewayBehavior } from '../../../../../framework/frontend/scripts/standGateway.mjs'
import { modelKey } from '../../../../../framework/frontend/scripts/standModel.mjs'
import { setAdminViewMode } from '../../../../../framework/frontend/scripts/adminViewMode.mjs'

// Hilos accessibility (a11y) e2e — the rarely-run a11y category (see
// docs/agents/testing.md "Selective testing"). Asserts the framework viewport
// table exposes a correct accessibility tree over the live socket: an accessible
// name from the page heading it stands under, a labeled search box, and a sortable
// header that reports aria-sort and is operable from the keyboard. The chat (Vue)
// layer checks this on the settings table; the file grows as the a11y arc lands
// its later steps (modals, app-shell, focus).

const test = base.extend<{ statusWorks: StatusWorkFixtureScope }>({
  statusWorks: statusWorkFixtures(expect),
})

test.use({ viewport: { width: 1440, height: 1000 }, colorScheme: 'light' })

/** Open the settings page and wait for the live table. */
async function openSettings(page: Page): Promise<void> {
  await gotoPage(expect, page, '/hilos/settings')
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
  await expectLicenseDependenciesAccessibility(expect, page, 'Hilos Chat')
})

test('Legal document links open real clauses from the keyboard and name the table through one heading', async ({
  page,
}) => {
  await createTestAdmin(expect, page)
  await gotoPage(expect, page, '/hilos/legal/documents')
  const open = shownByTestId(page, 'legal-document-open').and(
    page.locator('[data-document="terms"]'),
  )
  await open.focus()
  await expect(open).toBeFocused()
  await open.press('Enter')
  await expect(page).toHaveURL('/hilos/legal/document/terms')
  await expectPageReady(expect, page)
  await expect(page.getByTestId('legal-document-key')).toHaveText('terms')
  await expect(page.getByTestId('legal-document-revision')).toContainText(
    '2026-10-01',
  )
  const heading = page.getByRole('heading', { level: 1 })
  await expect(heading).toHaveCount(1)
  await expect(heading).toHaveText('Terms')
  const links = page.getByRole('navigation', {
    name: 'Document pages',
    exact: true,
  })
  const overview = links.getByTestId('hilos-page-tab-hilos_legal_document')
  await expect(
    links.getByRole('link').and(links.locator('[aria-current="page"]')),
  ).toHaveCount(1)
  await expect(overview).toHaveAttribute('aria-current', 'page')
  await expect(page.getByRole('tablist')).toHaveCount(0)
  await overview.focus()
  for (const [key, suffix, content] of [
    ['hilos_legal_document_set', 'set', 'Content is kept for 12 months.'],
    [
      'hilos_legal_document_deviations',
      'deviations',
      'it is reset without notice',
    ],
  ]) {
    await page.keyboard.press('Tab')
    const link = links.getByTestId(`hilos-page-tab-${key}`)
    await expect(link).toBeFocused()
    await expect(link).toHaveRole('link')
    await expect(link).toHaveAttribute(
      'href',
      `/hilos/legal/document/terms/${suffix}`,
    )
    await link.press('Enter')
    await expect(page).toHaveURL(`/hilos/legal/document/terms/${suffix}`)
    await expectPageReady(expect, page)
    await expect(heading).toHaveCount(1)
    await expect(heading).toHaveText('Terms')
    await expect(link).toHaveAttribute('aria-current', 'page')
    await expect(
      links.getByRole('link').and(links.locator('[aria-current="page"]')),
    ).toHaveCount(1)
    await expect(page.getByRole('tablist')).toHaveCount(0)
    const headingId = await heading.getAttribute('id')
    expect(headingId).toBeTruthy()
    const table = page.getByRole('table', { name: 'Terms', exact: true })
    await expect(table).toHaveAttribute('aria-labelledby', headingId!)
    await expect(table).toContainText(content!)
    // The new page mounts a new link; resume ordinary Tab navigation from it.
    await link.focus()
  }
})

test('the viewport table has an accessible name and a labelled search', async ({
  page,
}) => {
  await grantAdminToSelf(expect, page)
  await openSettings(page)

  // The page heading is the table's accessible name: a table carries no
  // title of its own, and points at the heading that already names it.
  await expect(page.getByRole('table', { name: 'Settings' })).toBeVisible()

  // The search box is reachable by an accessible name, not just a placeholder.
  await expect(
    page.getByRole('searchbox', { name: 'Search settings…' }),
  ).toBeVisible()
})

test('a sortable header reports aria-sort and sorts from the keyboard', async ({
  page,
}) => {
  await grantAdminToSelf(expect, page)
  await openSettings(page)

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

test('Analytics People names its table through the heading and sorts Last seen from the keyboard', async ({
  page,
}) => {
  const frames = watchDeliveryFrames(page)
  try {
    await grantAdminToSelf(expect, page)
    await gotoPage(expect, page, '/hilos/analytics/people')
    await expectPageReady(expect, page)
    const today = await expectAnalyticsPeopleWindow(
      expect,
      page,
      frames,
      'today',
    )
    await expectAnalyticsPeopleAccessibility(expect, page, 'descending')
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
    await expectAnalyticsPeopleAccessibility(expect, page, 'ascending')
    await expect(sort).toBeFocused()
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

test('Analytics Person has one entity heading naming its table and a decorative avatar without tabs', async ({
  page,
}) => {
  const userId = await createTestAdmin(expect, page)
  const name = await page.getByTestId('self-user').innerText()
  const label = `${name} (#${userId})`
  await gotoPage(expect, page, `/hilos/analytics/people/${userId}`)
  await expectPageReady(expect, page)

  const carrier = page.getByTestId('hilos-admin-page')
  await expect(carrier).toHaveAttribute('data-page', 'hilos_analytics_person')
  const head = carrier.getByTestId('hilos-page-head')
  const heading = head.getByRole('heading', { level: 1 })
  await expect(page.getByRole('heading', { level: 1 })).toHaveCount(1)
  await expect(heading).toHaveText(label)
  await expect(heading).toHaveAttribute('id', /\S+/)
  const headingId = (await heading.getAttribute('id'))!
  const table = carrier
    .getByTestId('hilos-analytics-browser-sessions-table')
    .getByRole('table')
  await expect(table).toBeVisible()
  await expect(table).toHaveAttribute('aria-labelledby', headingId)
  await expect(table).toHaveAccessibleName(label)
  await expect(carrier.getByTestId('hilos-table-title')).toHaveCount(0)

  const avatar = head.getByTestId('analytics-person-head-avatar')
  await expect(avatar).toBeVisible()
  await expect(avatar).toHaveAttribute('aria-hidden', 'true')
  await expect(avatar.locator('img:not([alt=""])')).toHaveCount(0)
  await expect(head.getByRole('img')).toHaveCount(0)
  const breadcrumb = head.getByTestId('hilos-breadcrumb')
  await expect(breadcrumb).toHaveRole('navigation')
  await expect(breadcrumb).toHaveAccessibleName('Breadcrumb')
  await expect(breadcrumb.locator('li')).toHaveText([
    'Hilos',
    'Analytics',
    'People',
    label,
  ])
  const current = breadcrumb.locator('li').last()
  await expect(current).toHaveAttribute('aria-current', 'page')
  await expect(current.getByRole('link')).toHaveCount(0)
  await expect(head.getByTestId('hilos-page-tabs')).toHaveCount(0)
  await expect(page.getByRole('tablist')).toHaveCount(0)
})

test('Security has one section heading, six named links and keyboard navigation naming the table', async ({
  page,
}) => {
  await createTestAdmin(expect, page)
  await gotoPage(expect, page, SECURITY_PATH)
  await expectSecuritySectionHead(expect, page)
  const breadcrumb = page.getByTestId('hilos-breadcrumb')
  await expect(breadcrumb).toHaveRole('navigation')
  await expect(breadcrumb).toHaveAccessibleName('Breadcrumb')
  await expect(
    breadcrumb.getByTestId('hilos-breadcrumb-hilos'),
  ).toHaveAccessibleName('Hilos')
  await expect(breadcrumb.locator('[aria-current="page"]')).toHaveCount(1)
  await expect(breadcrumb.locator('[aria-current="page"]')).toHaveText(
    'Security Center',
  )
  await expect(breadcrumb.locator('[aria-current="page"] a')).toHaveCount(0)
  await expect(page.getByRole('heading', { level: 2 })).toHaveText([
    'Two-factor',
    'Operations that ask for confirmation',
    'OAuth providers',
    'Sign-in methods',
    'Impersonation',
  ])
  const overview = page.getByTestId('hilos-page-tab-hilos_security')
  await overview.focus()
  await expect(overview).toBeFocused()
  await page.keyboard.press('Tab')
  const twoFactor = page.getByTestId('hilos-page-tab-hilos_security_2fa')
  await expect(twoFactor).toBeFocused()
  await page.keyboard.press('Enter')
  await expectPageReady(expect, page)
  await expect(page).toHaveURL(`${SECURITY_PATH}/2fa`)
  await expectSecuritySectionHead(expect, page, 'hilos_security_2fa')
  const heading = page.getByTestId('hilos-page-head-title')
  const headingId = (await heading.getAttribute('id'))!
  expect(headingId).toBeTruthy()
  await expect(page.getByRole('table')).toHaveAttribute(
    'aria-labelledby',
    headingId,
  )
  await expect(page.getByRole('table')).toHaveAccessibleName('Security Center')
  await expect(page.getByTestId('hilos-table-title')).toHaveCount(0)
  expect(
    await page
      .getByTestId('hilos-page-head')
      .locator('.bi')
      .evaluateAll(
        (icons) =>
          icons.filter((icon) => icon.closest('[aria-hidden="true"]') === null)
            .length,
      ),
  ).toBe(0)
})

test('OAuth provider has one named entity head and table, decorative icons and keyboard breadcrumbs without singleton tabs', async ({
  page,
}) => {
  const log = watchDeliveryFrames(page)
  try {
    await createTestAdmin(expect, page)
    await gotoPage(expect, page, `${SECURITY_PATH}/oauth`)
    const opening = log.sent.length
    await shownByTestId(page, 'hilos-oauth-provider-open-oauth:github').click()
    await expectPageReady(expect, page)
    const first = await expectSecurityFirstReply(
      expect,
      log,
      opening,
      SECURITY_PROVIDER_PAGE,
      { providerId: 'oauth:github' },
    )
    const provider = await expectSecurityProviderFirstHead(
      expect,
      log,
      first,
      'oauth:github',
    )
    await expectSecurityProviderHead(expect, page, provider)
    const heading = page.getByTestId('hilos-page-head-title')
    const headingId = (await heading.getAttribute('id'))!
    expect(headingId).toBeTruthy()
    await expect(page.getByRole('table')).toHaveAttribute(
      'aria-labelledby',
      headingId,
    )
    await expect(page.getByRole('table')).toHaveAccessibleName(provider.label)
    await expect(page.getByTestId('hilos-table-title')).toHaveCount(0)
    await expect(page.getByTestId('oauth-provider-head-icon')).toHaveAttribute(
      'aria-hidden',
      'true',
    )
    await expect(page.getByRole('tablist')).toHaveCount(0)
    const breadcrumb = page.getByTestId('hilos-breadcrumb')
    await expect(breadcrumb).toHaveAccessibleName('Breadcrumb')
    await expect(breadcrumb.getByRole('link')).toHaveCount(3)
    for (const [key, label, path] of [
      ['hilos', 'Hilos', '/hilos'],
      ['hilos_security', 'Security Center', SECURITY_PATH],
      ['hilos_security_oauth', 'OAuth providers', `${SECURITY_PATH}/oauth`],
    ]) {
      const link = breadcrumb.getByTestId(`hilos-breadcrumb-${key}`)
      await expect(link).toHaveAccessibleName(label!)
      await expect(link).toHaveAttribute('href', path!)
    }
    await expect(breadcrumb.locator('[aria-current="page"]')).toHaveCount(1)
    await expect(breadcrumb.locator('[aria-current="page"]')).toHaveText(
      provider.label,
    )
    await expect(breadcrumb.locator('[aria-current="page"] a')).toHaveCount(0)
    expect(
      await page
        .getByTestId('hilos-page-head')
        .locator('.bi')
        .evaluateAll(
          (icons) =>
            icons.filter(
              (icon) => icon.closest('[aria-hidden="true"]') === null,
            ).length,
        ),
    ).toBe(0)
    await breadcrumb.getByTestId('hilos-breadcrumb-hilos').focus()
    await page.keyboard.press('Tab')
    await expect(
      breadcrumb.getByTestId('hilos-breadcrumb-hilos_security'),
    ).toBeFocused()
    await page.keyboard.press('Tab')
    await expect(
      breadcrumb.getByTestId('hilos-breadcrumb-hilos_security_oauth'),
    ).toBeFocused()
    await page.keyboard.press('Enter')
    await expectPageReady(expect, page)
    await expect(page).toHaveURL(`${SECURITY_PATH}/oauth`)
    await expectSecuritySectionHead(expect, page, 'hilos_security_oauth')
  } finally {
    await test.info().attach('security-provider-accessibility-wire', {
      body: JSON.stringify({ sent: log.sent, packets: log.packets }),
      contentType: 'application/json',
    })
    log.stop()
  }
})

test('the shell exposes a skip link and marks the active nav item', async ({
  page,
}) => {
  await grantAdminToSelf(expect, page)
  await gotoPage(expect, page, '/hilos')
  await expect(page.getByTestId('conn-state')).toHaveText('connected')

  // The skip link targets the main landmark.
  await expect(page.getByTestId('skip-to-content')).toHaveAttribute(
    'href',
    '#hilos-main-content',
  )
  await expect(page.locator('main#hilos-main-content')).toBeVisible()

  // The admin gear points at /hilos, the current page, so it is aria-current.
  await expect(page.getByTestId('nav-admin')).toHaveAttribute(
    'aria-current',
    'page',
  )

  // Conversations is in the shell, and this page is the dashboard, so the
  // link is not the current page.
  await expect(page.getByTestId('nav-conversations')).not.toHaveAttribute(
    'aria-current',
    'page',
  )

  await expectPageReady(expect, page)
  const build = await expectDesktopShell(expect, page, 'nav-profile')
  const document = await watchClientDocument(page)
  try {
    await page.getByTestId('nav-brand').click()
    await expect(page).toHaveURL('/')
    await expectPageReady(expect, page)
    await expect(page.getByTestId('nav-conversations')).toHaveAttribute(
      'href',
      '/',
    )
    await expect(page.getByTestId('nav-conversations')).toHaveAttribute(
      'aria-current',
      'page',
    )
    await document.unchanged()
    expect(await expectDesktopShell(expect, page, 'nav-profile')).toBe(build)
  } finally {
    await document.dispose()
  }
})

test('each page titles the tab and announces the page on navigation', async ({
  page,
}) => {
  await grantAdminToSelf(expect, page)
  await openSettings(page)

  // The settings page titles the browser tab (framework label + app name), and
  // the live region carries the same title for a screen-reader announcement.
  await expect(page).toHaveTitle('Settings · Hilos Chat')
  await expect(page.getByTestId('page-title')).toHaveText(
    'Settings · Hilos Chat',
  )

  // A no-refresh navigation (the brand → home) updates both the tab title and
  // the announcement.
  await page.getByTestId('nav-brand').click()
  await expect(page).toHaveTitle('Conversations · Hilos Chat')
  await expect(page.getByTestId('page-title')).toHaveText(
    'Conversations · Hilos Chat',
  )
})

test('the home page exposes a heading and presence as text', async ({
  page,
}) => {
  // Presence is a signed-in property; the fixture binds the self user on '/'.
  const user = await createTestUser(expect, page)

  // Exactly one top-level heading names the page (visually hidden here).
  await expect(page.getByRole('heading', { level: 1 })).toHaveText(
    'Conversations',
  )

  // A participant exposes presence as text for assistive tech, not the colored
  // dot alone — the connected self user is online.
  await expect(page.getByTestId('self-user')).toHaveText(user.name)
  await expect(
    page.getByTestId('participant').filter({ hasText: user.name }),
  ).toContainText('online')

  // The same row is a real link. Its accessible name carries the person and
  // the presence, and Enter follows the address the href already names.
  const selfUserId = Number(
    await page.getByTestId('self-user-id').textContent(),
  )
  expect(selfUserId).toBe(user.userId)
  const link = page.getByTestId(`participant-link-${selfUserId}`)
  await expect(link).toHaveAccessibleName(`${user.name} online`)
  await expect(link).toHaveAttribute('href', `/user/${selfUserId}`)
  await expect(link).toHaveJSProperty('tagName', 'A')
  await link.focus()
  await expect(link).toBeFocused()
  await page.keyboard.press('Enter')
  await expectPageReady(expect, page)
  expect(new URL(page.url()).pathname).toBe(`/user/${selfUserId}`)
  await expect(page.getByTestId('user-name')).toHaveText(user.name)

  await page.getByTestId('nav-conversations').click()
  await expectPageReady(expect, page)
  expect(new URL(page.url()).pathname).toBe('/')
  await expect(page.getByRole('heading', { level: 1 })).toHaveText(
    'Conversations',
  )
})

test('the user Overview exposes a shared heading, named navigation and keyboard rename', async ({
  page,
}) => {
  const userId = await createTestAdmin(expect, page)
  const name = await page.getByTestId('self-user').innerText()
  await gotoPage(expect, page, `/hilos/user/${userId}`)
  await expectHilosUserOverview(expect, page, userId, name, 'Hilos Chat')
  await expect(page.getByRole('heading', { level: 2 })).toHaveText([
    'Profile',
    'Rights',
    'Access to the product',
    'Acting as another person',
    'Chat participant',
    'Danger zone',
  ])
  await expectHilosUserOverviewAccessibility(expect, page)
})

test('profile dialogs carry their refusal voice before any refusal', async ({
  page,
}) => {
  await createTestUser(expect, page, { phone: uniquePhone() })
  await gotoPage(expect, page, '/profile/sign-in')
  const passwordRow = page
    .getByTestId('profile-identity-item')
    .filter({ has: page.getByTestId('profile-password-change') })
  await clickSubmit(expect, passwordRow.getByTestId('identity-unlink'))
  const live = page.getByTestId('profile-unlink-modal').getByRole('alert')
  await expect(live).toHaveAttribute('aria-live', 'assertive')
  await expect(live).toHaveText('')
  await clickSubmit(expect, page.getByTestId('identity-unlink-cancel'))
  await expect(page.getByTestId('profile-unlink-modal')).toHaveCount(0)

  await gotoPage(expect, page, '/profile')
  await page.getByTestId('profile-edit').click()
  const dialogLive = page.getByTestId('profile-rename-live-assertive')
  await expect(dialogLive).toHaveAttribute('role', 'alert')
  await expect(dialogLive).toHaveText('')
})

test('the name checking voice keeps its room, editable field, focus trap and dirty close gestures', async ({
  page,
}) => {
  const wire = watchProfileRenameFrames(page)
  const sends = countSentFrames(page, 'hilos_profile_name_set')
  try {
    const user = await createTestUser(expect, page)
    await gotoPage(expect, page, '/profile')
    await expectProfileHeadAccessibility(expect, page, user.name)
    const opener = page.getByTestId('profile-edit')
    const dialog = page.getByRole('dialog')
    await clickSubmit(expect, opener)
    await expect(dialog).toHaveAccessibleName("Confirm it's you")
    await typeInto(page.getByTestId('step-up-password'), PASSWORD)
    await clickSubmit(expect, page.getByTestId('profile-name-step-up-confirm'))
    await expect(dialog).toHaveAccessibleName('Change name')
    const field = page.getByTestId('profile-name-input')
    await expect(field).toBeFocused()
    const status = await watchProfileRenameStatus(expect, page)
    await status.unchanged(false)
    const key = modelKey()
    const name = `Accessible ${key}`
    await dictateModerationVerdict(key, false, 'spam')
    // The real model holds its response while keyboard and pending semantics are checked.
    await dictateGatewayBehavior('/model/api/generate', key, { delayMs: 2_000 })
    await typeInto(field, name)
    await clickSubmit(expect, page.getByTestId('profile-rename-save'))
    const accepted = await expectProfileNameSnapshot(
      expect,
      wire,
      user.userId,
      { name, phase: 'checking' },
    )
    await status.unchanged(true)
    await expect(field).toBeEditable()
    await expect(page.getByTestId('profile-rename-save')).toBeDisabled()
    await expect(page.getByTestId('profile-rename-save')).toHaveAttribute(
      'aria-busy',
      'true',
    )
    await expect(page.getByTestId('modal-close')).toBeEnabled()
    await expect(page.getByTestId('profile-rename-cancel')).toBeEnabled()
    await expect(page.getByTestId('profile-name')).toHaveText(user.name)
    await page.getByTestId('profile-rename-cancel').focus()
    await page.keyboard.press('Tab')
    await expect(page.getByTestId('modal-close')).toBeFocused()
    await page.keyboard.press('Shift+Tab')
    await expect(page.getByTestId('profile-rename-cancel')).toBeFocused()
    await field.focus()
    await field.press('Enter')
    await field.press('Escape')
    await expect(page.getByTestId('modal-confirm')).toBeFocused()
    await clickSubmit(expect, page.getByTestId('modal-confirm-cancel'))
    await expect(field).toHaveValue(name)
    await expectProfileNameSnapshot(expect, wire, user.userId, {
      requestId: accepted.state.change!.requestId,
      phase: 'refused',
    })
    await expect(page.getByTestId('profile-rename-error')).toHaveText(
      'This name was not accepted because it appears to be spam.',
    )
    await status.unchanged(false)
    await expect(page.getByTestId('profile-name')).toHaveText(user.name)
    // All four dismissal paths pass the same dirty-confirm gate.
    await clickSubmit(expect, page.getByTestId('profile-rename-cancel'))
    await expect(page.getByTestId('modal-confirm')).toBeFocused()
    await clickSubmit(expect, page.getByTestId('modal-confirm-cancel'))
    await clickSubmit(expect, page.getByTestId('modal-close'))
    await expect(page.getByTestId('modal-confirm')).toBeFocused()
    await clickSubmit(expect, page.getByTestId('modal-confirm-cancel'))
    await page.getByTestId('modal').click({ position: { x: 2, y: 2 } })
    await expect(page.getByTestId('modal-confirm')).toBeFocused()
    await clickSubmit(expect, page.getByTestId('modal-confirm-discard'))
    await expect(page.getByTestId('modal')).toBeHidden()
    await expect(opener).toBeFocused()
    expect(sends()).toBe(1)
  } finally {
    await test.info().attach('name-accessibility-wire', {
      body: JSON.stringify({ nameActions: sends(), ...wire }, null, 2),
      contentType: 'application/json',
    })
    wire.stop()
  }
})

test('the profile session and device pages expose one named surface', async ({
  page,
}) => {
  const user = await createTestUser(expect, page)

  await gotoPage(expect, page, '/profile/sessions')
  await expect(page.getByRole('heading', { level: 1 })).toHaveText(user.name)
  await expect(page.getByTestId('profile-sessions')).toHaveAttribute(
    'aria-label',
    'Sessions',
  )

  await gotoPage(expect, page, '/profile/devices')
  await expect(page.getByRole('heading', { level: 1 })).toHaveText(user.name)
  await expect(page.getByTestId('profile-devices')).toHaveAttribute(
    'aria-label',
    'Devices',
  )
})

test('password visibility toggle operates from keyboard on sign-in', async ({
  page,
}) => {
  const user = await createTestUser(expect, page)
  await logout(expect, page)
  await gotoPage(expect, page, '/profile')
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

test.describe('SDK reading accessibility, synthetic fixture ingress', () => {
  test.beforeEach(async ({ page }) => {
    await openTableFixture(
      expect,
      page,
      '/__table_geometry/index.html',
      'geometry-fixture',
    )
    await page.getByTestId('geometry-height-mode').selectOption('lines')
    await page.getByTestId('geometry-lines').selectOption('3')
    await page.getByTestId('geometry-height-content').selectOption('parts')
    await page.getByTestId('geometry-reading').click()
    await expectTableTextControl(expect, page, 'a', false)
  })

  test('H05 Enter/Space keep the native button and focus through live shortening and collapse', async ({
    page,
  }, info) => {
    const control = tableTextToggle(page, 'a')
    await control.focus()
    await control.press('Enter')
    await expectTableTextControl(expect, page, 'a', true)
    await expect(control).toBeFocused()
    const original = await control.elementHandle()
    await page.getByTestId('geometry-payload').selectOption('empty')
    // Synthetic ingress preserves the reader's focus; clicking a fixture
    // publisher normally would move focus to that publisher instead.
    await control.focus()
    await page
      .getByTestId('geometry-value')
      .evaluate((button: HTMLButtonElement) => button.click())
    await expectTableTextControl(expect, page, 'a', true)
    await expect(control).toBeFocused()
    await settleTableLayout(page)
    await info.attach('expanded-empty-minimum', {
      body: await expectTableTextGeometry(
        expect,
        page,
        'a',
        { mode: 'lines', count: 3 },
        true,
      ),
      contentType: 'application/json',
    })
    await control.press('Space')
    await expectTableTextControl(expect, page, 'a', false)
    await expect(control).toBeFocused()
    expect(
      await original!.evaluate(
        (button) =>
          button.isConnected &&
          button === document.activeElement &&
          button.textContent?.trim() === 'Show in full',
      ),
    ).toBe(true)
    await control.press('Tab')
    await expect(tableTextToggle(page, 'a')).toHaveCount(0)
    expect(await original!.evaluate((button) => button.isConnected)).toBe(false)
    await original!.dispose()
    await expect(page.getByTestId('geometry-state')).toHaveAttribute(
      'data-selected',
      'null',
    )
    await expect(page.getByTestId('geometry-state')).toHaveAttribute(
      'data-requests',
      '0',
    )
    await expect(page.getByTestId('geometry-actions')).toHaveText('')
    await expect(page.getByTestId('modal')).toHaveCount(0)
  })

  test('H05 text and detail keep independent expanded states and unique targets in both mounted branches', async ({
    page,
  }) => {
    const text = tableTextToggle(page, 'a')
    const detail = shownByTestId(page, 'hilos-table-expand-a')
    await text.focus()
    await text.press('Space')
    await expectTableTextControl(expect, page, 'a', true)
    await expect(detail).toHaveAttribute('aria-expanded', 'false')
    await detail.focus()
    await detail.press('Enter')
    await expect(detail).toHaveAttribute('aria-expanded', 'true')
    await expect(shownByTestId(page, 'geometry-detail-a')).toBeVisible()
    await expectTableTextControl(expect, page, 'a', true)
    const targets = await page
      .getByTestId('hilos-table-row-a')
      .getByTestId('hilos-table-text-toggle')
      .getAttribute('aria-controls')
    expect(targets).not.toBe(await detail.getAttribute('aria-controls'))
    const ids = await page
      .locator('[id]')
      .evaluateAll((nodes) => nodes.map((node) => node.id))
    expect(new Set(ids).size).toBe(ids.length)
    await text.focus()
    await text.press('Enter')
    await expectTableTextControl(expect, page, 'a', false)
    await expect(detail).toHaveAttribute('aria-expanded', 'true')
    await expect(text).toBeFocused()
  })

  for (const focus of [
    'text',
    'detail',
    'action',
    'outside',
    'root',
  ] as const) {
    test(`H06/B05 ${focus}: departure returns focus only from the departing occurrence`, async ({
      page,
    }) => {
      if (focus === 'root') {
        await page.getByTestId('geometry-no-search').click()
        await expectTableTextControl(expect, page, 'a', false)
      }
      const text = tableTextToggle(page, 'a')
      await text.click()
      await expectTableTextControl(expect, page, 'a', true)
      if (focus === 'detail') {
        await shownByTestId(page, 'hilos-table-expand-a').click()
        await expect(
          shownByTestId(page, 'geometry-detail-action-a'),
        ).toBeVisible()
      }
      const control =
        focus === 'outside'
          ? page.getByTestId('geometry-target')
          : focus === 'detail'
            ? shownByTestId(page, 'geometry-detail-action-a')
            : focus === 'action'
              ? shownByTestId(page, 'geometry-action-a')
              : text
      await control.focus()
      await expect(control).toBeFocused()
      await page
        .getByTestId('geometry-own')
        .evaluate((button: HTMLButtonElement) => button.click())
      await expect(page.getByTestId('geometry-text-a')).toHaveCount(0)
      await expect(
        page
          .getByTestId('hilos-table-row-a')
          .getByTestId('hilos-table-text-toggle'),
      ).toHaveCount(0)
      await expect(
        page
          .getByTestId('hilos-table-card-a')
          .getByTestId('hilos-table-text-toggle'),
      ).toHaveCount(0)
      await settleTableLayout(page)
      await expect(
        focus === 'outside'
          ? control
          : page.getByTestId(
              focus === 'root' ? 'hilos-viewport-table' : 'hilos-table-search',
            ),
      ).toBeFocused()
    })
  }

  test.describe('desktop touch', () => {
    test.use({ hasTouch: true })
    test('H05 tap operates the same native disclosure without selection or detail', async ({
      page,
    }) => {
      const control = tableTextToggle(page, 'a')
      await control.tap()
      await expectTableTextControl(expect, page, 'a', true)
      await control.tap()
      await expectTableTextControl(expect, page, 'a', false)
      await expect(page.getByTestId('geometry-state')).toHaveAttribute(
        'data-selected',
        'null',
      )
      await expect(page.getByTestId('geometry-state')).toHaveAttribute(
        'data-requests',
        '0',
      )
      await expect(page.getByTestId('geometry-detail-a')).toHaveCount(0)
      await expect(page.getByTestId('geometry-actions')).toHaveText('')
    })
  })
})

test('Communications exposes one named head and keyboard links between channel pages', async ({
  page,
}) => {
  await grantAdminToSelf(expect, page)
  await gotoPage(expect, page, '/hilos/communications/email')
  await expectCommunicationsAccessibility(expect, page, 'overview', 'Enabled')

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
    'Enabled',
  )

  const deliveries = page.getByTestId(
    'hilos-page-tab-hilos_communications_deliveries',
  )
  await deliveries.focus()
  await expect(deliveries).toBeFocused()
  await deliveries.press('Enter')
  await expectPageReady(expect, page)
  await expectCommunicationsAccessibility(expect, page, 'deliveries', 'Enabled')

  const overview = page.getByTestId(
    'hilos-page-tab-hilos_communications_channel',
  )
  await overview.focus()
  await expect(overview).toBeFocused()
  await overview.press('Enter')
  await expectPageReady(expect, page)
  await expectCommunicationsAccessibility(expect, page, 'overview', 'Enabled')
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

test('the moderation kinds root exposes one heading, named table and keyboard open link', async ({
  page,
}) => {
  await grantAdminToSelf(expect, page)
  await gotoPage(expect, page, '/hilos/moderation')
  await expectPageReady(expect, page)

  // Exactly one h1 names the page from the shared head.
  const heading = page.getByRole('heading', { level: 1 })
  await expect(heading).toHaveCount(1)
  await expect(heading).toHaveText('Moderation')

  // The page heading is the table's accessible name.
  await expect(page.getByRole('table', { name: 'Moderation' })).toBeVisible()

  // The Open link is reachable and operable from the keyboard.
  const openLink = shownByTestId(
    page,
    'hilos-moderation-open-hilos_profile_name',
  )
  await openLink.focus()
  await expect(openLink).toBeFocused()
  await expect(openLink).toHaveAttribute('aria-label', 'Open Profile name')
})

test('Rules inherits the sole kind heading as its table name and uses keyboard page links', async ({
  page,
}) => {
  await grantAdminToSelf(expect, page)
  const declared = {
    kind: 'hilos_profile_name',
    title: 'Profile name',
    owner: 'Framework',
  }
  await gotoPage(expect, page, '/hilos/moderation/hilos_profile_name/rules')
  await expectModerationKindHead(expect, page, declared, 'rules')
  const table = page
    .getByTestId('hilos-moderation-rules-table')
    .getByRole('table')
  await expect(table).toBeVisible()
  await expect(table).toHaveAccessibleName('Profile name')
  const headingId = await page
    .getByTestId('hilos-page-head-title')
    .getAttribute('id')
  expect(headingId).toBeTruthy()
  await expect(table).toHaveAttribute('aria-labelledby', headingId!)
  await expect(page.getByTestId('hilos-table-title')).toHaveCount(0)
  await expect(shownByTestId(page, 'hilos-table-search')).toHaveAccessibleName(
    'Search rules…',
  )

  const overview = page.getByTestId('hilos-page-tab-hilos_moderation_kind')
  const rules = page.getByTestId('hilos-page-tab-hilos_moderation_kind_rules')
  await overview.focus()
  await expect(overview).toBeFocused()
  await page.keyboard.press('Tab')
  await expect(rules).toBeFocused()
  await page.keyboard.press('Shift+Tab')
  await expect(overview).toBeFocused()
  await overview.press('Enter')
  await expectPageReady(expect, page)
  await expect(page).toHaveURL('/hilos/moderation/hilos_profile_name')
  await expectModerationKindHead(expect, page, declared, 'overview')
  await expect(page.getByTestId('hilos-moderation-rules-table')).toHaveCount(0)
  await overview.focus()
  await page.keyboard.press('Tab')
  await expect(rules).toBeFocused()
  await rules.press('Enter')
  await expectPageReady(expect, page)
  await expect(page).toHaveURL('/hilos/moderation/hilos_profile_name/rules')
  await expectModerationKindHead(expect, page, declared, 'rules')
  await expect(table).toHaveAccessibleName('Profile name')
})

for (const declared of [
  { kind: 'hilos_profile_name', title: 'Profile name' },
  { kind: 'hilos_profile_photo', title: 'Profile photo' },
]) {
  test(`Add rule for ${declared.title} names its field and traps and returns keyboard focus through dirty discard`, async ({
    page,
  }) => {
    const sends = countSentFrames(page, 'hilos_moderation_rule_add')
    await grantAdminToSelf(expect, page)
    await gotoPage(expect, page, `/hilos/moderation/${declared.kind}/rules`)
    const opener = page.getByTestId('hilos-table-main-action')
    await expect(opener).toHaveAccessibleName('Add rule')
    await opener.focus()
    await expect(opener).toBeFocused()
    await opener.press('Enter')

    const dialog = page.getByTestId('modal')
    await expect(dialog).toHaveRole('dialog')
    await expect(dialog).toHaveAttribute('aria-modal', 'true')
    await expect(dialog).toHaveAccessibleName(`Add rule · ${declared.title}`)
    const field = page.getByTestId('hilos-moderation-rule-add-rule')
    await expect(field).toHaveAccessibleName('Rule')
    await expect(field).toHaveAttribute('data-autofocus', '')
    await expect(field).toBeFocused()
    await expect(field).toHaveValue('')
    const save = page.getByTestId('hilos-moderation-rule-add-submit')
    await expect(save).toHaveAccessibleName('Save')
    await expect(save).toBeDisabled()
    const cancel = page.getByTestId('hilos-moderation-rule-add-cancel')
    const close = page.getByTestId('modal-close')
    await cancel.focus()
    await cancel.press('Tab')
    await expect(close).toBeFocused()
    await close.press('Shift+Tab')
    await expect(cancel).toBeFocused()
    await cancel.press('Escape')
    await expect(dialog).toBeHidden()
    await expect(opener).toBeFocused()

    await opener.press('Space')
    await expect(field).toBeFocused()
    await typeInto(field, 'An unsaved accessible policy')
    await expect(save).toBeEnabled()
    await save.focus()
    await save.press('Tab')
    await expect(close).toBeFocused()
    await close.press('Shift+Tab')
    await expect(save).toBeFocused()
    await field.focus()
    await field.press('Escape')

    const confirm = page.getByTestId('modal-confirm')
    await expect(confirm).toHaveRole('alertdialog')
    await expect(confirm).toHaveAttribute('aria-modal', 'true')
    await expect(confirm).toHaveAccessibleName('Discard changes?')
    await expect(confirm).toBeFocused()
    const keep = page.getByTestId('modal-confirm-cancel')
    const discard = page.getByTestId('modal-confirm-discard')
    await keep.focus()
    await keep.press('Shift+Tab')
    await expect(discard).toBeFocused()
    await discard.press('Tab')
    await expect(keep).toBeFocused()
    await keep.press('Enter')
    await expect(confirm).toBeHidden()
    await expect(field).toBeFocused()
    await expect(field).toHaveValue('An unsaved accessible policy')

    await cancel.focus()
    await cancel.press('Enter')
    await expect(confirm).toBeFocused()
    await discard.focus()
    await discard.press('Enter')
    await expect(confirm).toBeHidden()
    await expect(dialog).toBeHidden()
    await expect(opener).toBeFocused()
    await opener.press('Enter')
    await expect(field).toBeFocused()
    await expect(field).toHaveValue('')
    await expect(save).toBeDisabled()
    await field.press('Escape')
    await expect(dialog).toBeHidden()
    await expect(opener).toBeFocused()
    expect(sends()).toBe(0)
    await expect(page.getByTestId('hilos-toast-success')).toHaveCount(0)
  })
}

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

test('Analytics Browser sessions names its table through one heading and operates Last seen and Period from the keyboard', async ({
  page,
}) => {
  const frames = watchDeliveryFrames(page)
  try {
    await grantAdminToSelf(expect, page)
    await gotoPage(expect, page, ANALYTICS_SESSION_LIST_PATH)
    const today = await expectAnalyticsSessionListWindow(
      expect,
      page,
      frames,
      'today',
    )
    await expectAnalyticsSessionListAccessibility(expect, page, 'descending')
    let changing = frames.sent.length
    const sort = page
      .getByTestId(ANALYTICS_SESSION_LIST_HANDLE)
      .getByTestId('hilos-table-sort-lastSeenTs')
    await sort.focus()
    await expect(sort).toBeFocused()
    await sort.press('Enter')
    const ascending = await expectAnalyticsSessionListWindow(
      expect,
      page,
      frames,
      'today',
      changing,
    )
    expect(ascending.request.sort).toEqual([
      { field: 'lastSeenTs', direction: 'asc' },
    ])
    expect(ascending.request.filter).toEqual(today.request.filter)
    await expectAnalyticsSessionListAccessibility(expect, page, 'ascending')
    await expect(sort).toBeFocused()
    const period = page.getByTestId('hilos-table-filter-period')
    const toggle = period.getByTestId('hilos-dropdown-toggle')
    await toggle.focus()
    await expect(toggle).toBeFocused()
    await toggle.press('ArrowDown')
    await expect(period.getByRole('listbox')).toHaveAccessibleName('Period')
    const any = period.getByTestId('hilos-dropdown-option--1')
    await expect(any).toBeFocused()
    await any.press('End')
    const week = period.getByTestId('hilos-dropdown-option-2')
    await expect(week).toBeFocused()
    changing = frames.sent.length
    await week.press('Enter')
    await expect(period.getByTestId('hilos-dropdown-menu')).toBeHidden()
    const selected = await expectAnalyticsSessionListWindow(
      expect,
      page,
      frames,
      'this-week',
      changing,
    )
    expect(selected.request.filter).toEqual({
      ...today.context,
      period: 'this-week',
    })
    await expect(toggle).toHaveText('Period: This week')
    await expect(toggle).toBeFocused()
    await toggle.press('ArrowUp')
    await expect(week).toBeFocused()
    await week.press('Escape')
    await expect(period.getByTestId('hilos-dropdown-menu')).toBeHidden()
    await expect(toggle).toBeFocused()
  } finally {
    await test.info().attach('analytics-session-list-accessibility', {
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

test('Change Log names its section navigation and tables from one shared heading', async ({
  page,
}) => {
  await grantAdminToSelf(expect, page)
  await gotoPage(expect, page, '/hilos/change-log')
  await expectChangeLogSectionHead(expect, page, 'hilos_change_log')
  await expect(page.getByTestId(/^hilos-change-log-link-/)).toHaveCount(2)
  await expect(page.getByTestId('hilos-change-log-link-feed')).toHaveRole(
    'link',
  )
  await expect(page.getByTestId('hilos-change-log-link-tables')).toHaveRole(
    'link',
  )
  for (const key of [
    'hilos_change_log_feed',
    'hilos_change_log_tables',
    'hilos_change_log_noisy',
  ]) {
    const tab = page.getByTestId(`hilos-page-tab-${key}`)
    await tab.focus()
    await page.keyboard.press('Enter')
    await expectPageReady(expect, page)
    await expectChangeLogSectionHead(expect, page, key)
    await expect(
      page.getByRole('table', { name: 'Change Log', exact: true }),
    ).toBeVisible()
    await expect(page.getByTestId('hilos-table-title')).toHaveCount(0)
  }
})

test('Change Log table owner names its navigation and viewport for tracked and untracked tables', async ({
  page,
}) => {
  await grantAdminToSelf(expect, page)
  const tablePath = `${CHANGE_LOG_TABLES_PATH}/hilos_user`
  await gotoPage(expect, page, tablePath)
  await expectChangeLogTableHead(
    expect,
    page,
    'hilos_user',
    'hilos_change_log_table',
    { owner: 'Framework', since: 85 },
  )
  await expect(page.getByRole('heading', { level: 1 })).toHaveCount(1)
  await expect(page.getByRole('tablist')).toHaveCount(0)

  const columnsTile = shownByTestId(page, 'hilos-change-log-table-link-columns')
  await expect(columnsTile).toHaveRole('link')
  await expect(columnsTile).toHaveAttribute('href', `${tablePath}/columns`)
  await columnsTile.focus()
  await expect(columnsTile).toBeFocused()
  await columnsTile.press('Enter')
  await expect(page).toHaveURL(`${tablePath}/columns`)
  await expectPageReady(expect, page)
  await expectChangeLogTableHead(
    expect,
    page,
    'hilos_user',
    'hilos_change_log_table_columns',
    { owner: 'Framework', since: 85 },
  )
  await expect(
    page.getByRole('table', { name: 'hilos_user', exact: true }),
  ).toBeVisible()
  expect(
    await page
      .locator('.bi')
      .evaluateAll(
        (icons) =>
          icons.filter((icon) => icon.closest('[aria-hidden="true"]') === null)
            .length,
      ),
  ).toBe(0)

  const triggersTab = page.getByTestId(
    'hilos-page-tab-hilos_change_log_table_triggers',
  )
  await expect(triggersTab).toHaveRole('link')
  await triggersTab.focus()
  await expect(triggersTab).toBeFocused()
  await triggersTab.press('Enter')
  await expect(page).toHaveURL(`${tablePath}/triggers`)
  await expectPageReady(expect, page)
  await expectChangeLogTableHead(
    expect,
    page,
    'hilos_user',
    'hilos_change_log_table_triggers',
    { owner: 'Framework', since: 85 },
  )
  await expect(
    page.getByRole('table', { name: 'hilos_user', exact: true }),
  ).toBeVisible()
  await expect(
    shownByTestId(page, 'hilos-table-row-hilos_cl_hilos_user_after_insert'),
  ).toContainText('AFTER INSERT on hilos_user')
  expect(
    await page
      .locator('.bi')
      .evaluateAll(
        (icons) =>
          icons.filter((icon) => icon.closest('[aria-hidden="true"]') === null)
            .length,
      ),
  ).toBe(0)

  const untrackedPath = `${CHANGE_LOG_TABLES_PATH}/hilos_session`
  await gotoPage(expect, page, untrackedPath)
  await expectChangeLogTableHead(
    expect,
    page,
    'hilos_session',
    'hilos_change_log_table',
    { owner: 'Framework', since: null, journaled: false },
  )
  await expect(page).toHaveURL(untrackedPath)
  const untrackedTabs = page.getByTestId('hilos-page-tabs')
  await expect(untrackedTabs.getByRole('link')).toHaveText([
    'Overview',
    'Triggers',
  ])
  await expect(
    page.getByTestId('hilos-page-tab-hilos_change_log_table_columns'),
  ).toHaveCount(0)
  await expect(
    page.getByTestId('hilos-page-tab-hilos_change_log_table_history'),
  ).toHaveCount(0)
  await expect(
    page.getByTestId('hilos-change-log-table-link-columns'),
  ).toHaveCount(0)
  await expect(
    page.getByTestId('hilos-change-log-table-link-history'),
  ).toHaveCount(0)
  await expect(
    page.getByTestId('hilos-change-log-table-head-badges'),
  ).toHaveText('Framework')
  await expect(
    page.getByTestId('hilos-change-log-table-head-description'),
  ).toHaveText(
    "The entity has no journal label — this table's history is not written.",
  )
  await expect(page.getByTestId('hilos-viewport-table')).toHaveCount(0)
  await expect(page.getByTestId('hilos-change-log-table-columns')).toHaveCount(
    0,
  )
  await expect(page.getByTestId('hilos-change-log-table-triggers')).toHaveCount(
    0,
  )
  expect(
    await page
      .locator('.bi')
      .evaluateAll(
        (icons) =>
          icons.filter((icon) => icon.closest('[aria-hidden="true"]') === null)
            .length,
      ),
  ).toBe(0)

  await gotoPage(expect, page, `${untrackedPath}/triggers`)
  await expectChangeLogTableHead(
    expect,
    page,
    'hilos_session',
    'hilos_change_log_table_triggers',
    { owner: 'Framework', since: null, journaled: false },
  )
  await expect(page).toHaveURL(`${untrackedPath}/triggers`)
  await expect(
    page.getByRole('table', { name: 'hilos_session', exact: true }),
  ).toBeVisible()
  await expect(page.getByTestId('hilos-table-empty')).toHaveCount(2)
  await expect(
    page.locator('table tbody tr[data-id^="hilos-table-row-"]'),
  ).toHaveCount(0)
  await expect(page.getByTestId('hilos-table-count')).toHaveCount(0)
  expect(
    await page
      .locator('.bi')
      .evaluateAll(
        (icons) =>
          icons.filter((icon) => icon.closest('[aria-hidden="true"]') === null)
            .length,
      ),
  ).toBe(0)
})

test('Status Subscribers names its table and destructive dialog, supports keyboard sorting and explains viewer controls', async ({
  page,
  browser,
  baseURL,
}) => {
  const guestContext = await browser.newContext({
    baseURL,
    ignoreHTTPSErrors: true,
    colorScheme: 'light',
  })
  const guest = await guestContext.newPage()
  const viewer = await guestContext.newPage()
  const logs = [page, guest, viewer].map(watchDeliveryFrames)
  const email = uniqueEmail()
  try {
    await gotoPublicStatus(expect, guest)
    await expect(guest.getByTestId('conn-state')).toHaveText('connected')
    await subscribeToStatus(expect, guest, logs[1], email)
    await grantAdminToSelf(expect, page)
    await gotoPage(expect, page, STATUS_PATH)
    const link = page.getByTestId('hilos-page-tab-hilos_status_subscribers')
    await link.focus()
    await link.press('Enter')
    await expectPageReady(expect, page)
    await expectStatusHead(expect, page, 'subscribers')
    await expect(page.getByTestId('page-title')).toHaveAttribute(
      'aria-live',
      'polite',
    )
    const skip = page.getByTestId('skip-to-content')
    const beforeSkip = logs[0].sent.length
    await skip.focus()
    await skip.press('Enter')
    await expect(page.getByRole('main')).toBeFocused()
    await expectPageReady(expect, page)
    expect(
      logs[0].sent
        .slice(beforeSkip)
        .filter(({ wire }) => deliveryObject(wire).type === 'page_subscribe'),
    ).toEqual([])
    await expectStatusSubscribersAccessibility(expect, page, logs[0])
    const id = await statusSubscriberId(expect, page, email)
    await expect(
      shownByTestId(page, `hilos-status-subscriber-confirmed-${id}`),
    ).toHaveText('Awaiting confirmation')
    await expectStatusSubscriberRemoveDialog(expect, page, id)
    await setAdminViewMode(true)
    await gotoPage(expect, viewer, STATUS_SUBSCRIBERS_PATH)
    await expectStatusHead(expect, viewer, 'subscribers')
    const trash = shownByTestId(viewer, `hilos-status-subscriber-remove-${id}`)
    await expect(trash).toBeDisabled()
    await expect(trash).toHaveAccessibleDescription(
      'View mode · You can look around, but not change anything.',
    )
    await expect(
      shownByTestId(viewer, `hilos-status-subscriber-email-${id}`).getByTestId(
        'hilos-hidden',
      ),
    ).toHaveText('Hidden')
    expect(JSON.stringify(logs[2].packets)).not.toContain(email)
    expect(await viewer.locator('body').textContent()).not.toContain(email)
    await removeStatusSubscriber(expect, page, logs[0], id)
  } finally {
    await test.info().attach('status-subscribers-accessibility-wire', {
      body: JSON.stringify(logs),
      contentType: 'application/json',
    })
    for (const log of logs) log.stop()
    await guestContext.close()
    await setAdminViewMode(false)
  }
})

test('public Status subscription and mailed unsubscribe expose named keyboard dialogs and announced feedback', async ({
  page,
}) => {
  const log = watchPublicStatus(page)
  const email = uniqueEmail()
  try {
    await gotoPublicStatus(expect, page)
    await expectPublicStatusPair(expect, page, log, ({ status }) =>
      status.components.every((component) => component.state === 'operational'),
    )
    await expectPublicStatusAccessibility(expect, page, 'Service')
    expectPublicStatusReadOnly(expect, log)
    await expect(page.getByTestId('conn-state')).toHaveText('connected')
    const room = await watchHeight(
      page.getByTestId('public-status-subscription-slot'),
    )
    await expectStatusSubscribeDialog(expect, page)
    const errorRoom = await watchHeight(
      page.getByTestId('status-subscription-error-slot'),
    )
    await typeInto(page.getByTestId('status-subscription-email'), 'invalid')
    await clickSubmit(expect, page.getByTestId('status-subscription-submit'))
    await expect(page.getByTestId('status-subscription-error')).toBeVisible()
    await expect(page.getByTestId('status-subscription-submit')).toBeEnabled()
    await expect(page.getByTestId('status-subscription-email')).toHaveAttribute(
      'aria-invalid',
      'true',
    )
    await errorRoom.unchanged()
    const since = log.wire.sent.length
    await typeInto(page.getByTestId('status-subscription-email'), email)
    await clickSubmit(expect, page.getByTestId('status-subscription-submit'))
    await expect(page.getByTestId('status-subscription-form')).toBeHidden()
    await expectTrackedActionReply(
      expect,
      log.wire,
      'status_subscribe',
      since,
      { status: 'check_inbox' },
    )
    await expect(
      page.getByTestId('public-status-subscription-live'),
    ).toHaveAttribute('role', 'status')
    await expect(
      page.getByTestId('public-status-subscription-live'),
    ).toHaveText('Check your inbox — we sent a link to confirm the address.')
    await room.unchanged()
    const links = statusMailLinks(
      await waitForMailTo(email, STATUS_CONFIRM_SUBJECT),
    )
    await openStatusMailLink(expect, page, links.unsubscribe)
    await expectStatusUnsubscribeDialog(expect, page, email)
    await openStatusMailLink(expect, page, links.unsubscribe)
    const removing = log.wire.sent.length
    await clickSubmit(expect, page.getByTestId('status-subscription-submit'))
    await expect(page.getByTestId('status-subscription-form')).toBeHidden()
    await expectTrackedActionReply(
      expect,
      log.wire,
      'status_unsubscribe',
      removing,
      { status: 'unsubscribed' },
    )
    await expect(
      page.getByTestId('public-status-subscription-live'),
    ).toHaveText('You are unsubscribed. No more letters will come.')
  } finally {
    await log.stop()
    await test.info().attach('status-subscriptions-accessibility-wire', {
      body: JSON.stringify(log),
      contentType: 'application/json',
    })
  }
})

test('Status Works keyboard forms retain focus, announce live conflicts, lock busy requests and explain view mode', async ({
  page,
  browser,
  baseURL,
  statusWorks,
}) => {
  // Two real admin connections, native calendar entry and two correlated writes.
  test.slow()
  const gate = await holdSocketAction(page)
  const otherContext = await browser.newContext({
    baseURL,
    ignoreHTTPSErrors: true,
    colorScheme: 'light',
  })
  const viewerContext = await browser.newContext({
    baseURL,
    ignoreHTTPSErrors: true,
    colorScheme: 'light',
  })
  const other = await otherContext.newPage()
  const viewer = await viewerContext.newPage()
  const log = watchDeliveryFrames(page)
  const echo = watchDeliveryFrames(other)
  try {
    await grantAdminToSelf(expect, page)
    await grantAdminToSelf(expect, other)
    await gotoPage(expect, page, STATUS_PATH)
    const tab = page.getByTestId('hilos-page-tab-hilos_status_works')
    await tab.focus()
    await tab.press('Enter')
    await expectPageReady(expect, page)
    await expectStatusHead(expect, page, 'works')
    await expect(page.getByTestId('page-title')).toHaveAttribute(
      'aria-live',
      'polite',
    )
    await expectStatusWorksAccessibility(expect, page, log)
    await expectStatusWorkScheduleDialog(expect, page)
    await openStatusWorkSchedule(expect, page)
    const startsAt = Math.floor(Date.now() / 1000) * 1000 + 600_000
    const draft = {
      title: 'Accessible planned work',
      description: 'The live modal stays readable.',
      startsAt,
      endsAt: startsAt + 3_600_000,
      componentKeys: ['service'],
    }
    statusWorks.reserve(draft)
    await draftStatusWork(expect, page, draft)
    const since = log.sent.length
    gate.hold(STATUS_WORK_SCHEDULE)
    await clickSubmit(expect, page.getByTestId('hilos-status-work-save'))
    await expect.poll(() => gate.captured.length).toBe(1)
    await expect(page.getByTestId('hilos-status-work-save')).toHaveAttribute(
      'aria-busy',
      'true',
    )
    await expect(page.getByTestId('hilos-status-work-title')).toBeDisabled()
    await expect(page.getByTestId('hilos-status-work-close')).toBeDisabled()
    await page.keyboard.press('Escape')
    await expect(page.getByTestId('hilos-status-work-form')).toBeVisible()
    await expect(page.getByTestId('modal-confirm')).toHaveCount(0)
    gate.release()
    await expect(page.getByTestId('hilos-status-work-form')).toBeHidden()
    await expect
      .poll(() => trackedActionReplies(log, STATUS_WORK_SCHEDULE, since).length)
      .toBe(1)
    expect(
      trackedActionReplies(log, STATUS_WORK_SCHEDULE, since)[0].frame.type,
    ).toBe('action_success')
    await expect
      .poll(() =>
        statusWorkRows(log).find(
          (work) => work.title === 'Accessible planned work',
        ),
      )
      .toBeDefined()
    const work = statusWorkRows(log).find(
      (work) => work.title === 'Accessible planned work',
    )!
    await gotoPage(expect, other, STATUS_WORKS_PATH)
    await dismissToasts(page)
    const opener = shownByTestId(page, `hilos-status-work-edit-${work.id}`)
    await opener.focus()
    await opener.press('Enter')
    await expect(page.getByTestId('modal')).toHaveAccessibleName(
      'Change scheduled maintenance',
    )
    await expect(page.getByTestId('hilos-status-work-title')).toBeFocused()
    await typeInto(page.getByTestId('hilos-status-work-title'), 'Keep my draft')
    await shownByTestId(other, `hilos-status-work-edit-${work.id}`).click()
    await typeInto(
      other.getByTestId('hilos-status-work-title'),
      'The incoming announcement',
    )
    await submitStatusWork(expect, other, echo, STATUS_WORK_CHANGE)
    await expect
      .poll(() => latestStatusWork(log, work.publicId)?.version)
      .toBe(2)
    const notice = page.getByTestId('hilos-status-work-notice-slot')
    await expect(notice).toHaveAttribute('role', 'status')
    await expect(notice).toHaveAttribute('aria-live', 'polite')
    await expect(page.getByTestId('hilos-status-work-notice')).toContainText(
      'Changed elsewhere',
    )
    await expect(page.getByTestId('hilos-status-work-title')).toHaveValue(
      'Keep my draft',
    )
    await expect(page.getByTestId('hilos-status-work-save')).toBeDisabled()
    const mine = page.getByTestId('conflict-accept-mine')
    const theirs = page.getByTestId('conflict-accept-theirs')
    await expect(mine).toHaveAccessibleName('Keep mine')
    await expect(theirs).toHaveAccessibleName('Take theirs')
    await mine.focus()
    await mine.press('Tab')
    await expect(theirs).toBeFocused()
    await theirs.press('Enter')
    await expect(page.getByTestId('hilos-status-work-title')).toHaveValue(
      'The incoming announcement',
    )
    await expect(page.getByTestId('hilos-status-work-title')).toBeFocused()
    await page.keyboard.press('Escape')
    await expect(page.getByTestId('hilos-status-work-form')).toBeHidden()
    await expect(opener).toBeFocused()
    const cancel = shownByTestId(page, `hilos-status-work-cancel-${work.id}`)
    await cancel.focus()
    await cancel.press('Enter')
    const modal = page.getByTestId('modal')
    await expect(modal).toHaveRole('dialog')
    await expect(modal).toHaveAttribute('aria-modal', 'true')
    await expect(modal).toHaveAccessibleName('Cancel scheduled maintenance')
    await expect(modal).toBeFocused()
    const confirm = page.getByTestId('hilos-status-work-cancel-confirm')
    await confirm.focus()
    await confirm.press('Tab')
    await expect(page.getByTestId('modal-close')).toBeFocused()
    await page.keyboard.press('Shift+Tab')
    await expect(confirm).toBeFocused()
    await page.keyboard.press('Escape')
    await expect(modal).toBeHidden()
    await expect(cancel).toBeFocused()
    await setAdminViewMode(true)
    await gotoPage(expect, viewer, STATUS_WORKS_PATH)
    await expectStatusHead(expect, viewer, 'works')
    await openStatusWorkSchedule(expect, viewer)
    await expect(viewer.getByTestId('hilos-status-work-save')).toBeDisabled()
    await expect(
      viewer.getByTestId('hilos-status-work-save'),
    ).toHaveAccessibleDescription(
      'View mode · You can look around, but not change anything.',
    )
    await expect(viewer.getByTestId('hilos-status-work-notice')).toContainText(
      'You can look around, but not change anything.',
    )
    await viewer.keyboard.press('Escape')
    await expect(viewer.getByTestId('hilos-status-work-form')).toBeHidden()
  } finally {
    gate.release()
    await test.info().attach('status-works-accessibility-real-wire', {
      body: JSON.stringify({
        writer: log,
        focused: echo,
        held: gate.captured,
      }),
      contentType: 'application/json',
    })
    log.stop()
    echo.stop()
    await otherContext.close()
    await viewerContext.close()
    await setAdminViewMode(false)
  }
})
