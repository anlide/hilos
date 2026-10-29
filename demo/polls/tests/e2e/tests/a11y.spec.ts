import { test, expect, type Page } from '@playwright/test'
import { holdLegalRevision } from '../helpers/legalHold.js'

import {
  enterIdentifierAndPassword,
  logout,
  openSignIn,
  PASSWORD,
  createTestUser,
  expectCommunicationsAccessibility,
  expectDesktopShell,
  DAEMON_NODE_PATH,
  DAEMON_NODE_TABS,
  expectLanguagePageHead,
  expectDaemonNodeHead,
  expectDaemonNodeTableName,
  expectStandaloneWebsockets,
  watchDaemonWebsocketsFrames,
  expectPasswordVisibility,
  shownByTestId,
  watchClientDocument,
  DAEMON_ROOT_PATH,
  expectDaemonPage,
  expectDaemonSectionHead,
  expectDaemonSectionAccessibility,
  clickSubmit,
  typeInto,
  expectPageReady,
  gotoPage,
  grantAdminToSelf,
  watchProfileRenameStatus,
  expectProfileTimeZone,
} from '../../../../../framework/frontend/e2e/index.js'

import { setAdminViewMode } from '../../../../../framework/frontend/scripts/adminViewMode.mjs'

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

test('the language Names table takes its name from the shared head', async ({
  page,
}) => {
  await grantAdminToSelf(expect, page)
  await gotoPage(expect, page, '/hilos/i18n/languages/en/names')
  await expectPageReady(expect, page)

  await expectLanguagePageHead(expect, page, {
    code: 'en',
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

test('public pages have one heading and legal history readers support keyboard focus', async ({
  page,
}) => {
  await gotoPage(expect, page, '/')
  await expect(page.getByTestId('conn-state')).toHaveText('connected')
  await expect(page.getByRole('heading', { level: 1 })).toHaveText('Polls')

  for (const history of [
    { document: 'terms', title: 'Terms', clauses: 6 },
    { document: 'privacy', title: 'Privacy', clauses: 7 },
  ]) {
    await test.step(`${history.title} history has one named table and a keyboard reader`, async () => {
      await gotoPage(expect, page, `/${history.document}/history`)
      const heading = page.getByRole('heading', { level: 1 })
      await expect(heading).toHaveCount(1)
      await expect(heading).toHaveText(history.title)
      await expect(page).toHaveTitle(
        `${history.title} · Revision history · Hilos Polls`,
      )
      const table = page.getByRole('table', {
        name: history.title,
        exact: true,
      })
      await expect(table).toHaveCount(1)
      await expect(table).toBeVisible()
      const open = shownByTestId(page, 'public-legal-history-open').and(
        page.locator('[data-revision="2026-09-17"]'),
      )
      await expect(open).toHaveAccessibleName('Open')
      await open.focus()
      await expect(open).toBeFocused()
      await open.press('Enter')
      const dialog = page.getByTestId('modal')
      const text = page.getByTestId('public-legal-history-modal')
      await expect(dialog).toHaveRole('dialog')
      await expect(dialog).toHaveAttribute('aria-modal', 'true')
      await expect(dialog).toHaveAccessibleName(
        `${history.title} — revision of 17 September 2026`,
      )
      await expect(dialog).toBeFocused()
      await expect(page.getByTestId('legal-dialog-loading')).toBeHidden()
      await expect(text).toHaveAttribute('data-document', history.document)
      await expect(text.getByTestId('legal-revision-clause')).toHaveCount(
        history.clauses,
      )
      await dialog.press('Escape')
      await expect(dialog).toBeHidden()
      await expect(open).toBeFocused()

      await open.press('Enter')
      await expect(dialog).toBeVisible()
      await expect(dialog).toBeFocused()
      await expect(page.getByTestId('legal-dialog-loading')).toBeHidden()
      const close = page.getByTestId('public-legal-history-close')
      await expect(close).toHaveAccessibleName('Close')
      await close.focus()
      await close.press('Enter')
      await expect(dialog).toBeHidden()
      await expect(open).toBeFocused()
    })
  }
})

test('the sign-in card holds room for a refusal before there is one', async ({
  page,
}) => {
  // The room is taken by an invisible twin of the row, so the refusal has
  // somewhere to land without moving the form (HIL-647). Angular has no
  // component world of its own yet (HIL-848), so this is where its copy of the
  // component is proved.
  await gotoPage(expect, page, '/')
  await expect(page.getByTestId('conn-state')).toHaveText('connected')
  await openSignIn(expect, page)

  await expect(page.getByTestId('auth-error-slot')).toBeAttached()
  await expect(page.getByTestId('auth-error-idle')).toHaveAttribute(
    'aria-hidden',
    'true',
  )
  await expect(page.getByTestId('auth-error')).toHaveCount(0)
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
  await expect(page).toHaveURL(/\/hilos\/communications\/email$/)

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
  await expect(page).toHaveURL(/\/hilos\/communications\/email\/configuration$/)

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
  await expect(page).toHaveURL(/\/hilos\/communications\/email\/deliveries$/)

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
  await expect(page).toHaveURL(/\/hilos\/communications\/email$/)
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

test('the profile name dialog exposes accessible focus and dirty-close behavior', async ({
  page,
}) => {
  const user = await createTestUser(expect, page)
  await gotoPage(expect, page, '/profile')
  await expect(page.getByTestId('conn-state')).toHaveText('connected')
  await expect(page.getByTestId('profile-name')).toHaveText(user.name)

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

test.describe('Profile time zone accessibility', () => {
  test.use({ timezoneId: 'Europe/Warsaw', colorScheme: 'light' })

  test('names the picker, supports keyboard search, choice and Close, and returns focus', async ({
    page,
  }) => {
    await createTestUser(expect, page)
    await gotoPage(expect, page, '/profile')
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
    await expect(automatic).toContainText('Europe/Warsaw — from this browser')
    await expect(page.getByTestId('profile-time-zone')).toHaveText(
      'Europe/Warsaw · automatic',
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
      'Europe/Warsaw',
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
