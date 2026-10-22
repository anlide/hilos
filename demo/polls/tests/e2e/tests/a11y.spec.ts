import { test, expect, type Page } from '@playwright/test'
import { grantAdminToSelf } from '../helpers/adminGrant'
import { gotoPage } from '../helpers/page'
import { holdLegalRevision } from '../helpers/legalHold.js'
import {
  clickSubmit,
  enterIdentifierAndPassword,
  logout,
  openSignIn,
  PASSWORD,
  signUp,
} from '../helpers/session'
import { expectPasswordVisibility } from '../../../../../framework/frontend/e2e/index.js'

// Hilos accessibility (a11y) e2e — the rarely-run a11y category (see
// docs/agents/testing.md "Selective testing"). Asserts the framework viewport
// table exposes a correct accessibility tree over the live socket: an accessible
// name from the page heading it stands under, a labeled search box, and a sortable
// header that reports aria-sort and is operable from the keyboard. The polls
// (Angular) layer checks this on the users table; the file grows as the a11y arc
// lands its later steps (modals, app-shell, focus).

/** Open the users admin and wait for the live table. */
async function openUsers(page: Page): Promise<void> {
  await gotoPage(page, '/hilos/users')
  await expect(page.getByTestId('conn-state')).toHaveText('connected')
  await expect(page.getByTestId('hilos-viewport-table')).toBeVisible()
}

test('the viewport table has an accessible name and a labelled search', async ({
  page,
}) => {
  await grantAdminToSelf(page)

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
  await grantAdminToSelf(page)

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
  await gotoPage(page, '/hilos')
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
  await grantAdminToSelf(page)
  await gotoPage(page, '/hilos')
  await expect(page.getByTestId('nav-admin')).toHaveAttribute(
    'aria-current',
    'page',
  )
})

test('each page titles the tab and announces the page on navigation', async ({
  page,
}) => {
  await grantAdminToSelf(page)

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

test('the home page exposes a single top-level heading', async ({ page }) => {
  await gotoPage(page, '/')
  await expect(page.getByTestId('conn-state')).toHaveText('connected')
  await expect(page.getByRole('heading', { level: 1 })).toHaveText('Polls')
})

test('the sign-in card holds room for a refusal before there is one', async ({
  page,
}) => {
  // The room is taken by an invisible twin of the row, so the refusal has
  // somewhere to land without moving the form (HIL-647). Angular has no
  // component world of its own yet (HIL-848), so this is where its copy of the
  // component is proved.
  await gotoPage(page, '/')
  await expect(page.getByTestId('conn-state')).toHaveText('connected')
  await openSignIn(page)

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
  await grantAdminToSelf(page)

  await gotoPage(page, '/hilos/logs/settings')
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
  const user = await signUp(page)
  await logout(page)
  await openSignIn(page)
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

test('Privacy comparison and erase expose names, keyboard focus and live regions', async ({
  page,
}) => {
  const person = await signUp(page)
  await gotoPage(page, '/privacy')
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
    await clickSubmit(dialog.getByTestId('privacy-changes-close'))
    await expect(dialog).toHaveCount(0)
    await expect(open).toBeFocused()
    await open.press('Enter')
    await expect(dialog).toBeVisible()
    await clickSubmit(dialog.getByTestId('modal-close'))
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
    await clickSubmit(page.getByTestId('privacy-erase-confirm'))
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
