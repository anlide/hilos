import { mount } from '@vue/test-utils'
import { markRaw, nextTick } from 'vue'
import { afterEach, describe, expect, it, vi } from 'vitest'
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

import { hilosRouterKey } from '../../hilosRouterKey.js'
import HilosChangeLogTablePage from './HilosChangeLogTablePage.vue'

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

const mounted: ReturnType<typeof mount>[] = []
const releases: (() => void)[] = []
afterEach(() => {
  for (const wrapper of mounted.splice(0)) wrapper.unmount()
  for (const release of releases.splice(0)) release()
})

async function settle(): Promise<void> {
  await Promise.resolve()
  await nextTick()
  await nextTick()
}

async function mountPage(
  h = harness(),
  r = router({ tableId: 'bot' }),
  push = true,
) {
  const wrapper = mount(HilosChangeLogTablePage, {
    props: { context: markRaw(h.context) },
    attachTo: document.body,
    global: { provide: { [hilosRouterKey as symbol]: r.value } },
  })
  mounted.push(wrapper)
  if (push) h.pushWindow()
  await nextTick()
  return { wrapper, r }
}

function element(id: string, root: ParentNode = document): HTMLElement {
  const found = root.querySelector(`[data-id="${id}"]`)
  expect(found).not.toBeNull()
  return found as HTMLElement
}

