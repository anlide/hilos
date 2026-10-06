import { test, expect } from '@playwright/test'

import { shownByTestId } from '../../../../../framework/frontend/e2e/index.js'
import { setAdminViewMode } from '../helpers/adminViewMode'
import { expectPageReady, gotoPage, PAGE_READY } from '../helpers/page'
import {
  clickSubmit,
  openSignIn,
  register,
  uniqueEmail,
} from '../helpers/session'

// HIL-1271: the React shell reads the node's admin view mode from the session
// response, as the Vue one does (HIL-1253, HIL-1260): the gear is drawn for a
// viewer who may look — a guest without an account included — and the admin
// routes do not refuse that viewer on the client. On every admin screen of the
// account side the viewer reads one strip saying the screen may be looked at and
// not changed, and what the server keeps from them reads as one mark, "Hidden".
// The screens of the operations side live in the ecommerce-shop demo (its
// admin-view-mode.spec.ts); the live grant and revoke are proven by the React
// shell's unit and by chat.
//
// The lever is node-wide; every test here leaves it off, failed or not.

/** The words of the view-mode strip, as the shell draws them. */
const VIEW_MODE_STRIP_TEXT =
  'View mode · You can look around, but not change anything.'

/** The admin screens of the account side that tasks mounts. */
const ACCOUNT_SCREENS = [
  '/hilos',
  '/hilos/security',
  '/hilos/security/sign-in-methods',
  '/hilos/security/2fa',
  '/hilos/security/2fa/step-up',
  '/hilos/security/oauth',
  '/hilos/security/impersonation',
  '/hilos/legal',
  '/hilos/legal/acceptances',
  '/hilos/legal/settings',
]

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

test('a guest reads the view-mode strip on the account screens and the acceptances as hidden', async ({
  browser,
  page,
}) => {
  // An account of this test's own: the acceptances then hold at least one person
  // whose name is kept from the guest.
  // A context made off the browser fixture inherits none of the project's `use`
  // options, so the address and the tolerance for the test nginx's self-signed
  // certificate are passed on by hand.
  const { baseURL, ignoreHTTPSErrors } = test.info().project.use
  const someone = await browser.newContext({ baseURL, ignoreHTTPSErrors })
  const registrant = await someone.newPage()
  await gotoPage(registrant, '/')
  await openSignIn(registrant)
  await register(registrant, uniqueEmail())
  await someone.close()

  await setAdminViewMode(true)
  await gotoPage(page, '/')
  await expect(page.getByTestId('conn-state')).toHaveText('connected')
  await expect(page.getByTestId('nav-admin')).toHaveAttribute(
    'data-access',
    'view',
  )
  await expect(page.getByTestId('view-mode-banner')).toHaveCount(0)

  for (const path of ACCOUNT_SCREENS) {
    await gotoPage(page, path, PAGE_READY)
    const strip = page.getByTestId('view-mode-banner')
    await expect(strip).toBeVisible()
    await expect(strip).toHaveText(VIEW_MODE_STRIP_TEXT)
    await expect(page.getByTestId('page-error')).toHaveCount(0)
    if (path === '/hilos/legal/acceptances') {
      await expect(
        shownByTestId(
          page.getByTestId('legal-acceptances-table'),
          'hilos-hidden',
        ).first(),
      ).toHaveText('Hidden')
    }
  }
})

// The fixed legal key is open to a viewer (HIL-1298); editing remains locked.
test('a guest opens a legal setting, reads its value and cannot save it', async ({
  page,
}) => {
  await setAdminViewMode(true)
  await gotoPage(page, '/hilos/legal/settings', PAGE_READY)
  await expect(
    shownByTestId(page, 'legal-setting-value-legal.consent_form'),
  ).toContainText('Checkbox')
  await expect(
    shownByTestId(page, 'legal-setting-value-legal.consent_form').getByTestId(
      'hilos-hidden',
    ),
  ).toHaveCount(0)
  await clickSubmit(
    shownByTestId(page, 'legal-setting-edit-legal.consent_form'),
  )
  const dialog = page.getByTestId('modal')
  await expect(dialog.getByTestId('hilos-hidden')).toHaveCount(0)
  await expect(page.getByTestId('legal-setting-input')).toBeVisible()
  const save = page.getByTestId('legal-setting-save')
  await expect(save).toBeDisabled()
  await expect(save).toHaveAttribute(
    'aria-describedby',
    /(^| )hilos-view-mode-strip-text( |$)/,
  )
  await clickSubmit(page.getByTestId('legal-setting-cancel'))
  await expect(dialog).toBeHidden()
})
