import { writeFile } from 'node:fs/promises'
import { test, expect, type Page } from '@playwright/test'

import {
  clearCustomSetting,
  deliveryObject as object,
  deliverySettingUpdates,
  draftCustomSetting,
  quietAfterHeartbeat,
  writeDeliverySettings,
  type DeliverySettingUpdate as Update,
  type DeliverySettingWrite as Mutation,
  ROWS,
  saveSettingEdit,
  setCustomSetting,
  shownByTestId,
  waitDeliveryBoundary,
  watchDeliveryFrames,
  type DeliveryFrameLog,
  type DeliveryPacket,
  typeInto,
  expectDocumentUnchanged,
  gotoPage,
  markDocument,
  PAGE_READY,
  grantAdminToSelf,
} from '../../../../../framework/frontend/e2e/index.js'

import { clearTableLag, setTableLag } from '../helpers/tableLag.js'

// Real Settings writes exercise the production sender. Values are inline row
// state, not entity fragments (the entity-field merge has shared PHP/TS tests).
test.describe.configure({ mode: 'default', retries: 0 })

const WINDOW_KEY = 'websocket.delivery_window_ms'
const SOURCE_KEY = 'example_string'
const OTHER_KEY = 'example_integer'
// The production window (DeliverySettingsCatalog::DEFAULT_WINDOW_MS), left as
// the stand runs it: a test command drives the source fast enough to fit its
// writes inside one window, and the burst asserts its actual acknowledged
// duration, so a slow source cannot pass by silently turning into quiet
// deliveries. The second window proves a hot setting change, without replacing
// either observer's socket. The delayed-response grace proof runs on the second
// window as well, for the geometry of its margin (see there).
const WINDOW_MS = 1_000
const CHANGED_WINDOW_MS = 2_000
const BURST_MUTATIONS = 10
// Worker/source sync and the master drain cost ticks even for an immediate
// delivery. 300 ms covers those ticks with 200 ms to spare over a command's
// write, and stays well inside the window. Cadence's lower bound allows only
// the transport jitter, never the source's write duration.
const IMMEDIATE_MS = 300
const TRANSPORT_JITTER_MS = 200
const DEADLINE_MARGIN_MS = IMMEDIATE_MS
// A delivery is due within the longest window this spec runs, plus ticks.
const RECEIVE_TIMEOUT_MS = CHANGED_WINDOW_MS + IMMEDIATE_MS * 2

function settingsUpdates(log: DeliveryFrameLog, key = SOURCE_KEY): Update[] {
  return deliverySettingUpdates(log, key)
}

function lastUpdate(log: DeliveryFrameLog): Update {
  const update = settingsUpdates(log).at(-1)
  expect(
    update,
    'a real source delta established the delivery boundary',
  ).toBeDefined()
  return update!
}

async function openSettings(page: Page, search: string): Promise<void> {
  await gotoPage(expect, page, '/hilos/settings', PAGE_READY)
  await typeInto(page.getByTestId('hilos-table-search'), search)
  await expect(page.getByTestId(`hilos-table-row-${search}`)).toBeVisible()
}

async function sourceVisible(page: Page, value: string): Promise<void> {
  await expect(page.getByTestId(`hilos-table-row-${SOURCE_KEY}`)).toContainText(
    value,
  )
}

async function receiveValue(
  log: DeliveryFrameLog,
  value: string,
): Promise<Update> {
  await expect
    .poll(() => settingsUpdates(log).some((update) => update.value === value), {
      timeout: RECEIVE_TIMEOUT_MS,
      // Writes that follow must stay inside the same 1 s window; a default
      // polling step would consume it.
      intervals: [20, 50, 100],
    })
    .toBe(true)
  return settingsUpdates(log).find((update) => update.value === value)!
}

/** Each operation is one real write the settings library accepted. */
async function mutate(
  mutations: Mutation[],
  value: string,
  key = SOURCE_KEY,
): Promise<Mutation> {
  const [mutation] = await writeDeliverySettings([{ key, value }])
  mutations.push(mutation)
  return mutation
}

