import { mount } from '@vue/test-utils'
import { markRaw, nextTick } from 'vue'
import { afterEach, describe, expect, it, vi } from 'vitest'
import {
  ActionLifecycle,
  CHANGE_LOG_OVERVIEW_SECTION,
  HILOS_CHANGE_LOG_ENTRY_READ,
  HILOS_CHANGE_LOG_RECEIPT_READ,
  HilosPages,
  ScopeManager,
  createSignal,
  type ActionLifecycleSource,
  type ActionErrorSignal,
  type ActionSuccessSignal,
  type ConnectionState,
  type HilosChangeLogReceiptContext,
  type HilosChangeLogReceiptHeader,
  type HilosChangeLogEntry,
  type HilosPageIdentity,
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

function header(
  overrides: Partial<HilosChangeLogReceiptHeader> = {},
): HilosChangeLogReceiptHeader {
  return {
    receiptId: 7,
    createdAt: '2026-10-09T10:11:12.123Z',
    actorId: 3,
    actorLabel: 'Ada',
    actorDeleted: false,
    subjectId: 4,
    subjectLabel: 'Bea',
    subjectDeleted: false,
    sessionId: 5,
    channel: 'web',
    source: 'hilos_app_bots',
    action: 'bot.update',
    agent: 'library',
    entryCount: 1,
    ...overrides,
  }
}

function entry(entryId = 11): HilosChangeLogEntry {
  return {
    entryId,
    receiptId: 7,
    createdAt: '2026-10-09T10:11:12.123Z',
    table: 'bot',
    recordKey: [3],
    mutation: 'update',
    changes: [],
  }
}

function element(id: string, root: ParentNode = document): HTMLElement {
  const found = root.querySelector(`[data-id="${id}"]`)
  expect(found).not.toBeNull()
  return found as HTMLElement
}

async function settle(): Promise<void> {
  await Promise.resolve()
  await nextTick()
  await nextTick()
}

async function open(
  h: ReturnType<typeof harness>,
  root: ParentNode = document.querySelector('table')!,
): Promise<HTMLElement> {
  const opener = element('hilos-change-log-open-receipt:7', root)
  opener.focus()
  opener.click()
  await settle()
  expect(h.source.sent[0]).toMatchObject({
    action: HILOS_CHANGE_LOG_RECEIPT_READ,
    data: { receiptId: 7, afterEntryId: null },
  })
  return opener
}

function reply(
  h: ReturnType<typeof harness>,
  index = 0,
  entries = [entry()],
  receiptHeader = header(),
  nextAfterEntryId: number | null = null,
): void {
  h.source.success(index, { receipt: receiptHeader, entries, nextAfterEntryId })
}

function router(tablesBuilt = false) {
  const identity = createSignal<HilosPageIdentity | undefined>(undefined)
  const leaves = new Set<() => void>()
  const value: HilosRouter = {
    currentRoute: createSignal<PageRouteMatch>({
      page: HilosPages.CHANGE_LOG,
      params: {},
      admin: true,
    }),
    currentPath: createSignal(''),
    currentTitle: createSignal(''),
    pageError: createSignal(null),
    pageLoading: createSignal(false),
    pageIdentity: identity,
    dashboardSections: createSignal(undefined),
    resolvePath: (page) =>
      tablesBuilt && page === HilosPages.CHANGE_LOG_TABLES
        ? '/hilos/change-log/tables'
        : undefined,
    clearPageError: () => {},
    denyCurrentPage: () => {},
    awaitPageAnswer: () => {},
    onLeave: (listener) => {
      leaves.add(listener)
      return () => leaves.delete(listener)
    },
    navigate: () => {},
    replacePath: () => {},
    start: () => {},
    stop: () => {},
  }
  return {
    value,
    identity,
    leave: () => leaves.forEach((listener) => listener()),
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

interface Events {
  actionSuccess: ActionSuccessSignal
  actionError: ActionErrorSignal
  state: ConnectionState
}

/** Exercise the real tracked lifecycle, including its parser, correlation and failures. */
class Source implements ActionLifecycleSource {
  connected = true
  readonly sent: { action: string; data: unknown; requestId?: string }[] = []
  private readonly listeners: {
    [E in keyof Events]: ((payload: Events[E]) => void)[]
  } = { actionSuccess: [], actionError: [], state: [] }

  sendAction(action: string, data: unknown, requestId?: string): boolean {
    this.sent.push({ action, data, requestId })
    return this.connected
  }

  on<E extends keyof Events>(
    event: E,
    listener: (payload: Events[E]) => void,
  ): () => void {
    this.listeners[event].push(listener)
    return () => {
      const index = this.listeners[event].indexOf(listener)
      if (index !== -1) this.listeners[event].splice(index, 1)
    }
  }

  success(index: number, reply?: unknown): void {
    const request = this.sent[index]!
    for (const listener of this.listeners.actionSuccess) {
      listener({
        kind: 'actionSuccess',
        action: request.action,
        requestId: request.requestId,
        reply,
        message: undefined,
        envelope: { type: 'action_success', data: {} },
      })
    }
  }

  fail(index: number, reason = 'Could not read the journal'): void {
    const request = this.sent[index]!
    for (const listener of this.listeners.actionError) {
      listener({
        kind: 'actionError',
        action: request.action,
        requestId: request.requestId,
        reason,
        errorCode: undefined,
        errorType: undefined,
        errorDetail: undefined,
        envelope: { type: 'action_error', data: {} },
      })
    }
  }

  state(state: ConnectionState): void {
    this.connected = state === 'connected'
    for (const listener of this.listeners.state) listener(state)
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
  const source = new Source()
  const actions = new ActionLifecycle(source)
  const context: HilosChangeLogReceiptContext = {
    connection:
      connection as unknown as HilosChangeLogReceiptContext['connection'],
    scopes,
    actions,
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
  return { context, scope, pushWindow, source }
}

const mounted: ReturnType<typeof mount>[] = []
afterEach(() => {
  for (const wrapper of mounted.splice(0)) wrapper.unmount()
})

async function mountPage(
  h: ReturnType<typeof harness>,
  tablesBuilt = false,
  r = router(tablesBuilt),
) {
  const wrapper = mount(HilosChangeLogPage, {
    props: { context: markRaw(h.context) },
    attachTo: document.body,
    global: { provide: { [hilosRouterKey as symbol]: r.value } },
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

  it.each(['escape', 'footer'])(
    'opens the receipt only on intent and returns focus on %s close',
    async (close) => {
      const h = harness(OVERVIEW, [
        { rowKey: 'receipt:7', slots: { feed: receipt() } },
      ])
      await mountPage(h)
      expect(h.source.sent).toHaveLength(0)
      const opener = await open(h)
      expect(element('modal').getAttribute('aria-label')).toBe('Receipt #7')
      expect(element('modal').querySelector('.modal-lg')).not.toBeNull()
      expect(document.activeElement).toBe(element('modal'))
      reply(h)
      await settle()
      const detail = element('hilos-change-log-detail')
      expect(detail.textContent).toContain('bot #3')
      expect(detail.textContent).toContain('bot.update')
      expect(detail.textContent).toContain('Impersonation: at the keyboard Ada')
      expect(detail.textContent).toContain('hilos_app_bots')
      expect(detail.textContent).toContain('library')
      expect(
        detail.querySelector('[data-id="hilos-change-log-more"]'),
      ).toBeNull()
      if (close === 'escape') {
        element('modal').dispatchEvent(
          new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }),
        )
      } else {
        element('hilos-change-log-detail-close').click()
      }
      await settle()
      expect(document.querySelector('[data-id="modal"]')).toBeNull()
      expect(document.activeElement).toBe(opener)
      expect(h.source.sent).toHaveLength(1)
    },
  )

  it('opens a bare change from the same actions slot, including its card projection', async () => {
    const h = harness(OVERVIEW, [
      {
        rowKey: 'entry:11',
        slots: {
          feed: receipt({ kind: 'entry', receiptId: null, entryId: 11 }),
        },
      },
    ])
    await mountPage(h)
    const cards = document.querySelector('[role="list"]')!
    element('hilos-change-log-open-entry:11', cards).click()
    await settle()
    expect(h.source.sent[0]).toMatchObject({
      action: HILOS_CHANGE_LOG_ENTRY_READ,
      data: { entryId: 11 },
    })
    h.source.success(0, { entry: { ...entry(), receiptId: null } })
    await settle()
    expect(element('modal').getAttribute('aria-label')).toBe(
      'Change #11 past the application',
    )
    const text = element('hilos-change-log-detail').textContent
    expect(text).toContain('Unknown')
    expect(text).toContain(
      'No receipt: this change was made past the application',
    )
    expect(text).not.toContain('Guest')
    expect(text).not.toContain('System')
  })

  it('renders hidden labels beside their numbers while keeping details readable', async () => {
    const h = harness(OVERVIEW, [
      {
        rowKey: 'receipt:7',
        slots: {
          feed: receipt({
            actorLabel: { _hidden: true },
            subjectLabel: { _hidden: true },
          }),
        },
      },
    ])
    await mountPage(h)
    await open(h, document.querySelector('[role="list"]')!)
    reply(
      h,
      0,
      [entry()],
      header({
        actorLabel: { _hidden: true },
        subjectLabel: { _hidden: true },
      }),
    )
    await settle()
    const text = element('hilos-change-log-detail').textContent?.replace(
      /\s+/g,
      ' ',
    )
    expect(text).toContain('Hidden (#3)')
    expect(text).toContain('Hidden (#4)')
    expect(text).toContain("The person's name is hidden in view mode.")
    expect(text).not.toContain('Ada')
    expect(text).not.toContain('Bea')
    expect(text).toContain('bot #3')
  })

  it('wires Show more and Hide at the tail, restores count and focuses the beginning after rendering', async () => {
    const h = harness(OVERVIEW, [
      { rowKey: 'receipt:7', slots: { feed: receipt() } },
    ])
    await mountPage(h)
    await open(h)
    const first = Array.from({ length: 50 }, (_, index) => entry(index + 1))
    reply(h, 0, first, header({ entryCount: 103 }), 50)
    await settle()
    expect(element('hilos-change-log-detail-count').textContent).toContain(
      'Shown 50 of 103',
    )
    expect(
      document.querySelector('[data-id="hilos-change-log-hide"]'),
    ).toBeNull()
    element('hilos-change-log-more').click()
    await settle()
    expect(
      element('hilos-change-log-more').getAttribute('disabled'),
    ).not.toBeNull()
    expect(h.source.sent[1].data).toEqual({ receiptId: 7, afterEntryId: 50 })
    reply(
      h,
      1,
      Array.from({ length: 50 }, (_, index) => entry(index + 51)),
      header({ entryCount: 103 }),
      100,
    )
    await settle()
    expect(element('hilos-change-log-detail-count').textContent).toContain(
      'Shown 100 of 103',
    )
    const heading = element('hilos-change-log-changes-heading')
    const scroll = vi.spyOn(heading, 'scrollIntoView')
    element('hilos-change-log-more').click()
    await settle()
    expect(element('hilos-change-log-hide').hasAttribute('disabled')).toBe(
      false,
    )
    element('hilos-change-log-hide').click()
    await settle()
    expect(element('hilos-change-log-detail-count').textContent).toContain(
      'Shown 50 of 103',
    )
    expect(document.activeElement).toBe(heading)
    expect(scroll).toHaveBeenCalledWith({ block: 'start' })
    expect(
      document.querySelector('[data-id="hilos-change-log-entry-51"]'),
    ).toBeNull()
    reply(
      h,
      2,
      [entry(101), entry(102), entry(103)],
      header({ entryCount: 103 }),
    )
    await settle()
    expect(element('hilos-change-log-detail-count').textContent).toContain(
      'Shown 50 of 103',
    )
    element('hilos-change-log-more').click()
    await settle()
    expect(h.source.sent[3].data).toEqual({ receiptId: 7, afterEntryId: 50 })
    h.source.fail(3)
    await settle()
    expect(element('hilos-change-log-detail-error').textContent).toContain(
      'Could not read the journal',
    )
    element('hilos-change-log-retry').click()
    await settle()
    expect(h.source.sent[4].data).toEqual({ receiptId: 7, afterEntryId: 50 })
    reply(
      h,
      4,
      Array.from({ length: 50 }, (_, index) => entry(index + 51)),
      header({ entryCount: 103 }),
      100,
    )
    await settle()
    element('hilos-change-log-more').click()
    await settle()
    reply(
      h,
      5,
      [entry(101), entry(102), entry(103)],
      header({ entryCount: 103 }),
    )
    await settle()
    expect(element('hilos-change-log-detail-count').textContent).toContain(
      'Shown 103 of 103',
    )
    expect(
      document.querySelector('[data-id="hilos-change-log-more"]'),
    ).toBeNull()
    expect(element('hilos-change-log-hide')).not.toBeNull()
    expect(element('hilos-change-log-hide').closest('.modal-footer')).toBeNull()
    scroll.mockRestore()
  })

  it('offers initial Retry and allows closing before a late read completes', async () => {
    const h = harness(OVERVIEW, [
      { rowKey: 'receipt:7', slots: { feed: receipt() } },
    ])
    await mountPage(h)
    await open(h)
    h.source.fail(0)
    await settle()
    expect(element('hilos-change-log-detail-error').textContent).toContain(
      'Could not read the journal',
    )
    element('hilos-change-log-retry').click()
    await settle()
    expect(h.source.sent[1].data).toEqual({ receiptId: 7, afterEntryId: null })
    element('hilos-change-log-detail-close').click()
    await settle()
    reply(h, 1)
    await settle()
    expect(document.querySelector('[data-id="modal"]')).toBeNull()
  })

  it.each(['leave', 'identity', 'scope', 'unmount'])(
    'discards attribution and pending details on %s',
    async (reset) => {
      const h = harness(OVERVIEW, [
        { rowKey: 'receipt:7', slots: { feed: receipt() } },
      ])
      const r = router()
      const wrapper = await mountPage(h, false, r)
      await open(h)
      reply(h)
      await settle()
      expect(element('hilos-change-log-detail').textContent).toContain('Ada')
      element('hilos-change-log-detail-close').click()
      await settle()
      element(
        'hilos-change-log-open-receipt:7',
        document.querySelector('table')!,
      ).click()
      await settle()
      if (reset === 'leave') r.leave()
      if (reset === 'identity')
        r.identity.set({
          label: 'Change Log',
          lead: '',
          breadcrumb: [],
          children: [],
        })
      if (reset === 'scope') h.context.scopes.dropPage()
      if (reset === 'unmount') wrapper.unmount()
      await settle()
      reply(h, 1)
      await settle()
      expect(
        document.querySelector('[data-id="hilos-change-log-detail"]'),
      ).toBeNull()
      expect(document.querySelector('[data-id="modal"]')).toBeNull()
    },
  )
})
