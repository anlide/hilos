import { expect, test } from '@playwright/test'

import { grantAdminToSelf } from '../../helpers/adminGrant.js'
import { CLUSTER_FOLLOWER, newMasterPage } from '../../helpers/cluster.js'
import { FOLLOWED_STREAM } from '../../helpers/logs.js'
import { gotoPage } from '../../helpers/page.js'

test('a stopped node live file is refused as offline', async ({ browser }) => {
  const page = await newMasterPage(browser, CLUSTER_FOLLOWER)
  try {
    const stopped = process.env.CLUSTER_STOPPED
    expect(stopped).toBeTruthy()
    await grantAdminToSelf(page)
    await gotoPage(page, `/hilos/logs/view/${stopped}/live/${FOLLOWED_STREAM}`)
    await expect(page.getByTestId('hilos-log-refusal')).toContainText(
      `Cluster node ${stopped} is offline`,
    )
  } finally {
    await page.context().close()
  }
})
