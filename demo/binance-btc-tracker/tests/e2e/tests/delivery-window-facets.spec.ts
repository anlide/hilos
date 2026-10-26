import { writeFile } from 'node:fs/promises'
import { test, expect } from '@playwright/test'
import {
  armSocketDrop,
  deliveryObject as object,
  dropSocket,
  ROWS,
  shownByTestId,
  type SocketFrame,
  watchDeliveryFrames,
} from '../../../../../framework/frontend/e2e/index.js'
import { grantAdminToSelf } from '../helpers/adminGrant.js'
import {
  expectDocumentUnchanged,
  expectPageReady,
  gotoPage,
  markDocument,
  PAGE_READY,
} from '../helpers/page.js'

test.describe.configure({ retries: 0 })

test('logs initial answers keep own state, response and declared facets in one physical delivery', async ({
  page,
}) => {
  test.slow() // Includes a real reconnect and the log owner's normal reporting tick.
  await armSocketDrop(page)
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

    await page.getByTestId('hilos-page-tab-hilos_logs_keys').click()
    await expectPageReady(page)
    await expect.poll(() => page.locator(ROWS).count()).toBeGreaterThan(0)
    await expect
      .poll(() =>
        log.sent.some(
          ({ wire }) =>
            object(wire).type === 'table_facets' &&
            object(wire).tableKey === 'hilosLogKeys',
        ),
      )
      .toBe(true)
    await expect
      .poll(() =>
        log.packets.some((packet) =>
          packet.frames.some(
            ({ wire }) =>
              object(wire).type === 'table_facet_counts' &&
              object(object(wire).data).tableKey === 'hilosLogKeys',
          ),
        ),
      )
      .toBe(true)
    await shownByTestId(page, 'hilos-table-filter-class').click()
    await expect(shownByTestId(page, 'hilos-table-facet-class-any')).toHaveText(
      /^\d+$/,
    )
    await shownByTestId(page, 'hilos-table-filter-class').click()
    const rowKeysBefore = await page
      .locator(ROWS)
      .evaluateAll((rows) => rows.map((row) => row.getAttribute('data-id')))
    const socket = log.sockets()
    await markDocument(page)
    await dropSocket(page)
    await expect.poll(log.sockets, { timeout: 15_000 }).toBe(socket + 1)
    await expect
      .poll(
        () =>
          log.packets.some(
            (packet) =>
              packet.socket === socket + 1 &&
              packet.frames.some(
                ({ wire }) =>
                  object(wire).type === 'page_response' &&
                  object(object(wire).data).page === 'hilos_logs_keys',
              ),
          ),
        { timeout: 15_000 },
      )
      .toBe(true)
    const subscribe = log.sent.find(
      ({ socket: id, wire }) =>
        id === socket + 1 &&
        object(wire).type === 'page_subscribe' &&
        object(wire).page === 'hilos_logs_keys',
    )!
    expect(subscribe).toBeDefined()
    const descriptor = object(
      object(object(subscribe.wire).tableWindows).hilosLogKeys,
    )
    expect(descriptor.facets).toEqual({ class: ['daemon', 'agent', 'worker'] })
    expect(descriptor.sort).toEqual([{ field: 'bytes', direction: 'desc' }])
    expect(descriptor.limit).toBe(25)
    expect(subscribe.raw).toContain('facets')
    const restored = log.packets.find(
      (packet) =>
        packet.socket === socket + 1 &&
        packet.frames.some(
          ({ wire }) =>
            object(wire).type === 'page_response' &&
            object(object(wire).data).page === 'hilos_logs_keys',
        ),
    )!
    expect(
      restored.frames
        .map(({ wire }) => object(wire).type)
        .filter(
          (type) =>
            type === 'subscription_page_hilos_logs_keys' ||
            type === 'page_response' ||
            type === 'table_facet_counts',
        ),
    ).toEqual([
      'subscription_page_hilos_logs_keys',
      'page_response',
      'table_facet_counts',
    ])
    expect(restored.raw).toMatch(/^\{"type":"signal_batch"/)
    expect(
      new Set(
        restored.frames.map(({ socket, message }) => `${socket}:${message}`),
      ).size,
    ).toBe(1)
    expect(restored.frames.every(({ raw }) => raw === restored.raw)).toBe(true)
    const reply = restored.frames.find(
      ({ wire }) => object(wire).type === 'page_response',
    )!
    const window = object(
      object(object(object(reply.wire).data).payload).windows,
    ).hilosLogKeys
    const rows = object(window).rows
    expect(Array.isArray(rows)).toBe(true)
    const restoredKeys = (rows as unknown[]).map(
      (row) => `hilos-table-row-${String(object(row).rowKey)}`,
    )
    expect(restoredKeys.length).toBeGreaterThan(0)
    expect(object(window).limit).toBe(descriptor.limit)
    const restoredFacetFrame = restored.frames.find(
      ({ wire }) =>
        object(wire).type === 'table_facet_counts' &&
        object(object(wire).data).tableKey === 'hilosLogKeys',
    )!
    const restoredFacet = object(
      object(object(restoredFacetFrame.wire).data).facets,
    ).class
    expect(object(restoredFacet).any).toEqual({
      count: object(window).totalCount,
      exact: object(window).totalExact,
    })
    await expect(page.getByTestId('conn-state')).toHaveText('connected')
    await expectPageReady(page)
    // Byte totals and ordering can change while disconnected, and keep changing
    // after the reconnect: the table is live, and viewing it grows the owner's
    // own log. The visible rows must therefore match the latest window the
    // server sent on the restored socket, in its actual server order — the
    // restored snapshot itself until a later table_window replaces it.
    const logKeysWindow = (
      frame: SocketFrame,
    ): Record<string, unknown> | undefined => {
      const wire = object(frame.wire)
      const data = object(wire.data)
      if (data.page !== 'hilos_logs_keys') {
        return undefined
      }
      if (wire.type === 'page_response') {
        const section = object(object(data.payload).windows).hilosLogKeys
        return section === undefined ? undefined : object(section)
      }
      return wire.type === 'table_window' && data.tableKey === 'hilosLogKeys'
        ? data
        : undefined
    }
    const latestLogKeysWindow = () => {
      const frames = log.packets
        .filter((packet) => packet.socket === socket + 1)
        .flatMap((packet) => packet.frames)
      return frames.slice(frames.indexOf(reply) + 1).reduce(
        (latest, frame) => {
          const found = logKeysWindow(frame)
          return found === undefined ? latest : { frame, window: found }
        },
        { frame: reply, window: object(window) },
      )
    }
    await expect
      .poll(async () => {
        const before = latestLogKeysWindow()
        const shown = await page
          .locator(ROWS)
          .evaluateAll((rows) => rows.map((row) => row.getAttribute('data-id')))
        const latest = latestLogKeysWindow()
        if (latest.frame !== before.frame) {
          return { shown, changed: 'a newer window arrived during the read' }
        }
        const latestRows = latest.window.rows
        if (!Array.isArray(latestRows)) {
          return { shown, window: latest.window }
        }
        const expected = latestRows.map(
          (row) => `hilos-table-row-${String(object(row).rowKey)}`,
        )
        return shown.length === expected.length &&
          shown.every((key, index) => key === expected[index])
          ? null
          : { shown, expected }
      })
      .toBeNull()
    expect(restoredKeys.some((key) => rowKeysBefore.includes(key))).toBe(true)
    await shownByTestId(page, 'hilos-table-filter-class').click()
    // New log streams can appear too. The restored delivery must agree within
    // itself (above); the live dropdown must draw the latest facet frame, even
    // when it has advanced beyond that delivery or the pre-disconnect count.
    const latestFacetFrame = () =>
      log.packets
        .filter((packet) => packet.socket === socket + 1)
        .flatMap((packet) => packet.frames)
        .reverse()
        .find(
          ({ wire }) =>
            object(wire).type === 'table_facet_counts' &&
            object(object(wire).data).page === 'hilos_logs_keys' &&
            object(object(wire).data).tableKey === 'hilosLogKeys',
        )!
    await expect
      .poll(async () => {
        const before = latestFacetFrame()
        const facet = object(object(object(before.wire).data).facets).class
        const counts = {
          any: object(facet).any,
          ...object(object(facet).options),
        }
        expect(Object.keys(counts).sort()).toEqual([
          'agent',
          'any',
          'daemon',
          'worker',
        ])
        const shown: Record<string, string | null> = {}
        const expected: Record<string, string> = {}
        for (const [key, value] of Object.entries(counts)) {
          expect(object(value).exact).toBe(true)
          expect(object(value).count).toEqual(expect.any(Number))
          expected[key] = String(object(value).count)
          shown[key] = await shownByTestId(
            page,
            `hilos-table-facet-class-${key}`,
          ).textContent()
        }
        if (latestFacetFrame() !== before) {
          return {
            shown,
            changed: 'a newer facet frame arrived during the read',
          }
        }
        return Object.keys(expected).every(
          (key) => shown[key] === expected[key],
        )
          ? null
          : { shown, expected }
      })
      .toBeNull()
    await expectDocumentUnchanged(page)
    expect(log.sockets()).toBe(socket + 1)
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
