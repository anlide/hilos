import { test, expect } from '@playwright/test'

import { shownByTestId } from '../../../../../framework/frontend/e2e/index.js'
import { setAdmin } from '../helpers/adminGrant'
import { setAdminViewMode } from '../helpers/adminViewMode'
import { expectPageReady, gotoPage, PAGE_READY } from '../helpers/page'
import { signUp } from '../helpers/session'

// HIL-1253: the browser knows the node's admin view mode from the session
// response, so the shell draws the admin gear for a viewer who may look — a
// guest without an account included — and the admin routes do not refuse that
// viewer on the client. A grant turns the gear full and a revoke takes it back
// to the view, live, with no 403 drawn on the way: the server answers the page
// again, the view or the full one, and the tab only waits for it.
//
// HIL-1260: on every admin screen the viewer reads one strip saying the screen
// may be looked at and not changed, and what the server keeps from them reads
// as one mark, "Hidden" — a guest's look at the people who accepted the terms
// and at the chat's users names nobody.
//
// The lever is node-wide; every test here leaves it off, failed or not.

/** The words of the view-mode strip, as the shell draws them. */
const VIEW_MODE_STRIP_TEXT =
  'View mode · You can look around, but not change anything.'

test.afterEach(() => setAdminViewMode(false))

test('a guest without an account sees the admin gear and opens the admin section in the view mode', async ({
  page,
}) => {
  await gotoPage(page, '/')
  await expect(page.getByTestId('conn-state')).toHaveText('connected')
  await expect(page.getByTestId('nav-admin')).toHaveCount(0)

  await setAdminViewMode(true)
  // The flip is not sent to the open tab; the next handshake carries it.
  await gotoPage(page, '/')
  const gear = page.getByTestId('nav-admin')
  await expect(gear).toBeVisible()
  await expect(gear).toHaveAttribute('data-access', 'view')

  await gear.click()
  await expectPageReady(page)
  await expect(page.getByTestId('dashboard-view')).toBeVisible()
  expect(new URL(page.url()).pathname).toBe('/hilos')
  await expect(page.getByTestId('page-error')).toHaveCount(0)
})

test('a guest reads the view-mode strip on the admin screens and the personal data as hidden', async ({
  browser,
  page,
}) => {
  // An account of this test's own: the acceptances and the users then hold at
  // least one person whose name is kept from the guest.
  const someone = await browser.newContext()
  await signUp(await someone.newPage())
  await someone.close()

  await setAdminViewMode(true)
  await gotoPage(page, '/')
  await expect(page.getByTestId('conn-state')).toHaveText('connected')
  await expect(page.getByTestId('nav-admin')).toHaveAttribute(
    'data-access',
    'view',
  )
  await expect(page.getByTestId('view-mode-banner')).toHaveCount(0)

  const screens = [
    { path: '/hilos', hidden: null },
    { path: '/hilos/legal/acceptances', hidden: 'legal-acceptances-table' },
    { path: '/hilos/app/users', hidden: 'admin-users-view' },
  ]
  for (const screen of screens) {
    await gotoPage(page, screen.path, PAGE_READY)
    const strip = page.getByTestId('view-mode-banner')
    await expect(strip).toBeVisible()
    await expect(strip).toHaveText(VIEW_MODE_STRIP_TEXT)
    if (screen.hidden !== null) {
      await expect(
        shownByTestId(page.getByTestId(screen.hidden), 'hilos-hidden').first(),
      ).toHaveText('Hidden')
    }
  }
})

test('a signed-in non-admin views the people, is granted the full section and taken back to the view live', async ({
  page,
}) => {
  await setAdminViewMode(true)
  const { userId } = await signUp(page)
  const gear = page.getByTestId('nav-admin')
  await expect(gear).toHaveAttribute('data-access', 'view')

  await gotoPage(page, '/hilos/app/users', PAGE_READY)
  await expect(page.getByTestId('admin-users-view')).toBeVisible()
  await expect(page.getByTestId('page-error')).toHaveCount(0)
  const strip = page.getByTestId('view-mode-banner')
  await expect(strip).toBeVisible()

  // The grant re-sends the session response to this tab: the gear turns full
  // and the page is answered again, now the full one; the strip leaves.
  await setAdmin(userId, true)
  await expect(gear).toHaveAttribute('data-access', 'full')
  await expectPageReady(page)
  await expect(page.getByTestId('admin-users-view')).toBeVisible()
  await expect(strip).toHaveCount(0)

  // The revoke with the mode on answers the view rather than a 403, and the
  // tab waits for it instead of drawing the refusal ahead of the server.
  await setAdmin(userId, false)
  await expect(gear).toHaveAttribute('data-access', 'view')
  await expectPageReady(page)
  await expect(page.getByTestId('page-error')).toHaveCount(0)
  await expect(page.getByTestId('admin-users-view')).toBeVisible()
  await expect(strip).toBeVisible()
})
