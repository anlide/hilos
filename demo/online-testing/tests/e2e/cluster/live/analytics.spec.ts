import { expect, test, type Page } from '@playwright/test'

import {
  PASSWORD,
  nameFromEmail,
  signUp,
  ANALYTICS_SIGN_UP_LAST_ACTION,
  ANALYTICS_WRITER_TIMEOUT_MS,
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
  readAnalyticsBrowserSession,
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

const stand = readClusterBrowserStand()
const sendCommand = clusterCommandChannel(stand, stand.follower)

for (const [index, sourceNode] of stand.masters.entries()) {
  const viewerNode = stand.masters[(index + 1) % stand.masters.length]

  test(`an action through ${sourceNode} is shown on the session page through ${viewerNode}`, async ({
    browser,
  }) => {
    // Two registrations, journal rotation and remote reads exceed the base cap.
    test.slow()
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

      const email = (await signUp(expect, source)).email
      const userId = Number(
        await source.getByTestId('self-user-id').innerText(),
      )
      expect(Number.isSafeInteger(userId)).toBe(true)
      expect(userId).toBeGreaterThan(0)
      const user = { userId, name: nameFromEmail(email) }
      const browserMajor = await source.evaluate(
        () => navigator.userAgent.match(/Chrome\/(\d+)/)?.[1],
      )
      expect(browserMajor).toBeDefined()
      const bodies = [email, PASSWORD]
      await sourceUpgrades.assert()

      await signUp(expect, admin)
      const adminId = Number(
        await admin.getByTestId('self-user-id').innerText(),
      )
      expect(Number.isSafeInteger(adminId)).toBe(true)
      expect(adminId).toBeGreaterThan(0)
      await setAdmin(adminId, true)
      await expect(admin.getByTestId('nav-admin')).toBeVisible()
      await viewerUpgrades.assert()

      const readUser = () =>
        readAnalyticsBrowserSession(sendCommand, user.userId)
      const readAdmin = () => readAnalyticsBrowserSession(sendCommand, adminId)
      await expect
        .poll(
          async () =>
            (await readUser()).actionNames.includes(
              ANALYTICS_SIGN_UP_LAST_ACTION,
            ),
          { timeout: ANALYTICS_WRITER_TIMEOUT_MS },
        )
        .toBe(true)
      await expect
        .poll(
          async () =>
            (await readAdmin()).actionNames.includes(
              ANALYTICS_SIGN_UP_LAST_ACTION,
            ),
          { timeout: ANALYTICS_WRITER_TIMEOUT_MS },
        )
        .toBe(true)
      const userSession = await readUser()
      const adminSession = await readAdmin()
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
      // The whole registration ran on the main page: every action stands on it.
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

      // The observer is on the admin's analytics page only: the registration
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
