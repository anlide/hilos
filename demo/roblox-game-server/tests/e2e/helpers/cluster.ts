// This binding keeps the names the cluster specs import; operations and
// harness environment parsing live in the shared e2e toolbox.
import type { Browser, Page } from '@playwright/test'

import {
  clusterNodeHost,
  clusterPlacementNode,
  expectClusterLeaderUnchanged,
  inspectClusterNode,
  newClusterMasterPage,
  readClusterBrowserStand,
  setClusterMaster,
  type ClusterInspection,
} from '../../../../../framework/frontend/e2e/index.js'
import { expect } from '../test.js'

const stand = readClusterBrowserStand()
export const CLUSTER_LEADER = stand.leader
export const CLUSTER_FOLLOWER = stand.follower
export const CLUSTER_MASTERS = stand.masters
export const CLUSTER_SLAVES = stand.slaves
export const CLUSTER_NODE_NAMES = stand.nodeNames
export const BASE_URL = stand.baseURL

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
export function clusterInspect(node: string): Promise<ClusterInspection> {
  return inspectClusterNode(stand, node)
}

/** @param agentType Agent id whose placement is read from the leader. */
export function placementNode(agentType: string): Promise<string> {
  return clusterPlacementNode(expect, stand, agentType)
}

/** @param before Snapshot taken before a cluster browser scenario. */
export function expectLeaderUnmoved(before: ClusterInspection): Promise<void> {
  return expectClusterLeaderUnchanged(expect, stand, before)
}
