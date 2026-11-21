import type { Page } from '@playwright/test'
import { expect, test } from '../test.js'

import {
  PAGE_READY,
  STATUS_COMPONENTS_PATH,
  STATUS_HISTORY_PAGE,
  STATUS_HISTORY_PATH,
  STATUS_HISTORY_TABLE,
  STATUS_PATH,
  STATUS_SUBSCRIBERS_PATH,
  STATUS_WORKS_PATH,
  controlSocketReconnect,
  deliveryObject,
  expectDocumentUnchanged,
  expectPageReady,
  expectPublicStatusPair,
  expectPublicStatusReadOnly,
  expectStatusHistoryFact,
  expectStatusSubscriberCounts,
  expectTableTotal,
  gotoPage,
  gotoPublicStatus,
  grantAdminToSelf,
  markDocument,
  shownByTestId,
  statusHistoryDeliveries,
  typeInto,
  watchDeliveryFrames,
  watchPublicStatus,
  type DeliveryFrameLog,
} from '../../../../../framework/frontend/e2e/index.js'
import { statusOverviewSchema } from '../../../../../framework/frontend/core/src/admin/status/index.js'
import { beatStatusHeartbeat } from '../../../../../framework/frontend/scripts/statusMoments.mjs'

// spec-owner: demo — proves this project serves its Status section and public status page.

// The React Status section and public /status as this project builds them: six
// lazy views over the real daemon and nginx. The SDK's unit tests draw each view
// from a fixture; only the built artifact can bind the wrong lazy page, lose the
// first page answer on delivery or let the shell close the public reader. Every
// step reads what the screen shows back against the server's own answer, so a
// view of another page or another project fails on data, not on a caption. The
// shared head and its tabs are proved by their own specs and are only followed
// here. Mail, works and the freeze stay with the subscription and works specs.

// Picked at random from the IANA list when this spec was written: the stand runs
// this project on UTC, so a reader-zone slip cannot hide behind the project's.
test.use({ timezoneId: 'America/Argentina/La_Rioja' })

// This project declares no Status components, so its catalog is the framework's
// single fallback: the project's index agent, published as Service.
const SERVICE = {
  componentKey: 'service',
  name: 'Service',
  description: null,
  agentType: 'hilos_index',
} as const
const CATALOG = [{ componentKey: SERVICE.componentKey, name: SERVICE.name }]

// The state filter offers the declared states by their place; test-owned
// vocabulary, never read from the SDK that draws them.
const STATES = [
  'operational',
  'checking',
  'partial_outage',
  'major_outage',
  'maintenance',
  'unknown',
] as const
const STATE_LABELS: Record<(typeof STATES)[number], string> = {
  operational: 'Operational',
  checking: 'Checking',
  partial_outage: 'Partial outage',
  major_outage: 'Major outage',
  maintenance: 'Maintenance',
  unknown: 'Unknown',
}

const OVERVIEW_PAGE = 'hilos_status'
const WORKS_PAGE = 'hilos_status_works'
const COMPONENTS_PAGE = 'hilos_status_components'
const SUBSCRIBERS_PAGE = 'hilos_status_subscribers'

const ADDRESSES = [
  [STATUS_PATH, OVERVIEW_PAGE],
  [STATUS_WORKS_PATH, WORKS_PAGE],
  [STATUS_COMPONENTS_PATH, COMPONENTS_PAGE],
  [STATUS_SUBSCRIBERS_PATH, SUBSCRIBERS_PAGE],
  [STATUS_HISTORY_PATH, STATUS_HISTORY_PAGE],
] as const

/** Page answers on this document's socket, in delivery order after the move. */
function pageAnswers(
  wire: DeliveryFrameLog,
  pageKey: string,
  since: number,
  socket: number,
) {
  return wire.packets
    .slice(since)
    .filter((packet) => packet.socket === socket)
    .flatMap((packet) =>
      packet.frames.flatMap((frame) => {
        const envelope = deliveryObject(frame.wire)
        const data = deliveryObject(envelope.data)
        return envelope.type === 'page_response' && data.page === pageKey
          ? [{ packet, frame, payload: deliveryObject(data.payload) }]
          : []
      }),
    )
}

/** The named first window inside a page answer: no companion request draws it. */
function firstWindow(payload: Record<string, unknown>, tableKey: string) {
  const section = deliveryObject(payload.windows)[tableKey]
  expect(section, `${tableKey} arrives in the page answer`).toBeDefined()
  const { rows, totalCount } = deliveryObject(section)
  expect(totalCount).toEqual(expect.any(Number))
  return {
    rows: (Array.isArray(rows) ? rows : []).map(deliveryObject),
    total: Number(totalCount),
  }
}

