// The Angular peer of vue/src/admin/changeLog/HilosChangeLogTablePage.test.ts and
// react/test/HilosChangeLogTablePage.test.tsx, under the same case names and over
// the same fixtures: the header with its activity line, the columns and service
// triggers, the untracked and damaged sections, the history with its record
// links, the record plate, a new window and a new table for a new address, the
// receipt read through the Change Log's action and its closes, the viewer's
// hidden names and the release of everything on destroy. Two cases have no Vue
// counterpart because they pin what Angular wires itself: the history's Field
// filter follows a later page answer, and the page refuses to build without a
// router.
import { Component, signal } from '@angular/core'
import { TestBed, type ComponentFixture } from '@angular/core/testing'
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
  type ActionErrorSignal,
  type ActionLifecycleSource,
  type ActionSuccessSignal,
  type ConnectionState,
  type HilosChangeLogReceiptContext,
  type HilosPageIdentity,
  type HilosRouter,
  type PageRouteMatch,
} from '@hilos/core'
import { afterEach, describe, expect, it, vi } from 'vitest'

import { HilosChangeLogTablePage } from '../src/admin/changeLog/HilosChangeLogTablePage.js'
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
  const identity = createSignal<HilosPageIdentity | undefined>({
    label: 'Tracked table',
    lead: 'One table: its columns, its journal triggers and its history.',
    breadcrumb: [],
    children: [],
  })
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
    pageIdentity: identity,
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
    identity,
    leaves,
    leave: () => leaves.forEach((listener) => listener()),
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

/**
 * The page's parent, which drops it the way the page outlet does: the page is
 * destroyed and its DOM goes with it, which a destroyed root fixture keeps.
 */
@Component({
  selector: 'test-change-log-table-host',
  imports: [HilosChangeLogTablePage],
  template: `
    @if (shown()) {
      <hilos-change-log-table-page [context]="context" />
    }
  `,
})
class PageHost {
  readonly shown = signal(true)
  context!: HilosChangeLogReceiptContext
}

// The host of the current case; one page is mounted per case.
let fixture: ComponentFixture<PageHost>
const releases: (() => void)[] = []
afterEach(() => {
  for (const release of releases.splice(0)) release()
})

/** Let a settled action and the page's own re-render reach the document. */
async function settle(): Promise<void> {
  // The tracked lifecycle settles through promises outside Angular's pending
  // tasks: let them run before waiting on the render they schedule.
  await new Promise((resolve) => setTimeout(resolve))
  await fixture.whenStable()
  fixture.detectChanges()
}

/**
 * Mount the page inside a navigator and, unless told not to, answer its history
 * window.
 *
 * @param h The harness whose context the page is mounted with.
 * @param r The navigator the page reads.
 * @param push Whether the history window arrives right after the mount.
 */
