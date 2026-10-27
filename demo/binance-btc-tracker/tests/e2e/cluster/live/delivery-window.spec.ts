import { writeFile } from 'node:fs/promises'

import { expect, test, type Page } from '@playwright/test'

import {
  clearCustomSetting,
  deliveryObject as object,
  deliverySettingUpdates,
  readClusterBrowserStand,
  ROWS,
  saveDeliverySetting,
  setCustomSetting,
  shownByTestId,
  waitDeliveryBoundary,
  waitForDeliveryQuiet,
  watchClusterMasterUpgrades,
  watchDeliveryFrames,
  type DeliveryFrameLog,
  type DeliverySettingMutation,
  typeInto,
  expectDocumentUnchanged,
  expectPageReady,
  gotoPage,
  markDocument,
  PAGE_READY,
  grantAdminToSelf,
} from '../../../../../../framework/frontend/e2e/index.js'
import {
  CLUSTER_FOLLOWER,
  CLUSTER_LEADER,
  CLUSTER_MASTERS,
  clusterInspect,
  expectLeaderUnmoved,
  newMasterPage,
  nodeHost,
  placementNode,
} from '../../helpers/cluster.js'
import { deliveryClusterControl } from '../../helpers/deliveryCluster.js'
import {
  appendLogLines,
  logMarker,
  TAIL_ARRIVAL_TIMEOUT_MS,
} from '../../helpers/logs.js'

// A worker fault is destructive and cannot be replayed on a dirty stand.
test.describe.configure({ mode: 'default', retries: 0 })

const WINDOW_KEY = 'websocket.delivery_window_ms'
const SOURCE_KEY = 'example_string'
const OTHER_KEY = 'example_integer'
const WINDOW_MS = 8_000
const CHANGED_WINDOW_MS = 4_000
const PENDING_WINDOW_MS = 3_000
const IMMEDIATE_MS = 1_000
const JITTER_MS = 200
const STATUS_HEARTBEAT_MS = 30_000
// Three independent admin contexts and several real 8 s/4 s delivery boundaries.
const REMOTE_TIMEOUT_FACTOR = 6
const control = deliveryClusterControl()
const stand = readClusterBrowserStand()

async function openSettings(page: Page, key: string): Promise<void> {
  await gotoPage(expect, page, '/hilos/settings', PAGE_READY)
  await typeInto(page.getByTestId('hilos-table-search'), key)
  await expect(page.getByTestId(`hilos-table-row-${key}`)).toBeVisible()
}

function updates(log: DeliveryFrameLog, key = SOURCE_KEY) {
  return deliverySettingUpdates(log, key)
}

async function received(log: DeliveryFrameLog, value: string) {
  await expect
    .poll(() => updates(log).some((update) => update.value === value), {
      timeout: WINDOW_MS + IMMEDIATE_MS * 2,
    })
    .toBe(true)
  return updates(log).find((update) => update.value === value)!
}

function expectGap(gap: number, windowMs: number): void {
  expect(gap).toBeGreaterThanOrEqual(windowMs - JITTER_MS)
  expect(gap).toBeLessThan(windowMs + IMMEDIATE_MS)
}

function hints(log: DeliveryFrameLog) {
  return log.packets.flatMap((packet) =>
    packet.frames.flatMap(({ wire }) =>
      object(wire).type === 'status_files_changed' ? [{ packet, wire }] : [],
    ),
  )
}

/** Every frame counts: an unexpected boundary must fail the exact packet oracle. */
function packetEvents(packet: DeliveryFrameLog['packets'][number]) {
  return packet.frames.map(({ wire }) => {
    const envelope = object(wire)
    if (envelope.type === 'status_files_changed') {
      expect(envelope.data).toEqual({})
      return { type: envelope.type }
    }
    const data = object(envelope.data)
    expect(envelope.type).toBe('table_viewport_delta')
    expect(data.page).toBe('hilos_settings')
    expect(data.tableKey).toBe('settings')
    expect(data.kind).toBe('row_updated')
    const slot = object(object(object(data.row).slots).settings)
    return { type: envelope.type, key: data.rowKey, value: String(slot.value) }
  })
}