/** The table shows the total of its first window, or its empty state. */
async function expectTableShows(page: Page, tableId: string, total: number) {
  if (total === 0)
    await expect(
      shownByTestId(page.getByTestId(tableId), 'hilos-table-empty'),
    ).toBeVisible()
  else await expectTableTotal(expect, page, total)
}

/**
 * Check the first answer supplies the page's data and the screen renders the
 * delivered state. Overview health can change while React is mounting.
 */
async function expectAnswerShown(
  page: Page,
  wire: DeliveryFrameLog,
  pageKey: string,
  since: number,
): Promise<Record<string, unknown>> {
  const socket = wire.connections.at(-1)!.socket
  const answers = () => pageAnswers(wire, pageKey, since, socket)
  await expect.poll(() => answers().length).toBeGreaterThan(0)
  const [first] = answers()
  const { payload } = first
  const data = deliveryObject(payload.data)
  if (pageKey === OVERVIEW_PAGE) {
    const overview = statusOverviewSchema.parse(data.status)
    expect(overview.components.totalCount).toBe(1)
    expect(overview.subscribers).not.toBeNull()
    expect(overview.historyCount).not.toBeNull()
    expect(overview.scheduledWorks).not.toBeNull()

    await expect
      .poll(
        async () => {
          const latest = answers().at(-1)!
          const { components, subscribers, historyCount, scheduledWorks } =
            statusOverviewSchema.parse(
              deliveryObject(latest.payload.data).status,
            )
          const { operationalCount, totalCount } = components
          const counters = [
            [
              'hilos-status-components-link',
              new RegExp(`^${operationalCount} / ${totalCount} operational\\b`),
            ],
            [
              'hilos-status-subscribers-tile-confirmed',
              String(subscribers?.confirmedCount ?? '—'),
            ],
            [
              'hilos-status-subscribers-tile-pending',
              String(subscribers?.pendingCount ?? '—'),
            ],
            ['hilos-status-history-count', String(historyCount ?? '—')],
            ['hilos-status-works-count', String(scheduledWorks?.count ?? '—')],
          ] as const
          const mismatches = await Promise.all(
            counters.map(async ([testId, expected]) => {
              const [count, visibleTexts] = await Promise.all([
                page.getByTestId(testId).count(),
                shownByTestId(page, testId).allInnerTexts(),
              ])
              const text = visibleTexts[0]?.trim()
              const matches =
                typeof expected === 'string'
                  ? text === expected
                  : text !== undefined && expected.test(text)
              return count === 1 && visibleTexts.length === 1 && matches
                ? null
                : { testId, expected, count, visibleTexts }
            }),
          )
          // A new delivery invalidates the whole comparison, even if its
          // arrival did not change the counter that was just read.
          return latest.frame === answers().at(-1)?.frame
            ? mismatches.filter((mismatch) => mismatch !== null)
            : ['Overview answer changed while reading its counters']
        },
        {
          message: 'Overview shows all counters from its latest answer',
        },
      )
      .toEqual([])
  }
  if (pageKey === WORKS_PAGE) {
    expect(deliveryObject(data.statusWorks).components).toEqual(CATALOG)
    const works = firstWindow(payload, 'hilosStatusWorks')
    await expectTableShows(page, 'hilos-status-works-table', works.total)
    for (const row of works.rows) {
      const work = deliveryObject(deliveryObject(row.slots).work)
      await expect(
        shownByTestId(page, `hilos-status-work-title-${String(work.id)}`),
      ).toContainText(String(work.title))
    }
  }
  if (pageKey === COMPONENTS_PAGE) {
    const components = firstWindow(payload, 'hilosStatusComponents')
    expect(components.total).toBe(1)
    const [row] = components.rows
    const component = deliveryObject(deliveryObject(row.slots).component)
    expect(component).toEqual({ ...SERVICE, state: expect.any(String) })
    const stale =
      Array.isArray(row.staleSources) && row.staleSources.includes('component')
    const table = page.getByTestId('hilos-status-components-table')
    await expect(
      shownByTestId(table, `hilos-status-component-${SERVICE.componentKey}`),
    ).toContainText(SERVICE.name)
    await expect(
      shownByTestId(
        table,
        `hilos-status-component-state-${SERVICE.componentKey}`,
      ),
    ).toHaveText(
      STATE_LABELS[
        stale ? 'unknown' : (component.state as (typeof STATES)[number])
      ],
    )
  }
  if (pageKey === SUBSCRIBERS_PAGE) {
    const counts = deliveryObject(data.statusSubscribers)
    expect(counts).toEqual({
      confirmedCount: expect.any(Number),
      pendingCount: expect.any(Number),
    })
    const confirmed = Number(counts.confirmedCount)
    const pending = Number(counts.pendingCount)
    await expectStatusSubscriberCounts(expect, page, wire, confirmed, pending)
    const subscribers = firstWindow(payload, 'hilosStatusSubscribers')
    expect(subscribers.total).toBe(confirmed + pending)
    await expectTableShows(
      page,
      'hilos-status-subscribers-table',
      subscribers.total,
    )
  }
  if (pageKey === STATUS_HISTORY_PAGE) {
    const [history] = statusHistoryDeliveries({
      ...wire,
      packets: [{ ...first.packet, frames: [first.frame] }],
    })
    expect(history.catalog).toEqual({ components: CATALOG })
    const { total } = firstWindow(payload, STATUS_HISTORY_TABLE)
    await expectTableShows(page, 'hilos-status-history-table', total)
    for (const fact of history.rows)
      await expectStatusHistoryFact(expect, page, fact)
  }
  return payload
}

