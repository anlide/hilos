import { writeFile } from 'node:fs/promises'
import { test, expect, type Page } from '@playwright/test'

import {
  clearCustomSetting,
  deliveryObject as object,
  deliverySettingUpdates,
  saveDeliverySetting,
  type DeliverySettingUpdate as Update,
  type DeliverySettingMutation as Mutation,
  ROWS,
  setCustomSetting,
  shownByTestId,
  waitDeliveryBoundary,
  watchDeliveryFrames,
  type DeliveryFrameLog,
  type DeliveryPacket,
} from '../../../../../framework/frontend/e2e/index.js'
import { grantAdminToSelf } from '../helpers/adminGrant.js'
import {
  expectDocumentUnchanged,
  gotoPage,
  markDocument,
  PAGE_READY,
} from '../helpers/page.js'
import { typeInto } from '../helpers/session.js'
import { clearTableLag, setTableLag } from '../helpers/tableLag.js'

// Real Settings writes exercise the production sender. Values are inline row
// state, not entity fragments (the entity-field merge has shared PHP/TS tests).
test.describe.configure({ mode: 'default', retries: 0 })

const WINDOW_KEY = 'websocket.delivery_window_ms'
const SOURCE_KEY = 'example_string'
const OTHER_KEY = 'example_integer'
// Eight seconds accommodates ten keyboard/modal/save round trips on this stand.
// The burst asserts its actual acknowledged duration; a slow source cannot pass
// by silently turning into ten quiet deliveries. The second window proves a hot
// setting change, without replacing either observer's socket.
const WINDOW_MS = 8_000
const CHANGED_WINDOW_MS = 4_000
const BURST_MUTATIONS = 10
// Worker/source sync and the master drain cost ticks even for an immediate
// delivery. One second includes those ticks plus 200 ms transport jitter, and
// remains much smaller than either configured window. Cadence's lower bound
// allows only the latter jitter, never the source's UI operation duration.
const IMMEDIATE_MS = 1_000
const TRANSPORT_JITTER_MS = 200
const DEADLINE_MARGIN_MS = IMMEDIATE_MS
const SETTINGS_TIMEOUT_FACTOR = 8

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
  await gotoPage(page, '/hilos/settings', PAGE_READY)
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
      timeout: WINDOW_MS + IMMEDIATE_MS * 2,
    })
    .toBe(true)
  return settingsUpdates(log).find((update) => update.value === value)!
}

