import { StrictMode, type ReactNode } from 'react'
import { act, render } from '@testing-library/react'
import {
  createAppPageRouter,
  createSignal,
  HilosPages,
  pageIdentity,
  ScopeManager,
  type HilosLegalContext,
  type HilosPageKey,
  type HilosRouter,
  type TableRow,
  type TableViewportController,
} from '@hilos/core/base'
import type { HilosLegalAdminRevision } from '@hilos/core/admin/legal'
import { vi } from 'vitest'
import { HilosRouterContext } from '../src/hilosRouterContext.js'

export const revisionTabs = [
  { page: HilosPages.LEGAL_REVISION, label: 'Overview' },
  { page: HilosPages.LEGAL_REVISION_TEXT, label: 'Text' },
]

export const legalRevision: HilosLegalAdminRevision = {
  document: 'terms',
  revisionId: 'historical/revision',
  declared: true,
  revision: {
    revisionId: 'historical/revision',
    publishedOn: '2026-01-01',
    effectiveOn: '2026-01-15',
    significance: 'editorial',
    setVersion: 1,
    deviationCount: 1,
  },
  current: false,
  predecessorId: 'previous',
  clauses: [
    {
      clauseKey: 'first',
      source: 'standard',
      statement: 'Historical first',
      text: 'The first historical clause.',
      standardStatement: 'Historical first',
      direction: null,
    },
    {
      clauseKey: 'retention',
      source: 'deviation',
      statement: 'Historical deviation',
      text: 'The historical project retention.',
      standardStatement: 'Limited retention',
      direction: 'stricter',
    },
    {
      clauseKey: 'last',
      source: 'standard',
      statement: 'Historical last',
      text: 'The last historical clause.',
      standardStatement: 'Historical last',
      direction: null,
    },
  ],
  changes: [
    {
      clauseKey: 'retention',
      title: 'Retention changed',
      kind: 'changed',
      before: {
        source: 'standard',
        statement: 'Previous retention',
        text: 'Before.',
        direction: null,
      },
      after: {
        source: 'deviation',
        statement: 'Historical deviation',
        text: 'After.',
        direction: 'stricter',
      },
    },
  ],
}

export const recordedRevision: HilosLegalAdminRevision = {
  document: 'retired/document',
  revisionId: 'removed/revision',
  declared: false,
  revision: null,
  current: null,
  predecessorId: null,
  clauses: null,
  changes: null,
}

export function acceptanceRows(
  count: number,
  details: HilosLegalAdminRevision = legalRevision,
): TableRow[] {
  return [
    {
      rowKey: details.revisionId,
      slots: {
        revision: {
          declared: details.declared,
          revision: details.revision,
          publishedOn: details.revision?.publishedOn ?? null,
          effectiveOn: details.revision?.effectiveOn ?? null,
          current: details.current,
          origin: null,
          heldCount: 0,
          acceptedCount: count,
        },
      },
    },
  ]
}

type Listener = (frame: never) => void

