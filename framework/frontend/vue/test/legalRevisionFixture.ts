import { mount } from '@vue/test-utils'
import {
  createAppPageRouter,
  createSignal,
  HilosPages,
  pageIdentity,
  ScopeManager,
  type HilosLegalContext,
  type HilosRouter,
  type TableRow,
} from '@hilos/core/base'
import { type HilosLegalAdminRevision } from '@hilos/core/admin/legal'
import { vi } from 'vitest'
import { markRaw, type Component } from 'vue'
import { hilosRouterKey } from '../src/hilosRouterKey.js'

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
export function acceptanceRows(count: number): TableRow[] {
  return [
    {
      rowKey: legalRevision.revisionId,
      slots: {
        revision: {
          declared: true,
          revision: legalRevision.revision,
          publishedOn: '2026-01-01',
          effectiveOn: '2026-01-15',
          current: false,
          origin: null,
          heldCount: 0,
          acceptedCount: count,
        },
      },
    },
  ]
}

/** Mounts the production head and page over their ordinary page answer and table frames. */
export function revisionHarness(
  component: Component,
  page: string = HilosPages.LEGAL_REVISION,
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
  const listeners = new Map<string, Set<(frame: never) => void>>()
  const registered = new Set<string>()
  const sendViewport = vi.fn()
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
  const answer = {
    type: 'page_response',
    data: {
      page,
      payload: {
        data,
        windows:
          page === HilosPages.LEGAL_REVISION && options.window !== false
            ? { [tableKey]: windowOf(acceptanceRows(8)) }
            : {},
      },
    },
  }
  const context = {
    scopes,
    connection: {
      on(event: string, listener: (frame: never) => void) {
        const group = listeners.get(event) ?? new Set()
        listeners.set(event, group)
        group.add(listener)
        if (event === 'projectSignal' && options.answered !== false)
          listener(answer as never)
        return () => group.delete(listener)
      },
      registerTableWindow: (key: string) => registered.add(key),
      unregisterTableWindow: (key: string) => registered.delete(key),
      sendTableViewport: sendViewport,
      sendTableRendered: vi.fn(),
      sendTableRowFocus: vi.fn(),
      sendTableFacets: vi.fn(),
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
  const view = mount(component, {
    props: { context: markRaw(context), page },
    global: { provide: { [hilosRouterKey as symbol]: router } },
    attachTo: document.body,
  })
  const emit = (event: string, frame: unknown) => {
    for (const listener of listeners.get(event) ?? []) listener(frame as never)
  }
  return {
    view,
    scope,
    scopes,
    router,
    context,
    registered,
    sendViewport,
    dispatch,
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
    listenerCount: () =>
      [...listeners.values()].reduce((sum, group) => sum + group.size, 0),
  }
}
