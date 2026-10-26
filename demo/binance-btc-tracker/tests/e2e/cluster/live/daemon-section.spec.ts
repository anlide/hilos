import { readFileSync } from 'node:fs'
import { expect, test } from '@playwright/test'

import {
  DAEMON_ROOT_PATH,
  DAEMON_PLACEMENT_PATH,
  deliveryObject,
  expectDaemonPage,
  expectDaemonSectionHead,
  expectDaemonNodeHead,
  expectDaemonEntryPoints,
  expectDaemonPlacementAnswer,
  expectDaemonPlacementRequests,
  expectDaemonPlacementOwnership,
  readDaemonSectionOverview,
  watchDeliveryFrames,
} from '../../../../../../framework/frontend/e2e/index.js'
import { grantAdminToSelf } from '../../helpers/adminGrant.js'
import {
  CLUSTER_FOLLOWER,
  CLUSTER_LEADER,
  clusterInspect,
  expectLeaderUnmoved,
  newMasterPage,
} from '../../helpers/cluster.js'
import { gotoPage, PAGE_READY } from '../../helpers/page.js'

// Live browser proof of the section on a follower, independent of the parked
// daemon fault scenario. The host keeps every node alive throughout this test.
test('daemon section navigation: follower entry points name the live leader and every section link owns its address', async ({
  browser,
}) => {
  // Consensus inspection, cold Placement and four addressed subscriptions are real round trips.
  test.slow()
  const before = await clusterInspect(CLUSTER_FOLLOWER)
  expect(before.leaderId).toBe(CLUSTER_LEADER)
  expect(before.leaderId).not.toBe(CLUSTER_FOLLOWER)
  const follower = await newMasterPage(browser, CLUSTER_FOLLOWER)
  const rootLog = watchDeliveryFrames(follower)
  let placementLog: ReturnType<typeof watchDeliveryFrames> | undefined
  try {
    await grantAdminToSelf(follower)
    await gotoPage(follower, DAEMON_ROOT_PATH, PAGE_READY)
    await expectDaemonSectionHead(expect, follower, 'hilos_daemon', true)
    const rootReplies = () =>
      rootLog.packets
        .flatMap((packet) => packet.frames)
        .filter((frame) => {
          const wire = deliveryObject(frame.wire)
          return (
            wire.type === 'page_response' &&
            deliveryObject(wire.data).page === 'hilos_daemon'
          )
        })
    await expect
      .poll(() => {
        const last = rootReplies().at(-1)
        return last
          ? readDaemonSectionOverview(last)?.entryPoints?.nodeId
          : null
      })
      .toBe(before.leaderId)
    const root = rootReplies().at(-1)!
    const overview = readDaemonSectionOverview(root)!
    expect(overview.consensus?.leaderId).toBe(before.leaderId)
    expect(overview.nodes).toContainEqual(
      expect.objectContaining({
        nodeId: before.leaderId,
        online: true,
        state: 'leader',
      }),
    )
    const source = await expectDaemonEntryPoints(expect, follower, root)
    // Read the stand's declared command listener, rather than the page agent's environment.
    const configuration = readFileSync(
      new URL('../../../../docker/cluster/common.env', import.meta.url),
      'utf8',
    )
    const commandPort = /^COMMAND_PORT=(\d+)$/m.exec(configuration)
    expect(commandPort).not.toBeNull()
    expect(source.commandPort).toBe(Number(commandPort![1]))
    await follower.getByTestId('hilos-daemon-entry-metrics').press('Enter')
    await expectDaemonPage(expect, follower, 'hilos_daemon_http_server')
    await expect(follower).toHaveURL(
      `${DAEMON_ROOT_PATH}/${source.nodeId}/http`,
    )
    await expect(follower.getByTestId('hilos-daemon-http-address')).toHaveText(
      new RegExp(`:${source.httpPort}\\s*$`),
    )
    const http = rootLog.packets
      .flatMap((packet) => packet.frames)
      .filter((frame) => {
        const wire = deliveryObject(frame.wire)
        return (
          wire.type === 'page_response' &&
          deliveryObject(wire.data).page === 'hilos_daemon_http_server'
        )
      })
      .at(-1)!
    const httpData = deliveryObject(
      deliveryObject(deliveryObject(http.wire).data).payload,
    ).data
    expect(deliveryObject(deliveryObject(httpData).http).port).toBe(
      source.httpPort,
    )
    expect(deliveryObject(httpData).node).toEqual({
      clustered: true,
      state: 'leader',
      silentSince: null,
      role: 'master',
    })
    await expectDaemonNodeHead(
      expect,
      follower,
      'hilos_daemon_http_server',
      source.nodeId,
    )
    const placement = await follower.context().newPage()
    placementLog = watchDeliveryFrames(placement)
    await gotoPage(placement, DAEMON_PLACEMENT_PATH, PAGE_READY)
    const initial = await expectDaemonPlacementAnswer(
      expect,
      placement,
      placementLog.packets.flatMap((packet) => packet.frames),
      true,
    )
    expect(initial.entryPoints).toEqual(source)
    expect(initial.consensus?.leaderId).toBe(before.leaderId)
    await expectDaemonPlacementRequests(expect, placementLog)
    await placement.getByTestId('hilos-page-tab-hilos_daemon').press('Enter')
    await expectDaemonPage(expect, placement, 'hilos_daemon')
    await expectDaemonSectionHead(expect, placement, 'hilos_daemon', true)
    await placement
      .getByTestId('hilos-page-tab-hilos_daemon_env_mismatch')
      .press('Enter')
    await expectDaemonPage(expect, placement, 'hilos_daemon_env_mismatch')
    await expectDaemonSectionHead(
      expect,
      placement,
      'hilos_daemon_env_mismatch',
      true,
    )
    await expect(placement).toHaveURL('/hilos/daemon/env-mismatch')
    await placement.goBack()
    await expectDaemonPage(expect, placement, 'hilos_daemon')
    await expectDaemonSectionHead(expect, placement, 'hilos_daemon', true)
    await placement
      .getByTestId('hilos-page-tab-hilos_daemon_placement')
      .press('Enter')
    await expectDaemonPage(expect, placement, 'hilos_daemon_placement')
    await expectDaemonSectionHead(
      expect,
      placement,
      'hilos_daemon_placement',
      true,
    )
    expectDaemonPlacementOwnership(expect, rootLog)
    expectDaemonPlacementOwnership(expect, placementLog)
  } finally {
    await test.info().attach('daemon-section-browser-deliveries', {
      body: JSON.stringify({
        inspection: before,
        follower: rootLog.packets,
        placement: placementLog?.packets,
        sent: placementLog?.sent,
      }),
      contentType: 'application/json',
    })
    rootLog.stop()
    placementLog?.stop()
    await follower.context().close()
    await expectLeaderUnmoved(before)
  }
})
