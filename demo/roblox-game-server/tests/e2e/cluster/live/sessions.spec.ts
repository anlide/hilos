import { expect, test } from '../../test.js'

import {
  logout,
  nameFromEmail,
  openSignIn,
  register,
  uniqueEmail,
  leaveSecondAdmin,
  gotoPage,
  PAGE_REFUSED,
  grantAdminToSelf,
  bindTestUser,
  expectDocumentUnchanged,
  expectProfileSessionNumericMoments,
  markDocument,
  profileSessionSnapshot,
  readClusterBrowserStand,
  watchClusterMasterUpgrades,
  watchSocketFrames,
  type SocketFrame,
} from '../../../../../../framework/frontend/e2e/index.js'
import { HilosPages } from '../../../../../../framework/frontend/core/src/routing/hilosPages.js'
import { resolveHilosPath } from '../../../../../../framework/frontend/core/src/routing/hilosAdmin.js'
import { setAdmin } from '../../../../../../framework/frontend/scripts/adminGrant.mjs'
import {
  CLUSTER_FOLLOWER,
  CLUSTER_LEADER,
  CLUSTER_MASTERS,
  clusterInspect,
  expectLeaderUnmoved,
  newMasterPage,
  nodeHost,
  onMaster,
} from '../../helpers/cluster.js'