/** Opening a page draws it from its answer alone: nothing asks for more. */
function expectNoActions(wire: DeliveryFrameLog) {
  expect(
    wire.sent.filter(
      ({ wire: frame }) => deliveryObject(frame).type === 'action',
    ),
  ).toEqual([])
}

test('Dashboard, cold entry and F5 at each Status address show this project from its first answer', async ({
  page,
}) => {
  await grantAdminToSelf(expect, page)
  const wire = watchDeliveryFrames(page)
  try {
    await test.step('the Dashboard card opens the Overview', async () => {
      await gotoPage(expect, page, '/hilos', PAGE_READY)
      const since = wire.packets.length
      await page.getByTestId('dashboard-card-hilos_status').click()
      await expectPageReady(expect, page)
      await expect(page).toHaveURL(new RegExp(`${STATUS_PATH}$`))
      await expectAnswerShown(page, wire, OVERVIEW_PAGE, since)
    })
    for (const [path, pageKey] of ADDRESSES) {
      await test.step(`${path} on a cold document and after F5`, async () => {
        const cold = wire.packets.length
        await gotoPage(expect, page, path, PAGE_READY)
        await expectAnswerShown(page, wire, pageKey, cold)
        const sockets = wire.connections.length
        const reloaded = wire.packets.length
        await page.reload()
        await expectPageReady(expect, page)
        await expect(page).toHaveURL(new RegExp(`${path}$`))
        expect(wire.connections.length).toBeGreaterThan(sockets)
        await expectAnswerShown(page, wire, pageKey, reloaded)
      })
    }
    expectNoActions(wire)
  } finally {
    await test.info().attach('status-addresses-wire', {
      body: JSON.stringify(wire),
      contentType: 'application/json',
    })
    wire.stop()
  }
})

