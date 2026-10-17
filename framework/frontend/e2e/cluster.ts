import {
  closeSync,
  fstatSync,
  openSync,
  readFileSync,
  readSync,
  statSync,
} from 'node:fs'
import https from 'node:https'
import { isIP } from 'node:net'
import { isAbsolute } from 'node:path'

import type { Browser, Expect, Page } from '@playwright/test'

import { createCommandChannel } from '../scripts/commandChannel.mjs'
import {
  ANALYTICS_WRITER_TIMEOUT_MS,
  readAnalyticsBrowserSession,
} from './analytics.js'

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

/** The existing inspect command lists POLICY placements, not LEADER or NODE agents. */
export interface ClusterInspection {
  leaderId: string | null
  term: number | null
  placements: readonly { agentId: string; nodeId: string; state: string }[]
}

export const STAND_MASTER_COOKIE = 'hilos_stand_master'

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

/** Open an isolated context with its master cookie set before creating the page. */
export async function newClusterMasterPage(
  browser: Browser,
  stand: ClusterBrowserStand,
  node: string,
): Promise<Page> {
  if (!stand.masters.includes(node)) {
    throw new Error(`Not a cluster master: ${node}`)
  }
  const context = await browser.newContext({
    baseURL: stand.baseURL,
    ignoreHTTPSErrors: true,
  })
  try {
    await context.addCookies([
      { name: STAND_MASTER_COOKIE, value: node, url: stand.baseURL },
    ])
    return await context.newPage()
  } catch (error) {
    await context.close()
    throw error
  }
}

/** Steer future sockets of the context; already open sockets stay on their master. */
export async function setClusterMaster(
  page: Page,
  stand: ClusterBrowserStand,
  node: string,
): Promise<void> {
  if (!stand.masters.includes(node)) {
    throw new Error(`Not a cluster master: ${node}`)
  }
  await page
    .context()
    .addCookies([
      { name: STAND_MASTER_COOKIE, value: node, url: stand.baseURL },
    ])
}

/** Bind the existing command channel to one member of the running stand. */
export function clusterCommandChannel(
  stand: ClusterBrowserStand,
  node: string,
) {
  const host = stand.nodeHosts[node]
  if (!host) throw new Error(`No command host for cluster node ${node}`)
  const port = Number(process.env.COMMAND_PORT ?? 8094)
  if (!Number.isInteger(port) || port < 1 || port > 65535) {
    throw new Error('Invalid cluster COMMAND_PORT')
  }
  return createCommandChannel({ host, port, timeoutMs: 15_000 })
}

/** Read a fresh follower view; a changed leader or term invalidates this scenario. */
export async function expectClusterLeaderUnchanged(
  expect: Expect,
  stand: ClusterBrowserStand,
  before: ClusterInspection,
): Promise<void> {
  expect(before.leaderId, 'The harness leader before the scenario').toBe(
    stand.leader,
  )
  expect(before.term, 'A known election term').not.toBeNull()
  const now = await clusterCommandChannel(stand, stand.follower)(
    'test:cluster:inspect',
    {},
  )
  expect(
    { leaderId: now.leaderId, term: now.term },
    'Cluster leadership must stay unchanged during the browser scenario',
  ).toEqual({ leaderId: before.leaderId, term: before.term })
}

const ANALYTICS_READER = 'hilos_analytics'
const ANALYTICS_WRITER = 'hilos_analytics_writer'
const ANALYTICS_JOURNAL = 'hilos_analytics_journal'
const ANALYTICS_AGENTS = new Set([
  ANALYTICS_READER,
  ANALYTICS_WRITER,
  ANALYTICS_JOURNAL,
])

/** Replay the current daemon life; a rotated or incomplete journal proves nothing. */
function activeClusterAnalyticsAgents(path: string): readonly string[] {
  const descriptor = openSync(path, 'r')
  let journal: string
  try {
    const before = fstatSync(descriptor)
    if (!before.isFile()) throw new Error(`Not a daemon log file: ${path}`)
    journal = readFileSync(descriptor, 'utf8')
    const after = statSync(path)
    if (
      before.dev !== after.dev ||
      before.ino !== after.ino ||
      after.size < before.size
    ) {
      throw new Error(
        `Daemon journal replaced or truncated while reading ${path}`,
      )
    }
  } finally {
    closeSync(descriptor)
  }
  if (!journal.endsWith('\n')) {
    throw new Error(`Incomplete daemon journal: ${path}`)
  }

  let running = false
  let active: { agentId: string; worker: string }[] = []
  for (const line of journal.split('\n')) {
    if (line.endsWith('] Daemon started with epoll')) {
      running = true
      active = []
      continue
    }
    if (/\] Daemon stopped(?: after a failure)?$/.test(line)) {
      running = false
      active = []
      continue
    }
    if (!running) continue

    // A worker number can be reused, including after a death without agent stops.
    const worker = line.match(
      /\] Worker #(\d+) (?:died hosting \d+ agent\(s\):|started \[type=)/,
    )
    if (worker) {
      active = active.filter((entry) => entry.worker !== worker[1])
      continue
    }
    const agent = line.match(
      /\] Agent '([^']+)' (started|stopped) on worker #(\d+)$/,
    )
    if (agent && ANALYTICS_AGENTS.has(agent[1])) {
      if (agent[2] === 'started') {
        active.push({ agentId: agent[1], worker: agent[3] })
      } else {
        active = active.filter(
          (entry) => entry.agentId !== agent[1] || entry.worker !== agent[3],
        )
      }
    } else if (
      /\] Agent 'hilos_analytics(?:_writer|_journal)?' .* on worker #/.test(
        line,
      )
    ) {
      throw new Error(
        `Incomplete Analytics lifecycle record in ${path}: ${line}`,
      )
    }
  }
  if (!running) throw new Error(`No current daemon start in ${path}`)
  return active.map((entry) => entry.agentId)
}