/** Ordinary page data and table frames for the real Revision shell and views. */
export function legalRevisionTestHarness(
  page: HilosPageKey = HilosPages.LEGAL_REVISION,
  options: {
    details?: HilosLegalAdminRevision
    answered?: boolean
    window?: boolean
    tabs?: typeof revisionTabs
  } = {},
) {
  const scopes = new ScopeManager()
  const scope = scopes.openPage(page)
  const details = options.details ?? legalRevision
  const data = {
    pageLabel: page === HilosPages.LEGAL_REVISION ? 'Revision' : 'Text',
    pageLead: 'The exact text, its changes, and its acceptances.',
    pageChildren: [],
    pageHeadOwner: {
      page: HilosPages.LEGAL_REVISION,
      label: 'Revision',
      lead: 'The exact text, its changes, and its acceptances.',
      icon: 'bi-file-earmark-text',
    },
    pageBreadcrumb: [
      { page: HilosPages.DASHBOARD, label: 'Hilos' },
      { page: HilosPages.LEGAL, label: 'Legal' },
      { page: HilosPages.LEGAL_DOCUMENTS, label: 'Documents' },
      { page: HilosPages.LEGAL_DOCUMENT, label: 'Document' },
      { page: HilosPages.LEGAL_DOCUMENT_REVISIONS, label: 'Revisions' },
      { page: HilosPages.LEGAL_REVISION, label: 'Revision' },
    ],
    pageTabs: options.tabs ?? revisionTabs,
    legalCatalogRefusal: null,
    legalRevision: details,
  }
  if (options.answered !== false)
    for (const [key, value] of Object.entries(data)) scope.data.set(key, value)

  const listeners = new Map<string, Set<Listener>>()
  const live = new Map<string, number>()
  const controllers = new Set<TableViewportController<unknown>>()
  const register = vi.fn(
    (key: string, controller: TableViewportController<unknown>) => {
      live.set(key, (live.get(key) ?? 0) + 1)
      controllers.add(controller)
    },
  )
  const unregister = vi.fn((key: string) => {
    live.set(key, (live.get(key) ?? 0) - 1)
  })
  const sendViewport = vi.fn(() => true)
  const dispatch = vi.fn()
  const tableKey = 'hilosLegalRevisions'
  const windowOf = (rows: TableRow[]) => ({
    filter: { document: details.document, revision: details.revisionId },
    rows,
    totalCount: rows.length,
    totalExact: true,
    firstAnchor: null,
    lastAnchor: null,
    limit: 25,
    sort: [{ field: 'publishedOn', direction: 'desc' }],
  })
  const answer = {
    type: 'page_response',
    data: {
      page,
      payload: {
        data,
        windows:
          page === HilosPages.LEGAL_REVISION && options.window !== false
            ? { [tableKey]: windowOf(acceptanceRows(8, details)) }
            : {},
      },
    },
  }
  const context = {
    scopes,
    connection: {
      on(event: string, listener: Listener) {
        const group = listeners.get(event) ?? new Set<Listener>()
        listeners.set(event, group)
        group.add(listener)
        if (event === 'projectSignal' && options.answered !== false)
          listener(answer as never)
        return () => group.delete(listener)
      },
      registerTableWindow: register,
      unregisterTableWindow: unregister,
      sendTableViewport: sendViewport,
      sendTableRendered: vi.fn(() => true),
    },
    actions: { dispatch },
  } as unknown as HilosLegalContext
  const routes = createAppPageRouter({})
  const params = {
    documentKey: details.document,
    revisionId: details.revisionId,
  }
  const router = {
    currentRoute: createSignal({ page, params, admin: true }),
    currentPath: createSignal(routes.path(page, params)!),
    pageIdentity: pageIdentity(scopes),
    resolvePath: (key: string, routeParams: Record<string, string>) =>
      routes.path(key, routeParams),
    navigate: vi.fn(),
    pageLoading: createSignal(options.answered === false),
  } as unknown as HilosRouter
  const emit = (event: string, frame: unknown) => {
    act(() => {
      for (const listener of listeners.get(event) ?? [])
        listener(frame as never)
    })
  }

  return {
    context,
    controllers,
    data,
    dispatch,
    liveWindows: () =>
      Object.fromEntries([...live].filter(([, count]) => count !== 0)),
    listenerCount: () =>
      [...listeners.values()].reduce((sum, group) => sum + group.size, 0),
    mount(node: ReactNode, strictMode = false) {
      const pageNode = (
        <HilosRouterContext.Provider value={router}>
          {node}
        </HilosRouterContext.Provider>
      )
      return render(strictMode ? <StrictMode>{pageNode}</StrictMode> : pageNode)
    },
    router,
    scopes,
    scope,
    register,
    unregister,
    sendViewport,
    emit,
    window(rows: TableRow[]) {
      emit('tableWindow', {
        type: 'table_window',
        data: { page, tableKey, ...windowOf(rows) },
      })
    },
    refuse() {
      emit('tableWindowRefused', {
        type: 'table_window_refused',
        data: { page, tableKey, errorCode: 'internal_error' },
      })
    },
    setPageData(key: string, value: unknown) {
      act(() => scope.data.set(key, value))
    },
    tableKey,
  }
}
