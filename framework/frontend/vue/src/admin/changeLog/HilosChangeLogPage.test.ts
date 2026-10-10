import { mount } from '@vue/test-utils'
import { markRaw, nextTick } from 'vue'
import { afterEach, describe, expect, it } from 'vitest'
import {
  CHANGE_LOG_OVERVIEW_SECTION,
  HilosPages,
  ScopeManager,
  createSignal,
  type HilosChangeLogContext,
  type HilosChangeLogOverview,
  type HilosRouter,
  type PageRouteMatch,
} from '@hilos/core'

import HilosChangeLogPage from './HilosChangeLogPage.vue'
import { hilosRouterKey } from '../../hilosRouterKey.js'

const TABLE = 'hilosChangeLogFeed'
const OVERVIEW: HilosChangeLogOverview = {
  journalEntries: 12,
  journalBytes: 2048,
  oldestAt: '2026-09-01T10:00:00.000Z',
  journaledTables: ['bot', 'hilos_user'],
  liveTables: 32,
}

function router(tablesBuilt = false): HilosRouter {
  return {
    currentRoute: createSignal<PageRouteMatch>({
      page: HilosPages.CHANGE_LOG,
      params: {},
      admin: true,
    }),
    currentPath: createSignal(''),
    currentTitle: createSignal(''),
    pageError: createSignal(null),
    pageLoading: createSignal(false),
    pageIdentity: createSignal(undefined),
    dashboardSections: createSignal(undefined),
    resolvePath: (page) =>
      tablesBuilt && page === HilosPages.CHANGE_LOG_TABLES
        ? '/hilos/change-log/tables'
        : undefined,
    clearPageError: () => {},
    denyCurrentPage: () => {},
    awaitPageAnswer: () => {},
    onLeave: () => () => {},
    navigate: () => {},
    replacePath: () => {},
    start: () => {},
    stop: () => {},
  }
}

function receipt(overrides: Record<string, unknown> = {}) {
  return {
    kind: 'receipt',
    receiptId: 7,
    entryId: null,
    createdAt: '2026-10-09T10:11:12.123Z',
    actorId: 3,
    actorLabel: 'Ada',
    actorDeleted: false,
    subjectId: 4,
    subjectLabel: 'Bea',
    subjectDeleted: false,
    channel: 'web',
    action: 'bot.update',
    touched: [
      {
        table: 'bot',
        records: 1,
        recordKey: [3],
        mutation: 'update',
        changedFields: 2,
      },
    ],
    ...overrides,
  }
}

type WindowListener = (signal: { data: Record<string, unknown> }) => void

function harness(
  overview: HilosChangeLogOverview = OVERVIEW,
  rows: Array<{ rowKey: string; slots: Record<string, unknown> }> = [],
) {
  const scopes = new ScopeManager()
  const scope = scopes.openPage(HilosPages.CHANGE_LOG)
  scope.data.set(CHANGE_LOG_OVERVIEW_SECTION, overview)
  const listeners = new Set<WindowListener>()
  const connection = {
    registerTableWindow(): void {},
    unregisterTableWindow(): void {},
    tableWindowDescriptors: () => ({}),
    sendTableViewport(): boolean {
      return true
    },
    sendTableRendered(): boolean {
      return true
    },
    on(event: string, listener: WindowListener): () => void {
      if (event === 'tableWindow') {
        listeners.add(listener)
        return () => listeners.delete(listener)
      }
      return () => {}
    },
  }
  const context: HilosChangeLogContext = {
    connection: connection as unknown as HilosChangeLogContext['connection'],
    scopes,
  }
  function pushWindow() {
    const data = {
      page: HilosPages.CHANGE_LOG,
      tableKey: TABLE,
      rows,
      totalCount: rows.length,
      totalExact: true,
      firstAnchor: null,
      lastAnchor: null,
      offset: 0,
      limit: 25,
    }
    for (const listener of listeners) listener({ data })
  }
  return { context, scope, pushWindow }
}

const mounted: ReturnType<typeof mount>[] = []
afterEach(() => {
  for (const wrapper of mounted.splice(0)) wrapper.unmount()
})

async function mountPage(h: ReturnType<typeof harness>, tablesBuilt = false) {
  const wrapper = mount(HilosChangeLogPage, {
    props: { context: markRaw(h.context) },
    attachTo: document.body,
    global: { provide: { [hilosRouterKey as symbol]: router(tablesBuilt) } },
  })
  mounted.push(wrapper)
  h.pushWindow()
  await nextTick()
  return wrapper
}

