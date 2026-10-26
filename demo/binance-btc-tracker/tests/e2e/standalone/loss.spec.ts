import { expect, test } from '@playwright/test'
import { readFile, writeFile } from 'node:fs/promises'
import { join } from 'node:path'
import { setTimeout as delay } from 'node:timers/promises'
import {
  publicStatusFileSchema,
  publicStatusHeartbeatSchema,
  type PublicStatusFile,
} from '../../../../../framework/frontend/core/src/status/index.js'
import {
  PUBLIC_STATUS_FILES,
  expectPublicStatusArchive,
  expectPublicStatusPair,
  expectPublicStatusReadOnly,
  expectPublicStatusStale,
  watchPublicStatus,
  type DisplayedStatusPair,
} from '../../../../../framework/frontend/e2e/index.js'
import { standaloneStatusControl } from '../../../../../framework/frontend/scripts/standaloneStatus.mjs'
import { gotoPublicStatus } from '../helpers/page.js'

test.use({ colorScheme: 'light', timezoneId: 'Europe/Warsaw' })

test('full daemon stop keeps nginx Status readable, stale and cold, then recovers from real facts', async ({
  page,
  browser,
  baseURL,
}) => {
  test.slow() // Actual PID-1 stop, configured stale boundary, cold loads and startup.
  const control = standaloneStatusControl()
  const archive: {
    accepted: DisplayedStatusPair
    history: string
    days: string[]
  } = JSON.parse(
    await readFile(join(control.directory, 'accepted.json'), 'utf8'),
  )
  // Same reader zone as the independently accepted archive.
  const context = await browser.newContext({
    baseURL,
    ignoreHTTPSErrors: true,
    colorScheme: 'light',
    timezoneId: 'Europe/Warsaw',
  })
  const guest = await context.newPage()
  const log = watchPublicStatus(guest)
  const coldLog = watchPublicStatus(page)
  const events: Record<string, unknown> = { timeOrigin: performance.timeOrigin }
  try {
    await gotoPublicStatus(guest)
    const before = await expectPublicStatusPair(
      expect,
      guest,
      log,
      ({ status }) =>
        status.components.every(
          (component) => component.state === 'operational',
        ),
    )
    expect(before.status.outages).toEqual(archive.accepted.status.outages)
    await expectPublicStatusArchive(expect, guest, archive)
    await expect(guest.getByTestId('conn-state')).toHaveText('connected')
    events.stopRequestedAt = Date.now()
    const stopped = await control.request('stop')
    events.knownDead = stopped
    expect(stopped.daemon.state).toMatchObject({
      Running: false,
      Pid: 0,
      Status: 'exited',
    })
    expect(stopped.nginx.state.Running).toBe(true)
    const packetsAtStop = log.wire.packets.length
    // docker stop may allow a final genuine publication. Accept the final pair
    // from nginx after PID 1 and every child have exited, instead of assuming
    // the pre-stop snapshot was the last write.
    const reads = log.reads[0].length
    await expect
      .poll(() =>
        log.reads.every((file) => file[reads]?.completedAt !== undefined),
      )
      .toBe(true)
    const frozen = await expectPublicStatusPair(expect, guest, log)
    events.frozenAt = Date.now()
    events.frozen = frozen
    expect(frozen.heartbeat.aliveAt).toBeGreaterThanOrEqual(
      before.heartbeat.aliveAt,
    )
    expect(frozen.status.outages).toEqual(archive.accepted.status.outages)
    const boundary =
      frozen.heartbeat.aliveAt + frozen.status.config.staleAfterSeconds * 1000
    // Intentional real elapsed time, derived from the actual accepted config.
    // The browser clock and every publisher input remain untouched.
    await delay(Math.max(0, boundary - Date.now() + 1))
    await expectPublicStatusStale(expect, guest, frozen)
    events.staleAt = Date.now()
    expect(Date.now()).toBeGreaterThan(boundary)
    await expectPublicStatusArchive(expect, guest, archive)
    const stillDead = await control.request('inspect')
    expect(stillDead.daemon.state).toEqual(stopped.daemon.state)
    expect(stillDead.nginx.id).toBe(stopped.nginx.id)
    expect(stillDead.nginx.state.Running).toBe(true)
    events.stillDead = stillDead

    // Own cold document navigation is part of this stopped-daemon scenario.
    const beforeReload = log.reads[0].length
    await guest.reload()
    await expect.poll(() => log.reads[0].length).toBeGreaterThan(beforeReload)
    const reloaded = await expectPublicStatusPair(expect, guest, log)
    expect(reloaded.status).toEqual(frozen.status)
    expect(reloaded.heartbeat).toEqual(frozen.heartbeat)
    await expectPublicStatusStale(expect, guest, frozen)
    await expectPublicStatusArchive(expect, guest, archive)
    await gotoPublicStatus(page)
    const cold = await expectPublicStatusPair(expect, page, coldLog)
    expect(cold.status).toEqual(frozen.status)
    expect(cold.heartbeat).toEqual(frozen.heartbeat)
    await expectPublicStatusStale(expect, page, cold)
    expect(cold.status.outages).toEqual(archive.accepted.status.outages)
    await expectPublicStatusArchive(expect, page, archive)
    expect(coldLog.wire.packets).toEqual([])
    expect(log.wire.packets.length).toBe(packetsAtStop)
    events.coldAt = Date.now()
    const afterCold = await control.request('inspect')
    expect(afterCold.daemon.state).toEqual(stopped.daemon.state)
    events.afterCold = afterCold
    for (const reader of [log, coldLog]) {
      expectPublicStatusReadOnly(expect, reader)
      for (const [kind, file] of reader.reads.entries()) {
        const stoppedReads = reader === log ? file.slice(reads) : file
        for (const read of stoppedReads.filter(
          (read) => read.completedAt !== undefined,
        )) {
          expect(read.status).toBe(200)
          expect(read.body).toEqual(
            kind === 0 ? frozen.status : frozen.heartbeat,
          )
        }
      }
      for (const file of reader.reads) {
        expect(
          file.filter((read) => read.status === 200).length,
        ).toBeGreaterThan(0)
        for (const read of file.filter((read) => read.status === 200))
          expect(read.headers?.['cache-control']).toContain('no-store')
      }
    }
    events.restartRequestedAt = Date.now()
    const started = await control.request('start')
    expect(started.daemon.id).toBe(stopped.daemon.id)
    expect(started.daemon.state.Running).toBe(true)
    events.started = started
    // Bootstrap has no five-second recovery SLA. Await an actual publication
    // through the normal HTTP event wait; all configured caps stay unchanged.
    if (
      !coldLog
        .pairs()
        .some(({ heartbeat }) => heartbeat.aliveAt > frozen.heartbeat.aliveAt)
    ) {
      await page.waitForResponse(async (response) => {
        if (
          new URL(response.url()).pathname !== PUBLIC_STATUS_FILES[1] ||
          response.status() !== 200
        )
          return false
        const heartbeat = publicStatusHeartbeatSchema.safeParse(
          await response.json(),
        )
        return (
          heartbeat.success && heartbeat.data.aliveAt > frozen.heartbeat.aliveAt
        )
      })
    }
    events.writerObservedAt = Date.now()
    // Use the cold reader to witness the writer's genuinely newer heartbeat,
    // even if its sources are Unknown. Warm polling may accept it later;
    // its health deadline starts only after independent source readiness.
    events.firstFreshReader = 'cold'
    events.firstFresh = await expectPublicStatusPair(
      expect,
      page,
      coldLog,
      ({ heartbeat }) => heartbeat.aliveAt > frozen.heartbeat.aliveAt,
    )
    events.firstFreshAt = Date.now()
    // Restore can refresh the archive's heartbeat before rebuilding current
    // observations. Source readiness needs a new healthy projection as well.
    const healthySource = (status: PublicStatusFile): boolean =>
      status.generatedAt > frozen.status.generatedAt &&
      status.components.every((component) => component.state === 'operational')
    if (!coldLog.pairs().some(({ status }) => healthySource(status))) {
      await page.waitForResponse(async (response) => {
        if (
          new URL(response.url()).pathname !== PUBLIC_STATUS_FILES[0] ||
          response.status() !== 200
        )
          return false
        const status = publicStatusFileSchema.safeParse(await response.json())
        return status.success && healthySource(status.data)
      })
    }
    events.sourceKnownAt = Date.now()
    const sourceReady = await expectPublicStatusPair(
      expect,
      page,
      coldLog,
      ({ status, heartbeat }) =>
        healthySource(status) &&
        heartbeat.aliveAt > frozen.heartbeat.aliveAt &&
        Date.now() - heartbeat.aliveAt < status.config.staleAfterSeconds * 1000,
    )
    events.sourceReadyAt = Date.now()
    events.sourceReady = sourceReady
    events.coldConnectionAtSourceReady = {
      state: await page.getByTestId('conn-state').innerText(),
      message: await page
        .getByTestId('public-status-connection')
        .allTextContents(),
    }
    await expect(page.getByTestId('public-status-banner')).toHaveText(
      'All systems operational',
    )
    await expect(page.getByTestId('public-status-component-state')).toHaveText(
      'Operational',
    )
    expect(sourceReady.status.outages).toEqual(archive.accepted.status.outages)
    await expectPublicStatusArchive(expect, page, archive)
    events.coldHealthyAt = Date.now()
    // Only now measure the original warm reader's automatic acceptance. Its
    // configured polling and ordinary reconnect keep running throughout.
    events.warmRecoveryCheckAt = Date.now()
    const fresh = await expectPublicStatusPair(
      expect,
      guest,
      log,
      ({ status, heartbeat }) =>
        status.generatedAt >= sourceReady.status.generatedAt &&
        heartbeat.aliveAt > frozen.heartbeat.aliveAt &&
        Date.now() - heartbeat.aliveAt <
          status.config.staleAfterSeconds * 1000 &&
        status.components.every(
          (component) => component.state === 'operational',
        ),
    )
    events.fresh = fresh
    await expect(guest.getByTestId('public-status-banner')).toHaveText(
      'All systems operational',
    )
    await expect(guest.getByTestId('public-status-component-state')).toHaveText(
      'Operational',
    )
    expect(fresh.status.outages).toEqual(archive.accepted.status.outages)
    await expectPublicStatusArchive(expect, guest, archive)
    events.freshAt = Date.now()
    await expect(guest.getByTestId('conn-state')).toHaveText('connected')
    await expect(guest.getByTestId('public-status-connection')).toHaveCount(0)
    events.connectedAt = Date.now()
    expectPublicStatusReadOnly(expect, log)
    expectPublicStatusReadOnly(expect, coldLog)
  } finally {
    try {
      events.cleanup = await control.request('start')
    } finally {
      await log.stop()
      await coldLog.stop()
      const evidence = test.info().outputPath('stop-cold-recovery.json')
      await writeFile(
        evidence,
        JSON.stringify({ events, archive, public: log, cold: coldLog }),
      )
      await test.info().attach('standalone-status-stop-cold-recovery', {
        path: evidence,
        contentType: 'application/json',
      })
      await context.close()
    }
  }
})
