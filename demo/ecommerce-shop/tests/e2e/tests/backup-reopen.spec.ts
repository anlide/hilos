import { test, expect } from '@playwright/test'

import { grantAdminToSelf, sessionToken } from '../helpers/adminGrant'
import { gotoPage } from '../helpers/page'
import {
  enterProtectedMode,
  leaveProtectedMode,
  openProtectedModeIfAny,
} from '../helpers/protectedMode'

// HIL-911: the reopen block reaches a backup page that was already open when the
// verification window opened. The operator's tab stays subscribed through the
// whole freeze and comes back out of the stub onto the page it had - answered
// while the system was still frozen, so without a fresh answer it carries no
// block, and the banner tells the operator to reopen from a page offering
// nothing to press. Before the fix the block appeared only after a reload.
//
// So the assertion is a WAIT for the block and never a reload or a navigation:
// reloading is exactly the workaround the leaf removes, and a spec that reloaded
// would pass on the unfixed code. The load counter below makes that explicit.
//
// What the button does, and that the pages reload themselves when it is pressed,
// is not proven here: the node accepts a reopen only from the backup agent as the
// recorded initiator, and the freeze lever names the index agent instead. Proving
// it takes a real restore, which rewrites the stand's database.
//
// The whole node freezes for the duration, so this spec must never leave one
// behind: the runner is serialized (CI=1 -> workers: 1), and the teardown lifts
// unconditionally.

const OPERATION = 'e2e-freeze'

const BACKUP_URL = '/hilos/backup'

test.afterEach(async () => {
  // Unconditional, and an open rather than a close: a failed assertion can strand
  // the node in any phase, and only the open lifts from all of them.
  await openProtectedModeIfAny()
})

test('an open backup page offers the reopen block when the window opens, without a reload', async ({
  page,
}) => {
  await grantAdminToSelf(page)
  await gotoPage(page, BACKUP_URL)
  await expect(page.getByTestId('hilos-viewport-table')).toBeVisible()
  // Answered while nothing is frozen: the block is not on the page yet, which is
  // what makes its arrival below the thing under test.
  await expect(page.getByTestId('hilos-backup-reopen-panel')).toHaveCount(0)
  const operatorSession = await sessionToken(page)

  let fullLoads = 0
  page.on('load', () => {
    fullLoads += 1
  })

  // Entered for this browser, exactly as a restore started from this page enters
  // it, and then ended - which is what leaves the node in the verification window
  // with the operator's tab still open on the backup page.
  expect(await enterProtectedMode(OPERATION, operatorSession)).toBe('active')
  expect(await leaveProtectedMode()).toBe('verifying')

  // The same document, answered again: the block and its button draw themselves.
  await expect(page.getByTestId('maintenance')).toBeHidden()
  await expect(page.getByTestId('hilos-backup-reopen-panel')).toBeVisible()
  await expect(page.getByTestId('hilos-backup-reopen')).toBeVisible()
  expect(fullLoads).toBe(0)
})
