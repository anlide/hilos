import type { Expect, Page } from '@playwright/test'
import { expect, test } from '../test.js'
import { randomUUID } from 'node:crypto'
import { readFile, rm, stat, writeFile } from 'node:fs/promises'
import {
  type watchStatusFrames,
  RESTART_AGENT,
  RESTART_MARK_TIMEOUT_MS,
  readRestartWorker,
  reportDaemonNode,
  reportDaemonNodeUntil,
  withStoppedRestartWorker,
} from '../../../../../framework/frontend/e2e/index.js'

import {
  publicStatusFileSchema,
  type PublicStatusFile,
} from '../../../../../framework/frontend/core/src/status/index.js'
import { createCommandChannel } from '../../../../../framework/frontend/scripts/commandChannel.mjs'
import { STATUS_EXPECT_TIMEOUT_MS } from '../status.config.js'

/** The e2e-only catalog binds this component to the existing isolated worker. */
export const STATUS_FAULT_COMPONENT = 'settings'

/**
 * The test stand's real Status disk, data/status-test, as the owner
 * installs it.
 */
export const STATUS_DISK = new URL(
  '../../../data/status-test/',
  import.meta.url,
)

/**
 * The status file the owner last published to its disk, the one nginx
 * serves: a reader's first GET from now on reads this or a later one. The
 * owner renames each file into place whole; undefined means the published
 * file is not a status file this reader understands.
 */
export async function readPublishedStatus(): Promise<
  PublicStatusFile | undefined
> {
  const status = publicStatusFileSchema.safeParse(
    JSON.parse(await readFile(new URL('status.json', STATUS_DISK), 'utf8')),
  )
  return status.success ? status.data : undefined
}

const sendCommand = createCommandChannel({
  host: process.env.COMMAND_HOST ?? 'binance-btc-tracker-daemon-test',
  port: Number(process.env.COMMAND_PORT ?? 8094),
  // The existing pause watchdog bounds the loss to ten seconds. The normal
  // restart answer follows SIGCONT, and must have time to travel back too.
  timeoutMs: 15_000,
})

/**
 * A real stop/start through the master's ordinary restart path. The helper
 * never changes a roster, incident, clock or websocket frame. Its existing
 * settings-only pause guard and independent watchdog remain unchanged.
 *
 * The loss reaches the master by itself, after the restart is sent, and travels
 * on with a node report. So `run` is handed `untilReported(check)`, which
 * repeats a node report and the check in the pause window's budget; a caller
 * wraps its first wait for the loss in it. The check reads what has already
 * been delivered and does not wait by itself. Once the restart is answered the
 * master knows the new worker, and the helper brings one node report for the
 * callers' waits for recovery.
 *
 * That report resolves the loss within the collector's fan-out window, while
 * the owner writes a pair over several of its ticks: a recovery that comes
 * first replaces the open pair before any reader can get it. A caller that
 * reads the open pair after the worker resumes hands that read as
 * `recoverAfter`, and the report waits for it to settle.
 */
export async function withStatusAgentLoss(
  expect: Expect,
  workers: Page,
  log: ReturnType<typeof watchStatusFrames>,
  run: (
    untilReported: (check: () => Promise<void> | void) => Promise<void>,
  ) => Promise<void>,
  recoverAfter?: Promise<unknown>,
): Promise<void> {
  const worker = await readRestartWorker(expect, workers)
  // Operational is meaningful only with known, fresh Status observations. The
  // Workers identity above comes from the same real collector pipeline; neither
  // a mounted page nor an empty incident list alone proves source readiness.
  await expect
    .poll(() => log.latest())
    .toMatchObject({
      healthKnown: true,
      components: { operationalCount: 1, totalCount: 1 },
      maintenance: null,
      incidents: [],
    })
  const evidence = {
    worker,
    ready: log.latest(),
    stoppedAt: null as number | null,
    checkedAt: null as number | null,
    finalState: undefined as string | undefined,
  }
  let restart: Promise<Error | undefined> | undefined
  let failure: unknown
  try {
    await withStoppedRestartWorker(worker, async () => {
      const pausedExpect = expect.configure({
        timeout: Math.min(STATUS_EXPECT_TIMEOUT_MS, RESTART_MARK_TIMEOUT_MS),
      })
      // Do not mistake a delayed observation after watchdog recovery for loss.
      await pausedExpect
        .poll(
          async () =>
            (await readFile(`/proc/${worker.pid}/status`, 'utf8')).match(
              /^State:\s+(\S)/m,
            )?.[1],
        )
        .toBe('T')
      evidence.stoppedAt = Date.now()
      restart = sendCommand('agent:restart', { agentType: RESTART_AGENT }).then(
        () => undefined,
        (error: Error) => error,
      )
      await run((check) => reportDaemonNodeUntil(pausedExpect, check))
      evidence.finalState = (
        await readFile(`/proc/${worker.pid}/status`, 'utf8')
      ).match(/^State:\s+(\S)/m)?.[1]
      evidence.checkedAt = Date.now()
      pausedExpect(evidence.finalState).toBe('T')
    })
  } catch (error) {
    failure = error
  }
  // The pause helper's finally has already resumed the exact worker. Always
  // settle the command, including when an assertion failed during the loss.
  const restartError = await restart
  await test.info().attach('status-real-loss', {
    body: JSON.stringify(evidence),
    contentType: 'application/json',
  })
  if (failure !== undefined) throw failure
  if (restartError) throw restartError
  // The caller's read of the open loss goes first; its failure is the
  // caller's own to report, so only its settling is awaited here.
  await Promise.allSettled([recoverAfter])
  // The answered restart is known to the master: one node report carries the
  // new worker to the callers' waits for recovery.
  await reportDaemonNode()
}

/**
 * Private names on the test stand's real Status disk. A genuine cluster
 * marker is preserved; standalone has no marker, so its otherwise absent name
 * gets an isolated sentinel too. Published JSON files are never modified.
 */
export async function withPrivateStatusFiles(
  run: (names: string[], sentinel: string) => Promise<void>,
): Promise<void> {
  const marker = '.hilos-cluster-directory.json'
  const sentinel = `private-status-e2e-${randomUUID()}`
  const names = [marker, `.${sentinel}.tmp`, `${sentinel}.json`]
  const created: URL[] = []
  try {
    for (const name of names) {
      const path = new URL(name, STATUS_DISK)
      try {
        await writeFile(path, sentinel, { flag: 'wx' })
        created.push(path)
      } catch (error) {
        if (
          name !== marker ||
          (error as NodeJS.ErrnoException).code !== 'EEXIST'
        )
          throw error
        expect((await stat(path)).isFile()).toBe(true)
      }
    }
    await run(names, sentinel)
  } finally {
    for (const path of created) await rm(path)
  }
}