/**
 * Prove the live reader, writer and per-node journals from this fresh stand.
 * Inspect supplies only the writer's POLICY placement; logs supply every live copy.
 *
 * @param expect The spec's expect.
 * @param stand The running stand's roles and readable node journals.
 */
export async function expectClusterAnalyticsAgents(
  expect: Expect,
  stand: ClusterBrowserStand,
): Promise<void> {
  const nodes = Object.keys(stand.nodeLogs).sort()
  const inspect = clusterCommandChannel(stand, stand.leader)
  // The reader starts on demand. Its existing read accepts any positive user id,
  // including one with no session yet; this preparation neither creates nor seeds it.
  await readAnalyticsBrowserSession(inspect, 1)
  await expect
    .poll(
      async () => {
        const inspection = (await inspect(
          'test:cluster:inspect',
          {},
        )) as unknown as ClusterInspection
        const writerPlacements = inspection.placements.filter(
          (placement) => placement.agentId === ANALYTICS_WRITER,
        )
        const active = nodes.flatMap((node) =>
          activeClusterAnalyticsAgents(stand.nodeLogs[node]).map((agentId) => ({
            node,
            agentId,
          })),
        )
        const hosts = (agentId: string) =>
          active
            .filter((entry) => entry.agentId === agentId)
            .map((entry) => entry.node)
        const writerNodes = hosts(ANALYTICS_WRITER)
        return {
          leaderId: inspection.leaderId,
          readerNodes: hosts(ANALYTICS_READER),
          journalNodes: hosts(ANALYTICS_JOURNAL),
          writerMatchesPlacement:
            writerPlacements.length === 1 &&
            writerPlacements[0].state === 'started' &&
            writerNodes.length === 1 &&
            writerNodes[0] === writerPlacements[0].nodeId,
          writerPlacements,
          writerNodes,
        }
      },
      {
        timeout: ANALYTICS_WRITER_TIMEOUT_MS,
        message:
          'Live Analytics agents agree with the harness roles and POLICY writer placement',
      },
    )
    .toMatchObject({
      leaderId: stand.leader,
      readerNodes: [stand.leader],
      journalNodes: nodes,
      writerMatchesPlacement: true,
    })
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

export const READY_TIMEOUT_MS = 120_000
const POLL_INTERVAL_MS = 2_000

/**
 * @param baseURL Front origin.
 * @param agent Agent tolerating its test certificate.
 */
export function probeStatic(
  baseURL: string,
  agent: https.Agent,
): Promise<boolean> {
  return new Promise((resolve) => {
    const request = https.request(
      baseURL,
      { method: 'HEAD', agent },
      (response) => {
        response.resume()
        response.on('end', () => {
          const status = response.statusCode ?? 0
          resolve(status >= 200 && status < 400)
        })
      },
    )
    request.on('error', () => resolve(false))
    request.end()
  })
}

/**
 * @param baseURL Front origin.
 * @param agent Agent tolerating its test certificate.
 * @param master Optional master selected through the cluster stand entry.
 */
export function probeWebSocketUpgrade(
  baseURL: string,
  agent: https.Agent,
  master?: string,
): Promise<boolean> {
  return new Promise((resolve) => {
    const request = https.request(`${baseURL}/ws`, {
      agent,
      headers: {
        ...(master ? { Cookie: `hilos_stand_master=${master}` } : {}),
        Connection: 'Upgrade',
        Upgrade: 'websocket',
        'Sec-WebSocket-Version': '13',
        'Sec-WebSocket-Key': Buffer.from('hilos-e2e-ready!').toString('base64'),
      },
    })
    request.on('upgrade', (response, socket) => {
      socket.destroy()
      resolve(response.statusCode === 101)
    })
    request.on('response', (response) => {
      response.resume()
      resolve(false)
    })
    request.on('error', () => resolve(false))
    request.end()
  })
}

/**
 * Poll one front until its static or WebSocket response arrives.
 *
 * @param what Failure-message label for the probed surface.
 * @param deadline Epoch milliseconds after which readiness fails.
 * @param probe One probe attempt.
 */
export async function waitUntilReady(
  what: string,
  deadline: number,
  probe: () => Promise<boolean>,
): Promise<void> {
  while (Date.now() < deadline) {
    if (await probe()) {
      return
    }
    await new Promise((resolve) => setTimeout(resolve, POLL_INTERVAL_MS))
  }
  throw new Error(`${what} did not become ready within ${READY_TIMEOUT_MS}ms`)
}
