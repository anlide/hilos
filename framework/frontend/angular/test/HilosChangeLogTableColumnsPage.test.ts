import { Component, signal } from '@angular/core'
import { TestBed, type ComponentFixture } from '@angular/core/testing'
import {
  bindAdminAccess,
  bindSessionScope,
  createAppPageRouter,
  createSignal,
  HilosPages,
  hilosAdminAccess,
  pageIdentity,
  ScopeManager,
  type HilosRouter,
  type PageError,
  type PageRouteMatch,
  type Scope,
  type TableRowFragment,
} from '@hilos/core/base'
import {
  CHANGE_LOG_TABLE_COPY,
  CHANGE_LOG_TABLE_SECTION,
  type HilosChangeLogReceiptContext,
  type HilosChangeLogTableDetail,
} from '@hilos/core/admin/change-log'
import { afterEach, describe, expect, it, vi } from 'vitest'

import { HilosChangeLogTableColumnsPage } from '../src/admin/changeLog/HilosChangeLogTableColumnsPage.js'
import { HILOS_ROUTER } from '../src/hilosRouterToken.js'

const PAGE = HilosPages.CHANGE_LOG_TABLE_COLUMNS
const TABLE = 'hilosChangeLogTableColumns'
const BOT: HilosChangeLogTableDetail = {
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
const ROWS = BOT.columns.map((row) => ({
  rowKey: row.name,
  slots: { column: row },
}))
const TABS = [
  { page: HilosPages.CHANGE_LOG_TABLE, label: 'Overview' },
  { page: HilosPages.CHANGE_LOG_TABLE_COLUMNS, label: 'Columns' },
  { page: HilosPages.CHANGE_LOG_TABLE_TRIGGERS, label: 'Triggers' },
  { page: HilosPages.CHANGE_LOG_TABLE_HISTORY, label: 'History' },
]

type Listener = (signal: {
  type?: string
  data: Record<string, unknown>
}) => void

/** Keep the page answer and window independent to expose stale controller data. */
function harness(section: unknown = BOT, page: string = PAGE) {
  const scopes = new ScopeManager()
  const scope = scopes.openPage(page)
  const listeners = new Map<string, Set<Listener>>()
  let heldAnswer: Parameters<Listener>[0] | undefined
  const connection = {
    registerTableWindow: vi.fn(),
    unregisterTableWindow: vi.fn(),
    tableWindowDescriptors: () => ({}),
    sendTableViewport: vi.fn<
      HilosChangeLogReceiptContext['connection']['sendTableViewport']
    >(() => true),
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
  const context: HilosChangeLogReceiptContext = {
    connection:
      connection as unknown as HilosChangeLogReceiptContext['connection'],
    scopes,
    actions: {} as HilosChangeLogReceiptContext['actions'],
  }
  function emit(event: string, payload: Parameters<Listener>[0]): void {
    for (const listener of listeners.get(event) ?? []) listener(payload)
  }
  function answer(detail: unknown = BOT, target: Scope = scopes.page()!): void {
    target.data.set(CHANGE_LOG_TABLE_SECTION, detail)
    target.data.set('pageLabel', 'Columns')
    target.data.set('pageHeadOwner', {
      page: HilosPages.CHANGE_LOG_TABLE,
      label: 'Tracked table',
      lead: 'One table.',
      icon: 'bi-table',
    })
    target.data.set('pageTabs', TABS)
  }
  answer(section)
  function pushWindow(
    rows: TableRowFragment[] = ROWS,
    requestId?: string,
  ): void {
    emit('tableWindow', {
      data: {
        page: PAGE,
        tableKey: TABLE,
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
  function pushPageWindow(): void {
    heldAnswer = {
      type: 'page_response',
      data: {
        page: PAGE,
        payload: {
          windows: {
            [TABLE]: {
              rows: ROWS,
              totalCount: ROWS.length,
              totalExact: true,
              firstAnchor: null,
              lastAnchor: null,
              offset: 0,
              limit: 25,
              sort: null,
            },
          },
        },
      },
    }
    emit('projectSignal', heldAnswer)
  }
  return {
    context,
    scopes,
    scope,
    connection,
    listeners,
    answer,
    pushWindow,
    pushPageWindow,
    emit,
  }
}

function router(scopes: ScopeManager) {
  const route = createSignal<PageRouteMatch>({
    page: PAGE,
    params: { tableId: 'bot' },
    admin: true,
  })
  const error = createSignal<PageError | null>(null)
  const matcher = createAppPageRouter({})
  const value: HilosRouter = {
    currentRoute: route,
    currentPath: createSignal(matcher.path(PAGE, route.get().params)!),
    currentTitle: createSignal(''),
    pageError: error,
    pageLoading: createSignal(false),
    pageIdentity: pageIdentity(scopes),
    dashboardSections: createSignal(undefined),
    resolvePath: (page, params) => matcher.path(page, params),
    clearPageError: vi.fn(),
    denyCurrentPage: vi.fn(),
    awaitPageAnswer: vi.fn(),
    onLeave: () => () => {},
    navigate: vi.fn(),
    replacePath: vi.fn(),
    start: vi.fn(),
    stop: vi.fn(),
  }
  return { value, route, error }
}

/** Drop the component and its DOM in the same way as the routed page outlet. */
@Component({
  selector: 'test-change-log-table-columns-host',
  imports: [HilosChangeLogTableColumnsPage],
  template: `
    @if (shown()) {
      <hilos-change-log-table-columns-page [context]="context()" />
    }
  `,
})
class PageHost {
  readonly shown = signal(true)
  readonly context = signal<HilosChangeLogReceiptContext>(null!)
}

let fixture: ComponentFixture<PageHost>
const releases: (() => void)[] = []
afterEach(() => {
  for (const release of releases.splice(0)) release()
})

async function settle(): Promise<void> {
  await fixture.whenStable()
  fixture.detectChanges()
}

async function mountPage(h = harness(), r = router(h.scopes), push = true) {
  TestBed.configureTestingModule({
    providers: [{ provide: HILOS_ROUTER, useValue: r.value }],
  })
  fixture = TestBed.createComponent(PageHost)
  fixture.componentInstance.context.set(h.context)
  fixture.detectChanges()
  if (push) h.pushWindow()
  await settle()
  return r
}

function get(selector: string, root: ParentNode = document): HTMLElement {
  const found = root.querySelector(selector)
  expect(found).not.toBeNull()
  return found as HTMLElement
}

function text(node: Element): string {
  return (node.textContent ?? '').replace(/\s+/g, ' ').trim()
}

function rows(): string[][] {
  return Array.from(
    document.querySelectorAll('tbody tr[data-id^="hilos-table-row-"]'),
    (tr) => Array.from(tr.querySelectorAll('td'), text).slice(0, 3),
  )
}

function listenerCounts(h: ReturnType<typeof harness>): number[] {
  return [...h.listeners.values()].map((set) => set.size)
}

describe('HilosChangeLogTableColumnsPage', () => {
  it.each(['full', 'view'] as const)(
    'draws schema order, storage and modes from the server window under %s access',
    async (access) => {
      const h = harness({
        ...BOT,
        columns: [...BOT.columns, { ...BOT.columns[1], name: 'snapshot_only' }],
      })
      bindSessionScope(h.context.connection, h.scopes)
      releases.push(bindAdminAccess(h.scopes))
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
      await mountPage(h)
      expect(document.querySelectorAll('h1')).toHaveLength(1)
      expect(text(get('h1'))).toBe('bot')
      const table = get('table')
      expect(table.getAttribute('aria-labelledby')).toBe(get('h1').id)
      expect(
        Array.from(table.querySelectorAll('th'), text).slice(0, 3),
      ).toEqual(['Column', 'Stored as', 'In the journal'])
      expect(rows()).toEqual([
        ['id', '—', 'Record key'],
        ['name', 'String', 'With value'],
        ['description', 'Long text', 'With value'],
        ['email', '—', 'Personal — only a fact'],
        ['seen_at', '—', 'Noisy — not written'],
      ])
      const cards = Array.from(
        document.querySelectorAll('[data-id^="hilos-table-card-"]'),
      )
      expect(cards).toHaveLength(5)
      expect(cards[2]!.textContent).toContain('Long text')
      expect(cards[3]!.textContent).toContain('Personal — only a fact')
      const list = get('[role="list"]', get('[data-id="hilos-table-cards"]'))
      expect(list.getAttribute('aria-labelledby')).toBe(get('h1').id)
      expect(list.querySelectorAll('[role="listitem"]')).toHaveLength(5)
      expect(document.body.textContent).not.toContain('snapshot_only')
      const modeIcons = Array.from(table.querySelectorAll('tbody tr'), (row) =>
        get('i', row.querySelectorAll('td')[2]!),
      )
      expect(modeIcons).toHaveLength(5)
      expect(
        modeIcons.every((icon) => icon.getAttribute('aria-hidden') === 'true'),
      ).toBe(true)
      expect(document.body.textContent).toContain(
        CHANGE_LOG_TABLE_COPY.noisyNote,
      )
      expect(document.querySelector('h2')).toBeNull()
      expect(
        document.querySelector('[data-id="hilos-change-log-table-overview"]'),
      ).toBeNull()
      expect(h.connection.registerTableWindow).toHaveBeenCalledWith(
        TABLE,
        expect.anything(),
      )
      expect(h.connection.registerTableWindow).toHaveBeenCalledTimes(1)
      expect(document.querySelector('[data-id="modal"]')).toBeNull()
      expect(h.connection.sendTableViewport).not.toHaveBeenCalled()
    },
  )

  it.each(['scope', 'route', 'name', 'damaged'] as const)(
    'does not open a window before a permitted answer or for a wrong %s',
    async (wrong) => {
      const h = harness(null)
      const r = await mountPage(h)
      expect(document.querySelector('table')).toBeNull()
      expect(h.connection.registerTableWindow).not.toHaveBeenCalled()
      if (wrong === 'scope') h.scopes.openPage(HilosPages.CHANGE_LOG_TABLE)
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
      if (wrong === 'scope') h.scopes.openPage(PAGE)
      r.route.set({ ...r.route.get(), page: PAGE })
      h.answer()
      await settle()
      h.pushWindow()
      await settle()
      expect(rows()).toHaveLength(ROWS.length)
      expect(h.connection.registerTableWindow).toHaveBeenCalledTimes(1)
    },
  )

  it('starts after the permitted first answer and consumes its buffered window through the existing preset exchange', async () => {
    const h = harness(null)
    await mountPage(h, router(h.scopes), false)
    h.answer()
    h.pushPageWindow()
    await settle()
    expect(h.connection.registerTableWindow).toHaveBeenCalledTimes(1)
    expect(h.connection.sendTableViewport).toHaveBeenCalledTimes(1)
    const request = h.connection.sendTableViewport.mock.calls[0]!
    expect(request).toEqual([
      PAGE,
      TABLE,
      expect.objectContaining({
        filter: { table: 'bot' },
        sort: null,
        limit: 25,
      }),
    ])
    expect(rows()).toEqual([])
    h.pushWindow(ROWS, request[2].requestId)
    await settle()
    expect(rows()).toHaveLength(ROWS.length)
  })

  it('replaces the controller and its DOM on a new scope at the same address', async () => {
    const h = harness()
    await mountPage(h)
    const oldTable = get('table')
    const oldController = h.connection.registerTableWindow.mock.calls[0]![1]
    const counts = listenerCounts(h)
    h.scopes.openPage(PAGE)
    h.answer()
    await settle()
    expect(h.connection.unregisterTableWindow).toHaveBeenCalledWith(
      TABLE,
      oldController,
    )
    expect(h.connection.registerTableWindow).toHaveBeenCalledTimes(2)
    expect(h.connection.registerTableWindow.mock.calls[1]![1]).not.toBe(
      oldController,
    )
    expect(oldTable.isConnected).toBe(false)
    expect(document.body.textContent).not.toContain(ROWS[0]!.slots.column.name)
    h.pushWindow([ROWS[1]!])
    await settle()
    expect(rows()).toHaveLength(1)
    expect(get('tbody').textContent).toContain(ROWS[1]!.slots.column.name)
    expect(get('tbody').textContent).not.toContain(ROWS[0]!.slots.column.name)
    expect(listenerCounts(h)).toEqual(counts)
  })

  it('rebinds context and scope managers and ignores updates from the former context', async () => {
    const h = harness()
    await mountPage(h)
    let oldTable = get('table')
    fixture.componentInstance.context.set({ ...h.context })
    await settle()
    expect(h.connection.registerTableWindow).toHaveBeenCalledTimes(2)
    expect(h.connection.unregisterTableWindow).toHaveBeenCalledTimes(1)
    expect(oldTable.isConnected).toBe(false)
    h.pushWindow()
    await settle()
    oldTable = get('table')
    const replacement = new ScopeManager()
    replacement.openPage(PAGE)
    fixture.componentInstance.context.set({ ...h.context, scopes: replacement })
    await settle()
    expect(oldTable.isConnected).toBe(false)
    expect(document.querySelector('table')).toBeNull()
    expect(listenerCounts(h).every((size) => size === 0)).toBe(true)
    h.answer(null)
    h.answer(BOT, replacement.page()!)
    await settle()
    h.pushWindow()
    await settle()
    expect(rows()).toHaveLength(ROWS.length)
    const next = harness()
    fixture.componentInstance.context.set(next.context)
    await settle()
    next.pushWindow([ROWS[1]!])
    await settle()
    const table = get('table')
    expect(rows()).toHaveLength(1)
    expect(listenerCounts(h).every((size) => size === 0)).toBe(true)
    h.answer({ ...BOT, name: 'stale' }, replacement.page()!)
    h.pushWindow()
    await settle()
    expect(get('table')).toBe(table)
    expect(rows()).toHaveLength(1)
    expect(next.connection.registerTableWindow).toHaveBeenCalledTimes(1)
    fixture.componentInstance.shown.set(false)
    await settle()
    expect(listenerCounts(next).every((size) => size === 0)).toBe(true)
  })

  it('drops the former table on address changes and waits for its matching answer', async () => {
    const h = harness()
    const r = await mountPage(h)
    const oldTable = get('table')
    r.route.set({ ...r.route.get(), params: { tableId: 'other' } })
    await settle()
    expect(oldTable.isConnected).toBe(false)
    expect(document.querySelector('table')).toBeNull()
    expect(h.connection.unregisterTableWindow).toHaveBeenCalledTimes(1)
    h.answer({ ...BOT, name: 'other' })
    await settle()
    expect(h.connection.registerTableWindow).toHaveBeenCalledTimes(2)
    expect(get('tbody').textContent).not.toContain(ROWS[0]!.slots.column.name)
    h.pushWindow([ROWS[1]!])
    await settle()
    expect(rows()).toHaveLength(1)
    expect(text(get('h1'))).toBe('other')
    h.pushPageWindow()
    await settle()
    const controller = h.connection.registerTableWindow.mock.calls.at(-1)![1]
    expect(controller.descriptor()?.filter).toEqual({ table: 'other' })
  })

  it('follows later schema and window updates without multiplying controllers or listeners', async () => {
    const h = harness()
    await mountPage(h)
    const table = get('table')
    const counts = listenerCounts(h)
    const title: HilosChangeLogTableDetail['columns'][number] = {
      name: 'title',
      sqlType: 'varchar',
      mode: 'value',
      storage: 'inline',
      noiseReason: null,
    }
    h.answer({
      ...BOT,
      changesLast24h: 9,
      columns: [...BOT.columns.filter(({ mode }) => mode !== 'noise'), title],
    })
    await settle()
    expect(document.body.textContent).not.toContain(
      CHANGE_LOG_TABLE_COPY.noisyNote,
    )
    expect(get('tbody').textContent).not.toContain('title')
    h.pushWindow([{ rowKey: title.name, slots: { column: title } }])
    await settle()
    expect(rows()).toEqual([['title', 'String', 'With value']])
    expect(get('table')).toBe(table)
    expect(h.connection.registerTableWindow).toHaveBeenCalledTimes(1)
    expect(listenerCounts(h)).toEqual(counts)
    h.pushWindow([
      {
        rowKey: title.name,
        slots: { column: { ...title, mode: null, storage: null } },
      },
    ])
    await settle()
    expect(rows()).toEqual([['title', '—', '—']])
  })

  it('releases on answer loss, page errors and departures, then releases every binding on unmount', async () => {
    const h = harness()
    const r = await mountPage(h)
    const expectReleased = () => {
      expect(document.querySelector('table')).toBeNull()
      expect(listenerCounts(h).every((size) => size === 0)).toBe(true)
    }
    h.answer(null)
    await settle()
    expectReleased()
    h.answer()
    await settle()
    r.error.set({
      httpCode: 403,
      errorCode: 'forbidden',
      title: 'Access denied',
      message: 'This page is closed.',
    })
    await settle()
    expectReleased()
    r.error.set(null)
    await settle()
    r.route.set({ ...r.route.get(), page: HilosPages.CHANGE_LOG_TABLE })
    await settle()
    expectReleased()
    r.route.set({ ...r.route.get(), page: PAGE })
    await settle()
    h.scopes.openPage(HilosPages.CHANGE_LOG_TABLE)
    h.answer()
    await settle()
    expectReleased()
    h.scopes.openPage(PAGE)
    h.answer()
    await settle()
    h.pushWindow()
    await settle()
    fixture.componentInstance.shown.set(false)
    await settle()
    expectReleased()
    expect(h.connection.unregisterTableWindow.mock.calls).toEqual(
      h.connection.registerTableWindow.mock.calls,
    )
    expect(pageIdentity(h.scopes).get()?.pageHeadOwner?.label).toBe(
      'Tracked table',
    )
    h.answer({ ...BOT, name: 'after-unmount' })
    h.pushWindow()
    await settle()
    expectReleased()
  })
})