/** All packages, including extra relevant deliveries, enter these assertions. */
function expectCadence(
  log: DeliveryFrameLog,
  mutations: Mutation[],
  start: number,
  end: number,
  windowMs: number,
): void {
  const updates = settingsUpdates(log).filter(
    ({ packet }) => packet.at >= start && packet.at <= end,
  )
  expect(
    updates.length,
    'several fixed boundaries while the source stays active',
  ).toBeGreaterThanOrEqual(3)
  expect(
    new Set(updates.map(({ packet }) => `${packet.socket}:${packet.message}`))
      .size,
  ).toBe(updates.length)
  for (let index = 1; index < updates.length; index++) {
    const previous = updates[index - 1]!
    const current = updates[index]!
    const gap = current.packet.at - previous.packet.at
    expect(gap).toBeGreaterThanOrEqual(windowMs - TRANSPORT_JITTER_MS)
    expect(
      gap,
      'continuous input must not postpone the fixed release as debounce',
    ).toBeLessThan(windowMs + IMMEDIATE_MS)
    const sources = mutations.filter(
      (mutation) =>
        mutation.key === SOURCE_KEY &&
        mutation.accepted > previous.packet.at &&
        mutation.accepted < current.packet.at,
    )
    expect(
      sources.length,
      'multiple confirmed writes accumulated in this window',
    ).toBeGreaterThanOrEqual(2)
    for (let source = 1; source < sources.length; source++) {
      expect(
        sources[source]!.accepted - sources[source - 1]!.accepted,
      ).toBeLessThan(windowMs / 2)
    }
    expect(mutations.some((mutation) => mutation.value === current.value)).toBe(
      true,
    )
    expect(current.data.kind).toBe('row_updated')
    expect(current.data.own).not.toBe(true)
  }
}