describe('HilosChangeLogTablePage', () => {
  it('draws the header, columns and service triggers of a journaled table', async () => {
    const { wrapper } = await mountPage()
    expect(wrapper.findAll('h1')).toHaveLength(1)
    const header = wrapper.get('[data-id="hilos-change-log-table-header"]')
    expect(header.get('code').text()).toBe('bot')
    expect(header.text()).toContain('Project')
    expect(header.text()).toContain(CHANGE_LOG_TABLE_COPY.journaled)
    expect(header.text()).not.toContain(CHANGE_LOG_TABLES_UNTRACKED_NOTE)
    expect(header.text().replace(/\s+/g, ' ')).toContain(
      'Last 24 hours: 3 · Last change: —',
    )

    const columns = wrapper.get('[data-id="hilos-change-log-table-columns"]')
    expect(columns.get('h2').text()).toBe('Columns')
    const table = columns.get('table')
    expect(table.attributes('aria-labelledby')).toBe(
      columns.get('h2').attributes('id'),
    )
    expect(table.findAll('th').map((th) => th.text())).toEqual([
      'Column',
      'Stored as',
      'In the journal',
    ])
    expect(
      table
        .findAll('tbody tr')
        .map((tr) =>
          tr.findAll('td').map((td) => td.text().replace(/\s+/g, ' ')),
        ),
    ).toEqual([
      ['id', '—', 'Record key'],
      ['name', 'String', 'With value'],
      ['description', 'Long text', 'With value'],
      ['email', '—', 'Personal — only a fact'],
      ['seen_at', '—', 'Noisy — not written'],
    ])
    expect(
      columns
        .findAll('i')
        .every((icon) => icon.attributes('aria-hidden') === 'true'),
    ).toBe(true)
    const cards = columns.get('.d-md-none')
    expect(cards.attributes('role')).toBe('list')
    expect(cards.findAll('[role="listitem"]')).toHaveLength(5)
    expect(cards.text()).toContain('Long text')
    expect(cards.text()).toContain('Personal — only a fact')
    expect(columns.text()).toContain(CHANGE_LOG_TABLE_COPY.noisyNote)

    const triggers = wrapper.get('[data-id="hilos-change-log-table-triggers"]')
    expect(triggers.get('h2').text()).toBe('Journal service triggers')
    expect(
      triggers
        .get('table')
        .findAll('tbody tr')
        .map((tr) => tr.findAll('td').map((td) => td.text())),
    ).toEqual([
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
      triggers.get('.d-md-none').findAll('[role="listitem"]'),
    ).toHaveLength(3)
    expect(triggers.text()).toContain(CHANGE_LOG_TABLE_COPY.triggersFootnote)
    expect(wrapper.text()).toContain(CHANGE_LOG_TABLE_COPY.historyNote)
  })

  it('leaves the noisy line out when no column is noisy', async () => {
    const { wrapper } = await mountPage(
      harness({
        ...BOT,
        columns: BOT.columns.filter(({ mode }) => mode !== 'noise'),
      }),
    )
    expect(wrapper.text()).not.toContain(CHANGE_LOG_TABLE_COPY.noisyNote)
  })

  it('draws an untracked table as its header and explanation alone', async () => {
    const h = harness(SESSION)
    const { wrapper } = await mountPage(h, router({ tableId: 'hilos_session' }))
    const header = wrapper.get('[data-id="hilos-change-log-table-header"]')
    expect(header.get('code').text()).toBe('hilos_session')
    expect(header.text()).toContain('Framework')
    expect(header.text()).toContain(CHANGE_LOG_TABLES_UNTRACKED_NOTE)
    expect(header.text()).not.toContain(CHANGE_LOG_TABLE_COPY.journaled)
    expect(header.text()).not.toContain('Last 24 hours')
    expect(wrapper.text()).toContain(CHANGE_LOG_TABLE_COPY.untracked)
    for (const zone of [
      'hilos-change-log-table-columns',
      'hilos-change-log-table-triggers',
      'hilos-viewport-table',
    ]) {
      expect(wrapper.find(`[data-id="${zone}"]`).exists()).toBe(false)
    }
    expect(h.connection.registerTableWindow).not.toHaveBeenCalled()
  })

  it('draws a dash in place of the zones when the section is damaged', async () => {
    const h = harness({ ...BOT, owner: 'nobody' })
    const { wrapper } = await mountPage(h)
    expect(
      wrapper.find('[data-id="hilos-change-log-table-header"]').exists(),
    ).toBe(false)
    expect(wrapper.find('[data-id="hilos-viewport-table"]').exists()).toBe(
      false,
    )
    expect(wrapper.text()).toContain('—')
    expect(h.connection.registerTableWindow).not.toHaveBeenCalled()
  })

  it('builds the history with the table preset and links unmasked records', async () => {
    const h = harness()
    const { wrapper, r } = await mountPage(h)
    expect(h.connection.registerTableWindow).toHaveBeenCalledWith(
      HISTORY,
      expect.anything(),
    )
    expect(wrapper.text()).toContain('All records of this table')
    expect(
      wrapper.find('[data-id="hilos-change-log-record-filter"]').exists(),
    ).toBe(false)

    const row = wrapper.get('table [data-id="hilos-table-row-101"]')
    const link = row.get('[data-id="hilos-change-log-history-record-101"]')
    expect(link.text()).toBe('#57')
    expect(link.attributes('href')).toBe('/hilos/change-log/tables/bot/57')
    expect(r.value.resolvePath).toHaveBeenCalledWith(
      HilosPages.CHANGE_LOG_TABLE,
      { tableId: 'bot', record: '57' },
    )
    await link.trigger('click')
    expect(r.value.navigate).toHaveBeenCalledWith(
      '/hilos/change-log/tables/bot/57',
    )
    expect(row.text()).toContain('updated')
    expect(row.text()).toContain('description: before → after')
    expect(row.text()).toContain('Ada')
    expect(row.text()).toContain('Web')
    expect(
      row.get('[data-id="hilos-change-log-history-receipt-101"]').text(),
    ).toBe('Receipt')

    const masked = wrapper.get('table [data-id="hilos-table-row-102"]')
    expect(
      masked.find('[data-id="hilos-change-log-history-record-102"]').exists(),
    ).toBe(false)
    expect(masked.find('a').exists()).toBe(false)
    expect(masked.text()).toContain('#?')
    expect(masked.text()).toContain('deleted')
    expect(masked.text()).toContain('Row snapshot')
    expect(masked.text()).toContain('Unknown')
    expect(masked.text()).toContain(CHANGE_LOG_TABLE_COPY.pastApplication)
    expect(masked.text()).toContain(CHANGE_LOG_TABLE_COPY.noReceipt)
    expect(
      masked.find('[data-id="hilos-change-log-history-receipt-102"]').exists(),
    ).toBe(false)
  })

  it('narrows to the address record behind a plate whose × leaves the record', async () => {
    const h = harness()
    const { wrapper, r } = await mountPage(
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
    const plate = wrapper.get('[data-id="hilos-change-log-record-filter"]')
    expect(plate.text()).toContain('Only record #57')
    expect(wrapper.text()).not.toContain('All records of this table')
    const clear = plate.get('[data-id="hilos-change-log-record-filter-clear"]')
    expect(clear.attributes('href')).toBe('/hilos/change-log/tables/bot')
    expect(clear.attributes('aria-label')).toBe('Show all records')
    expect(clear.get('i').attributes('aria-hidden')).toBe('true')
    await clear.trigger('click')
    expect(r.value.navigate).toHaveBeenCalledWith(
      '/hilos/change-log/tables/bot',
    )
  })

  it('rebuilds the history window when the address gains a record', async () => {
    const h = harness()
    const { wrapper, r } = await mountPage(h)
    expect(h.connection.registerTableWindow).toHaveBeenCalledTimes(1)
    r.go({ tableId: 'bot', record: '57' })
    await settle()
    expect(h.connection.unregisterTableWindow).toHaveBeenCalledWith(HISTORY)
    expect(h.connection.registerTableWindow).toHaveBeenCalledTimes(2)
    h.pushPageWindow()
    expect(h.connection.sendTableViewport).toHaveBeenCalledTimes(1)
    expect(h.connection.sendTableViewport).toHaveBeenLastCalledWith(
      HilosPages.CHANGE_LOG_TABLE,
      HISTORY,
      expect.objectContaining({
        filter: expect.objectContaining({ table: 'bot', record: ['57'] }),
      }),
    )
    expect(
      wrapper.get('[data-id="hilos-change-log-record-filter"]').text(),
    ).toContain('Only record #57')
  })

  it('opens the row receipt through the Change Log reading action and closes it on an address change', async () => {
    const h = harness()
    const { r } = await mountPage(h)
    element(
      'hilos-change-log-history-receipt-101',
      document.querySelector('[data-id="hilos-viewport-table"] table')!,
    ).click()
    await settle()
    expect(h.source.sent[0]).toMatchObject({
      action: HILOS_CHANGE_LOG_RECEIPT_READ,
      data: { receiptId: 7, afterEntryId: null },
    })
    expect(element('modal').getAttribute('aria-label')).toBe('Receipt #7')
    r.go({ tableId: 'bot', record: '57' })
    await settle()
    expect(document.querySelector('[data-id="modal"]')).toBeNull()
  })

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
      const { wrapper } = await mountPage(h, router({ tableId: 'bot' }), false)
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
      await nextTick()
      expect(
        wrapper.get('[data-id="hilos-change-log-table-header"]').text(),
      ).toContain('bot')
      expect(
        wrapper
          .get('[data-id="hilos-change-log-table-columns"]')
          .findAll('tbody tr'),
      ).toHaveLength(5)
      expect(
        wrapper
          .get('[data-id="hilos-change-log-table-triggers"]')
          .findAll('tbody tr'),
      ).toHaveLength(3)
      const row = wrapper.get('table [data-id="hilos-table-row-101"]')
      if (access === 'view') {
        expect(row.text().replace(/\s+/g, ' ')).toContain('Hidden (#3)')
        expect(row.text()).not.toContain('Ada')
      } else {
        expect(row.text()).toContain('Ada')
      }
      expect(
        row.get('[data-id="hilos-change-log-history-receipt-101"]').text(),
      ).toBe('Receipt')
    },
  )

  it('releases the history window and every connection binding on unmount', async () => {
    const h = harness()
    const { wrapper } = await mountPage(h)
    expect([...h.listeners.values()].some((set) => set.size > 0)).toBe(true)
    wrapper.unmount()
    expect(h.connection.unregisterTableWindow).toHaveBeenCalledWith(HISTORY)
    expect([...h.listeners.values()].every((set) => set.size === 0)).toBe(true)
  })
})
