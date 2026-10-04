import { expect, test } from '@playwright/test'

import { grantAdminToSelf } from '../../helpers/adminGrant.js'
import {
  CLUSTER_FOLLOWER,
  newMasterPage,
  readCarry,
} from '../../helpers/cluster.js'
import { gotoPage } from '../../helpers/page.js'

test('the archive of the stopped slave stays visible but out of reach', async ({
  browser,
}) => {
  const page = await newMasterPage(browser, CLUSTER_FOLLOWER)
  try {
    await grantAdminToSelf(page)
    await gotoPage(page, '/hilos/backup')
    const backupRow = String(readCarry().backupRow)
    const stopped = process.env.CLUSTER_STOPPED
    expect(stopped).toBeTruthy()
    const row = page.getByTestId(`hilos-table-row-${backupRow}`)
    await expect(row).toBeVisible()
    const holder = row.getByTestId(`hilos-backup-holder-${backupRow}`)
    await expect(holder).toHaveText(stopped ?? '')
    await expect(holder).toHaveClass(/text-bg-secondary/)
    await expect(holder).toHaveAttribute(
      'title',
      `Stored on node ${stopped} - the backup agent runs elsewhere, so this archive cannot be reached from here`,
    )
    await expect(
      row.getByTestId(`hilos-backup-delete-${backupRow}`),
    ).toHaveCount(0)
    await expect(row.getByTestId(`hilos-backup-keep-${backupRow}`)).toHaveCount(
      0,
    )
  } finally {
    await page.context().close()
  }
})
