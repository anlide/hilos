import { StrictMode, type ReactNode } from 'react'
import { act, render } from '@testing-library/react'
import { HilosLegalTableKey } from '@hilos/core/admin/legal'
import {
  createSignal,
  HilosPages,
  pageIdentity,
  resolveHilosPath,
  ScopeManager,
  type HilosLegalContext,
  type HilosPageKey,
  type HilosRouter,
  type TableRow,
  type TableViewportController,
} from '@hilos/core/base'
import { vi } from 'vitest'
import { HilosRouterContext } from '../src/hilosRouterContext.js'

export const documentTabs = [
  { page: HilosPages.LEGAL_DOCUMENT, label: 'Overview' },
  { page: HilosPages.LEGAL_DOCUMENT_SET, label: 'Standard set' },
  { page: HilosPages.LEGAL_DOCUMENT_DEVIATIONS, label: 'Deviations' },
  { page: HilosPages.LEGAL_DOCUMENT_REVISIONS, label: 'Revisions' },
]

const revision = {
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
  newerSet: null,
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
      deviation: {
        ...legalDocument.deviations[0],
        position: 0,
      },
    },
  },
]

export const revisionRows: TableRow[] = [
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
]

type Listener = (frame: never) => void

interface LegalDocumentTestOptions {
  readonly answered?: boolean
  readonly document?: unknown
  readonly refusal?: string | null
  readonly rows?: TableRow[]
}

function tableKeyFor(page: HilosPageKey): string {
  if (page === HilosPages.LEGAL_DOCUMENT_SET)
    return HilosLegalTableKey.documentSet
  if (page === HilosPages.LEGAL_DOCUMENT_DEVIATIONS)
    return HilosLegalTableKey.documentDeviations
  if (page === HilosPages.LEGAL_DOCUMENT_REVISIONS)
    return HilosLegalTableKey.revisions
  return HilosLegalTableKey.settings
}

function rowsFor(page: HilosPageKey, rows?: TableRow[]): TableRow[] {
  if (rows) return rows
  if (page === HilosPages.LEGAL_DOCUMENT_SET) return clauseRows
  if (page === HilosPages.LEGAL_DOCUMENT_DEVIATIONS) return deviationRows
  if (page === HilosPages.LEGAL_DOCUMENT_REVISIONS) return revisionRows
  return [
    {
      rowKey: 'legal.refusal_after_deadline',
      slots: { setting: { value: 'freeze', defaultValue: 'freeze' } },
    },
  ]
}

function windowOf(page: HilosPageKey, rows: TableRow[]) {
  return {
    rows,
    totalCount: rows.length,
    totalExact: true,
    firstAnchor: null,
    lastAnchor: null,
    limit: 25,
    sort:
      page === HilosPages.LEGAL_DOCUMENT_REVISIONS
        ? [{ field: 'publishedOn', direction: 'desc' }]
        : null,
  }
}

/** Real legal page data, table connections, scope identity and router. */
export function legalDocumentTestHarness(
  page: HilosPageKey,
  options: LegalDocumentTestOptions = {},
) {
  const scopes = new ScopeManager()
  const scope = scopes.openPage(page)
  const document = Object.hasOwn(options, 'document')
    ? options.document
    : legalDocument
  const documentKey =
    typeof document === 'object' &&
    document !== null &&
    'document' in document &&
    typeof document.document === 'string'
      ? document.document
      : 'terms'
  const pageTabs = documentTabs
  const data = {
    pageLabel: pageTabs.find((tab) => tab.page === page)?.label ?? 'Overview',
    pageLead: '',
    pageChildren: [],
    pageHeadOwner: {
      page: HilosPages.LEGAL_DOCUMENT,
      label: 'Document',
      lead: 'The standard set, project deviations, and declared revisions.',
      icon: 'bi-file-earmark-text',
    },
    pageBreadcrumb: [
      { page: HilosPages.DASHBOARD, label: 'Hilos' },
      { page: HilosPages.LEGAL, label: 'Legal' },
      { page: HilosPages.LEGAL_DOCUMENTS, label: 'Documents' },
      { page: HilosPages.LEGAL_DOCUMENT, label: 'Document' },
    ],
    pageTabs,
    legalCatalogRefusal: options.refusal ?? null,
    legalDocument: document,
  }
  if (options.answered !== false)
    for (const [key, value] of Object.entries(data)) scope.data.set(key, value)

  const listeners = new Map<string, Set<Listener>>()
  const live = new Map<string, number>()
  const controllers = new Map<string, TableViewportController<unknown>>()
  const sendViewport = vi.fn(() => true)
  const sendRendered = vi.fn(() => true)
  const dispatch = vi.fn()
  const tableKey = tableKeyFor(page)
  const tableRows = rowsFor(page, options.rows)
  const answer = {
    type: 'page_response',
    data: {
      page,
      payload: {
        windows: {
          [tableKey]: windowOf(page, tableRows),
        },
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
      registerTableWindow(
        key: string,
        controller: TableViewportController<unknown>,
      ) {
        live.set(key, (live.get(key) ?? 0) + 1)
        controllers.set(key, controller)
      },
      unregisterTableWindow(key: string) {
        live.set(key, (live.get(key) ?? 0) - 1)
      },
      sendTableViewport: sendViewport,
      sendTableRendered: sendRendered,
    },
    actions: { dispatch },
  } as unknown as HilosLegalContext

  const params = { documentKey }
  const router = {
    currentRoute: createSignal({ page, params, admin: true }),
    currentPath: createSignal(resolveHilosPath(page, params)!),
    pageIdentity: pageIdentity(scopes),
    resolvePath: resolveHilosPath,
  } as unknown as HilosRouter

  return {
    context,
    controllers,
    data,
    dispatch,
    liveWindows: () =>
      Object.fromEntries([...live].filter(([, count]) => count !== 0)),
    listenerCount: () =>
      [...listeners.values()].reduce((count, group) => count + group.size, 0),
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
    sendRendered,
    sendViewport,
    emit(event: string, data: unknown) {
      const type =
        event === 'tableViewportDelta'
          ? 'table_viewport_delta'
          : event === 'tableWindow'
            ? 'table_window'
            : event
      const frame = { type, data }
      act(() => {
        for (const listener of listeners.get(event) ?? [])
          listener(frame as never)
      })
    },
    setPageData(key: string, value: unknown) {
      act(() => scope.data.set(key, value))
    },
    tableKey,
  }
}
