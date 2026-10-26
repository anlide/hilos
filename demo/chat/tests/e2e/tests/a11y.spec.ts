import { test, expect, type Page } from '@playwright/test'

import { signUpAdmin } from '../helpers/adminGrant'
import {
  clickSubmit,
  enterIdentifierAndPassword,
  logout,
  PASSWORD,
  signUp,
  signUpWithVerifiedEmail,
} from '../helpers/session'
import { expectPageReady, gotoPage } from '../helpers/page.js'
import {
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
  expectPasswordVisibility,
  expectTableTextControl,
  expectTableTextGeometry,
  openTableFixture,
  settleTableLayout,
  shownByTestId,
  tableTextToggle,
  watchClientDocument,
} from '../../../../../framework/frontend/e2e/index.js'

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
  await gotoPage(page, '/hilos/settings')
  await expect(page.getByTestId('conn-state')).toHaveText('connected')
  await expect(page.getByTestId('hilos-viewport-table')).toBeVisible()
}

test('the viewport table has an accessible name and a labelled search', async ({
  page,
}) => {
  await signUpAdmin(page)
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
  await signUpAdmin(page)
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
    await signUpAdmin(page)
    await gotoPage(page, '/hilos/analytics/people')
    await expectPageReady(page)
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

test('the shell exposes a skip link and marks the active nav item', async ({
  page,
}) => {
  await signUpAdmin(page)
  await gotoPage(page, '/hilos')
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

  await expectPageReady(page)
  const build = await expectDesktopShell(expect, page, 'nav-profile')
  const document = await watchClientDocument(page)
  try {
    await page.getByTestId('nav-brand').click()
    await expect(page).toHaveURL('/')
    await expectPageReady(page)
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
  await signUpAdmin(page)
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
  const user = await signUp(page)

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
  await expectPageReady(page)
  expect(new URL(page.url()).pathname).toBe(`/user/${selfUserId}`)
  await expect(page.getByTestId('user-name')).toHaveText(user.name)

  await page.getByTestId('nav-conversations').click()
  await expectPageReady(page)
  expect(new URL(page.url()).pathname).toBe('/')
  await expect(page.getByRole('heading', { level: 1 })).toHaveText(
    'Conversations',
  )
})

test('the user Overview exposes a shared heading, named navigation and keyboard rename', async ({
  page,
}) => {
  const userId = await signUpAdmin(page)
  const name = await page.getByTestId('self-user').innerText()
  await gotoPage(page, `/hilos/user/${userId}`)
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
  await signUpWithVerifiedEmail(page)
  await gotoPage(page, '/profile/sign-in')
  const passwordRow = page
    .getByTestId('profile-identity-item')
    .filter({ has: page.getByTestId('profile-password-change') })
  await clickSubmit(passwordRow.getByTestId('identity-unlink'))
  const live = page.getByTestId('profile-unlink-modal').getByRole('alert')
  await expect(live).toHaveAttribute('aria-live', 'assertive')
  await expect(live).toHaveText('')
  await clickSubmit(page.getByTestId('identity-unlink-cancel'))
  await expect(page.getByTestId('profile-unlink-modal')).toHaveCount(0)

  await gotoPage(page, '/profile')
  await page.getByTestId('profile-edit').click()
  const dialogLive = page.getByTestId('profile-rename-live-assertive')
  await expect(dialogLive).toHaveAttribute('role', 'alert')
  await expect(dialogLive).toHaveText('')
})

test('the profile session and device pages expose one named surface', async ({
  page,
}) => {
  await signUp(page)

  await gotoPage(page, '/profile/sessions')
  await expect(page.getByTestId('profile-sessions-heading')).toHaveText(
    'Sessions',
  )
  await expect(page.getByTestId('profile-sessions')).toHaveAttribute(
    'aria-label',
    'Sessions',
  )

  await gotoPage(page, '/profile/devices')
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
  const user = await signUp(page)
  await logout(page)
  await gotoPage(page, '/profile')
  await expect(page.getByTestId('auth-surface')).toBeVisible()
  await enterIdentifierAndPassword(page, user.email, PASSWORD)
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
  await signUpAdmin(page)
  await gotoPage(page, '/hilos/communications/email')
  await expectCommunicationsAccessibility(expect, page, 'overview', 'Enabled')

  const configuration = page.getByTestId(
    'hilos-page-tab-hilos_communications_channel_configuration',
  )
  await configuration.focus()
  await expect(configuration).toBeFocused()
  await configuration.press('Enter')
  await expectPageReady(page)
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
  await expectPageReady(page)
  await expectCommunicationsAccessibility(expect, page, 'deliveries', 'Enabled')

  const overview = page.getByTestId(
    'hilos-page-tab-hilos_communications_channel',
  )
  await overview.focus()
  await expect(overview).toBeFocused()
  await overview.press('Enter')
  await expectPageReady(page)
  await expectCommunicationsAccessibility(expect, page, 'overview', 'Enabled')
})

test('Node pages expose one entity heading, link tabs and tables named through the Shell', async ({
  page,
}) => {
  // Six addressed pages, each with a real subscription and keyboard navigation.
  test.slow()
  await signUpAdmin(page)
  await gotoPage(page, DAEMON_NODE_PATH)
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
  await signUpAdmin(page)
  await gotoPage(page, '/hilos/moderation')
  await expectPageReady(page)

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
