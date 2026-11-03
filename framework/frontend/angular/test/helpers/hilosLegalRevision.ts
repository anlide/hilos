import { TestBed } from '@angular/core/testing'
import type { Type } from '@angular/core'
import {
  computedSignal,
  createSignal,
  HilosPages,
  pageIdentity,
  resolveHilosPath,
  resolvePageTitle,
  ScopeManager,
  type HilosLegalContext,
  type HilosPageKey,
  type HilosRouter,
  type TableRow,
  type TableViewportController,
} from '@hilos/core/base'
import type { HilosLegalAdminRevision } from '@hilos/core/admin/legal'
import { vi } from 'vitest'
import { HILOS_ROUTER } from '../../src/hilosRouterToken.js'

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

type Listener = (frame: { type?: string; data: unknown }) => void

export interface LegalRevisionBenchOptions {
  readonly details?: HilosLegalAdminRevision
  readonly answered?: boolean
  readonly window?: boolean
  readonly tabs?: typeof revisionTabs
}

/** A real revision scope and first window, with only the transport replaced. */
export function legalRevisionBench(
  page: HilosPageKey = HilosPages.LEGAL_REVISION,
  options: LegalRevisionBenchOptions = {},
) {
  const scopes = new ScopeManager()
  const scope = scopes.openPage(page)
  const details = options.details ?? legalRevision
  const listeners = new Map<string, Set<Listener>>()
  const registered = new Map<string, TableViewportController<unknown>>()
  const controllers: TableViewportController<unknown>[] = []
  const sendTableViewport = vi.fn(() => true)
  const dispatch = vi.fn()
  const tableKey = 'hilosLegalRevisions'
  const pageData = (revision: unknown) => ({
    pageLabel: page === HilosPages.LEGAL_REVISION ? 'Revision' : 'Text',
    pageLead: '',
    pageChildren: [],
    pageHeadOwner: {
      page: HilosPages.LEGAL_REVISION,
      label: 'Revision',
      lead: '',
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
    legalRevision: revision,
  })
  const windowOf = (rows: TableRow[]) => ({
    rows,
    totalCount: rows.length,
    totalExact: true,
    firstAnchor: null,
    lastAnchor: null,
    limit: 25,
    sort: [{ field: 'publishedOn', direction: 'desc' }],
  })
  const writePageData = (revision: unknown) => {
    for (const [key, value] of Object.entries(pageData(revision)))
      scopes.page()!.data.set(key, value)
  }
  const initialAnswer = {
    type: 'page_response',
    data: {
      page,
      payload: {
        data: pageData(details),
        windows:
          page === HilosPages.LEGAL_REVISION && options.window !== false
            ? { [tableKey]: windowOf(acceptanceRows(8)) }
            : {},
      },
    },
  }
  if (options.answered !== false) writePageData(details)
  const context = {
    scopes,
    connection: {
      on(event: string, listener: Listener) {
        const group = listeners.get(event) ?? new Set<Listener>()
        listeners.set(event, group)
        group.add(listener)
        if (event === 'projectSignal' && options.answered !== false)
          listener(initialAnswer)
        return () => group.delete(listener)
      },
      registerTableWindow(
        key: string,
        controller: TableViewportController<unknown>,
      ) {
        registered.set(key, controller)
        controllers.push(controller)
      },
      unregisterTableWindow: (key: string) => registered.delete(key),
      sendTableViewport,
      sendTableRendered: vi.fn(() => true),
    },
    actions: { dispatch },
  } as unknown as HilosLegalContext
  const route = createSignal({
    page,
    params: { documentKey: details.document, revisionId: details.revisionId },
    admin: true,
  })
  const identity = pageIdentity(scopes)
  const router: HilosRouter = {
    currentRoute: route,
    currentPath: createSignal(resolveHilosPath(page, route.get().params)!),
    currentTitle: computedSignal(() => {
      const current = identity.get()
      return resolvePageTitle(
        route.get().page,
        {},
        'Polls',
        current?.label,
        true,
        current?.pageHeadOwner?.label,
      )
    }),
    pageError: createSignal(null),
    pageLoading: createSignal(options.answered === false),
    pageIdentity: identity,
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
  const emit = (event: string, frame: { type?: string; data: unknown }) => {
    for (const listener of listeners.get(event) ?? []) listener(frame)
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
    dispatch,
    emit,
    answer(revision: unknown = details) {
      writePageData(revision)
    },
    subscriptionWindow(rows: TableRow[]) {
      emit('projectSignal', {
        type: 'page_response',
        data: { page, payload: { windows: { [tableKey]: windowOf(rows) } } },
      })
    },
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

/** Mount the production revision page or a caller of its internal shell. */
export function revisionHarness<T>(
  component: Type<T>,
  page: HilosPageKey = HilosPages.LEGAL_REVISION,
  options: LegalRevisionBenchOptions = {},
  inputs: Record<string, unknown> = {},
) {
  const bench = legalRevisionBench(page, options)
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
    answer(revision: unknown = options.details ?? legalRevision) {
      bench.answer(revision)
      fixture.detectChanges()
    },
    data(key: string, value: unknown) {
      bench.scopes.page()!.data.set(key, value)
      fixture.detectChanges()
    },
    subscriptionWindow(rows: TableRow[]) {
      bench.subscriptionWindow(rows)
      fixture.detectChanges()
    },
    window(rows: TableRow[]) {
      bench.window(rows)
      fixture.detectChanges()
    },
    refuse() {
      bench.refuse()
      fixture.detectChanges()
    },
  }
}
