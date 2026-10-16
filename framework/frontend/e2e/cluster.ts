import { closeSync, fstatSync, openSync, readSync } from 'node:fs'
import { isIP } from 'node:net'
import { isAbsolute } from 'node:path'

import type { Expect, Page } from '@playwright/test'

/** Addresses supplied by the running cluster's browser harness. */
export interface ClusterBrowserStand {
  baseURL: string
  leader: string
  follower: string
  masters: readonly string[]
  nodeHosts: Readonly<Record<string, string>>
  nodeIps: Readonly<Record<string, string>>
  nodeLogs: Readonly<Record<string, string>>
}

function required(name: string): string {
  const value = process.env[name]?.trim()
  if (!value) {
    throw new Error(
      `cluster e2e requires ${name}; run it through composer run test:cluster:e2e`,
    )
  }
  return value
}

function addressMap(name: string): Record<string, string> {
  const entries = required(name)
    .split(',')
    .map((entry) => {
      const separator = entry.indexOf('=')
      if (separator < 1 || separator === entry.length - 1) {
        throw new Error(`Invalid ${name} entry: ${entry}`)
      }
      return [entry.slice(0, separator), entry.slice(separator + 1)] as const
    })
  if (new Set(entries.map(([node]) => node)).size !== entries.length) {
    throw new Error(`Duplicate node in ${name}`)
  }
  return Object.fromEntries(entries)
}

/** Read the harness environment, refusing missing roles or incomplete node maps. */
export function readClusterBrowserStand(): ClusterBrowserStand {
  const baseURL = required('BASE_URL')
  if (!['http:', 'https:'].includes(new URL(baseURL).protocol)) {
    throw new Error('BASE_URL must be an HTTP address')
  }
  const leader = required('CLUSTER_LEADER')
  const follower = required('CLUSTER_FOLLOWER')
  const masters = required('CLUSTER_MASTERS').split(',')
  if (
    masters.some((node) => !node) ||
    new Set(masters).size !== masters.length ||
    !masters.includes(leader) ||
    !masters.includes(follower) ||
    leader === follower
  ) {
    throw new Error('Invalid cluster master roles')
  }
  const nodeHosts = addressMap('CLUSTER_NODE_HOSTS')
  const nodeIps = addressMap('CLUSTER_NODE_IPS')
  const logs: unknown = JSON.parse(required('CLUSTER_NODE_LOGS'))
  if (logs === null || typeof logs !== 'object' || Array.isArray(logs)) {
    throw new Error('CLUSTER_NODE_LOGS must be a node-to-path object')
  }
  const nodeLogs: Record<string, string> = {}
  for (const [node, path] of Object.entries(logs)) {
    if (typeof path !== 'string' || !isAbsolute(path)) {
      throw new Error(`Invalid cluster log path for ${node}`)
    }
    nodeLogs[node] = path
  }
  const nodes = new Set([
    ...masters,
    ...Object.keys(nodeHosts),
    ...Object.keys(nodeIps),
    ...Object.keys(nodeLogs),
  ])
  for (const node of nodes) {
    if (!nodeHosts[node] || !nodeIps[node] || !nodeLogs[node]) {
      throw new Error(`Incomplete cluster address maps for ${node}`)
    }
    if (!isIP(nodeIps[node])) {
      throw new Error(`Invalid cluster IP for ${node}: ${nodeIps[node]}`)
    }
  }
  return { baseURL, leader, follower, masters, nodeHosts, nodeIps, nodeLogs }
}

/**
 * Observe actual /ws handshake replies, including every reconnect, from before navigation.
 * Listener failures are retained for assert() so they fail the spec, never the event loop.
 *
 * @param expect The spec's expect.
 * @param page The page before its first navigation.
 * @param stand The running stand's addresses.
 * @param node The master selected by this context's cookie.
 */
