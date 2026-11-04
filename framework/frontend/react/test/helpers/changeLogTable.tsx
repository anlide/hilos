import { StrictMode, type ComponentType, type ReactNode } from 'react'
import { act, render } from '@testing-library/react'
import { vi } from 'vitest'
import {
  ActionLifecycle,
  HilosPages,
  ScopeManager,
  createSignal,
  pageIdentity,
  type HilosRouter,
  type PageError,
  type PageRouteMatch,
} from '@hilos/core/base'
import {
  CHANGE_LOG_TABLE_SECTION,
  type HilosChangeLogReceiptContext,
} from '@hilos/core/admin/change-log'

import { HilosChangeLogTablePage } from '../../src/admin/changeLog/HilosChangeLogTablePage.js'
import { HilosChangeLogTableShell } from '../../src/admin/changeLog/HilosChangeLogTableShell.js'
import { HilosRouterContext } from '../../src/hilosRouterContext.js'

export const BOT = {
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

export const TABLE_TABS = [
  { page: HilosPages.CHANGE_LOG_TABLE, label: 'Overview' },
  { page: HilosPages.CHANGE_LOG_TABLE_COLUMNS, label: 'Columns' },
  { page: HilosPages.CHANGE_LOG_TABLE_TRIGGERS, label: 'Triggers' },
  { page: HilosPages.CHANGE_LOG_TABLE_HISTORY, label: 'History' },
]

/** The permitted table declaration and its owner identity arrive together. */
export function answerTable(
  scope: ReturnType<ScopeManager['openPage']>,
  section: unknown = BOT,
) {
  scope.data.set(CHANGE_LOG_TABLE_SECTION, section)
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
    (section as typeof BOT)?.journaled === false
      ? [TABLE_TABS[0], TABLE_TABS[2]]
      : TABLE_TABS,
  )
  scope.data.set('pageChildren', [])
}

/** Keep new owner fixtures separate from the legacy subpage scenarios. */
export function tableHarness(
  section: unknown = BOT,
  page: string = HilosPages.CHANGE_LOG_TABLE,
  answered = true,
) {
  const scopes = new ScopeManager()
  const scope = scopes.openPage(page)
  if (answered) answerTable(scope, section)
  type Listener = (signal: {
    type?: string
    data: Record<string, unknown>
  }) => void
  const listeners = new Map<string, Set<Listener>>()
  const connection = {
    registerTableWindow: vi.fn(),
    unregisterTableWindow: vi.fn(),
    tableWindowDescriptors: () => ({}),
    sendTableViewport: vi.fn<
      HilosChangeLogReceiptContext['connection']['sendTableViewport']
    >(() => true),
    sendTableRendered: vi.fn(() => true),
    on: vi.fn((event: string, listener: Listener) => {
      const set = listeners.get(event) ?? new Set<Listener>()
      listeners.set(event, set)
      set.add(listener)
      return () => set.delete(listener)
    }),
  }
  const source = {
    sendAction: vi.fn(() => true),
    on: vi.fn(() => vi.fn()),
  }
  const actions = new ActionLifecycle(source)
  const context: HilosChangeLogReceiptContext = {
    connection:
      connection as unknown as HilosChangeLogReceiptContext['connection'],
    scopes,
    actions,
  }
  function emit(event: string, signal: Parameters<Listener>[0]) {
    for (const listener of listeners.get(event) ?? []) listener(signal)
  }
  /** Deliver a page's first window or a later correlated window reply. */
  function pushMetadataWindow(
    page: string,
    tableKey: string,
    rows: readonly { rowKey: string; slots: Record<string, unknown> }[],
    subscription = false,
    extra: Record<string, unknown> = {},
  ) {
    const window = {
      filter: { table: BOT.name },
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
    source,
    listeners,
    emit,
    pushMetadataWindow,
  }
}

/** Resolve the same native addresses as the project's route declarations. */
export function tableRouter(
  h: { context: HilosChangeLogReceiptContext },
  params: Record<string, string> = { tableId: 'bot' },
  unbuilt: readonly string[] = [],
) {
  const route = createSignal<PageRouteMatch>({
    page: h.context.scopes.page()?.key ?? null,
    params,
    admin: true,
  })
  const error = createSignal<PageError | null>(null)
  const router: HilosRouter = {
    currentRoute: route,
    currentPath: createSignal(''),
    currentTitle: createSignal(''),
    pageError: error,
    pageLoading: createSignal(false),
    pageIdentity: pageIdentity(h.context.scopes),
    dashboardSections: createSignal(undefined),
    resolvePath: (page, to = {}) => {
      if (unbuilt.includes(page)) return undefined
      const base = `/hilos/change-log/tables/${encodeURIComponent(to.tableId ?? '')}`
      if (page === HilosPages.CHANGE_LOG_TABLE) return base
      if (page === HilosPages.CHANGE_LOG_TABLE_COLUMNS) return `${base}/columns`
      if (page === HilosPages.CHANGE_LOG_TABLE_TRIGGERS)
        return `${base}/triggers`
      if (page === HilosPages.CHANGE_LOG_TABLE_HISTORY)
        return `${base}/history${to.record === undefined ? '' : `/${encodeURIComponent(to.record)}`}`
      if (page === HilosPages.DASHBOARD) return '/hilos'
      if (page === HilosPages.CHANGE_LOG) return '/hilos/change-log'
      if (page === HilosPages.CHANGE_LOG_TABLES)
        return '/hilos/change-log/tables'
      return undefined
    },
    clearPageError: () => {},
    denyCurrentPage: () => {},
    awaitPageAnswer: () => {},
    onLeave: () => () => {},
    navigate: vi.fn(),
    replacePath: () => {},
    start: () => {},
    stop: () => {},
  }
  return { router, route, error }
}

/** Mount either Overview or the shell for a tab not redrawn yet. */
export function mountTable(
  h: { context: HilosChangeLogReceiptContext } = tableHarness(),
  r = tableRouter(h),
  shell = false,
  children: ReactNode = <p data-id="table-child">Table body</p>,
) {
  const page = r.route.get().page!
  const view = render(
    <StrictMode>
      <HilosRouterContext.Provider value={r.router}>
        {shell ? (
          <HilosChangeLogTableShell context={h.context} page={page}>
            {children}
          </HilosChangeLogTableShell>
        ) : (
          <HilosChangeLogTablePage context={h.context} />
        )}
      </HilosRouterContext.Provider>
    </StrictMode>,
  )
  return { ...view, r, h }
}

/** Mount a metadata page with the adopted identity and StrictMode lifecycle. */
export function mountMetadataPage(
  Component: ComponentType<{ context: HilosChangeLogReceiptContext }>,
  h: { context: HilosChangeLogReceiptContext },
  r = tableRouter(h),
) {
  const view = render(
    <StrictMode>
      <HilosRouterContext.Provider value={r.router}>
        <Component context={h.context} />
      </HilosRouterContext.Provider>
    </StrictMode>,
  )
  return { ...view, r }
}

/** Deliver a new permitted declaration without changing the view's mount. */
export function updateTable(
  h: ReturnType<typeof tableHarness>,
  section: unknown,
) {
  act(() => answerTable(h.context.scopes.page()!, section))
}