async function attach(name: string, evidence: unknown): Promise<void> {
  const path = test.info().outputPath(`${name}.json`)
  await writeFile(path, JSON.stringify(evidence))
  await test.info().attach(name, { path, contentType: 'application/json' })
}

function noInternalFrames(log: DeliveryFrameLog): void {
  expect(log.packets.map(({ raw }) => raw).join('\n')).not.toContain(
    'hilos_delivery_complete',
  )
  expect(log.sent.map(({ raw }) => raw).join('\n')).not.toContain('deliveryId')
}

// FIXME(F-036, 2026-10-26): Extra writer delta in the mixed hint segment · чужой · попытка 1 · HIL-1704.
// Evidence: full runs 1633 and 1621 fail the same exact-segment assertion.
test(
  'cluster delivery: remote source coalesces physical packets and keeps follower deadlines independent',
  { tag: '@quarantine:F-036' },
  async ({ browser }) => {
    test.setTimeout(test.info().timeout * REMOTE_TIMEOUT_FACTOR)
    const followers = CLUSTER_MASTERS.filter((node) => node !== CLUSTER_LEADER)
    expect(followers).toHaveLength(2)
    const writerPage = await newMasterPage(browser, CLUSTER_LEADER)
    const tabA = await newMasterPage(browser, followers[0]!)
    const tabB = await newMasterPage(browser, followers[1]!)
    const configPage = await writerPage.context().newPage()
    const writer = watchDeliveryFrames(writerPage)
    const a = watchDeliveryFrames(tabA)
    const b = watchDeliveryFrames(tabB)
    const upgrades = await Promise.all([
      watchClusterMasterUpgrades(expect, writerPage, stand, CLUSTER_LEADER),
      watchClusterMasterUpgrades(expect, tabA, stand, followers[0]!),
      watchClusterMasterUpgrades(expect, tabB, stand, followers[1]!),
    ])
    const mutations: DeliverySettingMutation[] = []
    const phases: Record<string, unknown> = {}
    let sequence = 0
    const write = async (key = SOURCE_KEY, otherValue = '156511') => {
      const value = key === OTHER_KEY ? otherValue : `remote-${++sequence}`
      const mutation = await saveDeliverySetting(
        expect,
        writerPage,
        writer,
        key,
        value,
      )
      mutations.push(mutation)
      return mutation
    }
    let configured = false
    try {
      const before = await clusterInspect(CLUSTER_FOLLOWER)
      for (const page of [writerPage, tabA, tabB])
        await grantAdminToSelf(expect, page)
      await openSettings(configPage, WINDOW_KEY)
      await openSettings(writerPage, SOURCE_KEY)
      await setCustomSetting(writerPage, SOURCE_KEY, 'remote-seed')
      await typeInto(writerPage.getByTestId('hilos-table-search'), 'example_')
      for (const page of [tabA, tabB]) {
        await openSettings(page, SOURCE_KEY)
        await typeInto(page.getByTestId('hilos-table-search'), 'example_')
        await expect(
          page.getByTestId(`hilos-table-row-${OTHER_KEY}`),
        ).toBeVisible()
        await markDocument(page)
      }
      const owner = await control.request(
        'locate',
        'hilos_index',
        followers[0]!,
      )
      expect(owner.worker.node).toBe(CLUSTER_LEADER)
      expect(owner.state?.state).not.toMatch(/^[TtZ]$/)
      phases.topology = {
        before,
        owner,
        sourceLibrary: await placementNode('hilos_settings_library'),
      }
      for (const upgrade of upgrades) await upgrade.assert()
      const sockets = [a.sockets(), b.sockets()]
      await setCustomSetting(configPage, WINDOW_KEY, String(WINDOW_MS))
      configured = true
      await expect(
        configPage.getByTestId(`hilos-table-row-${WINDOW_KEY}`),
      ).toContainText(String(WINDOW_MS))
      const passiveAt = performance.now()
      // Observe a real publication first, then all physical traffic reaching quiet.
      // This puts the first burst between heartbeats without silencing Status.
      await expect
        .poll(
          () =>
            [a, b].every((log) =>
              hints(log).some(({ packet }) => packet.at > passiveAt),
            ),
          {
            timeout: STATUS_HEARTBEAT_MS + WINDOW_MS + IMMEDIATE_MS,
          },
        )
        .toBe(true)
      phases.quiet = await waitForDeliveryQuiet(
        expect,
        [a, b],
        WINDOW_MS,
        IMMEDIATE_MS,
        STATUS_HEARTBEAT_MS + WINDOW_MS + IMMEDIATE_MS,
      )

      const anchor = await write()
      const anchors = [
        await received(a, anchor.value),
        await received(b, anchor.value),
      ]
      for (const update of anchors)
        expect(update.packet.at - anchor.sent).toBeLessThan(IMMEDIATE_MS)
      const burst = [
        await write(),
        await write(),
        await write(OTHER_KEY),
        await write(),
      ]
      expect(burst.at(-1)!.accepted).toBeLessThan(
        Math.min(...anchors.map(({ packet }) => packet.at)) +
          WINDOW_MS -
          IMMEDIATE_MS,
      )
      for (const log of [a, b])
        expect(updates(log).at(-1)!.value).toBe(anchor.value)
      const releases = [
        await received(a, burst.at(-1)!.value),
        await received(b, burst.at(-1)!.value),
      ]
      for (const [index, log] of [a, b].entries()) {
        const release = releases[index]!
        expectGap(release.packet.at - anchors[index]!.packet.at, WINDOW_MS)
        expect(
          updates(log)
            .filter(({ packet }) => packet.at > anchors[index]!.packet.at)
            .map(({ value }) => value),
        ).toEqual([burst.at(-1)!.value])
        const independent = updates(log, OTHER_KEY).find(
          ({ value }) => value === '156511',
        )!
        expect(independent.packet).toBe(release.packet)
        const relevant = release.packet.frames.filter(
          ({ wire }) => object(object(wire).data).tableKey === 'settings',
        )
        expect(
          relevant.map(({ wire }) => object(object(wire).data).rowKey),
        ).toEqual([OTHER_KEY, SOURCE_KEY])
        // No hint or other unsupported frame occurred inside this merge segment.
        expect(packetEvents(release.packet)).toEqual([
          { type: 'table_viewport_delta', key: OTHER_KEY, value: '156511' },
          {
            type: 'table_viewport_delta',
            key: SOURCE_KEY,
            value: burst.at(-1)!.value,
          },
        ])
        expect(
          hints(writer).filter(
            ({ packet }) =>
              packet.at > anchor.sent && packet.at <= release.packet.at,
          ),
        ).toEqual([])
        expect(release.packet.raw).toMatch(/^\{"type":"signal_batch"/)
        expect(
          release.packet.frames.every(
            ({ socket, message, raw }) =>
              socket === release.packet.socket &&
              message === release.packet.message &&
              raw === release.packet.raw,
          ),
        ).toBe(true)
      }
      phases.coalescing = { anchor, anchors, burst, releases }

      // The next real heartbeat falls inside a new fixed window. Two acknowledged
      // source states precede it, and two follow it: latest wins in EACH segment.
      phases.mixedQuiet = await waitForDeliveryQuiet(
        expect,
        [a, b],
        WINDOW_MS,
        IMMEDIATE_MS,
        STATUS_HEARTBEAT_MS + WINDOW_MS + IMMEDIATE_MS,
      )
      const mixedAnchor = await write()
      const mixedAnchors = [
        await received(a, mixedAnchor.value),
        await received(b, mixedAnchor.value),
      ]
      for (const update of mixedAnchors)
        expect(update.packet.at - mixedAnchor.sent).toBeLessThan(IMMEDIATE_MS)
      const beforeHint = [await write(), await write()]
      await expect
        .poll(
          () =>
            hints(writer).filter(({ packet }) => packet.at > mixedAnchor.sent)
              .length,
          { timeout: WINDOW_MS },
        )
        .toBe(1)
      const hint = hints(writer).find(
        ({ packet }) => packet.at > mixedAnchor.sent,
      )!
      expect(beforeHint.at(-1)!.accepted).toBeLessThan(hint.packet.at)
      // Keep the cross-node source send unambiguously after the independent hint.
      await waitDeliveryBoundary(hint.packet.at + JITTER_MS)
      const afterHint = [
        await write(OTHER_KEY, '156512'),
        await write(),
        await write(),
      ]
      expect(afterHint[0]!.sent).toBeGreaterThan(hint.packet.at)
      expect(afterHint.at(-1)!.accepted).toBeLessThan(
        Math.min(...mixedAnchors.map(({ packet }) => packet.at)) +
          WINDOW_MS -
          IMMEDIATE_MS,
      )
      for (const log of [a, b])
        expect(updates(log).at(-1)!.value).toBe(mixedAnchor.value)
      const mixedReleases = [
        await received(a, afterHint.at(-1)!.value),
        await received(b, afterHint.at(-1)!.value),
      ]
      const sourceEvents = writer.packets
        .filter(
          ({ at }) =>
            at > mixedAnchors[0]!.packet.at &&
            at <= mixedReleases[0]!.packet.at,
        )
        .flatMap(({ frames }) => frames)
        .filter(
          ({ wire }) =>
            object(wire).type === 'status_files_changed' ||
            (object(wire).type === 'table_viewport_delta' &&
              object(object(wire).data).tableKey === 'settings'),
        )
      // The writer is request-active and sees every own mutation and the genuine
      // opaque hint. This independent stream proves the exact segment membership.
      expect(
        sourceEvents.map(({ wire }) =>
          object(wire).type === 'status_files_changed'
            ? { type: 'status_files_changed' }
            : {
                type: 'table_viewport_delta',
                key: object(object(wire).data).rowKey,
                value: String(
                  object(
                    object(object(object(object(wire).data).row).slots)
                      .settings,
                  ).value,
                ),
              },
        ),
      ).toEqual([
        ...beforeHint.map(({ key, value }) => ({
          type: 'table_viewport_delta',
          key,
          value,
        })),
        { type: 'status_files_changed' },
        ...afterHint.map(({ key, value }) => ({
          type: 'table_viewport_delta',
          key,
          value,
        })),
      ])
      for (const [index, log] of [a, b].entries()) {
        const release = mixedReleases[index]!
        expectGap(release.packet.at - mixedAnchors[index]!.packet.at, WINDOW_MS)
        expect(packetEvents(release.packet)).toEqual([
          {
            type: 'table_viewport_delta',
            key: SOURCE_KEY,
            value: beforeHint.at(-1)!.value,
          },
          { type: 'status_files_changed' },
          { type: 'table_viewport_delta', key: OTHER_KEY, value: '156512' },
          {
            type: 'table_viewport_delta',
            key: SOURCE_KEY,
            value: afterHint.at(-1)!.value,
          },
        ])
        expect(
          updates(log)
            .filter(({ packet }) => packet.at > mixedAnchors[index]!.packet.at)
            .map(({ value }) => value),
        ).toEqual([beforeHint.at(-1)!.value, afterHint.at(-1)!.value])
        expect(
          updates(log, OTHER_KEY).find(({ value }) => value === '156512')!
            .packet,
        ).toBe(release.packet)
        expect(
          log.packets.filter(
            ({ at }) =>
              at > mixedAnchors[index]!.packet.at && at <= release.packet.at,
          ),
        ).toEqual([release.packet])
      }
      phases.mixed = {
        mixedAnchor,
        mixedAnchors,
        beforeHint,
        hint,
        afterHint,
        sourceEvents,
        mixedReleases,
      }

      phases.clickQuiet = await waitForDeliveryQuiet(
        expect,
        [a, b],
        WINDOW_MS,
        IMMEDIATE_MS,
        STATUS_HEARTBEAT_MS + WINDOW_MS + IMMEDIATE_MS,
      )
      const quiet = await write()
      const quietA = await received(a, quiet.value)
      const quietB = await received(b, quiet.value)
      expect(quietA.packet.at - quiet.sent).toBeLessThan(IMMEDIATE_MS)
      expect(quietB.packet.at - quiet.sent).toBeLessThan(IMMEDIATE_MS)
      const pending = [await write(), await write()]
      for (const log of [a, b])
        expect(updates(log).at(-1)!.value).toBe(quiet.value)
      const sentMark = a.sent.length
      await shownByTestId(tabA, 'hilos-table-sort-key').click()
      await expect
        .poll(() =>
          a.sent
            .slice(sentMark)
            .some(({ wire }) => object(wire).type === 'table_viewport'),
        )
        .toBe(true)
      const click = a.sent
        .slice(sentMark)
        .find(({ wire }) => object(wire).type === 'table_viewport')!
      const flushed = await received(a, pending.at(-1)!.value)
      expect(flushed.packet.at - click.at).toBeLessThan(IMMEDIATE_MS)
      await expect
        .poll(() =>
          a.packets.some(
            (packet) =>
              packet.at >= click.at &&
              packet.frames.some(
                ({ wire }) =>
                  object(wire).type === 'table_window' &&
                  object(object(wire).data).tableKey === 'settings',
              ),
          ),
        )
        .toBe(true)
      const response = a.packets.find(
        (packet) =>
          packet.at >= click.at &&
          packet.frames.some(
            ({ wire }) =>
              object(wire).type === 'table_window' &&
              object(object(wire).data).tableKey === 'settings',
          ),
      )!
      expect(response.at - click.at).toBeLessThan(IMMEDIATE_MS)
      await expect(tabA.getByTestId('hilos-table-loading')).toHaveCount(0)
      expect(updates(b).at(-1)!.value).toBe(quiet.value)
      const bRelease = await received(b, pending.at(-1)!.value)
      expectGap(bRelease.packet.at - quietB.packet.at, WINDOW_MS)
      phases.click = {
        quiet,
        quietA,
        quietB,
        pending,
        click,
        flushed,
        response,
        bRelease,
      }

      await setCustomSetting(configPage, WINDOW_KEY, String(CHANGED_WINDOW_MS))
      await expect(
        configPage.getByTestId(`hilos-table-row-${WINDOW_KEY}`),
      ).toContainText(String(CHANGED_WINDOW_MS))
      phases.changedQuiet = await waitForDeliveryQuiet(
        expect,
        [a, b],
        WINDOW_MS,
        IMMEDIATE_MS,
        STATUS_HEARTBEAT_MS + WINDOW_MS + IMMEDIATE_MS,
      )
      const changedAnchor = await write()
      const changedAnchors = [
        await received(a, changedAnchor.value),
        await received(b, changedAnchor.value),
      ]
      const changed = [await write(), await write()]
      const changedReleases = [
        await received(a, changed.at(-1)!.value),
        await received(b, changed.at(-1)!.value),
      ]
      for (const [index, log] of [a, b].entries()) {
        expectGap(
          changedReleases[index]!.packet.at - changedAnchors[index]!.packet.at,
          CHANGED_WINDOW_MS,
        )
        expect(
          updates(log)
            .filter(
              ({ packet }) => packet.at > changedAnchors[index]!.packet.at,
            )
            .map(({ value }) => value),
        ).toEqual([changed.at(-1)!.value])
      }
      phases.liveSetting = {
        changedAnchor,
        changedAnchors,
        changed,
        changedReleases,
      }
      for (const [index, page] of [tabA, tabB].entries()) {
        await expect(
          page.getByTestId(`hilos-table-row-${SOURCE_KEY}`),
        ).toContainText(changed.at(-1)!.value)
        await expectDocumentUnchanged(expect, page)
        expect([a, b][index]!.sockets()).toBe(sockets[index])
      }
      expect(
        b.sent.filter(({ at, raw }) => at > passiveAt && raw !== 'ping'),
      ).toEqual([])
      expect(
        a.sent.filter(({ at, raw }) => at > click.at && raw !== 'ping'),
      ).toEqual([])
      for (const log of [writer, a, b]) noInternalFrames(log)
      for (const upgrade of upgrades) await upgrade.assert()
      await expectLeaderUnmoved(before)
    } finally {
      await attach('remote-delivery', {
        clock: 'runner performance.now milliseconds',
        phases,
        mutations,
        writer,
        a,
        b,
        host: control.evidence,
      })
      writer.stop()
      a.stop()
      b.stop()
      try {
        if (configured) await clearCustomSetting(configPage, WINDOW_KEY)
        if (await writerPage.getByTestId('modal-close').isVisible())
          await writerPage.getByTestId('modal-close').click()
        await clearCustomSetting(writerPage, SOURCE_KEY)
        await clearCustomSetting(writerPage, OTHER_KEY)
      } finally {
        for (const upgrade of upgrades) await upgrade.stop()
        await Promise.all([
          writerPage.context().close(),
          tabA.context().close(),
          tabB.context().close(),
        ])
      }
    }
  },
)

test('cluster delivery: a pending remote owner loss cancels the hold and recovers on the original socket', async ({
  browser,
}) => {
  test.slow() // Includes deliberate pending beyond one window, real worker replacement and log scans.
  const page = await newMasterPage(browser, CLUSTER_FOLLOWER)
  const healthyNode = CLUSTER_MASTERS.find(
    (node) => node !== CLUSTER_LEADER && node !== CLUSTER_FOLLOWER,
  )!
  const healthyPage = await newMasterPage(browser, healthyNode)
  const configPage = await newMasterPage(browser, CLUSTER_LEADER)
  const log = watchDeliveryFrames(page)
  const healthy = watchDeliveryFrames(healthyPage)
  const upgrades = await Promise.all([
    watchClusterMasterUpgrades(expect, page, stand, CLUSTER_FOLLOWER),
    watchClusterMasterUpgrades(expect, healthyPage, stand, healthyNode),
  ])
  const phases: Record<string, unknown> = {}
  let configured = false
  try {
    const before = await clusterInspect(CLUSTER_FOLLOWER)
    for (const tab of [page, healthyPage, configPage])
      await grantAdminToSelf(expect, tab)
    await openSettings(configPage, WINDOW_KEY)
    await openSettings(healthyPage, SOURCE_KEY)
    await gotoPage(expect, page, '/hilos/logs/keys', PAGE_READY)
    await expect
      .poll(() => page.locator(ROWS).count(), {
        timeout: TAIL_ARRIVAL_TIMEOUT_MS,
      })
      .toBeGreaterThan(0)
    await page.getByTestId('hilos-page-tab-hilos_logs').click()
    await expectPageReady(expect, page)
    await expect(
      page.getByTestId('hilos-page-tab-hilos_logs_keys'),
    ).toBeVisible()
    const owner = await control.request(
      'locate',
      'hilos_logs',
      CLUSTER_FOLLOWER,
    )
    expect(owner.worker.node).not.toBe(CLUSTER_FOLLOWER)
    for (const upgrade of upgrades) await upgrade.assert()
    await setCustomSetting(configPage, WINDOW_KEY, String(PENDING_WINDOW_MS))
    configured = true
    await expect(
      configPage.getByTestId(`hilos-table-row-${WINDOW_KEY}`),
    ).toContainText(String(PENDING_WINDOW_MS))
    await markDocument(page)
    await markDocument(healthyPage)
    const originalSocket = log.sockets()
    const healthySocket = healthy.sockets()
    const healthyMark = performance.now()
    const paused = await control.request(
      'pause',
      'hilos_logs',
      CLUSTER_FOLLOWER,
    )
    expect(paused.worker).toEqual(owner.worker)
    expect(paused.state?.state).toMatch(/^[Tt]$/)
    phases.paused = { before, owner, paused, originalSocket, healthySocket }
    const sentMark = log.sent.length
    await page.getByTestId('hilos-page-tab-hilos_logs_keys').click()
    const subscribes = () =>
      log.sent
        .slice(sentMark)
        .filter(
          ({ wire }) =>
            object(wire).type === 'page_subscribe' &&
            object(wire).page === 'hilos_logs_keys',
        )
    await expect.poll(() => subscribes().length).toBe(1)
    const pending = subscribes()[0]!
    expect(pending.socket).toBe(originalSocket)
    const answers = () =>
      log.packets.filter(
        (packet) =>
          packet.at >= pending.at &&
          packet.frames.some(
            ({ wire }) =>
              object(wire).type === 'page_response' &&
              object(object(wire).data).page === 'hilos_logs_keys',
          ),
      )
    await waitDeliveryBoundary(pending.at + PENDING_WINDOW_MS + IMMEDIATE_MS)
    expect(answers()).toEqual([])
    expect(
      log.packets
        .filter((packet) => packet.at >= pending.at)
        .flatMap(({ frames }) => frames)
        .filter(
          ({ wire }) =>
            object(wire).type === 'subscription_page_hilos_logs_keys',
        ),
    ).toEqual([])
    await expect(page.getByTestId('hilos-page-state')).toHaveAttribute(
      'data-state',
      'loading',
    )
    await expect(page.getByTestId('conn-state')).toHaveText('connected')
    phases.pending = {
      request: pending,
      checkedAt: performance.now(),
      answers: answers(),
    }
    const killedAt = performance.now()
    const killed = await control.request('kill', 'hilos_logs', CLUSTER_FOLLOWER)
    expect(killed.worker).toEqual(paused.worker)
    expect(killed.loss?.length).toBe(1)
    phases.killed = { killedAt, killed }
    const recoveryFrames = () =>
      log.packets.filter(
        (packet) =>
          packet.at >= killedAt &&
          packet.frames.some(
            ({ wire }) =>
              object(wire).type === 'page_resubscribe' &&
              object(object(wire).data).page === 'hilos_logs_keys',
          ),
      )
    await expect
      .poll(() => recoveryFrames().length, { timeout: TAIL_ARRIVAL_TIMEOUT_MS })
      .toBe(1)
    await expect.poll(() => subscribes().length).toBe(2)
    await expect
      .poll(() => answers().length, { timeout: TAIL_ARRIVAL_TIMEOUT_MS })
      .toBe(1)
    const resubscribe = recoveryFrames()[0]!
    const retry = subscribes()[1]!
    const answer = answers()[0]!
    expect(resubscribe.socket).toBe(originalSocket)
    expect(resubscribe.at).toBeGreaterThanOrEqual(killedAt)
    expect(retry.at).toBeGreaterThanOrEqual(resubscribe.at)
    expect(answer.at).toBeGreaterThanOrEqual(retry.at)
    expect(retry.socket).toBe(originalSocket)
    expect(answer.socket).toBe(originalSocket)
    const types = answer.frames
      .map(({ wire }) => object(wire).type)
      .filter(
        (type) =>
          type === 'subscription_page_hilos_logs_keys' ||
          type === 'page_response' ||
          type === 'table_facet_counts',
      )
    const descriptor = object(
      object(object(retry.wire).tableWindows).hilosLogKeys,
    )
    const initialTypes =
      Object.keys(object(descriptor.facets)).length > 0
        ? [
            'subscription_page_hilos_logs_keys',
            'page_response',
            'table_facet_counts',
          ]
        : ['subscription_page_hilos_logs_keys', 'page_response']
    // The revived Logs owner can tick again in this same physical drain,
    // publishing a table_window and a second head after the completed answer.
    // Keep those independent events; the initial sequence itself stays exact.
    expect(types.slice(0, initialTypes.length)).toEqual(initialTypes)
    expect(answer.raw).toMatch(/^\{"type":"signal_batch"/)
    expect(
      answer.frames.every(
        ({ socket, message, raw }) =>
          socket === answer.socket &&
          message === answer.message &&
          raw === answer.raw,
      ),
    ).toBe(true)
    await expectPageReady(expect, page)
    await expect
      .poll(() => page.locator(ROWS).count(), {
        timeout: TAIL_ARRIVAL_TIMEOUT_MS,
      })
      .toBeGreaterThan(0)
    const recovered = await control.request(
      'recover',
      'hilos_logs',
      CLUSTER_FOLLOWER,
    )
    expect(recovered.worker).not.toEqual(paused.worker)
    expect(recovered.worker.node).toBe(paused.worker.node)
    expect(recovered.worker.container).toBe(paused.worker.container)
    expect(recovered.journal).toContain(
      `Agent 'hilos_logs' started on worker #${recovered.worker.index}`,
    )
    phases.recovered = { resubscribe, retry, answer, recovered }

    // The revived owner must project new real data, beyond merely finishing its initial answer.
    await typeInto(page.getByTestId('hilos-table-search'), 'hilos_log_store')
    await expect
      .poll(() => page.locator(ROWS).count(), {
        timeout: TAIL_ARRIVAL_TIMEOUT_MS,
      })
      .toBeGreaterThan(0)
    const streamRows = () =>
      log.packets.flatMap((packet) =>
        packet.frames.flatMap(({ wire }) => {
          const data = object(object(wire).data)
          const window =
            object(wire).type === 'page_response'
              ? object(object(object(data.payload).windows).hilosLogKeys)
              : data
          if (data.page !== 'hilos_logs_keys' || !Array.isArray(window.rows))
            return []
          return window.rows.map((row: unknown) => ({
            packet,
            key: String(object(row).rowKey),
            fields: object(object(object(row).slots).stream),
          }))
        }),
      )
    await expect
      .poll(() =>
        streamRows().some(
          ({ key }) =>
            key.startsWith(`${owner.worker.node}:`) &&
            key.includes('hilos_log_store'),
        ),
      )
      .toBe(true)
    const sourceRow = streamRows()
      .filter(
        ({ key }) =>
          key.startsWith(`${owner.worker.node}:`) &&
          key.includes('hilos_log_store'),
      )
      .at(-1)!
    const row = page.getByTestId(`hilos-table-row-${sourceRow.key}`)
    await expect(row).toBeVisible()
    const beforeText = await row.textContent()
    const appendAt = performance.now()
    expect(
      await appendLogLines(
        `${logMarker('delivery-recovered')}-${'x'.repeat(1024)}`,
        4,
        nodeHost(owner.worker.node),
      ),
    ).toBe(4)
    await expect
      .poll(
        () =>
          streamRows().some(
            ({ packet, key, fields }) =>
              packet.at > appendAt &&
              key === sourceRow.key &&
              Number(fields.bytes) > Number(sourceRow.fields.bytes),
          ),
        { timeout: TAIL_ARRIVAL_TIMEOUT_MS },
      )
      .toBe(true)
    await expect(row).not.toHaveText(beforeText!)
    phases.live = {
      sourceRow,
      appendAt,
      rows: streamRows().filter(({ packet }) => packet.at > appendAt),
    }
    expect(log.sockets()).toBe(originalSocket)
    expect(healthy.sockets()).toBe(healthySocket)
    expect(
      log.connections.find(({ socket }) => socket === originalSocket)?.closed,
    ).toBeUndefined()
    expect(
      healthy.sent.filter(({ at, raw }) => at > healthyMark && raw !== 'ping'),
    ).toEqual([])
    expect(
      healthy.packets
        .filter(({ at }) => at > healthyMark)
        .flatMap(({ frames }) => frames)
        .filter(({ wire }) => object(wire).type === 'page_resubscribe'),
    ).toEqual([])
    await expectDocumentUnchanged(expect, page)
    await expectDocumentUnchanged(expect, healthyPage)
    for (const frames of [log, healthy]) noInternalFrames(frames)
    for (const upgrade of upgrades) await upgrade.assert()
    await expectLeaderUnmoved(before)
  } finally {
    await attach('pending-remote-owner', {
      clock: 'runner performance.now milliseconds',
      phases,
      log,
      healthy,
      host: control.evidence,
    })
    log.stop()
    healthy.stop()
    try {
      if (configured) await clearCustomSetting(configPage, WINDOW_KEY)
    } finally {
      for (const upgrade of upgrades) await upgrade.stop()
      await Promise.all([
        page.context().close(),
        healthyPage.context().close(),
        configPage.context().close(),
      ])
    }
  }
})
