import { afterEach, describe, expect, it, vi } from 'vitest'
import { act, cleanup, render } from '@testing-library/react'
import {
  ActionLifecycle,
  CHANGE_LOG_TABLE_COPY,
  CHANGE_LOG_TABLE_SECTION,
  CHANGE_LOG_TABLES_UNTRACKED_NOTE,
  HILOS_CHANGE_LOG_RECEIPT_READ,
  HilosPages,
  ScopeManager,
  bindAdminAccess,
  bindSessionScope,
  createSignal,
  formatChangeLogTableActivity,
  hilosAdminAccess,
} from '@hilos/core'
import type {
  ActionErrorSignal,
  ActionLifecycleSource,
  ActionSuccessSignal,
  ConnectionState,
  HilosChangeLogReceiptContext,
  HilosPageIdentity,
  HilosRouter,
  PageRouteMatch,
} from '@hilos/core'

import { HilosChangeLogTablePage } from '../src/admin/changeLog/HilosChangeLogTablePage.js'
import { HilosRouterContext } from '../src/hilosRouterContext.js'

const HISTORY = 'hilosChangeLogHistory'
const BOT = {
  name: 'bot',
  owner: 'project',
  journaled: true,
  changesLast24h: 3,
  lastChangeAt: null,
  columns: [
    {
      name: 'id',
      sqlType: 'int',
      mode: 'record_key',
      storage: null,
      noiseReason: null,
    },
    {
      name: 'name',
      sqlType: 'varchar',
      mode: 'value',
      storage: 'inline',
      noiseReason: null,
    },
    {
      name: 'description',
      sqlType: 'text',
      mode: 'value',
      storage: 'long',
      noiseReason: null,
    },
    {
      name: 'email',
      sqlType: 'varchar',
      mode: 'personal',
      storage: 'fact',
      noiseReason: null,
    },
    {
      name: 'seen_at',
      sqlType: 'datetime',
      mode: 'noise',
      storage: 'ignored',
      noiseReason: 'Moves with every read',
    },
  ],
  triggers: [
    {
      name: 'hilos_cl_bot_after_insert',
      event: 'INSERT',
      table: 'bot',
      validFromMigration: 71,
    },
    {
      name: 'hilos_cl_bot_after_update',
      event: 'UPDATE',
      table: 'bot',
      validFromMigration: 71,
    },
    {
      name: 'hilos_cl_bot_after_delete',
      event: 'DELETE',
      table: 'bot',
      validFromMigration: 71,
    },
  ],
}
const SESSION = {
  name: 'hilos_session',
  owner: 'framework',
  journaled: false,
  changesLast24h: null,
  lastChangeAt: null,
  columns: [
    {
      name: 'id',
      sqlType: 'int',
      mode: null,
      storage: null,
      noiseReason: null,
    },
  ],
  triggers: [],
}

function historySlot(overrides: Record<string, unknown> = {}) {
  return {
    createdAt: '2026-10-09T10:11:12.123Z',
    recordKey: [57],
    mutation: 'update',
    changes: [
      {
        field: 'description',
        kind: 'inline',
        oldPresent: true,
        newPresent: true,
        oldValue: 'before',
        newValue: 'after',
        bodyOmitted: false,
      },
    ],
    receiptId: 7,
    actorId: 3,
    actorLabel: 'Ada',
    actorDeleted: false,
    subjectId: null,
    subjectLabel: null,
    subjectDeleted: false,
    channel: 'web',
    ...overrides,
  }
}

const ROWS = [
  { rowKey: '101', slots: { history: historySlot() } },
  {
    rowKey: '102',
    slots: {
      history: historySlot({
        recordKey: [null],
        mutation: 'delete',
        changes: [],
        receiptId: null,
        actorId: null,
        actorLabel: null,
        channel: null,
      }),
    },
  },
]

interface Events {
  actionSuccess: ActionSuccessSignal
  actionError: ActionErrorSignal
  state: ConnectionState
}

/** Exercise the real tracked lifecycle the receipt reader sends through. */
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
}

type Listener = (signal: {
  type?: string
  data: Record<string, unknown>
}) => void

