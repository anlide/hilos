import { test, expect } from '@playwright/test'

import { setAdmin } from '../helpers/adminGrant'
import { gotoPage, PAGE_REFUSED } from '../helpers/page'
import { signUpPerson } from '../helpers/session'

// Admin-gating e2e: the framework people page /hilos/users is closed to a person
// who is not an administrator with a 403, and a CLI-style grant over the daemon
// command channel (the same admin:grant the CLI command sends) opens it after a
// reconnect. The refusal is the ADMIN level every framework admin page inherits
// (docs/agents/architecture/page-access-control.md) — the same level that closed
// chat's own users screen once its own guard went (HIL-1251). The persistent
// httpOnly session cookie keeps one browser = one user, so the granted user is the
// same one on reload.

test('gates the people page for a non-admin, then opens it after a grant', async ({
  page,
}) => {
  // Register a user (the durable id feeds the grant); the account is not admin
  // yet, so the page's level still refuses it until the grant lands.
  const { userId } = await signUpPerson(page)

  // Not an admin: the subscription is refused with a 403 error page in place of
  // the admin view.
  await gotoPage(page, '/hilos/users', PAGE_REFUSED)
  const error = page.getByTestId('page-error')
  await expect(error).toBeVisible()
  await expect(error).toHaveAttribute('data-error-code', '403')
  await expect(error).toContainText('Forbidden')
  await expect(page.getByTestId('hilos-viewport-table')).toHaveCount(0)

  // Grant admin over the command channel, then reconnect with the same cookie.
  await setAdmin(userId, true)
  await gotoPage(page, '/hilos/users')

  // The now-admin user passes the level: the live table renders, no error page.
  await expect(page.getByTestId('conn-state')).toHaveText('connected')
  await expect(page.getByTestId('hilos-viewport-table')).toBeVisible()
  await expect(page.getByTestId('page-error')).toHaveCount(0)

  // Revoke so the shared-DB user does not stay admin for later specs.
  await setAdmin(userId, false)
})
