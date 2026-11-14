import { expect, test } from '../../test.js'

import {
  PAGE_READY,
  PASSWORD,
  clusterCommandChannel,
  expectClusterLeaderUnchanged,
  gotoPage,
  login,
  newClusterMasterPage,
  openSignIn,
  readClusterBrowserStand,
  uniqueEmail,
  watchClusterMasterUpgrades,
  type ClusterInspection,
} from '../../../../../../framework/frontend/e2e/index.js'
import { setAdmin } from '../../../../../../framework/frontend/scripts/adminGrant.mjs'
import { seedTestUser } from '../../../../../../framework/frontend/scripts/testUser.mjs'

// spec-owner: demo — carries File Gallery Manager's React cluster sign-in

const stand = readClusterBrowserStand()
const sendCommand = clusterCommandChannel(stand, stand.follower)

// The built React shell must sign in through the follower and reach the admin root.
// Its first application writes also exercise journal triggers on the binlogged primary.
test('signs in through the non-leader master and opens the admin root', async ({
  browser,
}) => {
  const before = (await sendCommand(
    'test:cluster:inspect',
    {},
  )) as unknown as ClusterInspection
  await expectClusterLeaderUnchanged(expect, stand, before)

  const user = await seedTestUser({ email: uniqueEmail() })
  await setAdmin(user.userId, true)
  const page = await newClusterMasterPage(browser, stand, stand.follower)
  try {
    const upgrades = await watchClusterMasterUpgrades(
      expect,
      page,
      stand,
      stand.follower,
    )
    try {
      await gotoPage(expect, page, '/')
      await openSignIn(expect, page)
      await login(expect, page, user.email, PASSWORD)
      await gotoPage(expect, page, '/hilos', PAGE_READY)
      await upgrades.assert()
      await expectClusterLeaderUnchanged(expect, stand, before)
    } finally {
      await upgrades.stop()
    }
  } finally {
    await page.context().close()
  }
})