function harness(section: unknown = BOT) {
  const scopes = new ScopeManager()
  const scope = scopes.openPage(HilosPages.CHANGE_LOG_TABLE)
  scope.data.set(CHANGE_LOG_TABLE_SECTION, section)
  const listeners = new Map<string, Set<Listener>>()
  const connection = {
    registerTableWindow: vi.fn(),
    unregisterTableWindow: vi.fn(),
    tableWindowDescriptors: () => ({}),
    sendTableViewport: vi.fn(() => true),
    sendTableRendered: vi.fn(() => true),
    on(event: string, listener: Listener): () => void {
      const set = listeners.get(event) ?? new Set<Listener>()
      listeners.set(event, set)
      set.add(listener)
      return () => {
        set.delete(listener)
      }
    },
  }
  const source = new Source()
  const context: HilosChangeLogReceiptContext = {
    connection:
      connection as unknown as HilosChangeLogReceiptContext['connection'],
    scopes,
    actions: new ActionLifecycle(source),
  }
  function emit(event: string, signal: Parameters<Listener>[0]) {
    for (const listener of listeners.get(event) ?? []) listener(signal)
  }
  function pushWindow(rows = ROWS) {
    emit('tableWindow', {
      data: {
        page: HilosPages.CHANGE_LOG_TABLE,
        tableKey: HISTORY,
        rows,
        totalCount: rows.length,
        totalExact: true,
        firstAnchor: null,
        lastAnchor: null,
        offset: 0,
        limit: 25,
      },
    })
  }
  function pushPageWindow() {
    emit('projectSignal', {
      type: 'page_response',
      data: {
        page: HilosPages.CHANGE_LOG_TABLE,
        payload: {
          windows: {
            [HISTORY]: {
              rows: [],
              totalCount: 0,
              totalExact: true,
              firstAnchor: null,
              lastAnchor: null,
              offset: 0,
              limit: 25,
              sort: [{ field: 'createdAt', direction: 'desc' }],
            },
          },
        },
      },
    })
  }
  return {
    context,
    scope,
    connection,
    listeners,
    source,
    emit,
    pushWindow,
    pushPageWindow,
  }
}

/** Address the page as the application's route map does. */
function tablePath(params: Record<string, string>): string {
  const record = params.record
  return `/hilos/change-log/tables/${encodeURIComponent(params.tableId ?? '')}${
    record === undefined ? '' : `/${encodeURIComponent(record)}`
  }`
}

function router(params: Record<string, string>, built = true) {
  const leaves = new Set<() => void>()
  const currentRoute = createSignal<PageRouteMatch>({
    page: HilosPages.CHANGE_LOG_TABLE,
    params,
    admin: true,
  })
  const value: HilosRouter = {
    currentRoute,
    currentPath: createSignal(tablePath(params)),
    currentTitle: createSignal('Tracked table'),
    pageError: createSignal(null),
    pageLoading: createSignal(false),
    pageIdentity: createSignal<HilosPageIdentity | undefined>({
      label: 'Tracked table',
      lead: 'One table: its columns, its journal triggers and its history.',
      breadcrumb: [],
      children: [],
    }),
    dashboardSections: createSignal(undefined),
    resolvePath: vi.fn((page: string, to: Record<string, string> = {}) =>
      built && page === HilosPages.CHANGE_LOG_TABLE ? tablePath(to) : undefined,
    ),
    clearPageError: () => {},
    denyCurrentPage: () => {},
    awaitPageAnswer: () => {},
    onLeave: (listener) => {
      leaves.add(listener)
      return () => leaves.delete(listener)
    },
    navigate: vi.fn(),
    replacePath: () => {},
    start: () => {},
    stop: () => {},
  }
  return {
    value,
    go(next: Record<string, string>) {
      leaves.forEach((listener) => listener())
      currentRoute.set({
        page: HilosPages.CHANGE_LOG_TABLE,
        params: next,
        admin: true,
      })
    },
  }
}

const releases: (() => void)[] = []

/** Wait out the microtasks a settled action resolves through. */
async function settle(): Promise<void> {
  await act(async () => {
    await Promise.resolve()
  })
}

/**
 * Mount the page inside a navigator and, unless told otherwise, answer its
 * history window.
 *
 * @param h The harness whose context the page is mounted with.
 * @param r The navigator the page reads.
 * @param push Whether to answer the history window right away.
 */
function mountPage(h = harness(), r = router({ tableId: 'bot' }), push = true) {
  const result = render(
    <HilosRouterContext.Provider value={r.value}>
      <HilosChangeLogTablePage context={h.context} />
    </HilosRouterContext.Provider>,
  )
  if (push) act(() => h.pushWindow())
  return { container: result.container, unmount: result.unmount, r }
}

