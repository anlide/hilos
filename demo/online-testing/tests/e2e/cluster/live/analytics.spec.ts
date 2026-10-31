import { expect, test, type Page } from '@playwright/test'

import {
  PASSWORD,
  uniqueEmail,
  login,
  openSignIn,
  waitAnalyticsBrowserSession,
  analyticsSessionLink,
  expectAnalyticsActionRows,
  expectAnalyticsActionsInOrder,
  expectAnalyticsActionsOnPage,
  expectAnalyticsPersonSession,
  expectAnalyticsSessionFrames,
  expectAnalyticsSessionHeader,
  expectNoAnalyticsBodies,
  expectNoAnalyticsPersonSession,
  expectOtherAnalyticsSession,
  watchAnalyticsFrames,
  clusterCommandChannel,
  expectClusterAnalyticsAgents,
  expectClusterLeaderUnchanged,
  newClusterMasterPage,
  readClusterBrowserStand,
  watchClusterMasterUpgrades,
  type ClusterInspection,
  expectPageReady,
  gotoPage,
} from '../../../../../../framework/frontend/e2e/index.js'
import { setAdmin } from '../../../../../../framework/frontend/scripts/adminGrant.mjs'
import { seedTestUser } from '../../../../../../framework/frontend/scripts/testUser.mjs'
import { AUTH_ACTION_LOGIN } from '../../../../../../framework/frontend/core/src/index.js'

const stand = readClusterBrowserStand()
const sendCommand = clusterCommandChannel(stand, stand.follower)
const journals = Object.keys(stand.nodeLogs)
  .sort()
  .map((node) => clusterCommandChannel(stand, node))

for (const [index, sourceNode] of stand.masters.entries()) {
  const viewerNode = stand.masters[(index + 1) % stand.masters.length]

  test(`an action through ${sourceNode} is shown on the session page through ${viewerNode}`, async ({
    browser,
  }) => {
    const before = (await sendCommand(
      'test:cluster:inspect',
      {},
    )) as unknown as ClusterInspection
    await expectClusterLeaderUnchanged(expect, stand, before)
    await expectClusterAnalyticsAgents(expect, stand)
    const source = await newClusterMasterPage(browser, stand, sourceNode)
    let admin: Page | undefined
    let sourceUpgrades:
      Awaited<ReturnType<typeof watchClusterMasterUpgrades>> | undefined
    let viewerUpgrades:
      Awaited<ReturnType<typeof watchClusterMasterUpgrades>> | undefined
    try {
      admin = await newClusterMasterPage(browser, stand, viewerNode)
      sourceUpgrades = await watchClusterMasterUpgrades(
        expect,
        source,
        stand,
        sourceNode,
      )
      viewerUpgrades = await watchClusterMasterUpgrades(
        expect,
        admin,
        stand,
        viewerNode,
      )
      const frames = watchAnalyticsFrames(admin)

      const user = await seedTestUser({ email: uniqueEmail() })
      await gotoPage(expect, source, '/')
      await openSignIn(expect, source)
      await login(expect, source, user.email, PASSWORD)
      const userId = user.userId
      expect(Number.isSafeInteger(userId)).toBe(true)
      expect(userId).toBeGreaterThan(0)
      const browserMajor = await source.evaluate(
        () => navigator.userAgent.match(/Chrome\/(\d+)/)?.[1],
      )
      expect(browserMajor).toBeDefined()
      const bodies = [user.email, PASSWORD]
      await sourceUpgrades.assert()

      const { userId: adminId, email: adminEmail } = await seedTestUser({
        email: uniqueEmail(),
      })
      await gotoPage(expect, admin, '/')
      await openSignIn(expect, admin)
      await login(expect, admin, adminEmail, PASSWORD)
      expect(Number.isSafeInteger(adminId)).toBe(true)
      expect(adminId).toBeGreaterThan(0)
      await setAdmin(adminId, true)
      await expect(admin.getByTestId('nav-admin')).toHaveAttribute(
        'data-access',
        'full',
      )
      bodies.push(adminEmail)
      await viewerUpgrades.assert()

      const userSession = await waitAnalyticsBrowserSession(
        expect,
        sendCommand,
        user.userId,
        AUTH_ACTION_LOGIN,
        journals,
      )
      const adminSession = await waitAnalyticsBrowserSession(
        expect,
        sendCommand,
        adminId,
        AUTH_ACTION_LOGIN,
        journals,
      )
      expect(userSession.browserSessionId).not.toBeNull()
      expect(adminSession.browserSessionId).not.toBeNull()
      expect(userSession.browserSessionId).not.toBe(
        adminSession.browserSessionId,
      )

      // The way in: the person's admin card, then their sessions in Analytics.
      await gotoPage(expect, admin, `/hilos/user/${user.userId}`)
      await admin.getByTestId('hilos-user-analytics-link').click()
      await expect(admin).toHaveURL(
        new RegExp(`/hilos/analytics/people/${user.userId}$`),
      )
      await expectPageReady(expect, admin)
      await expectAnalyticsPersonSession(
        expect,
        admin,
        user,
        userSession,
        browserMajor!,
      )
      await expectNoAnalyticsPersonSession(
        expect,
        admin,
        adminSession.browserSessionId!,
      )

      await analyticsSessionLink(admin, userSession.browserSessionId!).click()
      await expect(admin).toHaveURL(
        new RegExp(
          `/hilos/analytics/browser-sessions/${userSession.browserSessionId}$`,
        ),
      )
      await expectPageReady(expect, admin)
      await expectAnalyticsSessionHeader(
        expect,
        admin,
        userSession.browserSessionId!,
        user,
        browserMajor!,
      )
      const ascending = await expectAnalyticsActionsInOrder(
        expect,
        admin,
        userSession.actionNames,
      )
      // Both real logins ran on the main page: every action stands on it.
      await expectAnalyticsActionsOnPage(expect, admin, 'main')

      await expectAnalyticsSessionFrames(
        expect,
        admin,
        frames,
        userSession.browserSessionId!,
        user,
        ascending,
      )

      await admin.getByTestId('hilos-table-sort-createdTs').click()
      await expectAnalyticsActionRows(expect, admin, [...ascending].reverse())

      // The observer is on the admin's analytics page only: the sign-in
      // screens legitimately carried these bodies earlier, in the other context.
      await expectNoAnalyticsBodies(expect, admin, frames, bodies)

      await gotoPage(
        expect,
        admin,
        `/hilos/analytics/browser-sessions/${adminSession.browserSessionId}`,
      )
      await expectOtherAnalyticsSession(
        expect,
        admin,
        adminSession,
        adminId,
        ascending,
      )
      await sourceUpgrades.assert()
      await viewerUpgrades.assert()
      await expectClusterAnalyticsAgents(expect, stand)
      await expectClusterLeaderUnchanged(expect, stand, before)
    } finally {
      try {
        await Promise.all([sourceUpgrades?.stop(), viewerUpgrades?.stop()])
      } finally {
        await Promise.all([source.context().close(), admin?.context().close()])
      }
    }
  })
}