// FIXME(F-081, 2026-11-08): строка сервиса не появилась после смены фильтра · чужой · указание владельца 08.11 · HIL-2080
test(
  'links, tabs, Back and Forward stay on one document and socket, and Components searches and filters without writing',
  { tag: '@quarantine:F-081' },
  async ({ page }) => {
    await grantAdminToSelf(expect, page)
    const wire = watchDeliveryFrames(page)
    try {
      await gotoPage(expect, page, STATUS_PATH, PAGE_READY)
      await expectAnswerShown(page, wire, OVERVIEW_PAGE, 0)
      expect(wire.connections).toHaveLength(1)
      const [{ socket: opened }] = wire.connections
      await markDocument(page)
      const sentBefore = wire.sent.length
      const tab = (pageKey: string) =>
        page.getByTestId(`hilos-page-tab-${pageKey}`)
      const visit = async (
        move: () => Promise<unknown>,
        path: string,
        pageKey: string,
      ) => {
        const since = wire.packets.length
        await move()
        await expectPageReady(expect, page)
        await expect(page).toHaveURL(new RegExp(`${path}$`))
        return expectAnswerShown(page, wire, pageKey, since)
      }

      const components = await visit(
        () => page.getByTestId('hilos-status-components-link').click(),
        STATUS_COMPONENTS_PATH,
        COMPONENTS_PAGE,
      )
      await test.step('Components searches and filters by state, read only', async () => {
        const [row] = firstWindow(components, 'hilosStatusComponents').rows
        const state = String(
          deliveryObject(deliveryObject(row.slots).component).state,
        ) as (typeof STATES)[number]
        const table = page.getByTestId('hilos-status-components-table')
        const service = shownByTestId(
          table,
          `hilos-status-component-${SERVICE.componentKey}`,
        )
        const noMatches = shownByTestId(table, 'hilos-table-no-matches')
        // A read-only surface: no creation, bulk action or row selection.
        await expect(
          table.getByTestId(
            /^hilos-table-(main-action|actions|action-|select-)/,
          ),
        ).toHaveCount(0)

        const search = page.getByTestId('hilos-table-search')
        await typeInto(search, 'no-such-status-component')
        await expect(noMatches).toBeVisible()
        await expect(service).toHaveCount(0)
        await typeInto(search, SERVICE.agentType)
        await expect(service).toBeVisible()
        await page.getByTestId('hilos-table-search-clear').click()
        await expect(search).toHaveValue('')
        await expect(service).toBeVisible()

        const filter = page.getByTestId('hilos-table-filter-state')
        const current = STATES.indexOf(state)
        const other = STATES.indexOf(
          state === 'major_outage' ? 'operational' : 'major_outage',
        )
        await filter.getByTestId('hilos-dropdown-toggle').click()
        await filter.getByTestId(`hilos-dropdown-option-${other}`).click()
        await expect(noMatches).toBeVisible()
        await expect(service).toHaveCount(0)
        await filter.getByTestId('hilos-dropdown-toggle').click()
        await filter.getByTestId(`hilos-dropdown-option-${current}`).click()
        await expect(service).toBeVisible()
        await expect(
          shownByTestId(
            table,
            `hilos-status-component-state-${SERVICE.componentKey}`,
          ),
        ).toHaveText(STATE_LABELS[state])
      })

      await visit(
        () => tab(SUBSCRIBERS_PAGE).click(),
        STATUS_SUBSCRIBERS_PATH,
        SUBSCRIBERS_PAGE,
      )
      await visit(
        () => tab(STATUS_HISTORY_PAGE).click(),
        STATUS_HISTORY_PATH,
        STATUS_HISTORY_PAGE,
      )
      await visit(() => tab(WORKS_PAGE).click(), STATUS_WORKS_PATH, WORKS_PAGE)
      await visit(() => page.goBack(), STATUS_HISTORY_PATH, STATUS_HISTORY_PAGE)
      await visit(
        () => page.goBack(),
        STATUS_SUBSCRIBERS_PATH,
        SUBSCRIBERS_PAGE,
      )
      await visit(
        () => page.goForward(),
        STATUS_HISTORY_PATH,
        STATUS_HISTORY_PAGE,
      )
      await visit(() => tab(OVERVIEW_PAGE).click(), STATUS_PATH, OVERVIEW_PAGE)

      await expectDocumentUnchanged(expect, page)
      expect(wire.connections.map((connection) => connection.socket)).toEqual([
        opened,
      ])
      expect(
        wire.sent
          .slice(sentBefore)
          .map(({ socket, wire: frame }) => ({
            socket,
            frame: deliveryObject(frame),
          }))
          .filter(({ frame }) => frame.type === 'page_subscribe')
          .map(({ socket, frame }) => ({ socket, page: frame.page })),
      ).toEqual(
        [
          COMPONENTS_PAGE,
          SUBSCRIBERS_PAGE,
          STATUS_HISTORY_PAGE,
          WORKS_PAGE,
          STATUS_HISTORY_PAGE,
          SUBSCRIBERS_PAGE,
          STATUS_HISTORY_PAGE,
          OVERVIEW_PAGE,
        ].map((pageKey) => ({ socket: opened, page: pageKey })),
      )
      expectNoActions(wire)
    } finally {
      await test.info().attach('status-navigation-wire', {
        body: JSON.stringify(wire),
        contentType: 'application/json',
      })
      wire.stop()
    }
  },
)

