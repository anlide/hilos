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
import { vi } from 'vitest'
import { markRaw, type Component } from 'vue'
import { hilosRouterKey } from '../src/hilosRouterKey.js'

export const documentTabs = [
  { page: HilosPages.LEGAL_DOCUMENT, label: 'Overview' },
  { page: HilosPages.LEGAL_DOCUMENT_SET, label: 'Standard set' },
  { page: HilosPages.LEGAL_DOCUMENT_DEVIATIONS, label: 'Deviations' },
  { page: HilosPages.LEGAL_DOCUMENT_REVISIONS, label: 'Revisions' },
]
export const revision = {
  revisionId: 'current',
  publishedOn: '2026-09-27',
  effectiveOn: '2026-10-05',
  significance: 'substantial',
  setVersion: 1,
  deviationCount: 1,
}
export const legalDocument = {
  document: 'terms',
  declared: true,
  covered: 21,
  window: 7,
  lapsed: 3,
  standardSetCatalogClass: 'Hilos\\Legal\\StandardSetCatalog',
  projectCatalogClass: 'Project\\Legal\\LegalCatalog',
  revision,
  set: {
    version: 1,
    publishedOn: '2026-01-01',
    significance: 'substantial',
    clauses: [{ clauseKey: 'retention', statement: 'Retention is limited' }],
  },
  newerSet: {
    version: 2,
    publishedOn: '2026-10-01',
    significance: 'editorial',
    changes: [
      {
        clauseKey: 'retention',
        kind: 'changed',
        title: 'Retention clarified',
        before: null,
        after: null,
      },
    ],
  },
  deviations: [
    {
      clauseKey: 'retention',
      standardStatement: 'Retention is limited',
      statement: 'Messages stay',
      text: 'The project retains its messages.',
      direction: 'stricter',
    },
  ],
}
export const historicalDocument = {
  ...legalDocument,
  document: 'retired/document',
  declared: false,
  revision: null,
  set: null,
  newerSet: null,
  deviations: [],
  projectCatalogClass: null,
}
export const clauseRows: TableRow[] = [
  {
    rowKey: 'retention',
    slots: {
      clause: {
        clauseKey: 'retention',
        statement: 'Retention is limited',
        position: 0,
      },
    },
  },
]
export const deviationRows: TableRow[] = [
  {
    rowKey: 'retention',
    slots: {
      deviation: { ...legalDocument.deviations[0], position: 0 },
    },
  },
]
export const revisionRows = [
  {
    rowKey: 'current',
    slots: {
      revision: {
        declared: true,
        revision,
        publishedOn: revision.publishedOn,
        effectiveOn: revision.effectiveOn,
        current: true,
        origin: null,
        heldCount: 5,
        acceptedCount: 8,
      },
    },
  },
  {
    rowKey: 'removed/revision',
    slots: {
      revision: {
        declared: false,
        revision: null,
        publishedOn: null,
        effectiveOn: null,
        current: false,
        origin: null,
        heldCount: 14,
        acceptedCount: 16,
      },
    },
  },
] satisfies TableRow[]

/** Real page/head/table mounts consume one complete first response. */
export function documentHarness(
  component: Component,
  page: string = HilosPages.LEGAL_DOCUMENT,
  options: {
    document?: unknown
    rows?: TableRow[]
    refused?: boolean
    answered?: boolean
    policy?: unknown
  } = {},
) {
  const scopes = new ScopeManager()
  const scope = scopes.openPage(page)
  const listeners = new Map<string, Set<(frame: never) => void>>()
  const registered = new Set<string>()
  const sendViewport = vi.fn()
  const dispatch = vi.fn()
  const routeDocument =
    (options.document as { document?: string } | undefined)?.document ?? 'terms'
  const tableKey =
    page === HilosPages.LEGAL_DOCUMENT_SET
      ? 'hilosLegalDocumentSet'
      : page === HilosPages.LEGAL_DOCUMENT_DEVIATIONS
        ? 'hilosLegalDocumentDeviations'
        : page === HilosPages.LEGAL_DOCUMENT_REVISIONS
          ? 'hilosLegalRevisions'
          : 'hilosLegalSettings'
  const settingsRows = (value: unknown): TableRow[] => [
    {
      rowKey: 'legal.refusal_after_deadline',
      slots: { setting: { value, defaultValue: 'freeze' } },
    },
  ]
  const windowOf = (rows: TableRow[]) => ({
    rows,
    totalCount: rows.length,
    totalExact: true,
    firstAnchor: null,
    lastAnchor: null,
    limit: 25,
    sort:
      tableKey === 'hilosLegalRevisions'
        ? [{ field: 'publishedOn', direction: 'desc' }]
        : null,
  })
  const data = {
    pageLabel: documentTabs.find((tab) => tab.page === page)?.label,
    pageLead: 'This page lead',
    pageChildren: [],
    pageHeadOwner: {
      page: HilosPages.LEGAL_DOCUMENT,
      label: 'Document',
      lead: 'The standard set, project deviations, and declared revisions.',
      icon: 'bi-file-earmark-text',
    },
    pageBreadcrumb: [
      { page: 'hilos', label: 'Hilos' },
      { page: 'hilos_legal', label: 'Legal' },
      { page: 'hilos_legal_documents', label: 'Documents' },
      { page: 'hilos_legal_document', label: 'Document' },
    ],
    pageTabs: documentTabs,
    legalCatalogRefusal: null,
    legalDocument: Object.hasOwn(options, 'document')
      ? options.document
      : legalDocument,
  }
  if (options.answered !== false)
    for (const [key, value] of Object.entries(data)) scope.data.set(key, value)
  const answer = {
    type: 'page_response',
    data: {
      page,
      payload: {
        data,
        windows: options.refused
          ? {}
          : {
              [tableKey]: windowOf(
                options.rows ??
                  (tableKey === 'hilosLegalSettings'
                    ? settingsRows(options.policy ?? 'freeze')
                    : []),
              ),
            },
        refusedWindows: options.refused
          ? { [tableKey]: { errorCode: 'internal_error' } }
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
  const params = { documentKey: routeDocument }
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
    tableKey,
    window(rows: TableRow[]) {
      emit('tableWindow', {
        type: 'table_window',
        data: { page, tableKey, ...windowOf(rows) },
      })
    },
    policy(value: unknown) {
      this.window(settingsRows(value))
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
