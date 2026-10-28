import { test, expect } from '@playwright/test'
import type { Page } from '@playwright/test'

import {
  gotoMaintenance,
  gotoPage,
  expectPageReady,
  expectMaintenancePageHead,
  grantAdminToSelf,
  sessionToken,
  typeInto,
  watchDeliveryFrames,
} from '../../../../../framework/frontend/e2e/index.js'
import {
  enterProtectedMode,
  leaveProtectedMode,
  mintProtectedModePass,
  openProtectedModeIfAny,
} from '../../../../../framework/frontend/scripts/protectedMode.mjs'

// The backup page's block in the verification window (HIL-676): the operator who
// started the restore is offered the link to Maintenance, and nobody
// else is. It lived in chat's protected-mode.spec.ts and stayed in chat when that
// spec moved here (HIL-1221), because it belongs to the backup area rather than to
// the freeze; it came to binance-btc-tracker with the backup area itself
// (HIL-1220).
//
// It drives a freeze all the same, and the whole node freezes with it, so it runs
// the way the freeze spec did: the runner is serialized (CI=1 → workers: 1), and
// the teardown lifts unconditionally.

const OPERATION = 'e2e-freeze'

// The backup page, which carries the verification-window block.
const BACKUP_URL = '/hilos/backup'

test.afterEach(async () => {
  // Unconditional, and an open rather than a leave: an enter can be refused and
  // still land afterwards, a failed assertion can strand the node in any phase,
  // and only the open lifts from all of them.
  await openProtectedModeIfAny()
})

test('the verification window offers the reopen block to the operator, and to nobody else', async ({
  page,
  browser,
}) => {
  // HIL-676 acceptance. The block is the one thing on the backup page that is answered
  // PERSONALLY: two admins subscribe to the same page in the same window and only one of
  // them is offered the lever. Nothing short of two real browsers can show that, because
  // the whole decision lives on the session behind the connection.
  //
  // Both accounts are made BEFORE the freeze: under it there is no signing up.
  await grantAdminToSelf(expect, page)
  await gotoPage(expect, page, BACKUP_URL)
  await expect(page.getByTestId('hilos-viewport-table')).toBeVisible()
  const operatorSession = await sessionToken(page)
  const frames = watchDeliveryFrames(page)

  const verifierContext = await browser.newContext()
  const verifier = await verifierContext.newPage()
  await grantAdminToSelf(expect, verifier)
  await gotoPage(expect, verifier, BACKUP_URL)
  await expect(verifier.getByTestId('hilos-viewport-table')).toBeVisible()

  // Entered for the operator's BROWSER, exactly as a restore started from this page
  // enters it, and then ended - which is what leaves the node in the window.
  expect(await enterProtectedMode(OPERATION, '', operatorSession)).toBe(
    'active',
  )
  expect(await leaveProtectedMode()).toBe('verifying')

  // The operator, back on the page. The block carries an ordinary link.
  await gotoPage(expect, page, BACKUP_URL)
  await expect(page.getByTestId('hilos-backup-reopen-panel')).toBeVisible()
  const reopen = page.getByTestId('hilos-backup-reopen')
  await expect(reopen).toBeVisible()
  await expect(reopen).toHaveRole('link')
  await expect(reopen).toHaveAttribute('href', '/hilos/maintenance')
  await expect(page.getByTestId('hilos-backup-reopen-modal')).toHaveCount(0)

  // The verifier, admitted by the code the operator read out. They get the product -
  // that is what the window is for - and they do not get the lever: the pass names a
  // session the node let in, not the session that asked for the operation.
  const pass = await mintProtectedModePass()
  await gotoMaintenance(expect, verifier, BACKUP_URL)
  await presentCode(verifier, pass)
  await expect(verifier.getByTestId('maintenance')).toBeHidden()
  await expect(verifier.getByTestId('hilos-viewport-table')).toBeVisible()
  await expect(verifier.getByTestId('hilos-backup-reopen-panel')).toHaveCount(0)
  await expect(verifier.getByTestId('hilos-backup-reopen')).toHaveCount(0)

  // This is a test freeze, not a restore. Following the link is valid, while a
  // restore initiator's ladder belongs to the real restore scenario.
  await reopen.click()
  await expectPageReady(expect, page)
  await expect(page).toHaveURL(/\/hilos\/maintenance$/)
  await expectMaintenancePageHead(expect, page, 'hilos_maintenance')
  await expect(page.getByTestId('hilos-backup-reopen-modal')).toHaveCount(0)
  expect(frames.sent.some((frame) => frame.raw.includes('backup_reopen'))).toBe(
    false,
  )
  frames.stop()
  await verifierContext.close()
})

/**
 * Types a code into the verifier's field and drives the submit button.
 *
 * @param page The Playwright page showing the maintenance surface.
 * @param code The code to present.
 */
async function presentCode(page: Page, code: string): Promise<void> {
  const field = page.getByTestId('maintenance-pass')
  await typeInto(field, code)

  const submit = page.getByTestId('maintenance-pass-submit')
  await submit.scrollIntoViewIfNeeded()
  await expect(submit).toBeVisible()
  await expect(submit).toBeEnabled()
  await submit.focus()
  await submit.click()
}