// FIXME(F-088, 2026-11-09): F5 без сокета не дождался новой пары файлов · чужой · попытка 1 · HIL-2081
test(
  'public /status reads its two files with no subscription and keeps reading through a dropped socket and its recovery',
  { tag: '@quarantine:F-088' },
  async ({ page }) => {
    // A guest: the reader needs no account, page answer or handshake.
    const socket = await controlSocketReconnect(page)
    const log = watchPublicStatus(page)
    const recovery: Record<string, number> = {}
    try {
      await test.step('the first look reads both files and shows this project', async () => {
        await gotoPublicStatus(expect, page)
        await expect(page.getByTestId('conn-state')).toHaveText('connected')
        const first = await expectPublicStatusPair(expect, page, log)
        expect(first.status.components).toEqual([
          expect.objectContaining({
            componentKey: SERVICE.componentKey,
            name: SERVICE.name,
          }),
        ])
        await expect(
          page.getByTestId('public-status-components'),
        ).toContainText(SERVICE.name)
        await expect(page.getByTestId('public-status-subscribe')).toBeVisible()
      })

      await test.step('a publication hint over the live socket rereads the files', async () => {
        // The receipt is a floor: a newer publication may have replaced it.
        const { aliveAt } = await beatStatusHeartbeat()
        await expectPublicStatusPair(
          expect,
          page,
          log,
          (pair) => pair.heartbeat.aliveAt >= aliveAt,
        )
        expect(
          log.wire.packets.some((packet) =>
            packet.frames.some(
              ({ wire }) =>
                deliveryObject(wire).type === 'status_files_changed',
            ),
          ),
        ).toBe(true)
      })

      await test.step('a dropped socket keeps the facts and the page usable', async () => {
        const banner = await page
          .getByTestId('public-status-banner')
          .innerText()
        const states = await page
          .getByTestId('public-status-component-state')
          .allInnerTexts()
        await markDocument(page)
        await socket.drop()
        await expect(page.getByTestId('conn-state')).not.toHaveText('connected')
        await expect(page.getByTestId('public-status-connection')).toHaveText(
          'Your live connection to the site is lost — the system is up',
        )
        await expect(page.getByTestId('public-status-banner')).toHaveText(
          banner,
        )
        await expect(
          page.getByTestId('public-status-component-state'),
        ).toHaveText(states)
        await expect(page.getByTestId('public-status-subscribe')).toHaveCount(0)
        // The shell neither covers the reader nor disables its controls: the
        // oldest day answers a click (the detail starts on today).
        const day = page
          .getByTestId('public-status-days-90')
          .getByRole('button')
          .first()
        await expect(day).toBeEnabled()
        await day.click()
        await expect(page.getByTestId('public-status-day-detail')).toHaveText(
          (await day.getAttribute('aria-label'))!,
        )
        await expectDocumentUnchanged(expect, page)
      })

      await test.step('F5 without a socket reads the files a publication changed', async () => {
        const { aliveAt } = await beatStatusHeartbeat()
        const packets = log.wire.packets.length
        await page.reload()
        await expect(page.getByTestId('public-status-components')).toBeVisible()
        await expectPublicStatusPair(
          expect,
          page,
          log,
          (pair) => pair.heartbeat.aliveAt >= aliveAt,
        )
        await expect(page.getByTestId('public-status-connection')).toHaveText(
          'Your live connection to the site is lost — the system is up',
        )
        await expect(page.getByTestId('conn-state')).not.toHaveText('connected')
        expect(log.wire.packets.slice(packets)).toEqual([])
      })

      await test.step('recovery rereads at once, before the next poll', async () => {
        const { aliveAt } = await beatStatusHeartbeat()
        const before = log.reads[0].length
        const last = log.reads[0].at(-1)!
        const pair = log.displayed.at(-1)!
        recovery.lastReadAt = last.requestedAt
        recovery.releasedAt = performance.now()
        await socket.release()
        await expect(page.getByTestId('conn-state')).toHaveText('connected')
        await expect.poll(() => log.reads[0].length).toBeGreaterThan(before)
        recovery.rereadAt = log.reads[0][before].requestedAt
        expect(recovery.rereadAt).toBeLessThan(
          recovery.lastReadAt + pair.status.config.pollIntervalSeconds * 1000,
        )
        await expectPublicStatusPair(
          expect,
          page,
          log,
          (read) => read.heartbeat.aliveAt >= aliveAt,
        )
        await expect(page.getByTestId('public-status-connection')).toHaveCount(
          0,
        )
        await expect(page.getByTestId('public-status-subscribe')).toBeVisible()
      })

      expectPublicStatusReadOnly(expect, log)
    } finally {
      await log.stop()
      await socket.dispose()
      await test.info().attach('public-status-drop-and-recovery', {
        body: JSON.stringify({ public: log, recovery }),
        contentType: 'application/json',
      })
    }
  },
)