function element(id: string, root: ParentNode = document): HTMLElement {
  const found = root.querySelector(`[data-id="${id}"]`)
  expect(found).not.toBeNull()
  return found as HTMLElement
}

function get(root: ParentNode, selector: string): HTMLElement {
  const found = root.querySelector(selector)
  expect(found).not.toBeNull()
  return found as HTMLElement
}

function text(node: Element): string {
  return (node.textContent ?? '').replace(/\s+/g, ' ').trim()
}

function rows(table: HTMLElement): string[][] {
  return Array.from(table.querySelectorAll('tbody tr'), (tr) =>
    Array.from(tr.querySelectorAll('td'), text),
  )
}

describe('HilosChangeLogTablePage', () => {
  // The receipt modal is portalled to the document body, so a page left mounted
  // would leave it there for the next case to find.
  afterEach(() => {
    cleanup()
    for (const release of releases.splice(0)) release()
  })

  it('draws the header, columns and service triggers of a journaled table', () => {
    const { container } = mountPage()
    expect(container.querySelectorAll('h1')).toHaveLength(1)
    const header = element('hilos-change-log-table-header', container)
    expect(text(get(header, 'code'))).toBe('bot')
    expect(header.textContent).toContain('Project')
    expect(header.textContent).toContain(CHANGE_LOG_TABLE_COPY.journaled)
    expect(header.textContent).not.toContain(CHANGE_LOG_TABLES_UNTRACKED_NOTE)
    expect(text(header)).toContain(
      formatChangeLogTableActivity({ changesLast24h: 3, lastChangeAt: null }),
    )

    const columns = element('hilos-change-log-table-columns', container)
    expect(text(get(columns, 'h2'))).toBe('Columns')
    const table = get(columns, 'table')
    expect(table.getAttribute('aria-labelledby')).toBe(
      get(columns, 'h2').getAttribute('id'),
    )
    expect(Array.from(table.querySelectorAll('th'), text)).toEqual([
      'Column',
      'Stored as',
      'In the journal',
    ])
    expect(rows(table)).toEqual([
      ['id', '—', 'Record key'],
      ['name', 'String', 'With value'],
      ['description', 'Long text', 'With value'],
      ['email', '—', 'Personal — only a fact'],
      ['seen_at', '—', 'Noisy — not written'],
    ])
    expect(
      Array.from(columns.querySelectorAll('i')).every(
        (icon) => icon.getAttribute('aria-hidden') === 'true',
      ),
    ).toBe(true)
    const cards = get(columns, '.d-md-none')
    expect(cards.getAttribute('role')).toBe('list')
    expect(cards.querySelectorAll('[role="listitem"]')).toHaveLength(5)
    expect(cards.textContent).toContain('Long text')
    expect(cards.textContent).toContain('Personal — only a fact')
    expect(columns.textContent).toContain(CHANGE_LOG_TABLE_COPY.noisyNote)

    const triggers = element('hilos-change-log-table-triggers', container)
    expect(text(get(triggers, 'h2'))).toBe('Journal service triggers')
    expect(rows(get(triggers, 'table'))).toEqual([
      [
        'hilos_cl_bot_after_insert',
        'Service',
        'AFTER INSERT on bot',
        'Journal',
        'from migration 071',
      ],
      [
        'hilos_cl_bot_after_update',
        'Service',
        'AFTER UPDATE on bot',
        'Journal',
        'from migration 071',
      ],
      [
        'hilos_cl_bot_after_delete',
        'Service',
        'AFTER DELETE on bot',
        'Journal',
        'from migration 071',
      ],
    ])
    expect(
      get(triggers, '.d-md-none').querySelectorAll('[role="listitem"]'),
    ).toHaveLength(3)
    expect(triggers.textContent).toContain(
      CHANGE_LOG_TABLE_COPY.triggersFootnote,
    )
    expect(container.textContent).toContain(CHANGE_LOG_TABLE_COPY.historyNote)
  })

  it('leaves the noisy line out when no column is noisy', () => {
    const { container } = mountPage(
      harness({
        ...BOT,
        columns: BOT.columns.filter(({ mode }) => mode !== 'noise'),
      }),
    )
    expect(container.textContent).not.toContain(CHANGE_LOG_TABLE_COPY.noisyNote)
  })

  it('draws an untracked table as its header and explanation alone', () => {
    const h = harness(SESSION)
    const { container } = mountPage(h, router({ tableId: 'hilos_session' }))
    const header = element('hilos-change-log-table-header', container)
    expect(text(get(header, 'code'))).toBe('hilos_session')
    expect(header.textContent).toContain('Framework')
    expect(header.textContent).toContain(CHANGE_LOG_TABLES_UNTRACKED_NOTE)
    expect(header.textContent).not.toContain(CHANGE_LOG_TABLE_COPY.journaled)
    expect(header.textContent).not.toContain('Last 24 hours')
    expect(container.textContent).toContain(CHANGE_LOG_TABLE_COPY.untracked)
    for (const zone of [
      'hilos-change-log-table-columns',
      'hilos-change-log-table-triggers',
      'hilos-viewport-table',
    ]) {
      expect(container.querySelector(`[data-id="${zone}"]`)).toBeNull()
    }
    expect(h.connection.registerTableWindow).not.toHaveBeenCalled()
  })

  it('draws a dash in place of the zones when the section is damaged', () => {
    const h = harness({ ...BOT, owner: 'nobody' })
    const { container } = mountPage(h)
    expect(
      container.querySelector('[data-id="hilos-change-log-table-header"]'),
    ).toBeNull()
    expect(
      container.querySelector('[data-id="hilos-viewport-table"]'),
    ).toBeNull()
    expect(container.textContent).toContain('—')
    expect(h.connection.registerTableWindow).not.toHaveBeenCalled()
  })

  it('builds the history with the table preset and links unmasked records', () => {
    const h = harness()
    const { container, r } = mountPage(h)
    expect(h.connection.registerTableWindow).toHaveBeenCalledWith(
      HISTORY,
      expect.anything(),
    )
    expect(container.textContent).toContain('All records of this table')
    expect(
      container.querySelector('[data-id="hilos-change-log-record-filter"]'),
    ).toBeNull()

    const row = get(container, 'table [data-id="hilos-table-row-101"]')
    const link = element('hilos-change-log-history-record-101', row)
    expect(text(link)).toBe('#57')
    expect(link.getAttribute('href')).toBe('/hilos/change-log/tables/bot/57')
    expect(r.value.resolvePath).toHaveBeenCalledWith(
      HilosPages.CHANGE_LOG_TABLE,
      { tableId: 'bot', record: '57' },
    )
    act(() => link.click())
    expect(r.value.navigate).toHaveBeenCalledWith(
      '/hilos/change-log/tables/bot/57',
    )
    expect(row.textContent).toContain('updated')
    expect(row.textContent).toContain('description: before → after')
    expect(row.textContent).toContain('Ada')
    expect(row.textContent).toContain('Web')
    expect(text(element('hilos-change-log-history-receipt-101', row))).toBe(
      'Receipt',
    )

    const masked = get(container, 'table [data-id="hilos-table-row-102"]')
    expect(
      masked.querySelector('[data-id="hilos-change-log-history-record-102"]'),
    ).toBeNull()
    expect(masked.querySelector('a')).toBeNull()
    expect(masked.textContent).toContain('#?')
    expect(masked.textContent).toContain('deleted')
    expect(masked.textContent).toContain('Row snapshot')
    expect(masked.textContent).toContain('Unknown')
    expect(masked.textContent).toContain(CHANGE_LOG_TABLE_COPY.pastApplication)
    expect(masked.textContent).toContain(CHANGE_LOG_TABLE_COPY.noReceipt)
    expect(
      masked.querySelector('[data-id="hilos-change-log-history-receipt-102"]'),
    ).toBeNull()
  })

  it('narrows to the address record behind a plate whose × leaves the record', () => {
    const h = harness()
    const { container, r } = mountPage(
      h,
      router({ tableId: 'bot', record: '57' }),
      false,
    )
    act(() => h.pushPageWindow())
    expect(h.connection.sendTableViewport).toHaveBeenCalledWith(
      HilosPages.CHANGE_LOG_TABLE,
      HISTORY,
      expect.objectContaining({
        filter: expect.objectContaining({ table: 'bot', record: ['57'] }),
      }),
    )
    const plate = element('hilos-change-log-record-filter', container)
    expect(text(plate)).toContain('Only record #57')
    expect(container.textContent).not.toContain('All records of this table')
    const clear = element('hilos-change-log-record-filter-clear', plate)
    expect(clear.getAttribute('href')).toBe('/hilos/change-log/tables/bot')
    expect(clear.getAttribute('aria-label')).toBe('Show all records')
    expect(get(clear, 'i').getAttribute('aria-hidden')).toBe('true')
    act(() => clear.click())
    expect(r.value.navigate).toHaveBeenCalledWith(
      '/hilos/change-log/tables/bot',
    )
  })

  it('rebuilds the history window when the address gains a record', async () => {
    const h = harness()
    const { container, r } = mountPage(h)
    expect(h.connection.registerTableWindow).toHaveBeenCalledTimes(1)
    act(() => r.go({ tableId: 'bot', record: '57' }))
    await settle()
    expect(h.connection.unregisterTableWindow).toHaveBeenCalledWith(
      HISTORY,
      h.connection.registerTableWindow.mock.calls[0]?.[1],
    )
    expect(h.connection.registerTableWindow).toHaveBeenCalledTimes(2)
    act(() => h.pushPageWindow())
    expect(h.connection.sendTableViewport).toHaveBeenCalledTimes(1)
    expect(h.connection.sendTableViewport).toHaveBeenLastCalledWith(
      HilosPages.CHANGE_LOG_TABLE,
      HISTORY,
      expect.objectContaining({
        filter: expect.objectContaining({ table: 'bot', record: ['57'] }),
      }),
    )
    expect(
      text(element('hilos-change-log-record-filter', container)),
    ).toContain('Only record #57')
  })

  it('opens the row receipt through the Change Log reading action and closes it on an address change', async () => {
    const h = harness()
    const { r } = mountPage(h)
    act(() =>
      element(
        'hilos-change-log-history-receipt-101',
        document.querySelector('[data-id="hilos-viewport-table"] table')!,
      ).click(),
    )
    await settle()
    expect(h.source.sent[0]).toMatchObject({
      action: HILOS_CHANGE_LOG_RECEIPT_READ,
      data: { receiptId: 7, afterEntryId: null },
    })
    expect(element('modal').getAttribute('aria-label')).toBe('Receipt #7')
    act(() => r.go({ tableId: 'bot', record: '57' }))
    await settle()
    expect(document.querySelector('[data-id="modal"]')).toBeNull()
  })

  it.each(['full', 'view'] as const)(
    'shows the same table under %s access and hides people from a viewer',
    (access) => {
      const h = harness()
      bindSessionScope(h.context.connection, h.context.scopes)
      releases.push(bindAdminAccess(h.context.scopes))
      h.emit('projectSignal', {
        type: 'handshake_response',
        data: {
          entities: {
            currentUser: { id: 9, name: 'Ada', admin: access === 'full' },
          },
          data: { adminViewMode: true },
        },
      })
      expect(hilosAdminAccess.get()).toBe(access)
      const { container } = mountPage(h, router({ tableId: 'bot' }), false)
      act(() =>
        h.pushWindow([
          {
            rowKey: '101',
            slots: {
              history: historySlot(
                access === 'view' ? { actorLabel: { _hidden: true } } : {},
              ),
            },
          },
        ]),
      )
      expect(
        element('hilos-change-log-table-header', container).textContent,
      ).toContain('bot')
      expect(
        element('hilos-change-log-table-columns', container).querySelectorAll(
          'tbody tr',
        ),
      ).toHaveLength(5)
      expect(
        element('hilos-change-log-table-triggers', container).querySelectorAll(
          'tbody tr',
        ),
      ).toHaveLength(3)
      const row = get(container, 'table [data-id="hilos-table-row-101"]')
      if (access === 'view') {
        expect(text(row)).toContain('Hidden (#3)')
        expect(row.textContent).not.toContain('Ada')
      } else {
        expect(row.textContent).toContain('Ada')
      }
      expect(text(element('hilos-change-log-history-receipt-101', row))).toBe(
        'Receipt',
      )
    },
  )

  it('releases the history window and every connection binding on unmount', () => {
    const h = harness()
    const { unmount } = mountPage(h)
    expect([...h.listeners.values()].some((set) => set.size > 0)).toBe(true)
    unmount()
    expect(h.connection.unregisterTableWindow).toHaveBeenCalledWith(
      HISTORY,
      h.connection.registerTableWindow.mock.calls[0]?.[1],
    )
    expect([...h.listeners.values()].every((set) => set.size === 0)).toBe(true)
  })
})