export async function watchClusterMasterUpgrades(
  expect: Expect,
  page: Page,
  stand: ClusterBrowserStand,
  node: string,
): Promise<{ assert(): Promise<void>; stop(): Promise<void> }> {
  if (!stand.masters.includes(node) || !stand.nodeIps[node]) {
    throw new Error(`Not a cluster master with an IP: ${node}`)
  }
  const session = await page.context().newCDPSession(page)
  const sockets = new Set<string>()
  const replies: { status: number; master: string | undefined }[] = []
  const errors: unknown[] = []
  session.on('Network.webSocketCreated', (event) => {
    try {
      if (new URL(event.url).pathname === '/ws') sockets.add(event.requestId)
    } catch (error) {
      errors.push(error)
    }
  })
  session.on('Network.webSocketHandshakeResponseReceived', (event) => {
    try {
      if (!sockets.has(event.requestId)) return
      const header = Object.entries(event.response.headers).find(
        ([name]) => name.toLowerCase() === 'x-hilos-stand-master',
      )
      replies.push({ status: event.response.status, master: header?.[1] })
    } catch (error) {
      errors.push(error)
    }
  })
  try {
    await session.send('Network.enable')
  } catch (error) {
    await session.detach()
    throw error
  }
  return {
    async assert() {
      await expect
        .poll(() => replies.length + errors.length, {
          message: `A /ws handshake on ${node}`,
        })
        .toBeGreaterThan(0)
      expect(errors, `CDP listener failures on ${node}`).toEqual([])
      for (const reply of replies) {
        expect(reply, `Every /ws upgrade stays on ${node}`).toEqual({
          status: 101,
          master: `${stand.nodeIps[node]}:8092`,
        })
      }
    },
    async stop() {
      await session.detach()
    },
  }
}

/** Identity and byte offset of one readable daemon journal before an interval. */
export interface ClusterNodeLogMark {
  node: string
  path: string
  dev: number
  ino: number
  size: number
}

/**
 * Mark opened journals; a missing or unreadable file cannot prove an empty interval.
 *
 * @param stand The running stand's addresses.
 * @param nodes The nodes whose journals the scenario will check.
 */
export function markClusterNodeLogs(
  stand: ClusterBrowserStand,
  nodes: readonly string[],
): readonly ClusterNodeLogMark[] {
  return nodes.map((node) => {
    const path = stand.nodeLogs[node]
    if (!path) throw new Error(`No cluster log path for ${node}`)
    const descriptor = openSync(path, 'r')
    try {
      const stat = fstatSync(descriptor)
      if (!stat.isFile()) throw new Error(`Not a daemon log file: ${path}`)
      return { node, path, dev: stat.dev, ino: stat.ino, size: stat.size }
    } finally {
      closeSync(descriptor)
    }
  })
}

/**
 * Check only the marked interval, refusing a replaced or truncated journal.
 * The freeze completion line is specific; ordinary idle agent stops are allowed.
 *
 * @param expect The spec's expect.
 * @param marks Journal identities and byte offsets taken before enabling maintenance.
 */
export function expectNoProtectedModeAgentStops(
  expect: Expect,
  marks: readonly ClusterNodeLogMark[],
): void {
  for (const mark of marks) {
    const descriptor = openSync(mark.path, 'r')
    try {
      const stat = fstatSync(descriptor)
      expect([stat.dev, stat.ino], `Journal identity on ${mark.node}`).toEqual([
        mark.dev,
        mark.ino,
      ])
      expect(
        stat.size,
        `Journal not truncated on ${mark.node}`,
      ).toBeGreaterThanOrEqual(mark.size)
      const tail = Buffer.alloc(stat.size - mark.size)
      let offset = 0
      while (offset < tail.length) {
        const read = readSync(
          descriptor,
          tail,
          offset,
          tail.length - offset,
          mark.size + offset,
        )
        if (read === 0)
          throw new Error(`Journal truncated while reading ${mark.path}`)
        offset += read
      }
      expect(
        fstatSync(descriptor).size,
        `Journal retained on ${mark.node}`,
      ).toBeGreaterThanOrEqual(stat.size)
      expect(
        tail.toString('utf8'),
        `No protected-mode stop walk on ${mark.node}`,
      ).not.toContain('Protected mode: froze this node for ')
    } finally {
      closeSync(descriptor)
    }
  }
}