test("a sign-in and a sign-out on a follower master reach the same person's tab on another master", async ({
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
  const framesA: SocketFrame[] = []
  const framesB: SocketFrame[] = []
  const stops: (() => void | Promise<void>)[] = []
  const stand = readClusterBrowserStand()
  let extraBrowser: Awaited<ReturnType<typeof newMasterPage>> | undefined
  try {
    const observerA = watchSocketFrames(tabA, (frame) => framesA.push(frame))
    stops.push(observerA.stop)
    const masterA = await watchClusterMasterUpgrades(
      expect,
      tabA,
      stand,
      CLUSTER_FOLLOWER,
    )
    stops.push(masterA.stop)
    await gotoPage(expect, tabA, '/')
    await expect(tabA.getByTestId('conn-state')).toHaveText('connected')
    await onMaster(tabA, otherMaster)
    const tabB = await tabA.context().newPage()
    stops.push(watchSocketFrames(tabB, (frame) => framesB.push(frame)).stop)
    await gotoPage(expect, tabB, '/')
    await expect(tabB.getByTestId('conn-state')).toHaveText('connected')
    // Only future sockets follow this cookie. B is still connected to the other master.
    await onMaster(tabA, CLUSTER_FOLLOWER)

    const email = uniqueEmail()
    await openSignIn(expect, tabA)
    await register(expect, tabA, email)
    await expect(tabA.getByTestId('self-user')).toHaveText(nameFromEmail(email))
    await expect(tabB.getByTestId('self-user')).toHaveText(nameFromEmail(email))
    await expect(tabB.getByTestId('conn-state')).toHaveText('connected')
    const userId = Number(await tabA.getByTestId('self-user-id').textContent())
    expect(Number.isSafeInteger(userId)).toBe(true)
    expect(userId).toBeGreaterThan(0)

    const masterB =
      await test.step('raw session moments agree on two physical masters', async () => {
        const sessionsPath = resolveHilosPath(HilosPages.PROFILE_SESSIONS)
        await gotoPage(expect, tabA, sessionsPath)
        await onMaster(tabA, otherMaster)
        const masterB = await watchClusterMasterUpgrades(
          expect,
          tabB,
          stand,
          otherMaster,
        )
        stops.push(masterB.stop)
        await gotoPage(expect, tabB, sessionsPath)
        await expect.poll(() => profileSessionSnapshot(framesA)).toBeDefined()
        await expect.poll(() => profileSessionSnapshot(framesB)).toBeDefined()
        const snapshotA = profileSessionSnapshot(framesA)!
        const snapshotB = profileSessionSnapshot(framesB)!
        await test.info().attach('profile-sessions-cross-master', {
          body: JSON.stringify(
            { [CLUSTER_FOLLOWER]: snapshotA, [otherMaster]: snapshotB },
            null,
            2,
          ),
          contentType: 'application/json',
        })
        expectProfileSessionNumericMoments(expect, snapshotA)
        expectProfileSessionNumericMoments(expect, snapshotB)
        const sessionA = snapshotA.sessions.find(
          (session) => session.userId === userId,
        )
        expect(sessionA).toBeDefined()
        const sessionB = snapshotB.sessions.find(
          (session) => session.id === sessionA!.id,
        )
        expect(sessionB).toBeDefined()
        expect({
          id: sessionB!.id,
          userId: sessionB!.userId,
          createdAt: sessionB!.createdAt,
        }).toEqual({
          id: sessionA!.id,
          userId: sessionA!.userId,
          createdAt: sessionA!.createdAt,
        })
        const sharedConnections = snapshotA.connections.filter((connection) =>
          snapshotB.connections.some(
            (other) => other.acceptKey === connection.acceptKey,
          ),
        )
        expect(sharedConnections.length).toBeGreaterThan(0)
        for (const connection of sharedConnections) {
          const other = snapshotB.connections.find(
            (row) => row.acceptKey === connection.acceptKey,
          )!
          expect(other.connectedAt).toBe(connection.connectedAt)
        }
        await masterA.assert()
        await masterB.assert()
        return masterB
      })

    // Every later A reconnect must keep reaching the follower despite the shared cookie.
    await onMaster(tabA, CLUSTER_FOLLOWER)
    await test.step('a bound browser on another master updates the subscribed tab', async () => {
      const subscribed = profileSessionSnapshot(framesA)!
      const existingIds = new Set(
        subscribed.sessions.map((session) => session.id),
      )
      const firstLiveFrame = framesA.length
      const socketsBeforeBind = observerA.sockets()
      await markDocument(tabA)
      extraBrowser = await newMasterPage(browser, otherMaster)
      const extraMaster = await watchClusterMasterUpgrades(
        expect,
        extraBrowser,
        stand,
        otherMaster,
      )
      stops.push(extraMaster.stop)
      await bindTestUser(
        expect,
        extraBrowser,
        { email, name: nameFromEmail(email), userId },
        { commandHost: nodeHost(otherMaster) },
      )
      await expect
        .poll(() => {
          const snapshot = profileSessionSnapshot(framesA.slice(firstLiveFrame))
          return snapshot?.sessions.some(
            (session) =>
              !existingIds.has(session.id) &&
              session.userId === userId &&
              snapshot.connections.some(
                (connection) => connection.sessionId === session.id,
              ),
          )
        })
        .toBe(true)
      const live = profileSessionSnapshot(framesA.slice(firstLiveFrame))!
      await test.info().attach('profile-sessions-live', {
        body: JSON.stringify(live, null, 2),
        contentType: 'application/json',
      })
      expect(live.frame.socket).toBe(subscribed.frame.socket)
      expect(live.frame.message).toBeGreaterThan(subscribed.frame.message)
      expect(observerA.sockets()).toBe(socketsBeforeBind)
      expectProfileSessionNumericMoments(expect, live)
      const added = live.sessions.find(
        (session) => !existingIds.has(session.id),
      )!
      expect(added.id).toEqual(expect.any(Number))
      expect(Number.isSafeInteger(added.id)).toBe(true)
      expect(
        live.connections.some(
          (connection) => connection.sessionId === added.id,
        ),
      ).toBe(true)
      await extraMaster.assert()
      await masterA.assert()
      await expectDocumentUnchanged(expect, tabA)
    })

    // Return B to the original main-page identity check before cross-master sign-out.
    await onMaster(tabA, otherMaster)
    await gotoPage(expect, tabB, '/')
    await expect(tabB.getByTestId('self-user')).toHaveText(nameFromEmail(email))
    await expect(tabB.getByTestId('conn-state')).toHaveText('connected')
    await masterB.assert()
    await onMaster(tabA, CLUSTER_FOLLOWER)
    const siblingReconnect = tabB.waitForEvent('websocket')
    await logout(expect, tabA)
    await siblingReconnect
    await expect(tabB.getByTestId('nav-profile')).toHaveCount(0)
    await expect(tabB.getByTestId('conn-state')).toHaveText('connected')
  } finally {
    const cleanup = await Promise.allSettled(
      stops.reverse().map(async (stop) => stop()),
    )
    try {
      await extraBrowser?.context().close()
    } finally {
      try {
        await tabA.context().close()
      } finally {
        await expectLeaderUnmoved(before)
      }
    }
    expect(
      cleanup.filter((result) => result.status === 'rejected'),
      'Session watchers stopped',
    ).toEqual([])
  }
})

test('a change of rights reaches an admin page open on a follower master: a grant opens it, a revoke shuts it', async ({
  browser,
}) => {
  const before = await clusterInspect(CLUSTER_FOLLOWER)
  await expectLeaderUnmoved(before)
  const page = await newMasterPage(browser, CLUSTER_FOLLOWER)
  try {
    // Revocation requires another administrator, even when this test runs alone.
    const { baseURL, ignoreHTTPSErrors } = test.info().project.use
    await leaveSecondAdmin(
      browser,
      async (secondPage) => {
        await onMaster(secondPage, CLUSTER_FOLLOWER)
        return grantAdminToSelf(expect, secondPage)
      },
      { baseURL, ignoreHTTPSErrors },
    )
    const userId = await grantAdminToSelf(expect, page)
    await setAdmin(userId, false)
    await gotoPage(expect, page, '/hilos/backup', PAGE_REFUSED)
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