async function mountPage(
  h = harness(),
  r = router({ tableId: 'bot' }),
  push = true,
) {
  TestBed.configureTestingModule({
    providers: [{ provide: HILOS_ROUTER, useValue: r.value }],
  })
  fixture = TestBed.createComponent(PageHost)
  fixture.componentInstance.context = h.context
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

function rows(table: Element): string[][] {
  return Array.from(table.querySelectorAll('tbody tr'), (tr) =>
    Array.from(tr.querySelectorAll('td'), text),
  )
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

describe('HilosChangeLogTablePage', () => {
  it('draws the header, columns and service triggers of a journaled table', async () => {
    await mountPage()
    expect(document.querySelectorAll('h1')).toHaveLength(1)
    const header = element('hilos-change-log-table-header')
    expect(text(get('code', header))).toBe('bot')
    expect(header.textContent).toContain('Project')
    expect(header.textContent).toContain(CHANGE_LOG_TABLE_COPY.journaled)
    expect(header.textContent).not.toContain(CHANGE_LOG_TABLES_UNTRACKED_NOTE)
    expect(text(header)).toContain(
      formatChangeLogTableActivity({ changesLast24h: 3, lastChangeAt: null }),
    )

    const columns = element('hilos-change-log-table-columns')
    expect(text(get('h2', columns))).toBe('Columns')
    const table = get('table', columns)
    expect(table.getAttribute('aria-labelledby')).toBe(
      get('h2', columns).getAttribute('id'),
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
    const cards = get('.d-md-none', columns)
    expect(cards.getAttribute('role')).toBe('list')
    expect(cards.getAttribute('aria-labelledby')).toBe(
      get('h2', columns).getAttribute('id'),
    )
    expect(cards.querySelectorAll('[role="listitem"]')).toHaveLength(5)
    expect(cards.textContent).toContain('Long text')
    expect(cards.textContent).toContain('Personal — only a fact')
    expect(columns.textContent).toContain(CHANGE_LOG_TABLE_COPY.noisyNote)

    const triggers = element('hilos-change-log-table-triggers')
    expect(text(get('h2', triggers))).toBe('Journal service triggers')
    expect(get('h2', triggers).getAttribute('id')).not.toBe(
      get('h2', columns).getAttribute('id'),
    )
    expect(rows(get('table', triggers))).toEqual([
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
      get('.d-md-none', triggers).querySelectorAll('[role="listitem"]'),
    ).toHaveLength(3)
    expect(triggers.textContent).toContain(
      CHANGE_LOG_TABLE_COPY.triggersFootnote,
    )
    expect(document.body.textContent).toContain(
      CHANGE_LOG_TABLE_COPY.historyNote,
    )
  })

  it('leaves the noisy line out when no column is noisy', async () => {
    await mountPage(
      harness({
        ...BOT,
        columns: BOT.columns.filter(({ mode }) => mode !== 'noise'),
      }),
    )
    expect(document.body.textContent).not.toContain(
      CHANGE_LOG_TABLE_COPY.noisyNote,
    )
  })

  it('draws an untracked table as its header and explanation alone', async () => {
    const h = harness(SESSION)
    await mountPage(h, router({ tableId: 'hilos_session' }))
    const header = element('hilos-change-log-table-header')
    expect(text(get('code', header))).toBe('hilos_session')
    expect(header.textContent).toContain('Framework')
    expect(header.textContent).toContain(CHANGE_LOG_TABLES_UNTRACKED_NOTE)
    expect(header.textContent).not.toContain(CHANGE_LOG_TABLE_COPY.journaled)
    expect(header.textContent).not.toContain('Last 24 hours')
    expect(document.body.textContent).toContain(CHANGE_LOG_TABLE_COPY.untracked)
    for (const zone of [
      'hilos-change-log-table-columns',
      'hilos-change-log-table-triggers',
      'hilos-viewport-table',
    ]) {
      expect(document.querySelector(`[data-id="${zone}"]`)).toBeNull()
    }
    expect(h.connection.registerTableWindow).not.toHaveBeenCalled()
  })

  it('draws a dash in place of the zones when the section is damaged', async () => {
    const h = harness({ ...BOT, owner: 'nobody' })
    await mountPage(h)
    expect(
      document.querySelector('[data-id="hilos-change-log-table-header"]'),
    ).toBeNull()
    expect(
      document.querySelector('[data-id="hilos-viewport-table"]'),
    ).toBeNull()
    expect(document.body.textContent).toContain('—')
    expect(h.connection.registerTableWindow).not.toHaveBeenCalled()
  })

  it('builds the history with the table preset and links unmasked records', async () => {
    const h = harness()
    const { r } = await mountPage(h)
    expect(h.connection.registerTableWindow).toHaveBeenCalledWith(
      HISTORY,
      expect.anything(),
    )
    expect(document.body.textContent).toContain('All records of this table')
    expect(
      document.querySelector('[data-id="hilos-change-log-record-filter"]'),
    ).toBeNull()

    const row = get('table [data-id="hilos-table-row-101"]')
    const link = element('hilos-change-log-history-record-101', row)
    expect(text(link)).toBe('#57')
    expect(link.getAttribute('href')).toBe('/hilos/change-log/tables/bot/57')
    expect(r.value.resolvePath).toHaveBeenCalledWith(
      HilosPages.CHANGE_LOG_TABLE,
      { tableId: 'bot', record: '57' },
    )
    link.click()
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

  it('offers the field names of a later page answer in the history filter', async () => {
    const h = harness()
    await mountPage(h)
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
    expect(text(element('hilos-change-log-table-columns'))).toContain('title')

    const fieldFilter = get('[data-id="hilos-table-filter-field"]')
    get('button', fieldFilter).click()
    await settle()
    expect(fieldFilter.textContent).toContain('description')
    expect(fieldFilter.textContent).toContain('title')
    expect(h.connection.registerTableWindow).toHaveBeenCalledTimes(1)
    expect(h.connection.unregisterTableWindow).not.toHaveBeenCalled()
  })

  it('narrows to the address record behind a plate whose × leaves the record', async () => {
    const h = harness()
    const { r } = await mountPage(
      h,
      router({ tableId: 'bot', record: '57' }),
      false,
    )
    h.pushPageWindow()
    expect(h.connection.sendTableViewport).toHaveBeenCalledWith(
      HilosPages.CHANGE_LOG_TABLE,
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
    expect(clear.getAttribute('href')).toBe('/hilos/change-log/tables/bot')
    expect(clear.getAttribute('aria-label')).toBe('Show all records')
    expect(get('i', clear).getAttribute('aria-hidden')).toBe('true')
    clear.click()
    expect(r.value.navigate).toHaveBeenCalledWith(
      '/hilos/change-log/tables/bot',
    )
  })

  it('rebuilds the history window when the address gains a record', async () => {
    const h = harness()
    const { r } = await mountPage(h)
    expect(h.connection.registerTableWindow).toHaveBeenCalledTimes(1)
    const before = element('hilos-viewport-table')
    r.go({ tableId: 'bot', record: '57' })
    await settle()
    expect(h.connection.unregisterTableWindow).toHaveBeenCalledWith(HISTORY)
    expect(h.connection.registerTableWindow).toHaveBeenCalledTimes(2)
    // The table reads its controller's declaration once: the new window is
    // drawn by a table of its own.
    expect(element('hilos-viewport-table')).not.toBe(before)
    expect(before.isConnected).toBe(false)
    h.pushPageWindow()
    expect(h.connection.sendTableViewport).toHaveBeenCalledTimes(1)
    expect(h.connection.sendTableViewport).toHaveBeenLastCalledWith(
      HilosPages.CHANGE_LOG_TABLE,
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
    expect(h.connection.unregisterTableWindow).toHaveBeenCalledWith(HISTORY)
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

  it.each(['leave', 'identity', 'scope', 'unmount'])(
    'discards the receipt and its pending read on %s',
    async (reset) => {
      const h = harness()
      const { r } = await mountPage(h)
      await openReceipt(h)
      if (reset === 'leave') r.leave()
      if (reset === 'identity')
        r.identity.set({
          label: 'Tracked table',
          lead: '',
          breadcrumb: [],
          children: [],
        })
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
              access === 'view' ? { actorLabel: { _hidden: true } } : {},
            ),
          },
        },
      ])
      await settle()
      expect(element('hilos-change-log-table-header').textContent).toContain(
        'bot',
      )
      expect(
        element('hilos-change-log-table-columns').querySelectorAll('tbody tr'),
      ).toHaveLength(5)
      expect(
        element('hilos-change-log-table-triggers').querySelectorAll('tbody tr'),
      ).toHaveLength(3)
      const row = get('table [data-id="hilos-table-row-101"]')
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

  it('releases the history window and every connection binding on unmount', async () => {
    const h = harness()
    const { r } = await mountPage(h)
    expect([...h.listeners.values()].some((set) => set.size > 0)).toBe(true)
    expect(r.leaves.size).toBe(1)
    await openReceipt(h)
    await unmount()
    expect(h.connection.unregisterTableWindow).toHaveBeenCalledWith(HISTORY)
    expect([...h.listeners.values()].every((set) => set.size === 0)).toBe(true)
    expect(r.leaves.size).toBe(0)
    h.source.success(0, RECEIPT_REPLY)
    await settle()
    expect(h.source.sent).toHaveLength(1)
    expect(document.querySelector('[data-id="modal"]')).toBeNull()
  })

  it('refuses to build without a provided router', () => {
    expect(() => TestBed.createComponent(HilosChangeLogTablePage)).toThrow(
      'HilosChangeLogTablePage requires a provided Hilos router.',
    )
  })
})
