// Real History templates, server windows and receipt reading lifecycle.
import { Component, signal } from '@angular/core'
import { TestBed, type ComponentFixture } from '@angular/core/testing'
import {
  ActionLifecycle,
  CHANGE_LOG_TABLE_COPY,
  CHANGE_LOG_TABLE_SECTION,
  HILOS_CHANGE_LOG_RECEIPT_READ,
  HilosPages,
  ScopeManager,
  bindAdminAccess,
  bindSessionScope,
  createSignal,
  pageIdentity,
  hilosAdminAccess,
  type ActionErrorSignal,
  type ActionLifecycleSource,
  type ActionSuccessSignal,
  type ConnectionState,
  type HilosChangeLogReceiptContext,
  type HilosPageIdentity,
  type HilosRouter,
  type PageError,
  type PageRouteMatch,
  type TableRowFragment,
} from '@hilos/core'
import { afterEach, describe, expect, it, vi } from 'vitest'

import { HilosChangeLogTableHistoryPage } from '../src/admin/changeLog/HilosChangeLogTableHistoryPage.js'
import { HILOS_ROUTER } from '../src/hilosRouterToken.js'

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

// A whole answer to the receipt read: had the read stayed open, it would draw.
const RECEIPT_REPLY = {
  receipt: {
    receiptId: 7,
    createdAt: '2026-10-09T10:11:12.123Z',
    actorId: 3,
    actorLabel: 'Ada',
    actorDeleted: false,
    subjectId: null,
    subjectLabel: null,
    subjectDeleted: false,
    sessionId: 5,
    channel: 'web',
    source: 'hilos_app_bots',
    action: 'bot.update',
    agent: 'library',
    entryCount: 1,
  },
  entries: [
    {
      entryId: 11,
      receiptId: 7,
      createdAt: '2026-10-09T10:11:12.123Z',
      table: 'bot',
      recordKey: [57],
      mutation: 'update',
      changes: [],
    },
  ],
  nextAfterEntryId: null,
}

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

  fail(index: number): void {
    const request = this.sent[index]!
    for (const listener of this.listeners.actionError) {
      listener({
        kind: 'actionError',
        action: request.action,
        requestId: request.requestId,
        reason: 'Could not read the journal',
        errorCode: undefined,
        errorType: undefined,
        errorDetail: undefined,
        envelope: { type: 'action_error', data: {} },
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
  const scope = scopes.openPage(HilosPages.CHANGE_LOG_TABLE_HISTORY)
  function answer(detail: unknown = BOT, target = scopes.page()!): void {
    target.data.set(CHANGE_LOG_TABLE_SECTION, detail)
    target.data.set('pageLabel', 'History')
    target.data.set('pageHeadOwner', {
      page: HilosPages.CHANGE_LOG_TABLE,
      label: 'Tracked table',
      lead: 'One table.',
      icon: 'bi-table',
    })
    target.data.set('pageTabs', [
      { page: HilosPages.CHANGE_LOG_TABLE, label: 'Overview' },
      { page: HilosPages.CHANGE_LOG_TABLE_COLUMNS, label: 'Columns' },
      { page: HilosPages.CHANGE_LOG_TABLE_TRIGGERS, label: 'Triggers' },
      { page: HilosPages.CHANGE_LOG_TABLE_HISTORY, label: 'History' },
    ])
  }
  answer(section)
  const listeners = new Map<string, Set<Listener>>()
  let heldAnswer: Parameters<Listener>[0] | undefined
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
      if (event === 'projectSignal' && heldAnswer) listener(heldAnswer)
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
  function pushWindow(rows: TableRowFragment[] = ROWS, requestId?: string) {
    emit('tableWindow', {
      data: {
        page: HilosPages.CHANGE_LOG_TABLE_HISTORY,
        tableKey: HISTORY,
        rows,
        totalCount: rows.length,
        totalExact: true,
        firstAnchor: null,
        lastAnchor: null,
        offset: 0,
        limit: 25,
        requestId,
      },
    })
  }
  function pushPageWindow() {
    heldAnswer = {
      type: 'page_response',
      data: {
        page: HilosPages.CHANGE_LOG_TABLE_HISTORY,
        payload: {
          windows: {
            [HISTORY]: {
              filter: {},
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
    }
    emit('projectSignal', heldAnswer)
  }
  return {
    context,
    scope,
    answer,
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
  return `/hilos/change-log/table/${encodeURIComponent(params.tableId ?? '')}/history${
    record === undefined ? '' : `/${encodeURIComponent(record)}`
  }`
}

function router(params: Record<string, string>, built = true) {
  const leaves = new Set<() => void>()
  const identity = createSignal<HilosPageIdentity | undefined>({
    label: 'Tracked table',
    lead: 'One table: its columns, its journal triggers and its history.',
    breadcrumb: [],
    children: [],
  })
  const currentRoute = createSignal<PageRouteMatch>({
    page: HilosPages.CHANGE_LOG_TABLE_HISTORY,
    params,
    admin: true,
  })
  const error = createSignal<PageError | null>(null)
  const value: HilosRouter = {
    currentRoute,
    currentPath: createSignal(tablePath(params)),
    currentTitle: createSignal('Tracked table'),
    pageError: error,
    pageLoading: createSignal(false),
    pageIdentity: identity,
    dashboardSections: createSignal(undefined),
    resolvePath: vi.fn((page: string, to: Record<string, string> = {}) => {
      if (!built) return undefined
      if (page === HilosPages.CHANGE_LOG_TABLE_HISTORY) return tablePath(to)
      const root = `/hilos/change-log/table/${encodeURIComponent(to.tableId ?? '')}`
      if (page === HilosPages.CHANGE_LOG_TABLE) return root
      if (page === HilosPages.CHANGE_LOG_TABLE_COLUMNS) return `${root}/columns`
      if (page === HilosPages.CHANGE_LOG_TABLE_TRIGGERS)
        return `${root}/triggers`
      return undefined
    }),
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
    identity,
    route: currentRoute,
    error,
    leaves,
    leave: () => leaves.forEach((listener) => listener()),
    go(next: Record<string, string>) {
      leaves.forEach((listener) => listener())
      currentRoute.set({
        page: HilosPages.CHANGE_LOG_TABLE_HISTORY,
        params: next,
        admin: true,
      })
    },
  }
}

/**
 * The page's parent, which drops it the way the page outlet does: the page is
 * destroyed and its DOM goes with it, which a destroyed root fixture keeps.
 */
@Component({
  selector: 'test-change-log-table-host',
  imports: [HilosChangeLogTableHistoryPage],
  template: `
    @if (shown()) {
      <hilos-change-log-table-history-page [context]="context()" />
    }
  `,
})
class PageHost {
  readonly shown = signal(true)
  readonly context = signal<HilosChangeLogReceiptContext>(null!)
}

// The host of the current case; one page is mounted per case.
let fixture: ComponentFixture<PageHost>
const releases: (() => void)[] = []
afterEach(() => {
  for (const release of releases.splice(0)) release()
  vi.restoreAllMocks()
})

/** Let a settled action and the page's own re-render reach the document. */
async function settle(): Promise<void> {
  // The tracked lifecycle settles through promises outside Angular's pending
  // tasks: let them run before waiting on the render they schedule.
  await new Promise((resolve) => setTimeout(resolve))
  await fixture.whenStable()
  fixture.detectChanges()
}

/** Mount the real History page and optionally answer its server window. */
async function mountPage(
  h = harness(),
  r = router({ tableId: 'bot' }),
  push = true,
) {
  TestBed.configureTestingModule({
    providers: [
      {
        provide: HILOS_ROUTER,
        useValue: {
          ...r.value,
          pageIdentity: pageIdentity(h.context.scopes),
        },
      },
    ],
  })
  fixture = TestBed.createComponent(PageHost)
  fixture.componentInstance.context.set(h.context)
  fixture.detectChanges()
  if (push) h.pushWindow()
  await settle()
  return { r }
}

/** Drop the page from its parent and let the removal render. */
async function unmount(): Promise<void> {
  fixture.componentInstance.shown.set(false)
  await settle()
}

function element(id: string, root: ParentNode = document): HTMLElement {
  const found = root.querySelector(`[data-id="${id}"]`)
  expect(found).not.toBeNull()
  return found as HTMLElement
}

function get(selector: string, root: ParentNode = document): HTMLElement {
  const found = root.querySelector(selector)
  expect(found).not.toBeNull()
  return found as HTMLElement
}

function text(node: Element): string {
  return (node.textContent ?? '').replace(/\s+/g, ' ').trim()
}

function listenerCounts(h: ReturnType<typeof harness>): number[] {
  return [...h.listeners.values()].map((set) => set.size)
}

/** Open the receipt of the first history row and let its read be sent. */
async function openReceipt(h: ReturnType<typeof harness>): Promise<void> {
  element(
    'hilos-change-log-history-receipt-101',
    document.querySelector('[data-id="hilos-viewport-table"] table')!,
  ).click()
  await settle()
  expect(h.source.sent.at(-1)).toMatchObject({
    action: HILOS_CHANGE_LOG_RECEIPT_READ,
    data: { receiptId: 7, afterEntryId: null },
  })
  expect(element('modal').getAttribute('aria-label')).toBe('Receipt #7')
}

describe('HilosChangeLogTableHistoryPage', () => {
  it('builds the history with the table preset and links unmasked records', async () => {
    const h = harness()
    const { r } = await mountPage(h)
    expect(h.connection.registerTableWindow).toHaveBeenCalledWith(
      HISTORY,
      expect.anything(),
    )
    expect(document.body.textContent).toContain('All records of this table')
    expect(document.querySelectorAll('h1')).toHaveLength(1)
    expect(text(get('h1'))).toBe('bot')
    expect(get('table').getAttribute('aria-labelledby')).toBe(get('h1').id)
    expect(
      get('[data-id="hilos-table-cards"] [role="list"]').getAttribute(
        'aria-labelledby',
      ),
    ).toBe(get('h1').id)
    expect(document.querySelector('h2')).toBeNull()
    expect(
      document.querySelectorAll('[data-id="hilos-table-subtitle"]'),
    ).toHaveLength(1)
    expect(text(element('hilos-table-subtitle'))).toBe(
      'All records of this table',
    )
    expect(
      document.querySelector('[data-id="hilos-change-log-table-overview"]'),
    ).toBeNull()
    expect(
      document.querySelector('[data-id="hilos-change-log-record-filter"]'),
    ).toBeNull()

    const row = get('table [data-id="hilos-table-row-101"]')
    const link = element('hilos-change-log-history-record-101', row)
    expect(text(link)).toBe('#57')
    expect(link.getAttribute('href')).toBe(
      '/hilos/change-log/table/bot/history/57',
    )
    expect(r.value.resolvePath).toHaveBeenCalledWith(
      HilosPages.CHANGE_LOG_TABLE_HISTORY,
      { tableId: 'bot', record: '57' },
    )
    link.click()
    expect(r.value.navigate).toHaveBeenCalledWith(
      '/hilos/change-log/table/bot/history/57',
    )
    expect(row.textContent).toContain('updated')
    const changes = row.querySelectorAll('td')[3]!
    expect(text(get('code', changes))).toBe('description')
    expect(text(get('strong', changes))).toBe('after')
    expect(changes.textContent).toContain('before')
    expect(row.textContent).toContain('Ada')
    expect(row.textContent).toContain('Web')
    expect(text(element('hilos-change-log-history-receipt-101', row))).toBe(
      'Receipt',
    )

    const masked = get('table [data-id="hilos-table-row-102"]')
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

  it('renders literal inline values beside Was/Now, facts without personal values and long changes without their bodies', async () => {
    const h = harness()
    await mountPage(h)
    const before = '<img src="before" onerror="alert(1)">'
    const after = '<b>literal new value</b>'
    h.pushWindow([
      {
        rowKey: '101',
        slots: {
          history: historySlot({
            changes: [
              {
                field: 'name',
                kind: 'inline',
                oldPresent: true,
                newPresent: true,
                oldValue: before,
                newValue: after,
                bodyOmitted: false,
              },
              {
                field: 'email',
                kind: 'fact',
                oldPresent: true,
                newPresent: true,
                oldValue: 'private-before@example.test',
                newValue: 'private-after@example.test',
                bodyOmitted: false,
              },
              {
                field: 'description',
                kind: 'long',
                oldPresent: true,
                newPresent: true,
                oldValue: 'private long before',
                newValue: 'private long after',
                bodyOmitted: false,
              },
            ],
          }),
        },
      },
      {
        rowKey: '103',
        slots: { history: historySlot({ mutation: 'create' }) },
      },
      {
        rowKey: '104',
        slots: { history: historySlot({ mutation: 'delete' }) },
      },
    ])
    await settle()
    const row = get('table [data-id="hilos-table-row-101"]')
    const operation = row.querySelectorAll('td')[2]!
    expect(text(operation)).toBe('updated')
    expect(get('i', operation).getAttribute('aria-hidden')).toBe('true')
    const changes = row.querySelectorAll('td')[3]!
    const fields = Array.from(changes.querySelectorAll('code'))
    expect(fields.map(text)).toEqual(['name', 'email', 'description'])
    const inline = fields[0]!.parentElement!
    const oldValue = inline.querySelectorAll('span')[1]!
    const newValue = get('strong', inline)
    expect(oldValue.textContent).toBe(before)
    expect(newValue.textContent).toBe(after)
    expect(text(oldValue.previousElementSibling!)).toBe('Was')
    expect(text(newValue.previousElementSibling!)).toBe('Now')
    expect(get('i', inline).getAttribute('aria-hidden')).toBe('true')
    expect(inline.querySelector('img, b')).toBeNull()
    const fact = fields[1]!.parentElement!
    expect(text(fact)).toBe('email changed Personal — no value')
    expect(get('i', fact).getAttribute('aria-hidden')).toBe('true')
    expect(text(fields[2]!.parentElement!)).toBe(
      'description long text changed',
    )
    for (const secret of [
      'private-before@example.test',
      'private-after@example.test',
      'private long before',
      'private long after',
    ]) {
      expect(document.body.textContent).not.toContain(secret)
      expect(document.body.innerHTML).not.toContain(secret)
    }
    const created = get('table [data-id="hilos-table-row-103"]')
    const deleted = get('table [data-id="hilos-table-row-104"]')
    expect(text(created.querySelectorAll('td')[2]!)).toBe('created')
    expect(text(created.querySelectorAll('td')[3]!)).toBe('Created as a fact')
    expect(text(deleted.querySelectorAll('td')[2]!)).toBe('deleted')
    expect(text(deleted.querySelectorAll('td')[3]!)).toBe(
      'Row snapshot — in the receipt',
    )
    expect(
      deleted.querySelectorAll('td')[3]!.querySelector('strong'),
    ).toBeNull()
    expect(
      created.querySelectorAll('td')[3]!.querySelector('strong'),
    ).toBeNull()
  })

  it('offers the field names of a later page answer in the history filter', async () => {
    const h = harness()
    await mountPage(h)
    const table = get('table')
    const counts = listenerCounts(h)
    h.scope.data.set(CHANGE_LOG_TABLE_SECTION, {
      ...BOT,
      columns: [
        ...BOT.columns,
        {
          name: 'title',
          sqlType: 'varchar',
          mode: 'value',
          storage: 'inline',
          noiseReason: null,
        },
      ],
    })
    await settle()
    expect(
      document.querySelector('[data-id="hilos-change-log-table-columns"]'),
    ).toBeNull()

    const fieldFilter = get('[data-id="hilos-table-filter-field"]')
    get('button', fieldFilter).click()
    await settle()
    expect(fieldFilter.textContent).toContain('description')
    expect(fieldFilter.textContent).toContain('title')
    expect(fieldFilter.textContent).not.toContain('id')
    expect(get('table')).toBe(table)
    expect(listenerCounts(h)).toEqual(counts)
    expect(h.connection.registerTableWindow).toHaveBeenCalledTimes(1)
    expect(h.connection.unregisterTableWindow).not.toHaveBeenCalled()
  })

  it('preserves the record preset while changing field, period and sort through the server window', async () => {
    const h = harness()
    await mountPage(h, router({ tableId: 'bot', record: '57' }))
    h.pushPageWindow()
    await settle()
    const field = element('hilos-table-filter-field')
    get('button', field).click()
    await settle()
    const choice = Array.from(
      field.querySelectorAll<HTMLElement>('[role="option"]'),
    ).find((option) => text(option) === 'name')!
    expect(choice).toBeDefined()
    choice.click()
    await settle()
    expect(h.connection.sendTableViewport).toHaveBeenLastCalledWith(
      HilosPages.CHANGE_LOG_TABLE_HISTORY,
      HISTORY,
      expect.objectContaining({
        filter: { table: 'bot', record: ['57'], field: 'name' },
      }),
    )
    const period = element('hilos-table-filter-period')
    get('button', period).click()
    await settle()
    const day = Array.from(
      period.querySelectorAll<HTMLElement>('[role="option"]'),
    ).find((option) => text(option) === 'Last 24 hours')!
    expect(day).toBeDefined()
    day.click()
    await settle()
    expect(h.connection.sendTableViewport).toHaveBeenLastCalledWith(
      HilosPages.CHANGE_LOG_TABLE_HISTORY,
      HISTORY,
      expect.objectContaining({
        filter: { table: 'bot', record: ['57'], field: 'name', period: 'day' },
      }),
    )
    element('hilos-table-sort-createdAt').click()
    await settle()
    expect(h.connection.sendTableViewport).toHaveBeenLastCalledWith(
      HilosPages.CHANGE_LOG_TABLE_HISTORY,
      HISTORY,
      expect.objectContaining({
        filter: { table: 'bot', record: ['57'], field: 'name', period: 'day' },
        sort: [{ field: 'createdAt', direction: 'asc' }],
      }),
    )
    expect(h.connection.registerTableWindow).toHaveBeenCalledTimes(1)
  })

  it.each(['scope', 'route', 'name', 'damaged'] as const)(
    'waits for a permitted answer and refuses a wrong %s',
    async (wrong) => {
      const h = harness(null)
      const { r } = await mountPage(h)
      expect(document.querySelector('table')).toBeNull()
      expect(h.connection.registerTableWindow).not.toHaveBeenCalled()
      if (wrong === 'scope')
        h.context.scopes.openPage(HilosPages.CHANGE_LOG_TABLE)
      if (wrong === 'route')
        r.route.set({ ...r.route.get(), page: HilosPages.CHANGE_LOG_TABLE })
      h.answer(
        wrong === 'name'
          ? { ...BOT, name: 'other' }
          : wrong === 'damaged'
            ? { name: 'bot' }
            : BOT,
      )
      await settle()
      expect(document.querySelector('table')).toBeNull()
      expect(h.connection.registerTableWindow).not.toHaveBeenCalled()
      if (wrong === 'scope')
        h.context.scopes.openPage(HilosPages.CHANGE_LOG_TABLE_HISTORY)
      r.route.set({
        ...r.route.get(),
        page: HilosPages.CHANGE_LOG_TABLE_HISTORY,
      })
      h.answer()
      await settle()
      h.pushWindow()
      await settle()
      expect(get('table').textContent).toContain('Ada')
      expect(h.connection.registerTableWindow).toHaveBeenCalledTimes(1)
    },
  )

  it('starts from a later permitted first answer and consumes its buffered window through the preset exchange', async () => {
    const h = harness(null)
    await mountPage(h, router({ tableId: 'bot' }), false)
    h.answer()
    h.pushPageWindow()
    await settle()
    expect(h.connection.registerTableWindow).toHaveBeenCalledTimes(1)
    expect(h.connection.sendTableViewport).toHaveBeenCalledTimes(1)
    expect(h.connection.sendTableViewport).toHaveBeenCalledWith(
      HilosPages.CHANGE_LOG_TABLE_HISTORY,
      HISTORY,
      expect.objectContaining({
        filter: { table: 'bot' },
        sort: [{ field: 'createdAt', direction: 'desc' }],
      }),
    )
    expect(get('tbody').textContent).not.toContain('Ada')
  })

  it('replaces the window, DOM and pending receipt on a new scope at the same address and record', async () => {
    const h = harness()
    await mountPage(h, router({ tableId: 'bot', record: '57' }))
    const oldTable = get('table')
    const oldController = h.connection.registerTableWindow.mock.calls[0]![1]
    const counts = listenerCounts(h)
    await openReceipt(h)
    h.context.scopes.openPage(HilosPages.CHANGE_LOG_TABLE_HISTORY)
    h.answer()
    await settle()
    expect(h.connection.unregisterTableWindow).toHaveBeenCalledWith(
      HISTORY,
      oldController,
    )
    expect(h.connection.registerTableWindow).toHaveBeenCalledTimes(2)
    expect(h.connection.registerTableWindow.mock.calls[1]![1]).not.toBe(
      oldController,
    )
    expect(oldTable.isConnected).toBe(false)
    expect(get('tbody').textContent).not.toContain('Ada')
    expect(document.querySelector('[data-id="modal"]')).toBeNull()
    h.source.success(0, RECEIPT_REPLY)
    await settle()
    expect(document.querySelector('[data-id="modal"]')).toBeNull()
    expect(text(element('hilos-change-log-record-filter'))).toContain(
      'Only record #57',
    )
    h.pushWindow()
    await settle()
    expect(get('table').textContent).toContain('Ada')
    expect(listenerCounts(h)).toEqual(counts)
    await openReceipt(h)
    expect(h.source.sent).toHaveLength(2)
  })

  it('rebinds contexts and scope managers, cancels pending reads and ignores former sources', async () => {
    const h = harness()
    const { r } = await mountPage(h)
    await openReceipt(h)
    let oldTable = get('table')
    fixture.componentInstance.context.set({ ...h.context })
    await settle()
    expect(h.connection.registerTableWindow).toHaveBeenCalledTimes(2)
    expect(h.connection.unregisterTableWindow).toHaveBeenCalledTimes(1)
    expect(oldTable.isConnected).toBe(false)
    expect(document.querySelector('[data-id="modal"]')).toBeNull()
    h.source.success(0, RECEIPT_REPLY)
    await settle()
    expect(document.querySelector('[data-id="modal"]')).toBeNull()
    h.pushWindow()
    await settle()
    await openReceipt(h)
    oldTable = get('table')
    const scopes = new ScopeManager()
    scopes.openPage(HilosPages.CHANGE_LOG_TABLE_HISTORY)
    fixture.componentInstance.context.set({ ...h.context, scopes })
    await settle()
    expect(oldTable.isConnected).toBe(false)
    expect(document.querySelector('table')).toBeNull()
    expect(listenerCounts(h).every((size) => size === 0)).toBe(true)
    h.answer(BOT, scopes.page()!)
    await settle()
    h.pushWindow()
    await settle()
    expect(get('table').textContent).toContain('Ada')
    const next = harness()
    fixture.componentInstance.context.set(next.context)
    await settle()
    next.pushWindow([ROWS[1]!])
    await settle()
    const table = get('table')
    expect(listenerCounts(h).every((size) => size === 0)).toBe(true)
    h.answer({ ...BOT, name: 'stale' }, scopes.page()!)
    h.pushWindow()
    h.source.success(1, RECEIPT_REPLY)
    await settle()
    expect(get('table')).toBe(table)
    expect(get('tbody').textContent).not.toContain('Ada')
    expect(document.querySelector('[data-id="modal"]')).toBeNull()
    next.pushWindow()
    await settle()
    await openReceipt(next)
    await unmount()
    expect(listenerCounts(next).every((size) => size === 0)).toBe(true)
    expect(r.leaves.size).toBe(0)
    next.source.success(0, RECEIPT_REPLY)
    await settle()
    expect(document.querySelector('[data-id="modal"]')).toBeNull()
  })

  it.each(['answer', 'error', 'route', 'scope', 'address'] as const)(
    'removes the old window and listeners on loss of its current %s',
    async (lost) => {
      const h = harness()
      const { r } = await mountPage(h)
      const table = get('table')
      if (lost === 'answer') h.answer(null)
      if (lost === 'error')
        r.error.set({
          httpCode: 403,
          errorCode: 'forbidden',
          title: 'Access denied',
          message: 'This page is closed.',
        })
      if (lost === 'route')
        r.route.set({ ...r.route.get(), page: HilosPages.CHANGE_LOG_TABLE })
      if (lost === 'scope') {
        h.context.scopes.openPage(HilosPages.CHANGE_LOG_TABLE)
        h.answer()
      }
      if (lost === 'address') r.go({ tableId: 'other' })
      await settle()
      expect(table.isConnected).toBe(false)
      expect(document.querySelector('table')).toBeNull()
      expect(listenerCounts(h).every((size) => size === 0)).toBe(true)
      expect(h.connection.unregisterTableWindow.mock.calls).toEqual(
        h.connection.registerTableWindow.mock.calls,
      )
      r.error.set(null)
      r.route.set({
        ...r.route.get(),
        page: HilosPages.CHANGE_LOG_TABLE_HISTORY,
      })
      if (lost === 'scope')
        h.context.scopes.openPage(HilosPages.CHANGE_LOG_TABLE_HISTORY)
      h.answer(lost === 'address' ? { ...BOT, name: 'other' } : BOT)
      await settle()
      h.pushWindow()
      await settle()
      expect(h.connection.registerTableWindow).toHaveBeenCalledTimes(2)
      expect(get('table').textContent).toContain('Ada')
    },
  )

  it('narrows to the address record behind a plate whose × leaves the record', async () => {
    const h = harness()
    const { r } = await mountPage(
      h,
      router({ tableId: 'bot', record: '57' }),
      false,
    )
    h.pushPageWindow()
    expect(h.connection.sendTableViewport).toHaveBeenCalledWith(
      HilosPages.CHANGE_LOG_TABLE_HISTORY,
      HISTORY,
      expect.objectContaining({
        filter: expect.objectContaining({ table: 'bot', record: ['57'] }),
      }),
    )
    await settle()
    const plate = element('hilos-change-log-record-filter')
    expect(text(plate)).toContain('Only record #57')
    expect(document.body.textContent).not.toContain('All records of this table')
    const clear = element('hilos-change-log-record-filter-clear', plate)
    expect(clear.getAttribute('href')).toBe(
      '/hilos/change-log/table/bot/history',
    )
    expect(clear.getAttribute('aria-label')).toBe('Show all records')
    expect(get('i', clear).getAttribute('aria-hidden')).toBe('true')
    clear.click()
    expect(r.value.navigate).toHaveBeenCalledWith(
      '/hilos/change-log/table/bot/history',
    )
  })

  it('rebuilds the history window when the address gains a record', async () => {
    const h = harness()
    const { r } = await mountPage(h)
    expect(h.connection.registerTableWindow).toHaveBeenCalledTimes(1)
    const before = element('hilos-viewport-table')
    r.go({ tableId: 'bot', record: '57' })
    await settle()
    expect(h.connection.unregisterTableWindow).toHaveBeenCalledWith(
      HISTORY,
      h.connection.registerTableWindow.mock.calls[0]?.[1],
    )
    expect(h.connection.registerTableWindow).toHaveBeenCalledTimes(2)
    // The table reads its controller's declaration once: the new window is
    // drawn by a table of its own.
    expect(element('hilos-viewport-table')).not.toBe(before)
    expect(before.isConnected).toBe(false)
    h.pushPageWindow()
    expect(h.connection.sendTableViewport).toHaveBeenCalledTimes(1)
    expect(h.connection.sendTableViewport).toHaveBeenLastCalledWith(
      HilosPages.CHANGE_LOG_TABLE_HISTORY,
      HISTORY,
      expect.objectContaining({
        filter: expect.objectContaining({ table: 'bot', record: ['57'] }),
      }),
    )
    await settle()
    expect(text(element('hilos-change-log-record-filter'))).toContain(
      'Only record #57',
    )
  })

  it('releases the history window when the section loses its journal label', async () => {
    const h = harness()
    await mountPage(h)
    expect(h.connection.registerTableWindow).toHaveBeenCalledTimes(1)
    h.scope.data.set(CHANGE_LOG_TABLE_SECTION, { ...BOT, journaled: false })
    await settle()
    expect(h.connection.unregisterTableWindow).toHaveBeenCalledWith(
      HISTORY,
      h.connection.registerTableWindow.mock.calls[0]?.[1],
    )
    expect(h.connection.registerTableWindow).toHaveBeenCalledTimes(1)
    expect(
      document.querySelector('[data-id="hilos-viewport-table"]'),
    ).toBeNull()
    expect(document.body.textContent).toContain(CHANGE_LOG_TABLE_COPY.untracked)
  })

  it('opens the row receipt through the Change Log reading action and closes it on an address change', async () => {
    const h = harness()
    const { r } = await mountPage(h)
    await openReceipt(h)
    r.go({ tableId: 'bot', record: '57' })
    await settle()
    expect(document.querySelector('[data-id="modal"]')).toBeNull()
  })

  it.each(['escape', 'footer'] as const)(
    'keeps the receipt retryable and cancels a late read after %s closes it',
    async (close) => {
      const h = harness()
      await mountPage(h)
      await openReceipt(h)
      h.source.fail(0)
      await settle()
      expect(element('hilos-change-log-detail-error').textContent).toContain(
        'Could not read the journal',
      )
      element('hilos-change-log-retry').click()
      await settle()
      expect(h.source.sent[1]).toMatchObject({
        action: HILOS_CHANGE_LOG_RECEIPT_READ,
        data: { receiptId: 7, afterEntryId: null },
      })
      if (close === 'escape')
        element('modal').dispatchEvent(
          new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }),
        )
      else element('hilos-change-log-detail-close').click()
      await settle()
      h.source.success(1, RECEIPT_REPLY)
      await settle()
      expect(document.querySelector('[data-id="modal"]')).toBeNull()
      await openReceipt(h)
      h.source.success(2, RECEIPT_REPLY)
      await settle()
      expect(element('hilos-change-log-detail').textContent).toContain('Ada')
    },
  )

  it.each(['leave', 'identity', 'scope', 'unmount'])(
    'discards the receipt and its pending read on %s',
    async (reset) => {
      const h = harness()
      const { r } = await mountPage(h)
      await openReceipt(h)
      if (reset === 'leave') r.leave()
      if (reset === 'identity') h.scope.data.set('pageLabel', 'Redecided')
      if (reset === 'scope') h.context.scopes.dropPage()
      if (reset === 'unmount') await unmount()
      await settle()
      h.source.success(0, RECEIPT_REPLY)
      await settle()
      expect(
        document.querySelector('[data-id="hilos-change-log-detail"]'),
      ).toBeNull()
      expect(document.querySelector('[data-id="modal"]')).toBeNull()
    },
  )

  it.each(['full', 'view'] as const)(
    'shows the same table under %s access and hides people from a viewer',
    async (access) => {
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
      await mountPage(h, router({ tableId: 'bot' }), false)
      h.pushWindow([
        {
          rowKey: '101',
          slots: {
            history: historySlot(
              access === 'view'
                ? {
                    actorLabel: { _hidden: true },
                    subjectId: 4,
                    subjectLabel: { _hidden: true },
                  }
                : { subjectId: 4, subjectLabel: 'Grace' },
            ),
          },
        },
      ])
      await settle()
      const row = get('table [data-id="hilos-table-row-101"]')
      if (access === 'view') {
        expect(text(row)).toContain('Hidden (#3)')
        expect(text(row)).toContain('on behalf of Hidden (#4)')
        expect(row.textContent).not.toContain('Ada')
        expect(row.textContent).not.toContain('Grace')
      } else {
        expect(row.textContent).toContain('Ada')
        expect(row.textContent).toContain('Grace')
      }
      expect(text(element('hilos-change-log-history-receipt-101', row))).toBe(
        'Receipt',
      )
    },
  )

  it('releases the history window and every connection binding on unmount', async () => {
    const h = harness()
    const { r } = await mountPage(h)
    expect([...h.listeners.values()].some((set) => set.size > 0)).toBe(true)
    expect(r.leaves.size).toBe(1)
    await openReceipt(h)
    await unmount()
    expect(h.connection.unregisterTableWindow).toHaveBeenCalledWith(
      HISTORY,
      h.connection.registerTableWindow.mock.calls[0]?.[1],
    )
    expect([...h.listeners.values()].every((set) => set.size === 0)).toBe(true)
    expect(r.leaves.size).toBe(0)
    h.source.success(0, RECEIPT_REPLY)
    await settle()
    expect(h.source.sent).toHaveLength(1)
    expect(document.querySelector('[data-id="modal"]')).toBeNull()
  })

  it('refuses to build without a provided router', () => {
    expect(() =>
      TestBed.createComponent(HilosChangeLogTableHistoryPage),
    ).toThrow(
      'HilosChangeLogTableHistoryPage requires a provided Hilos router.',
    )
  })
})
