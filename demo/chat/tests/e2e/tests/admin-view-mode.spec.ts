import { test, expect } from '@playwright/test'

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
// The lever is node-wide; every test here leaves it off, failed or not.

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

  // The grant re-sends the session response to this tab: the gear turns full
  // and the page is answered again, now the full one.
  await setAdmin(userId, true)
  await expect(gear).toHaveAttribute('data-access', 'full')
  await expectPageReady(page)
  await expect(page.getByTestId('admin-users-view')).toBeVisible()

  // The revoke with the mode on answers the view rather than a 403, and the
  // tab waits for it instead of drawing the refusal ahead of the server.
  await setAdmin(userId, false)
  await expect(gear).toHaveAttribute('data-access', 'view')
  await expectPageReady(page)
  await expect(page.getByTestId('page-error')).toHaveCount(0)
  await expect(page.getByTestId('admin-users-view')).toBeVisible()
})