describe('HilosChangeLogPage', () => {
  it('draws the page snapshot and offers its table names in the feed filter', async () => {
    const h = harness()
    await mountPage(h)
    expect(
      document
        .querySelector('[data-id="hilos-change-log-tile-journal"]')
        ?.textContent?.trim(),
    ).toBe('12')
    expect(
      document
        .querySelector('[data-id="hilos-change-log-tile-tracked"]')
        ?.textContent?.trim(),
    ).toBe('2 of 32')
    expect(document.body.textContent).toContain('since September 2026')

    const tableFilter = document.querySelector(
      '[data-id="hilos-table-filter-table"]',
    ) as HTMLElement
    ;(tableFilter.querySelector('button') as HTMLElement).click()
    await nextTick()
    expect(tableFilter.textContent).toContain('bot')
    expect(tableFilter.textContent).toContain('hilos_user')
  })

  it.each([false, true])(
    'names an untracked empty feed; tables link built: %s',
    async (tablesBuilt) => {
      const h = harness({
        ...OVERVIEW,
        journalEntries: 0,
        oldestAt: null,
        journaledTables: [],
      })
      await mountPage(h, tablesBuilt)
      const state = document.querySelector(
        'table [data-id="hilos-change-log-feed-empty"]',
      ) as HTMLElement
      expect(state.dataset.state).toBe('untracked')
      expect(state.textContent).toContain('No table is tracked')
      expect(state.textContent).toContain('No entity carries the journal label')
      expect(
        state.querySelector('[data-id="hilos-change-log-open-tables"]') !==
          null,
      ).toBe(tablesBuilt)
    },
  )

  it('names an empty journal when tables are tracked', async () => {
    await mountPage(harness({ ...OVERVIEW, journalEntries: 0, oldestAt: null }))
    const state = document.querySelector(
      'table [data-id="hilos-change-log-feed-empty"]',
    ) as HTMLElement
    expect(state.dataset.state).toBe('empty')
    expect(state.textContent).toContain('Nothing recorded yet')
    expect(
      state.querySelector('[data-id="hilos-change-log-open-tables"]'),
    ).toBeNull()
  })

  it('draws a receipt, a deleted actor and an unattributed entry', async () => {
    await mountPage(
      harness(OVERVIEW, [
        { rowKey: 'receipt:7', slots: { feed: receipt() } },
        {
          rowKey: 'receipt:8',
          slots: {
            feed: receipt({
              receiptId: 8,
              actorId: 9,
              actorLabel: 'Deleted user #9',
              actorDeleted: true,
              subjectId: null,
              subjectLabel: null,
            }),
          },
        },
        {
          rowKey: 'entry:11',
          slots: {
            feed: receipt({
              kind: 'entry',
              receiptId: null,
              entryId: 11,
              actorId: null,
              actorLabel: null,
              subjectId: null,
              subjectLabel: null,
              channel: null,
              action: null,
            }),
          },
        },
      ]),
    )
    const receiptRow = document.querySelector(
      'table [data-id="hilos-table-row-receipt:7"]',
    ) as HTMLElement
    expect(receiptRow.textContent).toContain('Ada')
    expect(receiptRow.textContent).toContain('on behalf of Bea')
    expect(receiptRow.textContent).toContain('Web')
    expect(receiptRow.querySelector('.bi-globe')).not.toBeNull()
    expect(receiptRow.textContent).toContain('bot.update')
    expect(receiptRow.textContent).toContain('bot #3 · updated · 2 fields')
    const deleted = document.querySelector(
      'table [data-id="hilos-table-row-receipt:8"]',
    ) as HTMLElement
    expect(
      deleted.querySelector('.text-body-secondary')?.textContent,
    ).toContain('Deleted user #9')
    const entry = document.querySelector(
      'table [data-id="hilos-table-row-entry:11"]',
    ) as HTMLElement
    expect(entry.textContent).toContain('Unknown')
    expect(entry.textContent).toContain(
      'Changed past the application — no receipt',
    )
    expect(entry.querySelector('.badge')).toBeNull()
  })

  it('keeps viewer figures and marks hidden people beside their numbers', async () => {
    await mountPage(
      harness(OVERVIEW, [
        {
          rowKey: 'receipt:7',
          slots: {
            feed: receipt({
              actorLabel: { _hidden: true },
              subjectLabel: { _hidden: true },
            }),
          },
        },
      ]),
    )
    expect(
      document.querySelector('[data-id="hilos-change-log-tile-tracked"]')
        ?.textContent,
    ).toContain('2 of 32')
    const row = document.querySelector(
      'table [data-id="hilos-table-row-receipt:7"]',
    ) as HTMLElement
    expect(row.textContent?.replace(/\s+/g, ' ')).toContain('Hidden (#3)')
    expect(row.textContent?.replace(/\s+/g, ' ')).toContain(
      'on behalf of Hidden (#4)',
    )
    expect(row.textContent).not.toContain('Ada')
  })
})