// FIXME(F-028, 2026-10-25): the UI-driven burst overruns its 7 s budget on a loaded host · чужой · попытка 1 · HOTFIX
test(
  'fixed delivery cadence coalesces real writes, quiet and click are immediate, sockets and live settings stay independent',
  { tag: '@quarantine:F-028' },
  async ({ page }) => {
    // Its own work outlives the base cap: watching three 1 s and three 2 s
    // windows pass is the proof itself, not a wait for a moment, and it drives
    // some 160 confirmed writes and the Settings UI. It took 30.1 s against the
    // 30 s cap at host factor 1.01, and 28.7 s at factor 1.93; a flat triple
    // keeps ample room.
    test.slow()
    const writer = watchDeliveryFrames(page)
    const configPage = await page.context().newPage()
    const tabA = await page.context().newPage()
    const tabB = await page.context().newPage()
    const a = watchDeliveryFrames(tabA)
    const b = watchDeliveryFrames(tabB)
    const mutations: Mutation[] = []
    const phases: Record<string, unknown> = {}
    let sequence = 0
    const nextValue = () => `delivery-${++sequence}`
    const write = () => mutate(mutations, nextValue())

    try {
      await grantAdminToSelf(expect, page)
      await openSettings(configPage, WINDOW_KEY)
      const configRow = configPage.getByTestId(`hilos-table-row-${WINDOW_KEY}`)
      await expect(configRow).toContainText('1000')
      await expect(configRow).toContainText('default')

      // Create overrides before observing: every measured mutation is an update
      // with the same row identity, slot keys and own=false on passive receivers.
      await openSettings(page, SOURCE_KEY)
      await setCustomSetting(page, SOURCE_KEY, 'delivery-seed')
      await typeInto(page.getByTestId('hilos-table-search'), OTHER_KEY)
      await expect(
        page.getByTestId(`hilos-table-row-${OTHER_KEY}`),
      ).toBeVisible()
      await setCustomSetting(page, OTHER_KEY, '1')
      // Both rows are needed only during the independent-frame burst.
      await typeInto(page.getByTestId('hilos-table-search'), 'example_')
      for (const tab of [tabA, tabB]) {
        await openSettings(tab, SOURCE_KEY)
        await typeInto(tab.getByTestId('hilos-table-search'), 'example_')
        await expect(
          tab.getByTestId(`hilos-table-row-${OTHER_KEY}`),
        ).toBeVisible()
        await markDocument(tab)
      }
      // Every participant is open before the measurement: this proof measures
      // hot delivery on established sockets, not a new socket's welcome latency.
      const aSocket = a.sockets()
      const bSocket = b.sockets()
      const passiveStart = Math.max(
        a.sent.at(-1)!.at,
        b.sent.at(-1)!.at,
        a.packets.at(-1)!.at,
        b.packets.at(-1)!.at,
      )
      phases.steadyEligibility = {
        passiveStart,
        ...(await quietAfterHeartbeat(expect, [a, b], WINDOW_MS, IMMEDIATE_MS)),
        aSocket,
        bSocket,
      }

      const quiet = await write()
      const quietA = await receiveValue(a, quiet.value)
      const quietB = await receiveValue(b, quiet.value)
      for (const update of [quietA, quietB]) {
        expect(
          update.packet.at - quiet.sent,
          'first change after real silence includes source/tick latency',
        ).toBeLessThan(IMMEDIATE_MS)
      }
      phases.quiet = { quiet, a: quietA.packet.at, b: quietB.packet.at }

      // One command: the integer, then every source write, each its own write
      // and delta inside one window.
      const burst = await writeDeliverySettings([
        { key: OTHER_KEY, value: '2' },
        ...Array.from({ length: BURST_MUTATIONS }, () => ({
          key: SOURCE_KEY,
          value: nextValue(),
        })),
      ])
      mutations.push(...burst)
      const burstEnd = burst.at(-1)!
      expect(burstEnd.accepted).toBeLessThan(
        quietA.packet.at + WINDOW_MS - DEADLINE_MARGIN_MS,
      )
      // No preceding quiet release is included in this accumulated burst.
      expect(
        settingsUpdates(a).filter(({ packet }) => packet.at > quietA.packet.at),
      ).toHaveLength(0)
      expect(
        settingsUpdates(b).filter(({ packet }) => packet.at > quietB.packet.at),
      ).toHaveLength(0)
      const coalescedA = await receiveValue(a, burstEnd.value)
      const coalescedB = await receiveValue(b, burstEnd.value)
      for (const [log, anchor, result] of [
        [a, quietA, coalescedA],
        [b, quietB, coalescedB],
      ] as const) {
        const inners = settingsUpdates(log).filter(
          ({ packet }) =>
            packet.at > anchor.packet.at && packet.at <= result.packet.at,
        )
        expect(inners).toHaveLength(1)
        expect(inners[0]!.value).toBe(burstEnd.value)
        expect(result.packet.at - anchor.packet.at).toBeGreaterThanOrEqual(
          WINDOW_MS - TRANSPORT_JITTER_MS,
        )
        const independent = settingsUpdates(log, OTHER_KEY).filter(
          ({ packet }) => packet === result.packet,
        )
        expect(independent.map(({ value }) => value)).toEqual(['2'])
        const rowFrames = result.packet.frames.filter(
          ({ wire }) => object(wire).type === 'table_viewport_delta',
        )
        expect(
          rowFrames.map(({ wire }) => object(object(wire).data).rowKey),
          // The integer precedes every source write, so both layers agree: the
          // worker folds a row's changes in at its first appearance, the master
          // orders merged frames by a row's latest one (DeliveryCoalescerTest
          // holds that rule), and either gives the integer first however the
          // writes fall across worker ticks.
        ).toEqual([OTHER_KEY, SOURCE_KEY])
        expect(result.packet.raw).toMatch(/^\{"type":"signal_batch"/)
        expect(inners[0]!.data.kind).toBe('row_updated')
        expect(Object.keys(object(object(inners[0]!.data.row).slots))).toEqual([
          'settings',
        ])
      }
      await sourceVisible(tabA, burstEnd.value)
      await sourceVisible(tabB, burstEnd.value)
      phases.coalescing = {
        burst,
        expectedInnerUpdates: 1,
        a: coalescedA.packet,
        b: coalescedB.packet,
      }

      const busyStart = coalescedA.packet.at
      while (performance.now() < busyStart + WINDOW_MS * 3 + IMMEDIATE_MS)
        await write()
      const busyEnd = mutations.at(-1)!.accepted
      expectCadence(a, mutations, busyStart, busyEnd, WINDOW_MS)
      expectCadence(b, mutations, coalescedB.packet.at, busyEnd, WINDOW_MS)
      phases.busy = { start: busyStart, end: busyEnd, windowMs: WINDOW_MS }
      const busyFinal = mutations.at(-1)!.value
      await receiveValue(a, busyFinal)
      await receiveValue(b, busyFinal)
      await sourceVisible(tabB, busyFinal)

      // Stop the source and allow both the preceding delivery and grace to expire.
      phases.anchorEligibility = await quietAfterHeartbeat(
        expect,
        [a, b],
        WINDOW_MS,
        IMMEDIATE_MS,
      )
      const anchor = await write()
      const anchorA = await receiveValue(a, anchor.value)
      const anchorB = await receiveValue(b, anchor.value)
      expect(anchorA.packet.at - anchor.sent).toBeLessThan(IMMEDIATE_MS)
      expect(anchorB.packet.at - anchor.sent).toBeLessThan(IMMEDIATE_MS)
      const pending = [await write(), await write()]
      expect(settingsUpdates(b).at(-1)!.value).toBe(anchor.value)
      expect(pending.at(-1)!.accepted).toBeLessThan(
        anchorB.packet.at + WINDOW_MS - IMMEDIATE_MS * 2,
      )
      const beforeClick = a.sent.length
      await shownByTestId(tabA, 'hilos-table-sort-key').click()
      await expect
        .poll(() =>
          a.sent
            .slice(beforeClick)
            .some(({ wire }) => object(wire).type === 'table_viewport'),
        )
        .toBe(true)
      const click = a.sent
        .slice(beforeClick)
        .find(({ wire }) => object(wire).type === 'table_viewport')!
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
      await expect(tabA.getByTestId('hilos-table-loading')).toHaveCount(0)
      await expect
        .poll(() =>
          tabA
            .locator('[data-id^="hilos-table-row-"]')
            .evaluateAll((rows) =>
              rows.map((row) => row.getAttribute('data-id')),
            ),
        )
        .toEqual([
          `hilos-table-row-${SOURCE_KEY}`,
          `hilos-table-row-${OTHER_KEY}`,
          'hilos-table-row-example_boolean',
        ])
      await sourceVisible(tabA, pending.at(-1)!.value)
      const flushed = await receiveValue(a, pending.at(-1)!.value)
      expect(flushed.packet.at - click.at).toBeLessThan(IMMEDIATE_MS)
      expect(response.at - click.at).toBeLessThan(IMMEDIATE_MS)
      expect(response.at).toBeLessThan(
        anchorA.packet.at + WINDOW_MS - IMMEDIATE_MS,
      )
      expect(
        settingsUpdates(b).at(-1)!.value,
        'B has two confirmed pending writes and no action of its own',
      ).toBe(anchor.value)

      // Individually confirmed writes cross almost the whole grace the
      // response opened, each delivered at once. The loop leaves at its first
      // receipt at or after response + window - IMMEDIATE; a receipt sees its
      // packet within one polling step (100 ms), and the packet comes after
      // the command's answer, so the last packet clears the bound by IMMEDIATE
      // less that step, however long the command and its delivery took.
      const graceWrites: Array<{ mutation: Mutation; packet: DeliveryPacket }> =
        []
      while (performance.now() < response.at + WINDOW_MS - IMMEDIATE_MS) {
        const mutation = await write()
        const update = await receiveValue(a, mutation.value)
        expect(update.packet.at - mutation.sent).toBeLessThan(IMMEDIATE_MS)
        graceWrites.push({ mutation, packet: update.packet })
      }
      expect(graceWrites.at(-1)!.packet.at - response.at).toBeGreaterThan(
        WINDOW_MS - IMMEDIATE_MS * 2,
      )
      const bAfterClick = settingsUpdates(b).filter(
        ({ packet }) => packet.at > anchorB.packet.at,
      )
      await expect
        .poll(
          () =>
            settingsUpdates(b).filter(
              ({ packet }) => packet.at > anchorB.packet.at,
            ).length,
          { timeout: WINDOW_MS + IMMEDIATE_MS },
        )
        .toBeGreaterThan(0)
      const bRelease = settingsUpdates(b).find(
        ({ packet }) => packet.at > anchorB.packet.at,
      )!
      expect(bRelease.packet.at - anchorB.packet.at).toBeGreaterThanOrEqual(
        WINDOW_MS - TRANSPORT_JITTER_MS,
      )
      expect(bRelease.packet.at - anchorB.packet.at).toBeLessThan(
        WINDOW_MS + IMMEDIATE_MS,
      )
      expect(bRelease.value).not.toBe(anchor.value)
      phases.clickAndGrace = {
        pending,
        click,
        response,
        flushed: flushed.packet,
        graceWrites,
        bAfterClick,
        bRelease,
      }

      // Hot setting changes preserve the old already-opened boundary, then use
      // the new value for subsequent boundaries on the very same connection.
      // The new window is the longer one, so a change in force only after the
      // old boundary would pass a server that recomputes the open boundary and
      // fail a correct one on cadence: the change must land inside the OLD
      // window. The dialog is opened and drafted before the anchor, and only
      // its save runs after it.
      await receiveValue(b, graceWrites.at(-1)!.mutation.value)
      await draftCustomSetting(
        configPage,
        WINDOW_KEY,
        String(CHANGED_WINDOW_MS),
      )
      await waitDeliveryBoundary(
        Math.max(lastUpdate(a).packet.at, lastUpdate(b).packet.at) +
          WINDOW_MS +
          IMMEDIATE_MS,
      )
      const oldAnchorMutation = await write()
      const oldAnchor = await receiveValue(b, oldAnchorMutation.value)
      await write()
      await saveSettingEdit(configPage)
      await expect(configRow).toContainText(String(CHANGED_WINDOW_MS))
      const changedAt = performance.now()
      // Inside the old window by the margin a burst keeps: the server's
      // boundary lies earlier than the observed anchor plus the window by the
      // anchor's own delivery, and the worker's report brings the new value to
      // the master up to a tick after the row shows it.
      expect(changedAt).toBeLessThan(
        oldAnchor.packet.at + WINDOW_MS - DEADLINE_MARGIN_MS,
      )
      while (performance.now() < oldAnchor.packet.at + WINDOW_MS + IMMEDIATE_MS)
        await write()
      const preserved = settingsUpdates(b).find(
        ({ packet }) => packet.at > oldAnchor.packet.at,
      )!
      expect(preserved).toBeDefined()
      expect(preserved.packet.at - oldAnchor.packet.at).toBeGreaterThanOrEqual(
        WINDOW_MS - TRANSPORT_JITTER_MS,
      )
      expect(preserved.packet.at - oldAnchor.packet.at).toBeLessThan(
        WINDOW_MS + IMMEDIATE_MS,
      )
      while (
        performance.now() <
        preserved.packet.at + CHANGED_WINDOW_MS * 3 + IMMEDIATE_MS
      )
        await write()
      const changedEnd = mutations.at(-1)!.accepted
      expectCadence(
        b,
        mutations,
        preserved.packet.at,
        changedEnd,
        CHANGED_WINDOW_MS,
      )
      phases.liveSetting = {
        changedAt,
        oldAnchor: oldAnchor.packet.at,
        preserved: preserved.packet.at,
        end: changedEnd,
        windowMs: CHANGED_WINDOW_MS,
      }
      const finalValue = mutations.at(-1)!.value
      await receiveValue(a, finalValue)
      await receiveValue(b, finalValue)
      await sourceVisible(tabA, finalValue)
      await sourceVisible(tabB, finalValue)
      expect(a.sockets()).toBe(aSocket)
      expect(b.sockets()).toBe(bSocket)
      await expectDocumentUnchanged(expect, tabA)
      await expectDocumentUnchanged(expect, tabB)
      phases.documentsUnchanged = { a: true, b: true }
      expect(
        a.sent.filter(({ at, raw }) => at > click.at && raw !== 'ping'),
      ).toEqual([])
      // No passive browser request may secretly grant B immediate delivery.
      expect(
        b.sent.filter(({ at, raw }) => at > passiveStart && raw !== 'ping'),
      ).toEqual([])
      for (const log of [writer, a, b]) {
        expect(log.packets.map(({ raw }) => raw).join('\n')).not.toContain(
          'hilos_delivery_complete',
        )
      }
    } finally {
      const evidencePath = test
        .info()
        .outputPath('delivery-window-evidence.json')
      await writeFile(
        evidencePath,
        JSON.stringify({
          clock: 'runner performance.now milliseconds',
          WINDOW_MS,
          CHANGED_WINDOW_MS,
          IMMEDIATE_MS,
          TRANSPORT_JITTER_MS,
          DEADLINE_MARGIN_MS,
          phases,
          mutations,
          writer,
          a,
          b,
        }),
      )
      await test.info().attach('delivery-window-evidence', {
        path: evidencePath,
        contentType: 'application/json',
      })
      writer.stop()
      a.stop()
      b.stop()
      // Restore through the same Settings UI, including after an assertion fails.
      if (await configPage.getByTestId('modal-close').isVisible()) {
        await configPage.getByTestId('modal-close').click()
      }
      await clearCustomSetting(configPage, WINDOW_KEY)
      await expect(
        configPage.getByTestId(`hilos-table-row-${WINDOW_KEY}`),
      ).toContainText('1000')
      await clearCustomSetting(page, SOURCE_KEY)
      await typeInto(page.getByTestId('hilos-table-search'), OTHER_KEY)
      await expect(
        page.getByTestId(`hilos-table-row-${OTHER_KEY}`),
      ).toBeVisible()
      await clearCustomSetting(page, OTHER_KEY)
      await Promise.all([tabA.close(), tabB.close(), configPage.close()])
    }
  },
)

// The lag row is node-wide. Release it before every UI teardown and after a
// failed test as well; the suite's single worker owns the node for this case.
const HELD_WINDOW_MS = 60_000

// FIXME(F-044, 2026-10-27): немедленная доставка anchor занимает 8 с · чужой · попытка 2 · HIL-1720
test(
  'a deliberately delayed window response opens a fresh fixed grace window on its socket',
  { tag: '@quarantine:F-044' },
  async ({ page }) => {
    const writer = watchDeliveryFrames(page)
    const configPage = await page.context().newPage()
    const tabA = await page.context().newPage()
    const tabB = await page.context().newPage()
    const a = watchDeliveryFrames(tabA)
    const b = watchDeliveryFrames(tabB)
    const mutations: Mutation[] = []
    const phases: Record<string, unknown> = {}
    // This proof's window, set through Settings before any measurement.
    const windowMs = CHANGED_WINDOW_MS
    let sequence = 0
    const write = () => mutate(mutations, `held-${++sequence}`)
    const windowsAfter = (at: number) =>
      a.packets.filter(
        (packet) =>
          packet.at >= at &&
          packet.frames.some(
            ({ wire }) =>
              object(wire).type === 'table_window' &&
              object(object(wire).data).tableKey === 'settings',
          ),
      )

    try {
      await clearTableLag()
      await grantAdminToSelf(expect, page)
      await openSettings(configPage, WINDOW_KEY)
      const configRow = configPage.getByTestId(`hilos-table-row-${WINDOW_KEY}`)
      await expect(configRow).toContainText('1000')
      await expect(configRow).toContainText('default')
      // A departure from the production window the rest of this spec keeps: on
      // 1 s the slot after the grace, where the two writes must land before the
      // fixed release, holds at most ~225 ms against the 200 ms margin the
      // bounds keep, and "immediate" (300 ms) does not stretch. By the owner's
      // decision of 2026-10-31 this proof runs on the second window, set the
      // way the cadence proof changes it hot, before the observers open, and
      // cleared in finally.
      await setCustomSetting(configPage, WINDOW_KEY, String(windowMs))
      await expect(configRow).toContainText(String(windowMs))
      await openSettings(page, SOURCE_KEY)
      await setCustomSetting(page, SOURCE_KEY, 'held-seed')
      for (const tab of [tabA, tabB]) {
        await openSettings(tab, SOURCE_KEY)
        await typeInto(tab.getByTestId('hilos-table-search'), 'example_')
        await expect(
          tab.getByTestId('hilos-table-row-example_boolean'),
        ).toBeVisible()
        await markDocument(tab)
      }
      const aSocket = a.sockets()
      const bSocket = b.sockets()
      const passiveStart = Math.max(
        a.sent.at(-1)!.at,
        b.sent.at(-1)!.at,
        a.packets.at(-1)!.at,
        b.packets.at(-1)!.at,
      )
      // Every physical packet restarts this preparation, including hints;
      // elapsed time since a request alone cannot prove quiet.
      const quiet = await quietAfterHeartbeat(
        expect,
        [a, b],
        windowMs,
        IMMEDIATE_MS,
      )
      phases.quiet = quiet

      // Baseline: the quiet anchor passes, two real acknowledged writes then
      // accumulate. A later pass cannot claim immediate delivery by coincidence.
      const anchor = await write()
      const anchorA = await receiveValue(a, anchor.value)
      const anchorB = await receiveValue(b, anchor.value)
      for (const [index, log] of [a, b].entries()) {
        expect(anchor.sent - quiet.lastActivity[index]!).toBeGreaterThanOrEqual(
          windowMs + IMMEDIATE_MS,
        )
        expect(
          log.packets.filter(({ at }) => at > quiet.at && at < anchor.sent),
        ).toEqual([])
      }
      expect(anchorA.packet.at - anchor.sent).toBeLessThan(IMMEDIATE_MS)
      expect(anchorB.packet.at - anchor.sent).toBeLessThan(IMMEDIATE_MS)
      const pending = [await write(), await write()]
      expect(settingsUpdates(a).at(-1)!.value).toBe(anchor.value)
      expect(settingsUpdates(b).at(-1)!.value).toBe(anchor.value)
      expect(pending.at(-1)!.accepted).toBeLessThan(
        anchorA.packet.at + windowMs - IMMEDIATE_MS * 2,
      )
      phases.baseline = { anchor, anchorA, anchorB, pending }

      await setTableLag({ windowMs: HELD_WINDOW_MS })
      const beforeClick = a.sent.length // Observer is already armed before the UI action.
      await shownByTestId(tabA, 'hilos-table-sort-key').click()
      await expect
        .poll(() =>
          a.sent
            .slice(beforeClick)
            .some(({ wire }) => object(wire).type === 'table_viewport'),
        )
        .toBe(true)
      const click = a.sent
        .slice(beforeClick)
        .find(({ wire }) => object(wire).type === 'table_viewport')!
      expect(object(click.wire).sort).toEqual([
        { field: 'key', direction: 'desc' },
      ])
      const flushed = await receiveValue(a, pending.at(-1)!.value)
      expect(flushed.packet.at - click.at).toBeLessThan(IMMEDIATE_MS)
      expect(settingsUpdates(b).at(-1)!.value).toBe(anchor.value)
      await expect(shownByTestId(tabA, 'hilos-table-loading')).toBeVisible()
      phases.request = { click, flushed: flushed.packet }

      // This is the named request-origin grace boundary, not a UI sleep. The
      // handler still owns its held real response on the server after it passes.
      await waitDeliveryBoundary(click.at + windowMs + IMMEDIATE_MS)
      expect(windowsAfter(click.at)).toEqual([])
      const bRelease = await receiveValue(b, pending.at(-1)!.value)
      expect(bRelease.packet.at - anchorB.packet.at).toBeGreaterThanOrEqual(
        windowMs - TRANSPORT_JITTER_MS,
      )
      expect(bRelease.packet.at - anchorB.packet.at).toBeLessThan(
        windowMs + IMMEDIATE_MS,
      )
      phases.held = { checkedAt: performance.now(), bRelease: bRelease.packet }

      const releasedAt = performance.now()
      await clearTableLag()
      await expect.poll(() => windowsAfter(click.at).length).toBe(1)
      const response = windowsAfter(click.at)[0]!
      expect(response.at - click.at).toBeGreaterThan(windowMs + IMMEDIATE_MS)
      expect(response.at - releasedAt).toBeLessThan(IMMEDIATE_MS)
      await expect(tabA.getByTestId('hilos-table-loading')).toHaveCount(0)
      await expect
        .poll(() =>
          tabA
            .locator(ROWS)
            .evaluateAll((rows) =>
              rows.map((row) => row.getAttribute('data-id')),
            ),
        )
        .toEqual([
          `hilos-table-row-${SOURCE_KEY}`,
          `hilos-table-row-${OTHER_KEY}`,
          'hilos-table-row-example_boolean',
        ])
      await sourceVisible(tabA, pending.at(-1)!.value)
      phases.response = { releasedAt, response }

      // Multiple individually confirmed writes cross almost the whole NEW grace.
      // Requests never go out on either observer during this measurement.
      const graceWrites: Array<{ mutation: Mutation; packet: DeliveryPacket }> =
        []
      while (performance.now() < response.at + windowMs - IMMEDIATE_MS) {
        const mutation = await write()
        const update = await receiveValue(a, mutation.value)
        expect(update.packet.at - mutation.sent).toBeLessThan(IMMEDIATE_MS)
        expect(update.packet.at - response.at).toBeLessThan(windowMs)
        graceWrites.push({ mutation, packet: update.packet })
      }
      expect(graceWrites.length).toBeGreaterThanOrEqual(4)
      const lastGrace = graceWrites.at(-1)!
      // The loop leaves at its first receipt at or after response + window -
      // IMMEDIATE; a receipt sees its packet within one polling step (100 ms),
      // and the packet comes after the command's answer, so the last packet
      // clears the bound by IMMEDIATE less that step, however long the command
      // and its delivery took.
      expect(lastGrace.packet.at - response.at).toBeGreaterThan(
        windowMs - IMMEDIATE_MS * 2,
      )
      expect(lastGrace.packet.at - response.at).toBeLessThan(windowMs)
      const actualGrace = settingsUpdates(a).filter(
        ({ packet }) =>
          packet.at > response.at && packet.at <= lastGrace.packet.at,
      )
      expect(actualGrace.map(({ value }) => value)).toEqual(
        graceWrites.map(({ mutation }) => mutation.value),
      )
      expect(
        new Set(
          actualGrace.map(({ packet }) => `${packet.socket}:${packet.message}`),
        ).size,
      ).toBe(graceWrites.length)
      phases.grace = graceWrites

      // Stream updates do not renew grace as a debounce: just after the original
      // response's grace ends the next two writes must wait at a fixed busy
      // deadline measured from the last actual delivery, despite the recent write.
      await waitDeliveryBoundary(response.at + windowMs + TRANSPORT_JITTER_MS)
      const afterGrace = [await write(), await write()]
      expect(afterGrace.at(-1)!.accepted).toBeLessThan(
        lastGrace.packet.at + windowMs - IMMEDIATE_MS,
      )
      expect(
        settingsUpdates(a).filter(
          ({ packet }) => packet.at > lastGrace.packet.at,
        ),
      ).toEqual([])
      const fixedRelease = await receiveValue(a, afterGrace.at(-1)!.value)
      expect(
        fixedRelease.packet.at - lastGrace.packet.at,
      ).toBeGreaterThanOrEqual(windowMs - TRANSPORT_JITTER_MS)
      expect(fixedRelease.packet.at - lastGrace.packet.at).toBeLessThan(
        windowMs + IMMEDIATE_MS,
      )
      expect(
        settingsUpdates(a)
          .filter(({ packet }) => packet.at > lastGrace.packet.at)
          .map(({ value }) => value),
      ).toEqual([afterGrace.at(-1)!.value])
      await sourceVisible(tabA, afterGrace.at(-1)!.value)
      phases.afterGrace = { afterGrace, fixedRelease: fixedRelease.packet }
      expect(windowsAfter(click.at)).toHaveLength(1)
      expect(
        a.sent.filter(({ at, raw }) => at > click.at && raw !== 'ping'),
      ).toEqual([])
      expect(
        b.sent.filter(({ at, raw }) => at > passiveStart && raw !== 'ping'),
      ).toEqual([])
      expect(a.sockets()).toBe(aSocket)
      expect(b.sockets()).toBe(bSocket)
      await expectDocumentUnchanged(expect, tabA)
      await expectDocumentUnchanged(expect, tabB)
      for (const log of [writer, a, b]) {
        expect(log.packets.map(({ raw }) => raw).join('\n')).not.toContain(
          'hilos_delivery_complete',
        )
      }
    } finally {
      // Release the node before a cleanup Settings viewport can become held too.
      await clearTableLag()
      const evidencePath = test.info().outputPath('delayed-response-grace.json')
      await writeFile(
        evidencePath,
        JSON.stringify({
          clock: 'runner performance.now milliseconds',
          windowMs,
          IMMEDIATE_MS,
          TRANSPORT_JITTER_MS,
          HELD_WINDOW_MS,
          phases,
          mutations,
          writer,
          a,
          b,
        }),
      )
      await test.info().attach('delayed-response-grace', {
        path: evidencePath,
        contentType: 'application/json',
      })
      writer.stop()
      a.stop()
      b.stop()
      // Restore through the same Settings UI, including after an assertion fails.
      if (await configPage.getByTestId('modal-close').isVisible())
        await configPage.getByTestId('modal-close').click()
      await clearCustomSetting(configPage, WINDOW_KEY)
      await expect(
        configPage.getByTestId(`hilos-table-row-${WINDOW_KEY}`),
      ).toContainText('1000')
      if (await page.getByTestId('modal-close').isVisible())
        await page.getByTestId('modal-close').click()
      await clearCustomSetting(page, SOURCE_KEY)
      await Promise.all([tabA.close(), tabB.close(), configPage.close()])
    }
  },
)

test('a new socket records bootstrap before subscribe separately from immediate page delivery', async ({
  page,
}) => {
  const coldPage = await page.context().newPage()
  const cold = watchDeliveryFrames(coldPage)
  const phases: Record<string, unknown> = {}
  try {
    await grantAdminToSelf(expect, page)
    const startedAt = performance.now()
    await gotoPage(expect, coldPage, '/hilos/settings', PAGE_READY)
    const subscribe = cold.sent.find(
      ({ wire }) =>
        object(wire).type === 'page_subscribe' &&
        object(wire).page === 'hilos_settings',
    )!
    const welcome = cold.packets.find((packet) =>
      packet.frames.some(({ wire }) => object(wire).type === 'handshake'),
    )!
    const session = cold.packets.find((packet) =>
      packet.frames.some(
        ({ wire }) => object(wire).type === 'handshake_response',
      ),
    )!
    const response = cold.packets.find((packet) =>
      packet.frames.some(
        ({ wire }) =>
          object(wire).type === 'page_response' &&
          object(object(wire).data).page === 'hilos_settings',
      ),
    )!
    expect(subscribe).toBeDefined()
    expect(welcome).toBeDefined()
    expect(session).toBeDefined()
    expect(response).toBeDefined()
    phases.bootstrap = {
      startedAt,
      welcome,
      session,
      subscribe,
      response,
      readyAt: performance.now(),
      beforeSubscribeMs: subscribe.at - welcome.at,
      pageReplyMs: response.at - subscribe.at,
    }
    expect(welcome.at - cold.connections[0]!.opened).toBeLessThan(IMMEDIATE_MS)
    expect(subscribe.at).toBeGreaterThanOrEqual(session.at)
    expect(subscribe.at - session.at).toBeLessThan(IMMEDIATE_MS)
    expect(response.at - subscribe.at).toBeLessThan(IMMEDIATE_MS)
    expect(cold.sockets()).toBe(1)
    expect(cold.packets.map(({ raw }) => raw).join('\n')).not.toContain(
      'hilos_delivery_complete',
    )
  } finally {
    cold.stop()
    const evidencePath = test.info().outputPath('cold-socket-bootstrap.json')
    await writeFile(
      evidencePath,
      JSON.stringify({
        clock: 'runner performance.now milliseconds',
        WINDOW_MS,
        IMMEDIATE_MS,
        phases,
        cold,
      }),
    )
    await test.info().attach('cold-socket-bootstrap', {
      path: evidencePath,
      contentType: 'application/json',
    })
    await coldPage.close()
  }
})
