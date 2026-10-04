import { expect, test } from '@playwright/test'

import { grantAdminToSelf, setAdmin } from '../../helpers/adminGrant.js'
import {
  CLUSTER_FOLLOWER,
  CLUSTER_LEADER,
  CLUSTER_MASTERS,
  clusterInspect,
  expectLeaderUnmoved,
  newMasterPage,
  onMaster,
} from '../../helpers/cluster.js'
import { gotoPage, PAGE_REFUSED } from '../../helpers/page.js'
import {
  logout,
  nameFromEmail,
  openSignIn,
  register,
  uniqueEmail,
} from '../../helpers/session.js'

// Red on every attempt in run 0806 (HIL-1218, 7 lanes), green alone in one
// lane (0807) on the same HEAD. The harness recorded leader m3 at term 1; by
// the time the test began m1 led at term 2, so expectLeaderUnmoved() refused
// it before its first step - the extra election seconds after the cluster
// starts (P-459). Parked by the owner on 04.10.2026 (HOTFIX); HIL-1286 pays
// it off.
test.fixme("a sign-in and a sign-out on a follower master reach the same person's tab on another master", async ({
  browser,
}) => {
  // Registration and both remote session rotations must settle on two real sockets.
  test.slow()
  const before = await clusterInspect(CLUSTER_FOLLOWER)
  await expectLeaderUnmoved(before)
  const otherMaster = CLUSTER_MASTERS.find(
    (node) => node !== CLUSTER_LEADER && node !== CLUSTER_FOLLOWER,
  )
  if (!otherMaster)
    throw new Error('The cluster browser stand needs a third master')
  const tabA = await newMasterPage(browser, CLUSTER_FOLLOWER)
  try {
    await gotoPage(tabA, '/')
    await expect(tabA.getByTestId('conn-state')).toHaveText('connected')
    await onMaster(tabA, otherMaster)
    const tabB = await tabA.context().newPage()
    await gotoPage(tabB, '/')
    await expect(tabB.getByTestId('conn-state')).toHaveText('connected')
    // Only future sockets follow this cookie. B is still connected to the other master.
    await onMaster(tabA, CLUSTER_FOLLOWER)

    const email = uniqueEmail()
    await openSignIn(tabA)
    await register(tabA, email)
    await expect(tabA.getByTestId('self-user')).toHaveText(nameFromEmail(email))
    await expect(tabB.getByTestId('self-user')).toHaveText(nameFromEmail(email))
    await expect(tabB.getByTestId('conn-state')).toHaveText('connected')

    // The reconnect above followed the shared cookie onto F. Put B back on M so
    // the sign-out proves a second cross-node drop, rather than a local one.
    await onMaster(tabA, otherMaster)
    await tabB.reload()
    await expect(tabB.getByTestId('self-user')).toHaveText(nameFromEmail(email))
    await expect(tabB.getByTestId('conn-state')).toHaveText('connected')
    await onMaster(tabA, CLUSTER_FOLLOWER)
    const siblingReconnect = tabB.waitForEvent('websocket')
    await logout(tabA)
    await siblingReconnect
    await expect(tabB.getByTestId('nav-profile')).toHaveCount(0)
    await expect(tabB.getByTestId('conn-state')).toHaveText('connected')
  } finally {
    await tabA.context().close()
    await expectLeaderUnmoved(before)
  }
})

// Red on every attempt in run 0806 (HIL-1218, 7 lanes), green alone in one
// lane (0807) on the same HEAD. The harness recorded leader m3 at term 1; by
// the time the test began m1 led at term 2, so expectLeaderUnmoved() refused
// it before its first step - the extra election seconds after the cluster
// starts (P-459). Parked by the owner on 04.10.2026 (HOTFIX); HIL-1286 pays
// it off.
test.fixme('a change of rights reaches an admin page open on a follower master: a grant opens it, a revoke shuts it', async ({
  browser,
}) => {
  const before = await clusterInspect(CLUSTER_FOLLOWER)
  await expectLeaderUnmoved(before)
  const page = await newMasterPage(browser, CLUSTER_FOLLOWER)
  try {
    const userId = await grantAdminToSelf(page)
    await setAdmin(userId, false)
    await gotoPage(page, '/hilos/backup', PAGE_REFUSED)
    const error = page.getByTestId('page-error')
    await expect(error).toHaveAttribute('data-error-code', '403')

    await setAdmin(userId, true)
    // A refused page can open only when its holder answers again through the peer mesh.
    await expect(page.getByTestId('hilos-viewport-table')).toBeVisible()
    await expect(error).toHaveCount(0)
    await expect(page.getByTestId('hilos-table-main-action')).toBeVisible()

    await setAdmin(userId, false)
    await expect(error).toHaveAttribute('data-error-code', '403')
    await expect(page.getByTestId('hilos-viewport-table')).toHaveCount(0)
    await expect(page.getByTestId('hilos-table-main-action')).toHaveCount(0)
  } finally {
    await page.context().close()
    await expectLeaderUnmoved(before)
  }
})
