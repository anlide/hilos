import { TestBed } from '@angular/core/testing'
import type { Type } from '@angular/core'
import {
  computedSignal,
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
import type { HilosLegalAdminDocument } from '@hilos/core/admin/legal'
import { vi } from 'vitest'
import { HILOS_ROUTER } from '../../src/hilosRouterToken.js'

export const DOCUMENT: HilosLegalAdminDocument = {
  document: 'terms',
  declared: true,
  standardSetCatalogClass: 'Hilos\\Legal\\StandardSetCatalog',
  projectCatalogClass: 'Project\\Legal\\LegalCatalog',
  covered: 8,
  window: 3,
  lapsed: 2,
  revision: {
    revisionId: 'current',
    publishedOn: '2026-09-27',
    effectiveOn: '2026-10-01',
    significance: 'substantial',
    setVersion: 1,
    deviationCount: 0,
  },
  set: {
    version: 1,
    publishedOn: '2026-09-01',
    significance: 'substantial',
    clauses: [],
  },
  newerSet: null,
  deviations: [],
}
export const DOCUMENT_TABS = [
  { page: HilosPages.LEGAL_DOCUMENT, label: 'Overview' },
  { page: HilosPages.LEGAL_DOCUMENT_SET, label: 'Standard set' },
  { page: HilosPages.LEGAL_DOCUMENT_DEVIATIONS, label: 'Deviations' },
  { page: HilosPages.LEGAL_DOCUMENT_REVISIONS, label: 'Revisions' },
]
export const CLAUSE_ROWS: TableRow[] = [
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
export const DEVIATION_ROWS: TableRow[] = [
  {
    rowKey: 'retention',
    slots: {
      deviation: {
        clauseKey: 'retention',
        statement: 'Messages stay',
        standardStatement: 'Retention is limited',
        text: 'The project retains its messages.\n\nDeletion follows the schedule.',
        direction: 'stricter',
        position: 0,
      },
    },
  },
]
export const REVISION_ROWS: TableRow[] = [
  {
    rowKey: 'current',
    slots: {
      revision: {
        declared: true,
        revision: DOCUMENT.revision,
        publishedOn: DOCUMENT.revision?.publishedOn ?? null,
        effectiveOn: DOCUMENT.revision?.effectiveOn ?? null,
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
type Listener = (frame: { type?: string; data: unknown }) => void

export interface LegalDocumentBenchOptions {
  readonly initialAnswer?: boolean
  readonly tableKey?: string
  readonly rows?: TableRow[]
  readonly refused?: boolean
  readonly document?: unknown
  readonly routeDocument?: string
}

/** A real page scope and settings carrier, with only the transport replaced. */
export function legalDocumentBench(
  page: HilosPageKey = HilosPages.LEGAL_DOCUMENT,
  options: LegalDocumentBenchOptions = {},
) {
  const scopes = new ScopeManager()
  const scope = scopes.openPage(page)
  const listeners = new Map<string, Set<Listener>>()
  const registered = new Map<string, TableViewportController<unknown>>()
  const controllers: TableViewportController<unknown>[] = []
  const sendTableViewport = vi.fn(() => true)
  const sendTableRendered = vi.fn(() => true)
  const dispatch = vi.fn()
  const pageData = (document: unknown) => ({
    pageLabel:
      DOCUMENT_TABS.find((tab) => tab.page === page)?.label ?? 'Overview',
    pageLead: '',
    pageHeadOwner: {
      page: HilosPages.LEGAL_DOCUMENT,
      label: 'Document',
      lead: '',
      icon: 'bi-file-earmark-text',
    },
    pageBreadcrumb: [
      { page: HilosPages.DASHBOARD, label: 'Hilos' },
      { page: HilosPages.LEGAL, label: 'Legal' },
      { page: HilosPages.LEGAL_DOCUMENTS, label: 'Documents' },
      { page: HilosPages.LEGAL_DOCUMENT, label: 'Document' },
    ],
    pageTabs: DOCUMENT_TABS,
    pageChildren: [],
    legalDocument: document,
    legalCatalogRefusal: null,
  })
  const writePageData = (document: unknown) => {
    for (const [key, value] of Object.entries(pageData(document)))
      scope.data.set(key, value)
  }
  const settingsWindow = (value: unknown) => ({
    filter: {},
    rows: [
      {
        rowKey: 'legal.refusal_after_deadline',
        slots: { setting: { value, defaultValue: value } },
      },
    ],
    totalCount: 1,
    totalExact: true,
    firstAnchor: null,
    lastAnchor: null,
    limit: 25,
    sort: null,
  })
  const windowOf = (rows: TableRow[], tableKey: string) => ({
    filter: tableKey === 'hilosLegalSettings' ? {} : { document: documentKey },
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
  const configuredDocument = Object.hasOwn(options, 'document')
    ? options.document
    : DOCUMENT
  const documentKey =
    typeof configuredDocument === 'object' &&
    configuredDocument !== null &&
    'document' in configuredDocument
      ? configuredDocument.document
      : 'terms'
  const responseWindows = options.tableKey
    ? options.refused
      ? {}
      : { [options.tableKey]: windowOf(options.rows ?? [], options.tableKey) }
    : { hilosLegalSettings: settingsWindow('freeze') }
  const refusedWindows =
    options.tableKey && options.refused
      ? { [options.tableKey]: { errorCode: 'internal_error' } }
      : {}
  if (options.initialAnswer) writePageData(configuredDocument)
  const context = {
    scopes,
    connection: {
      on(event: string, listener: Listener) {
        const set = listeners.get(event) ?? new Set<Listener>()
        listeners.set(event, set)
        set.add(listener)
        if (event === 'projectSignal')
          listener({
            type: 'page_response',
            data: {
              page,
              payload: {
                ...(options.initialAnswer
                  ? { data: pageData(configuredDocument) }
                  : {}),
                windows: responseWindows,
                refusedWindows,
              },
            },
          })
        return () => set.delete(listener)
      },
      registerTableWindow(
        key: string,
        controller: TableViewportController<unknown>,
      ) {
        registered.set(key, controller)
        controllers.push(controller)
      },
      unregisterTableWindow(key: string) {
        registered.delete(key)
      },
      sendTableViewport,
      sendTableRendered,
    },
    actions: { dispatch },
  } as unknown as HilosLegalContext
  const route = createSignal({
    page,
    params: { documentKey: options.routeDocument ?? 'terms' },
    admin: true,
  })
  const currentPath = createSignal(
    resolveHilosPath(page, {
      documentKey: options.routeDocument ?? 'terms',
    })!,
  )
  const identity = pageIdentity(scopes)
  const router: HilosRouter = {
    currentRoute: route,
    currentPath,
    currentTitle: createSignal(''),
    pageError: createSignal(null),
    pageLoading: createSignal(false),
    pageIdentity: computedSignal(() => identity.get()),
    dashboardSections: createSignal(undefined),
    resolvePath: resolveHilosPath,
    navigate: vi.fn(),
    clearPageError() {},
    denyCurrentPage() {},
    awaitPageAnswer() {},
    onLeave: () => () => {},
    replacePath() {},
    start() {},
    stop() {},
  }
  return {
    context,
    scopes,
    scope,
    router,
    route,
    registered,
    controllers,
    sendTableViewport,
    sendTableRendered,
    dispatch,
    tableKey: options.tableKey,
    emit(event: string, frame: { type?: string; data: unknown }) {
      for (const listener of listeners.get(event) ?? []) listener(frame)
    },
    window(tableKey: string, rows: TableRow[]) {
      this.emit('tableWindow', {
        type: 'table_window',
        data: { page, tableKey, ...windowOf(rows, tableKey) },
      })
    },
    refuseWindow(tableKey: string) {
      this.emit('tableWindowRefused', {
        type: 'table_window_refused',
        data: { page, tableKey, errorCode: 'internal_error' },
      })
    },
    /** The first answer carries the head and all four document links. */
    answer(document: unknown = DOCUMENT) {
      writePageData(document)
    },
    policy(value: unknown) {
      for (const listener of listeners.get('tableWindow') ?? [])
        listener({
          data: {
            page,
            tableKey: 'hilosLegalSettings',
            ...settingsWindow(value),
          },
        })
    },
    listenerCount: () =>
      [...listeners.values()].reduce((sum, set) => sum + set.size, 0),
  }
}

/** Mount a real document page or a caller of the internal shell. */
export function legalDocumentHarness<T>(
  component: Type<T>,
  page: HilosPageKey = HilosPages.LEGAL_DOCUMENT,
  inputs: Record<string, unknown> = {},
  options: LegalDocumentBenchOptions = {},
) {
  const bench = legalDocumentBench(page, options)
  TestBed.configureTestingModule({
    providers: [{ provide: HILOS_ROUTER, useValue: bench.router }],
  })
  const fixture = TestBed.createComponent(component)
  fixture.componentRef.setInput('context', bench.context)
  for (const [key, value] of Object.entries(inputs))
    fixture.componentRef.setInput(key, value)
  fixture.detectChanges()
  return {
    ...bench,
    fixture,
    root: fixture.nativeElement as HTMLElement,
    answer(document: unknown = DOCUMENT) {
      bench.answer(document)
      fixture.detectChanges()
    },
    data(key: string, value: unknown) {
      bench.scope.data.set(key, value)
      fixture.detectChanges()
    },
    policy(value: unknown) {
      bench.policy(value)
      fixture.detectChanges()
    },
    window(rows: TableRow[]) {
      if (options.tableKey === undefined)
        throw new Error('This harness has no document table key.')
      bench.window(options.tableKey, rows)
      fixture.detectChanges()
    },
    refuseWindow() {
      if (options.tableKey === undefined)
        throw new Error('This harness has no document table key.')
      bench.refuseWindow(options.tableKey)
      fixture.detectChanges()
    },
  }
}

/** The meaningful text of an element, with template whitespace normalized. */
export function text(root: ParentNode, selector: string): string | undefined {
  return root.querySelector(selector)?.textContent?.replace(/\s+/g, ' ').trim()
}
