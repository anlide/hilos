import { expect, test } from '@playwright/test'

import { CLUSTER_FOLLOWER, newMasterPage } from '../../helpers/cluster.js'
import { FOLLOWED_STREAM } from '../../helpers/logs.js'
import {
  gotoPage,
  grantAdminToSelf,
} from '../../../../../../framework/frontend/e2e/index.js'

test('a stopped node live file is refused as offline', async ({ browser }) => {
  const page = await newMasterPage(browser, CLUSTER_FOLLOWER)
  try {
    const stopped = process.env.CLUSTER_STOPPED
    expect(stopped).toBeTruthy()
    await grantAdminToSelf(expect, page)
    await gotoPage(
      expect,
      page,
      `/hilos/logs/view/${stopped}/live/${FOLLOWED_STREAM}`,
    )
    await expect(page.getByTestId('hilos-log-refusal')).toContainText(
      `Cluster node ${stopped} is offline`,
    )
  } finally {
    await page.context().close()
  }
})