/** Each operation has a real framesent and a correlated successful server ack. */
async function mutate(
  page: Page,
  log: DeliveryFrameLog,
  mutations: Mutation[],
  value: string,
  key = SOURCE_KEY,
): Promise<Mutation> {
  const mutation = await saveDeliverySetting(expect, page, log, key, value)
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

test('fixed delivery cadence coalesces real writes, quiet and click are immediate, sockets and live settings stay independent', async ({
  page,
}) => {
  // This scenario deliberately crosses multiple 8 s and 4 s boundaries, two
  // quiet eligibility boundaries and a full response-origin grace period.
  test.setTimeout(test.info().timeout * SETTINGS_TIMEOUT_FACTOR)
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
  const write = () => mutate(page, writer, mutations, nextValue())

  try {
    await grantAdminToSelf(page)
    await openSettings(configPage, WINDOW_KEY)
    const configRow = configPage.getByTestId(`hilos-table-row-${WINDOW_KEY}`)
    await expect(configRow).toContainText('1000')
    await expect(configRow).toContainText('default')

    // Create overrides before observing: every measured mutation is an update
    // with the same row identity, slot keys and own=false on passive receivers.
    await openSettings(page, SOURCE_KEY)
    await setCustomSetting(page, SOURCE_KEY, 'delivery-seed')
    await typeInto(page.getByTestId('hilos-table-search'), OTHER_KEY)
    await expect(page.getByTestId(`hilos-table-row-${OTHER_KEY}`)).toBeVisible()
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
    // Open every participant before changing the window: this proof measures
    // hot delivery on established sockets, not a new socket's welcome latency.
    await setCustomSetting(configPage, WINDOW_KEY, String(WINDOW_MS))
    await expect(configRow).toContainText(String(WINDOW_MS))
    await expect(configRow).toContainText('custom')
    const aSocket = a.sockets()
    const bSocket = b.sockets()
    const passiveStart = Math.max(
      a.sent.at(-1)!.at,
      b.sent.at(-1)!.at,
      a.packets.at(-1)!.at,
      b.packets.at(-1)!.at,
    )
    await waitDeliveryBoundary(passiveStart + WINDOW_MS + IMMEDIATE_MS)
    phases.steadyEligibility = {
      passiveStart,
      at: performance.now(),
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

    const burst: Mutation[] = []
    for (let index = 0; index < BURST_MUTATIONS; index++) {
      burst.push(await write())
      if (index === 0) {
        await mutate(page, writer, mutations, '2', OTHER_KEY)
      }
    }
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
        // The integer's last write precedes the string's final write. Replacing
        // earlier state preserves that order of the surviving source writes.
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
      sourceMutations: burst,
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
    await waitDeliveryBoundary(
      Math.max(lastUpdate(a).packet.at, lastUpdate(b).packet.at) +
        WINDOW_MS +
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

    const graceWrites: Mutation[] = []
    while (performance.now() < response.at + WINDOW_MS - IMMEDIATE_MS) {
      const mutation = await write()
      graceWrites.push(mutation)
      const update = await receiveValue(a, mutation.value)
      expect(update.packet.at - mutation.sent).toBeLessThan(IMMEDIATE_MS)
    }
    expect(graceWrites.at(-1)!.sent - response.at).toBeGreaterThan(
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
    await receiveValue(b, graceWrites.at(-1)!.value)
    await waitDeliveryBoundary(
      Math.max(lastUpdate(a).packet.at, lastUpdate(b).packet.at) +
        WINDOW_MS +
        IMMEDIATE_MS,
    )
    const oldAnchorMutation = await write()
    const oldAnchor = await receiveValue(b, oldAnchorMutation.value)
    await write()
    await setCustomSetting(configPage, WINDOW_KEY, String(CHANGED_WINDOW_MS))
    await expect(configRow).toContainText(String(CHANGED_WINDOW_MS))
    const changedAt = performance.now()
    expect(changedAt).toBeLessThan(oldAnchor.packet.at + CHANGED_WINDOW_MS)
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
    await expectDocumentUnchanged(tabA)
    await expectDocumentUnchanged(tabB)
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
    const evidencePath = test.info().outputPath('delivery-window-evidence.json')
    await writeFile(
      evidencePath,
      JSON.stringify({
        clock: 'runner performance.now milliseconds',
        WINDOW_MS,
        CHANGED_WINDOW_MS,
        IMMEDIATE_MS,
        TRANSPORT_JITTER_MS,
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
    await expect(page.getByTestId(`hilos-table-row-${OTHER_KEY}`)).toBeVisible()
    await clearCustomSetting(page, OTHER_KEY)
    await Promise.all([tabA.close(), tabB.close(), configPage.close()])
  }
})

test('logs overview keeps own state and response in one physical delivery', async ({
  page,
}) => {
  test.slow() // Includes the log owner's normal reporting tick.
  const log = watchDeliveryFrames(page)
  try {
    await grantAdminToSelf(page)
    await gotoPage(page, '/hilos/logs', PAGE_READY)
    await expect(page.getByTestId('hilos-logs-tile-daemon')).toBeVisible()
    const overview = log.packets.find((packet) =>
      packet.frames.some(
        ({ wire }) =>
          object(wire).type === 'page_response' &&
          object(object(wire).data).page === 'hilos_logs',
      ),
    )!
    expect(overview).toBeDefined()
    expect(
      overview.frames
        .map(({ wire }) => object(wire).type)
        .filter(
          (type) =>
            type === 'subscription_page_hilos_logs' || type === 'page_response',
        ),
    ).toEqual(['subscription_page_hilos_logs', 'page_response'])
    expect(overview.raw).toMatch(/^\{"type":"signal_batch"/)

    await expect(page.getByTestId('hilos-logs-tile-daemon')).toHaveText(
      /^\d+\s+·\s+\d/,
    )
    expect(
      new Set(
        overview.frames.map(({ socket, message }) => `${socket}:${message}`),
      ).size,
    ).toBe(1)
    expect(overview.frames.every(({ raw }) => raw === overview.raw)).toBe(true)
    expect(log.packets.map(({ raw }) => raw).join('\n')).not.toContain(
      'hilos_delivery_complete',
    )
  } finally {
    log.stop()
    const evidencePath = test
      .info()
      .outputPath('initial-logs-physical-deliveries.json')
    await writeFile(evidencePath, JSON.stringify(log))
    await test.info().attach('initial-logs-physical-deliveries', {
      path: evidencePath,
      contentType: 'application/json',
    })
  }
})

// The lag row is node-wide. Release it before every UI teardown and after a
// failed test as well; the suite's single worker owns the node for this case.
const HELD_WINDOW_MS = 60_000

test('a deliberately delayed window response opens a fresh fixed grace window on its socket', async ({
  page,
}) => {
  test.slow() // Crosses quiet, held-response, response-grace and post-grace boundaries.
  const writer = watchDeliveryFrames(page)
  const configPage = await page.context().newPage()
  const tabA = await page.context().newPage()
  const tabB = await page.context().newPage()
  const a = watchDeliveryFrames(tabA)
  const b = watchDeliveryFrames(tabB)
  const mutations: Mutation[] = []
  const phases: Record<string, unknown> = {}
  let sequence = 0
  const write = () => mutate(page, writer, mutations, `held-${++sequence}`)
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
    await grantAdminToSelf(page)
    await openSettings(configPage, WINDOW_KEY)
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
    await setCustomSetting(configPage, WINDOW_KEY, String(WINDOW_MS))
    await expect(
      configPage.getByTestId(`hilos-table-row-${WINDOW_KEY}`),
    ).toContainText(String(WINDOW_MS))
    const aSocket = a.sockets()
    const bSocket = b.sockets()
    const passiveStart = Math.max(
      a.sent.at(-1)!.at,
      b.sent.at(-1)!.at,
      a.packets.at(-1)!.at,
      b.packets.at(-1)!.at,
    )
    await waitDeliveryBoundary(passiveStart + WINDOW_MS + IMMEDIATE_MS)

    // Baseline: the quiet anchor passes, two real acknowledged writes then
    // accumulate. A later pass cannot claim immediate delivery by coincidence.
    const anchor = await write()
    const anchorA = await receiveValue(a, anchor.value)
    const anchorB = await receiveValue(b, anchor.value)
    expect(anchorA.packet.at - anchor.sent).toBeLessThan(IMMEDIATE_MS)
    expect(anchorB.packet.at - anchor.sent).toBeLessThan(IMMEDIATE_MS)
    const pending = [await write(), await write()]
    expect(settingsUpdates(a).at(-1)!.value).toBe(anchor.value)
    expect(settingsUpdates(b).at(-1)!.value).toBe(anchor.value)
    expect(pending.at(-1)!.accepted).toBeLessThan(
      anchorA.packet.at + WINDOW_MS - IMMEDIATE_MS * 2,
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
    await waitDeliveryBoundary(click.at + WINDOW_MS + IMMEDIATE_MS)
    expect(windowsAfter(click.at)).toEqual([])
    const bRelease = await receiveValue(b, pending.at(-1)!.value)
    expect(bRelease.packet.at - anchorB.packet.at).toBeGreaterThanOrEqual(
      WINDOW_MS - TRANSPORT_JITTER_MS,
    )
    expect(bRelease.packet.at - anchorB.packet.at).toBeLessThan(
      WINDOW_MS + IMMEDIATE_MS,
    )
    phases.held = { checkedAt: performance.now(), bRelease: bRelease.packet }

    const releasedAt = performance.now()
    await clearTableLag()
    await expect.poll(() => windowsAfter(click.at).length).toBe(1)
    const response = windowsAfter(click.at)[0]!
    expect(response.at - click.at).toBeGreaterThan(WINDOW_MS + IMMEDIATE_MS)
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
    while (performance.now() < response.at + WINDOW_MS - IMMEDIATE_MS) {
      const mutation = await write()
      const update = await receiveValue(a, mutation.value)
      expect(update.packet.at - mutation.sent).toBeLessThan(IMMEDIATE_MS)
      expect(update.packet.at - response.at).toBeLessThan(WINDOW_MS)
      graceWrites.push({ mutation, packet: update.packet })
    }
    expect(graceWrites.length).toBeGreaterThanOrEqual(4)
    const lastGrace = graceWrites.at(-1)!
    expect(lastGrace.mutation.sent - response.at).toBeGreaterThan(
      WINDOW_MS - IMMEDIATE_MS * 2,
    )
    expect(lastGrace.packet.at - response.at).toBeLessThan(WINDOW_MS)
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
    await waitDeliveryBoundary(response.at + WINDOW_MS + TRANSPORT_JITTER_MS)
    const afterGrace = [await write(), await write()]
    expect(afterGrace.at(-1)!.accepted).toBeLessThan(
      lastGrace.packet.at + WINDOW_MS - IMMEDIATE_MS,
    )
    expect(
      settingsUpdates(a).filter(
        ({ packet }) => packet.at > lastGrace.packet.at,
      ),
    ).toEqual([])
    const fixedRelease = await receiveValue(a, afterGrace.at(-1)!.value)
    expect(fixedRelease.packet.at - lastGrace.packet.at).toBeGreaterThanOrEqual(
      WINDOW_MS - TRANSPORT_JITTER_MS,
    )
    expect(fixedRelease.packet.at - lastGrace.packet.at).toBeLessThan(
      WINDOW_MS + IMMEDIATE_MS,
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
    await expectDocumentUnchanged(tabA)
    await expectDocumentUnchanged(tabB)
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
        WINDOW_MS,
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
    if (await configPage.getByTestId('modal-close').isVisible())
      await configPage.getByTestId('modal-close').click()
    await clearCustomSetting(configPage, WINDOW_KEY)
    if (await page.getByTestId('modal-close').isVisible())
      await page.getByTestId('modal-close').click()
    await clearCustomSetting(page, SOURCE_KEY)
    await Promise.all([tabA.close(), tabB.close(), configPage.close()])
  }
})

test('a new socket records bootstrap before subscribe separately from immediate page delivery', async ({
  page,
}) => {
  test.slow() // A cold application bootstrap may span the deliberately selected 8 s window.
  const coldPage = await page.context().newPage()
  const cold = watchDeliveryFrames(coldPage)
  const phases: Record<string, unknown> = {}
  try {
    await grantAdminToSelf(page)
    await openSettings(page, WINDOW_KEY)
    await setCustomSetting(page, WINDOW_KEY, String(WINDOW_MS))
    await expect(
      page.getByTestId(`hilos-table-row-${WINDOW_KEY}`),
    ).toContainText(String(WINDOW_MS))
    const startedAt = performance.now()
    // Local only: allow two ordinary bootstrap deliveries at the selected window
    // plus the normal tick/transport allowance. No acceptance budget is raised.
    await gotoPage(
      coldPage,
      '/hilos/settings',
      PAGE_READY,
      WINDOW_MS * 2 + IMMEDIATE_MS * 2,
    )
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
    await clearCustomSetting(page, WINDOW_KEY)
    await coldPage.close()
  }
})
