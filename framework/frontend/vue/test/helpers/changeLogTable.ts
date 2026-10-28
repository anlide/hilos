import { mount } from '@vue/test-utils'
import { markRaw, nextTick } from 'vue'
import { afterEach, expect, vi } from 'vitest'
import {
  ActionLifecycle,
  HilosPages,
  ScopeManager,
  createSignal,
  type ActionErrorSignal,
  type ActionLifecycleSource,
  type ActionSuccessSignal,
  type ConnectionState,
  pageIdentity,
  type HilosRouter,
  type PageRouteMatch,
} from '@hilos/core/base'
import {
  CHANGE_LOG_TABLE_SECTION,
  type HilosChangeLogReceiptContext,
} from '@hilos/core/admin/change-log'

import { hilosRouterKey } from '../../src/hilosRouterKey.js'
import HilosChangeLogTablePage from '../../src/admin/changeLog/HilosChangeLogTablePage.vue'
import HilosChangeLogTableColumnsPage from '../../src/admin/changeLog/HilosChangeLogTableColumnsPage.vue'
import HilosChangeLogTableTriggersPage from '../../src/admin/changeLog/HilosChangeLogTableTriggersPage.vue'
import HilosChangeLogTableHistoryPage from '../../src/admin/changeLog/HilosChangeLogTableHistoryPage.vue'

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
  const scope = scopes.openPage(HilosPages.CHANGE_LOG_TABLE_HISTORY)
  scope.data.set(CHANGE_LOG_TABLE_SECTION, section)
  const listeners = new Map<string, Set<Listener>>()
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
        page: HilosPages.CHANGE_LOG_TABLE_HISTORY,
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
        page: HilosPages.CHANGE_LOG_TABLE_HISTORY,
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
  /** Deliver a metadata window through the same binder as a real page reply. */
  function pushMetadataWindow(
    page: string,
    tableKey: string,
    rows: readonly { rowKey: string; slots: Record<string, unknown> }[],
    subscription = false,
    extra: Record<string, unknown> = {},
  ) {
    const window = {
      rows,
      totalCount: rows.length,
      totalExact: true,
      firstAnchor: null,
      lastAnchor: null,
      rowsBefore: 0,
      limit: 25,
      sort: [],
      ...extra,
    }
    if (subscription) {
      emit('projectSignal', {
        type: 'page_response',
        data: { page, payload: { windows: { [tableKey]: window } } },
      })
    } else {
      emit('tableWindow', { data: { page, tableKey, ...window } })
    }
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
    pushMetadataWindow,
  }
}

/** Address the page as the application's route map does. */
function tablePath(params: Record<string, string>): string {
  const record = params.record
  return `/hilos/change-log/tables/${encodeURIComponent(params.tableId ?? '')}/history${
    record === undefined ? '' : `/${encodeURIComponent(record)}`
  }`
}

function router(params: Record<string, string>, built = true) {
  const leaves = new Set<() => void>()
  const currentRoute = createSignal<PageRouteMatch>({
    page: HilosPages.CHANGE_LOG_TABLE_HISTORY,
    params,
    admin: true,
  })
  const value: HilosRouter = {
    currentRoute,
    currentPath: createSignal(tablePath(params)),
    currentTitle: createSignal('Tracked table'),
    pageError: createSignal(null),
    pageLoading: createSignal(false),
    pageIdentity: createSignal(undefined),
    dashboardSections: createSignal(undefined),
    resolvePath: vi.fn((page: string, to: Record<string, string> = {}) => {
      if (!built) return undefined
      const base = `/hilos/change-log/tables/${encodeURIComponent(to.tableId ?? '')}`
      if (page === HilosPages.CHANGE_LOG_TABLE) return base
      if (page === HilosPages.CHANGE_LOG_TABLE_COLUMNS) return `${base}/columns`
      if (page === HilosPages.CHANGE_LOG_TABLE_TRIGGERS)
        return `${base}/triggers`
      if (page === HilosPages.CHANGE_LOG_TABLE_HISTORY) return tablePath(to)
      if (page === HilosPages.DASHBOARD) return '/hilos'
      if (page === HilosPages.CHANGE_LOG) return '/hilos/change-log'
      if (page === HilosPages.CHANGE_LOG_TABLES)
        return '/hilos/change-log/tables'
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
    route: currentRoute,
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

const TABLE_TABS = [
  { page: HilosPages.CHANGE_LOG_TABLE, label: 'Overview' },
  { page: HilosPages.CHANGE_LOG_TABLE_COLUMNS, label: 'Columns' },
  { page: HilosPages.CHANGE_LOG_TABLE_TRIGGERS, label: 'Triggers' },
  { page: HilosPages.CHANGE_LOG_TABLE_HISTORY, label: 'History' },
]
function answerIdentity(
  scope: ReturnType<ScopeManager['openPage']>,
  journaled = true,
) {
  scope.data.set('pageLabel', 'Tracked table')
  scope.data.set('pageHeadOwner', {
    page: HilosPages.CHANGE_LOG_TABLE,
    label: 'Tracked table',
    lead: '',
    icon: 'bi-table',
  })
  scope.data.set('pageBreadcrumb', [
    { page: HilosPages.DASHBOARD, label: 'Hilos' },
    { page: HilosPages.CHANGE_LOG, label: 'Change Log' },
    { page: HilosPages.CHANGE_LOG_TABLES, label: 'Tracked tables' },
    { page: HilosPages.CHANGE_LOG_TABLE, label: 'Tracked table' },
  ])
  scope.data.set(
    'pageTabs',
    journaled ? TABLE_TABS : [TABLE_TABS[0], TABLE_TABS[2]],
  )
  scope.data.set('pageChildren', [])
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
  component = HilosChangeLogTableHistoryPage,
) {
  const page =
    component === HilosChangeLogTablePage
      ? HilosPages.CHANGE_LOG_TABLE
      : component === HilosChangeLogTableColumnsPage
        ? HilosPages.CHANGE_LOG_TABLE_COLUMNS
        : component === HilosChangeLogTableTriggersPage
          ? HilosPages.CHANGE_LOG_TABLE_TRIGGERS
          : HilosPages.CHANGE_LOG_TABLE_HISTORY
  const section = h.context.scopes
    .pageDataSignal(CHANGE_LOG_TABLE_SECTION)
    .get()
  const scope = h.context.scopes.openPage(page)
  scope.data.set(CHANGE_LOG_TABLE_SECTION, section)
  answerIdentity(scope, (section as typeof BOT)?.journaled !== false)
  r.route.set({ ...r.value.currentRoute.get(), page })
  r.value = { ...r.value, pageIdentity: pageIdentity(h.context.scopes) }
  const wrapper = mount(component, {
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

function mountOverview(
  h = harness(),
  r = router({ tableId: 'bot' }),
  push = false,
) {
  return mountPage(h, r, push, HilosChangeLogTablePage)
}

function mountColumns(
  h = harness(),
  r = router({ tableId: 'bot' }),
  push = false,
) {
  return mountPage(h, r, push, HilosChangeLogTableColumnsPage)
}

function mountTriggers(
  h = harness(),
  r = router({ tableId: 'bot' }),
  push = false,
) {
  return mountPage(h, r, push, HilosChangeLogTableTriggersPage)
}

export {
  BOT,
  SESSION,
  HISTORY,
  ROWS,
  historySlot,
  harness,
  router,
  mountPage,
  mountOverview,
  mountColumns,
  mountTriggers,
  settle,
  element,
  releases,
  answerIdentity,
  TABLE_TABS,
}
