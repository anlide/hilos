import { test, expect } from '@playwright/test'

import { setAdminViewMode } from '../helpers/adminViewMode.js'
import { gotoPage, PAGE_READY } from '../helpers/page.js'

// HIL-1273: the operations side of the Angular admin view mode — the area moved
// here from polls by HIL-1226. On every operations screen a viewer — a guest
// without an account included — reads one strip saying the screen may be looked
// at and not changed. The account side (the gear, the account screens, the
// acceptances read as hidden, the Legal setting window) is polls'
// admin-view-mode.spec.ts (HIL-1272).
//
// The lever is node-wide; every test here leaves it off, failed or not.

/** The words of the view-mode strip, as the shell draws them. */
const VIEW_MODE_STRIP_TEXT =
  'View mode · You can look around, but not change anything.'

/**
 * Every admin page of demo/online-testing/backend/Hilos.php (PAGES) whose route
 * takes no parameter, the dashboard aside — it is the shell's, walked in polls.
 */
const OPERATIONS_SCREENS = [
  '/hilos/settings',
  '/hilos/users',
  '/hilos/maintenance',
  '/hilos/logs',
  '/hilos/logs/keys',
  '/hilos/logs/workers',
  '/hilos/logs/rotations',
  '/hilos/logs/settings',
  '/hilos/logs/view',
]

test.afterEach(() => setAdminViewMode(false))

test('a guest reads the view-mode strip on every operations screen', async ({
  page,
}) => {
  await setAdminViewMode(true)
  await gotoPage(page, '/')
  await expect(page.getByTestId('conn-state')).toHaveText('connected')
  await expect(page.getByTestId('nav-admin')).toHaveAttribute(
    'data-access',
    'view',
  )
  await expect(page.getByTestId('view-mode-banner')).toHaveCount(0)

  for (const path of OPERATIONS_SCREENS) {
    await gotoPage(page, path, PAGE_READY)
    const strip = page.getByTestId('view-mode-banner')
    await expect(strip).toBeVisible()
    await expect(strip).toHaveText(VIEW_MODE_STRIP_TEXT)
    await expect(page.getByTestId('page-error')).toHaveCount(0)
  }
})
