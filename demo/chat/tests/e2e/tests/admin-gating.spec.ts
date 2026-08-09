import { test, expect } from '@playwright/test'
import { setAdmin } from '../helpers/adminGrant'
import { signUp } from '../helpers/session'
import { gotoPage, PAGE_REFUSED } from '../helpers/page'

// Admin-gating e2e: the ACCESS guard on /hilos/admin_users closes the page to a
// guest with a 403, and a CLI-style grant over the daemon command channel (the
// same setAdmin the admin:grant command sends) opens it after a reconnect. The
// persistent httpOnly session cookie keeps one browser = one user, so the granted
// user is the same one on reload. The admin-users page is served by the chat agent
// that owns the user and connection state, so the guard resolves identity
// authoritatively (no cross-agent mirror race).

test('gates the admin users page for a guest, then opens it after a grant', async ({
  page,
}) => {
  // Register a user (the durable id feeds the grant); the account is not admin
  // yet, so the ACCESS guard still rejects the admin page until the grant lands.
  const { userId } = await signUp(page)

  // Guest: the ACCESS guard rejects the subscription with a 403 error page in
  // place of the admin view.
  await gotoPage(page, '/hilos/admin_users', PAGE_REFUSED)
  const error = page.getByTestId('page-error')
  await expect(error).toBeVisible()
  await expect(error).toHaveAttribute('data-error-code', '403')
  await expect(error).toContainText('Forbidden')
  await expect(page.getByTestId('admin-users-view')).toHaveCount(0)

  // Grant admin over the command channel, then reconnect with the same cookie.
  await setAdmin(userId, true)
  await gotoPage(page, '/hilos/admin_users')

  // The now-admin user passes the guard: the live table renders, no error page.
  await expect(page.getByTestId('conn-state')).toHaveText('connected')
  await expect(page.getByTestId('admin-users-view')).toBeVisible()
  await expect(page.getByTestId('hilos-viewport-table')).toBeVisible()
  await expect(page.getByTestId('page-error')).toHaveCount(0)

  // Revoke so the shared-DB user does not stay admin for later specs.
  await setAdmin(userId, false)
})
