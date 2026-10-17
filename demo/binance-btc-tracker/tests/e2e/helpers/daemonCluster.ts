import {
  closeSync,
  existsSync,
  fstatSync,
  openSync,
  readFileSync,
  readSync,
  renameSync,
  writeFileSync,
} from 'node:fs'
import { basename, join } from 'node:path'

import { expect, test } from '@playwright/test'

import { CLUSTER_LEADER, CLUSTER_MASTERS, CLUSTER_SLAVES } from './cluster.js'

interface DaemonClusterEnvironment {
  nodeId: string
  envKey: string
  envValue: string
  runId: string
  controlDirectory: string
  nodeLogs: Record<string, string>
}

interface LeaderKillResult {
  runId: string
  ok: true
  stopped: string
  leaderId: string
  term: number
}

function required(name: string): string {
  const value = process.env[name]
  if (!value) {
    throw new Error(
      `${name} requires composer run test:cluster:e2e -- --daemon`,
    )
  }
  return value
}

/** Read only the current host run's handshake and the stand's existing log map. */
export function daemonClusterEnvironment(): DaemonClusterEnvironment {
  const runId = required('CLUSTER_DAEMON_RUN_ID')
  const controlDirectory = required('CLUSTER_DAEMON_CONTROL_DIR')
  const nodeId = required('CLUSTER_DAEMON_NODE')
  if (
    basename(controlDirectory) !== runId ||
    !CLUSTER_SLAVES.includes(nodeId)
  ) {
    throw new Error(
      'Daemon control directory or remote node does not belong to this run',
    )
  }
  const nodeLogs = JSON.parse(required('CLUSTER_NODE_LOGS')) as Record<
    string,
    unknown
  >
  for (const node of [...CLUSTER_MASTERS, ...CLUSTER_SLAVES]) {
    if (typeof nodeLogs[node] !== 'string') {
      throw new Error(`No daemon log path for cluster member ${node}`)
    }
  }
  return {
    nodeId,
    envKey: required('CLUSTER_DAEMON_ENV_KEY'),
    envValue: required('CLUSTER_DAEMON_ENV_VALUE'),
    runId,
    controlDirectory,
    nodeLogs: nodeLogs as Record<string, string>,
  }
}

/**
 * Publish browser readiness once and wait for the host's real kill/election result.
 * The caller must finish its initial UI checks before invoking this handshake.
 */
export async function requestLeaderKill(): Promise<LeaderKillResult> {
  const { runId, controlDirectory } = daemonClusterEnvironment()
  const requestPath = join(controlDirectory, 'request.json')
  const resultPath = join(controlDirectory, 'result.json')
  if (existsSync(requestPath) || existsSync(resultPath)) {
    throw new Error(
      'Daemon leader-loss handshake has already been used or failed',
    )
  }
  const temporary = `${requestPath}.tmp`
  writeFileSync(
    temporary,
    JSON.stringify({ runId, action: 'kill-leader', nodeId: CLUSTER_LEADER }),
    { encoding: 'utf8', flag: 'wx' },
  )
  renameSync(temporary, requestPath)
  // Elections are part of this slow test's budget, not an ordinary UI assertion.
  await expect
    .poll(() => existsSync(resultPath), { timeout: test.info().timeout })
    .toBe(true)
  const result = JSON.parse(readFileSync(resultPath, 'utf8')) as Record<
    string,
    unknown
  >
  if (result.runId !== runId)
    throw new Error('Daemon result belongs to another run')
  if (result.ok !== true)
    throw new Error(`Daemon host failed: ${String(result.error)}`)
  if (
    result.stopped !== CLUSTER_LEADER ||
    typeof result.leaderId !== 'string' ||
    !CLUSTER_MASTERS.includes(result.leaderId) ||
    result.leaderId === CLUSTER_LEADER ||
    typeof result.term !== 'number' ||
    !Number.isInteger(result.term)
  ) {
    throw new Error(`Invalid daemon host result: ${JSON.stringify(result)}`)
  }
  return result as unknown as LeaderKillResult
}

/**
 * Mark every real node journal immediately before the UI confirms a restart.
 * Keep the marked descriptors open until the evidence is attached. Each read
 * compares the inode on its open descriptor, as the host node_log_since does:
 * rotation reads the new journal from zero rather than skipping its first lines.
 */
export function markDaemonNodeLogs(): {
  tails(): Record<string, string>
  close(): void
} {
  const marks: Array<{
    nodeId: string
    path: string
    descriptor: number
    inode: number
    offset: number
  }> = []
  try {
    for (const [nodeId, path] of Object.entries(
      daemonClusterEnvironment().nodeLogs,
    )) {
      const descriptor = openSync(path, 'r')
      const stat = fstatSync(descriptor)
      marks.push({
        nodeId,
        path,
        descriptor,
        inode: stat.ino,
        offset: stat.size,
      })
    }
  } catch (error) {
    for (const mark of marks) closeSync(mark.descriptor)
    throw error
  }
  return {
    tails() {
      return Object.fromEntries(
        marks.map((mark) => {
          const descriptor = openSync(mark.path, 'r')
          try {
            const stat = fstatSync(descriptor)
            const offset = stat.ino === mark.inode ? mark.offset : 0
            if (stat.size < offset) {
              throw new Error(`Daemon journal truncated on ${mark.nodeId}`)
            }
            const buffer = Buffer.alloc(stat.size - offset)
            const length = readSync(
              descriptor,
              buffer,
              0,
              buffer.length,
              offset,
            )
            return [mark.nodeId, buffer.toString('utf8', 0, length)]
          } finally {
            closeSync(descriptor)
          }
        }),
      )
    },
    close() {
      for (const mark of marks.splice(0)) closeSync(mark.descriptor)
    },
  }
}
