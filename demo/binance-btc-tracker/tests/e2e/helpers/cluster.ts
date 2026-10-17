/**
 * Cluster specs use newMasterPage instead of Playwright's page/context fixtures:
 * every context names its master before navigating, so no request goes round-robin.
 * A changed cookie steers the next socket; sockets already open stay where they are.
 */
import { readFileSync, writeFileSync } from 'node:fs'

import type { Browser, Page } from '@playwright/test'

import { createCommandChannel } from '../../../../../framework/frontend/scripts/commandChannel.mjs'
import {
  newClusterMasterPage,
  readClusterBrowserStand,
  setClusterMaster,
} from '../../../../../framework/frontend/e2e/index.js'

export { STAND_MASTER_COOKIE } from '../../../../../framework/frontend/e2e/index.js'

interface Placement {
  agentId: string
  nodeId: string
  state: string
}

export interface ClusterView {
  leaderId: string | null
  term: number | null
  placements: Placement[]
}

function required(name: string): string {
  const value = process.env[name]
  if (!value) {
    throw new Error(
      `cluster e2e requires ${name}; run it through composer run test:cluster:e2e`,
    )
  }
  return value
}

const stand = readClusterBrowserStand()
export const CLUSTER_LEADER = stand.leader
export const CLUSTER_FOLLOWER = stand.follower
export const CLUSTER_MASTERS = [...stand.masters]
export const CLUSTER_SLAVES = required('CLUSTER_SLAVES').split(',')
export const BASE_URL = stand.baseURL
const nodeHosts = stand.nodeHosts
const faultFile = required('CLUSTER_FAULT_FILE')
const commandPort = Number(process.env.COMMAND_PORT ?? 8094)

/** @param node Cluster member whose command socket should be reached. */
export function nodeHost(node: string): string {
  const host = nodeHosts[node]
  if (!host) throw new Error(`No command host for cluster node ${node}`)
  return host
}

/**
 * Open a separate browser context through the stand entry on a named master.
 *
 * @param browser Playwright browser.
 * @param node Master id.
 */
export async function newMasterPage(
  browser: Browser,
  node: string,
): Promise<Page> {
  return newClusterMasterPage(browser, stand, node)
}

/**
 * Steer the next socket of every tab in this context; open sockets stay put.
 *
 * @param page Page whose context carries the shared cookie.
 * @param node Master to receive the next connection.
 */
export async function onMaster(page: Page, node: string): Promise<void> {
  await setClusterMaster(page, stand, node)
}

/** @param node Node whose command server reports its cluster view. */
export async function clusterInspect(node: string): Promise<ClusterView> {
  const send = createCommandChannel({
    host: nodeHost(node),
    port: commandPort,
    timeoutMs: 15_000,
  })
  return (await send('test:cluster:inspect', {})) as unknown as ClusterView
}

/** @param agentType Agent id whose placement is read from the leader. */
export async function placementNode(agentType: string): Promise<string> {
  const view = await clusterInspect(CLUSTER_LEADER)
  await expectLeaderUnmoved(view)
  const placement = view.placements.find(
    (row) => row.agentId === agentType && row.state === 'started',
  )
  if (!placement)
    throw new Error(
      `No started placement for ${agentType} on leader ${CLUSTER_LEADER}`,
    )
  return placement.nodeId
}

/** @param before Snapshot taken before a protected-mode browser scenario. */
export async function expectLeaderUnmoved(before: ClusterView): Promise<void> {
  const now = await clusterInspect(CLUSTER_FOLLOWER)
  if (
    before.leaderId !== CLUSTER_LEADER ||
    now.leaderId !== before.leaderId ||
    now.term !== before.term
  ) {
    throw new Error(
      `leadership moved from ${before.leaderId} term ${before.term} to ${now.leaderId} term ${now.term} (P-459 → HIL-1286)`,
    )
  }
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
  if (!CLUSTER_SLAVES.includes(node)) {
    throw new Error(`A cluster browser spec may stop a slave only: ${node}`)
  }
  writeFileSync(
    faultFile,
    JSON.stringify({ stop: node, reason, carry }),
    'utf8',
  )
}

/** @returns The values written by the live phase for the after-loss phase. */
export function readCarry(): Record<string, unknown> {
  const request = JSON.parse(readFileSync(faultFile, 'utf8')) as {
    carry?: Record<string, unknown>
  }
  return request.carry ?? {}
}
