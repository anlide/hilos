import { test, expect, type Page } from '@playwright/test'

import { logout, signUp, signUpWithVerifiedEmail } from '../helpers/session.js'
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
  expectDaemonNodeHead,
  expectDaemonNodeTableName,
  expectHilosUserOverview,
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
  signUpAdmin,
  countSentFrames,
  expectProfileNameSnapshot,
  watchProfileRenameFrames,
  watchProfileRenameStatus,
} from '../../../../../framework/frontend/e2e/index.js'

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

test.use({ viewport: { width: 1440, height: 1000 }, colorScheme: 'light' })

/** Open the settings page and wait for the live table. */
async function openSettings(page: Page): Promise<void> {
  await gotoPage(expect, page, '/hilos/settings')
  await expect(page.getByTestId('conn-state')).toHaveText('connected')
  await expect(page.getByTestId('hilos-viewport-table')).toBeVisible()
}

test('the viewport table has an accessible name and a labelled search', async ({
  page,
}) => {
  await signUpAdmin(expect, page, { entryId: 'message-signin' })
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
  await signUpAdmin(expect, page, { entryId: 'message-signin' })
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
    await signUpAdmin(expect, page, { entryId: 'message-signin' })
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
  const userId = await signUpAdmin(expect, page, { entryId: 'message-signin' })
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

test('the shell exposes a skip link and marks the active nav item', async ({
  page,
}) => {
  await signUpAdmin(expect, page, { entryId: 'message-signin' })
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
  await signUpAdmin(expect, page, { entryId: 'message-signin' })
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
  // Presence is a signed-in property, so establish a user; the register lands on
  // '/' with the self user resolved and online.
  const user = await signUp(expect, page)

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
  const userId = await signUpAdmin(expect, page, { entryId: 'message-signin' })
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
  await signUpWithVerifiedEmail(expect, page)
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
    const user = await signUp(expect, page)
    await gotoPage(expect, page, '/profile')
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
  await signUp(expect, page)

  await gotoPage(expect, page, '/profile/sessions')
  await expect(page.getByTestId('profile-sessions-heading')).toHaveText(
    'Sessions',
  )
  await expect(page.getByTestId('profile-sessions')).toHaveAttribute(
    'aria-label',
    'Sessions',
  )

  await gotoPage(expect, page, '/profile/devices')
  await expect(page.getByTestId('profile-devices-heading')).toHaveText(
    'Devices',
  )
  await expect(page.getByTestId('profile-devices')).toHaveAttribute(
    'aria-label',
    'Devices',
  )
})

test('password visibility toggle operates from keyboard on sign-in', async ({
  page,
}) => {
  const user = await signUp(expect, page)
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
  await signUpAdmin(expect, page, { entryId: 'message-signin' })
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
  await signUpAdmin(expect, page, { entryId: 'message-signin' })
  await gotoPage(expect, page, DAEMON_NODE_PATH)
  await expectDaemonNodeHead(expect, page, 'hilos_daemon_node')
  for (const tab of DAEMON_NODE_TABS.slice(1)) {
    const link = page.getByTestId(`hilos-page-tab-${tab.key}`)
    await link.focus()
    await expect(link).toBeFocused()
    await link.press('Enter')
    await expect(page).toHaveURL(`${DAEMON_NODE_PATH}${tab.path}`)
    await expectDaemonNodeHead(expect, page, tab.key)
    if (tab.key !== 'hilos_daemon_env')
      await expectDaemonNodeTableName(expect, page)
    await page.getByTestId('hilos-page-tab-hilos_daemon_node').click()
    await expectDaemonNodeHead(expect, page, 'hilos_daemon_node')
  }
})

test('the moderation kinds root exposes one heading, named table and keyboard open link', async ({
  page,
}) => {
  await signUpAdmin(expect, page, { entryId: 'message-signin' })
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
  await signUpAdmin(expect, page, { entryId: 'message-signin' })
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

test.describe('Daemon section accessibility', () => {
  test.afterEach(() => setAdminViewMode(false))

  for (const viewer of [false, true]) {
    test(`Daemon section links, shared heading and Placement keyboard controls as ${viewer ? 'a viewer' : 'an admin'}`, async ({
      page,
    }) => {
      await setAdminViewMode(viewer)
      if (!viewer)
        await signUpAdmin(expect, page, { entryId: 'message-signin' })
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
    await signUpAdmin(expect, page, { entryId: 'message-signin' })
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
