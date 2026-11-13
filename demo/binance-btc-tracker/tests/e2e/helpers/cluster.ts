// This binding keeps the names these specs already import; cluster operations
// and harness environment parsing live in the shared e2e toolbox.
import type { Browser, Page } from '@playwright/test'

import {
  clusterNodeHost,
  clusterPlacementNode,
  expectClusterLeaderUnchanged,
  inspectClusterNode,
  newClusterMasterPage,
  readClusterBrowserStand,
  readClusterCarry,
  requestClusterNodeStop,
  setClusterMaster,
  type ClusterInspection,
} from '../../../../../framework/frontend/e2e/index.js'
import { expect } from '../test.js'

export { STAND_MASTER_COOKIE } from '../../../../../framework/frontend/e2e/index.js'

const stand = readClusterBrowserStand()
export const CLUSTER_LEADER = stand.leader
export const CLUSTER_FOLLOWER = stand.follower
export const CLUSTER_MASTERS = stand.masters
export const CLUSTER_SLAVES = stand.slaves
export const CLUSTER_NODE_NAMES = stand.nodeNames
export const BASE_URL = stand.baseURL

export type ClusterView = ClusterInspection

/** @param node Cluster member whose command socket should be reached. */
export function nodeHost(node: string): string {
  return clusterNodeHost(stand, node)
}

/**
 * Open a separate browser context through the stand entry on a named master.
 *
 * @param browser Playwright browser.
 * @param node Master id.
 */
export function newMasterPage(browser: Browser, node: string): Promise<Page> {
  return newClusterMasterPage(browser, stand, node)
}

/**
 * Steer the next socket of every tab in this context; open sockets stay put.
 *
 * @param page Page whose context carries the shared cookie.
 * @param node Master to receive the next connection.
 */
export function onMaster(page: Page, node: string): Promise<void> {
  return setClusterMaster(page, stand, node)
}

/** @param node Node whose command server reports its cluster view. */
export function clusterInspect(node: string): Promise<ClusterView> {
  return inspectClusterNode(stand, node)
}

/** @param agentType Agent id whose placement is read from the leader. */
export function placementNode(agentType: string): Promise<string> {
  return clusterPlacementNode(expect, stand, agentType)
}

/** @param before Snapshot taken before a cluster browser scenario. */
export function expectLeaderUnmoved(before: ClusterView): Promise<void> {
  return expectClusterLeaderUnchanged(expect, stand, before)
}

/**
 * Ask the host harness to stop a slave between Playwright phases.
 *
 * @param node Slave that holds the artifact under test.
 * @param reason Human-readable reason printed by the harness.
 * @param carry Data the after-loss phase needs from the live phase.
 */
export function requestNodeStop(
  node: string,
  reason: string,
  carry: Record<string, unknown>,
): void {
  return requestClusterNodeStop(stand, node, reason, carry)
}

/** @returns The values written by the live phase for the after-loss phase. */
export function readCarry(): Record<string, unknown> {
  return readClusterCarry()
}
