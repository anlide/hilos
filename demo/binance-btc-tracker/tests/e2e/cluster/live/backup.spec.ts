import { expect, test } from '@playwright/test'

import { grantAdminToSelf } from '../../helpers/adminGrant.js'
import {
  CLUSTER_SLAVES,
  CLUSTER_FOLLOWER,
  newMasterPage,
  placementNode,
  requestNodeStop,
} from '../../helpers/cluster.js'
import { gotoPage } from '../../helpers/page.js'

const READY_CARD = /Backup "([^"]+)" is ready\./

// Red on every attempt in run 0806 (HIL-1218, 7 lanes), green alone in one
// lane (0807) on the same HEAD. The harness recorded leader m3 at term 1; by
// the time the test began m1 led at term 2, so expectLeaderUnmoved() refused
// it before its first step - the extra election seconds after the cluster
// starts (P-459). Parked by the owner on 04.10.2026 (HOTFIX); HIL-1286 pays
// it off.
// This test is also the one that asks the harness to stop a slave, so while it
// is parked the after-loss phase does not run at all.
test.fixme('a backup made through a follower master lands as a completed row, and its slave is named for the loss phase', async ({
  browser,
}) => {
  const page = await newMasterPage(browser, CLUSTER_FOLLOWER)
  try {
    // The command round trip and remote artifact delivery outlive the base cap.
    test.slow()
    await grantAdminToSelf(page)
    await gotoPage(page, '/hilos/backup')
    await expect(page.getByTestId('hilos-viewport-table')).toBeVisible()

    await page.getByTestId('hilos-table-main-action').click()
    const dialog = page.getByTestId('modal')
    await expect(dialog).toBeVisible()
    await dialog
      .getByTestId('hilos-backup-create-scope')
      .selectOption('schema-only')
    const confirm = dialog.getByTestId('hilos-backup-create-confirm')
    await confirm.scrollIntoViewIfNeeded()
    await expect(confirm).toBeVisible()
    await expect(confirm).toBeEnabled()
    await confirm.focus()
    await confirm.click()
    await expect(dialog).toBeHidden()

    await expect(page.getByTestId('hilos-toast-error')).toHaveCount(0)
    const card = page
      .getByTestId('hilos-toast-success')
      .filter({ hasText: READY_CARD })
    await expect(card).toBeVisible({ timeout: 60_000 })
    const backupRow = READY_CARD.exec((await card.textContent()) ?? '')?.[1]
    expect(backupRow).toBeTruthy()
    const row = page.getByTestId(`hilos-table-row-${backupRow}`)
    await expect(row).toBeVisible()
    await expect(row).toContainText('schema-only')
    await expect(row).toContainText('success')

    const holder = await placementNode('hilos_backup')
    expect(CLUSTER_SLAVES).toContain(holder)
    requestNodeStop(holder, 'the slave holding the archive', { backupRow })
  } finally {
    await page.context().close()
  }
})
