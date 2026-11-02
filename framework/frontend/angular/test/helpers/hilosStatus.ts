import { type Type } from '@angular/core'
import { TestBed, type ComponentFixture } from '@angular/core/testing'
import { afterEach, beforeEach, vi } from 'vitest'
import {
  ActionLifecycle,
  HilosPages,
  ScopeManager,
  applyServerTime,
  bindAdminAccess,
  bindPageScope,
  bindTimeZoneState,
  createSignal,
  hilosToasts,
  PROTECTED_MODE_INACTIVE,
  type ConnectionState,
  type HilosConnection,
  type HilosRouter,
  type HilosPageIdentity,
  type PageRouteMatch,
  type TableRow,
} from '@hilos/core/base'
import type {
  HilosStatusContext,
  HilosStatusIncident,
  HilosStatusOverviewData,
  HilosStatusSubscriberRow,
  HilosStatusSubscribersData,
  HilosStatusWorkRow,
  HilosStatusWorksData,
} from '@hilos/core/admin/status'

import type { PublicStatusFile, PublicStatusFetch } from '@hilos/core/status'

import { HILOS_ROUTER } from '../../src/hilosRouterToken.js'

export function incident(
  overrides: Partial<HilosStatusIncident> = {},
): HilosStatusIncident {
  return {
    incidentId: 11,
    componentKey: 'service',
    componentName: 'Service',
    agentType: 'service',
    severity: 'major_outage',
    detectedAt: 1800000000000,
    publishAt: 1800000060000,
    publishedAt: null,
    suppressedAt: null,
    expectedInstances: 2,
    liveInstances: 0,
    observedAt: 1800000000000,
    updates: [],
    ...overrides,
  }
}

export function overview(
  overrides: Partial<HilosStatusOverviewData> = {},
): HilosStatusOverviewData {
  return {
    healthKnown: true,
    historyCount: 0,
    components: { operationalCount: 0, totalCount: 2 },
    publishDelaySeconds: 60,
    maintenance: null,
    scheduledWorks: null,
    incidents: [incident()],
    subscribers: { confirmedCount: 0, pendingCount: 0 },
    ...overrides,
  }
}

/** Real scopes and tracked lifecycle; only the network and route transport are fakes. */
export function statusHarness(page: string = HilosPages.STATUS) {
  const listeners = new Map<string, Set<(value: unknown) => void>>()
  const sent: { action: string; data: unknown; requestId?: string }[] = []
  const viewports: unknown[][] = []
  const focuses: unknown[][] = []
  const facets: unknown[][] = []
  const rendered: unknown[][] = []
  const registered = new Set<string>()
  const emit = (event: string, value: unknown): void => {
    for (const listener of [...(listeners.get(event) ?? [])]) listener(value)
  }
  let connectionState: ConnectionState = 'connected'
  let protectedMode = PROTECTED_MODE_INACTIVE
  const connection = {
    projectTimeZone: 'UTC',
    reconnectDragging: false,
    get state() {
      return connectionState
    },
    get protectedMode() {
      return protectedMode
    },
    forgetPageFrames() {},
    send() {
      return true
    },
    tableWindowDescriptors() {
      return {}
    },
    on(event: string, listener: (value: unknown) => void) {
      const bucket = listeners.get(event) ?? new Set()
      listeners.set(event, bucket)
      bucket.add(listener)
      return () => bucket.delete(listener)
    },
    sendAction(action: string, data: unknown, requestId?: string) {
      sent.push({ action, data, requestId })
      return true
    },
    sendTableViewport(...args: unknown[]) {
      viewports.push(args)
      return true
    },
    sendTableRendered(...args: unknown[]) {
      rendered.push(args)
      return true
    },
    sendTableRowFocus(...args: unknown[]) {
      focuses.push(args)
      return true
    },
    sendTableFacets(...args: unknown[]) {
      facets.push(args)
      return true
    },
    registerTableWindow(key: string) {
      registered.add(key)
    },
    unregisterTableWindow(key: string) {
      registered.delete(key)
    },
  } as unknown as HilosConnection
  const scopes = new ScopeManager()
  scopes.openPage(page)
  const user = { type: 'users', id: '1' }
  scopes.session.data.set('currentUser', user)
  scopes.session.entities.upsert(user, { admin: true })
  const releaseAccess = bindAdminAccess(scopes)
  const actions = new ActionLifecycle(connection, {
    timeoutMs: 1000,
    deferredLoadingMs: 30,
  })
  const context: HilosStatusContext = { scopes, connection, actions }
  const paths: Record<string, string> = {
    [HilosPages.DASHBOARD]: '/hilos',
    [HilosPages.STATUS]: '/hilos/status',
    [HilosPages.STATUS_COMPONENTS]: '/hilos/status/components',
    [HilosPages.STATUS_SUBSCRIBERS]: '/hilos/status/subscribers',
    [HilosPages.STATUS_WORKS]: '/hilos/status/works',
    [HilosPages.STATUS_HISTORY]: '/hilos/status/history',
    [HilosPages.SETTINGS]: '/hilos/settings',
    [HilosPages.MAINTENANCE]: '/hilos/maintenance',
  }
  const router: HilosRouter = {
    currentRoute: createSignal<PageRouteMatch>({
      page,
      params: {},
      admin: true,
    }),
    currentPath: createSignal(paths[page]!),
    currentTitle: createSignal('Status'),
    pageError: createSignal(null),
    pageLoading: createSignal(false),
    pageIdentity: createSignal<HilosPageIdentity | undefined>({
      label: page === HilosPages.STATUS ? 'Status' : 'Components',
      lead: '',
      children: [],
      breadcrumb: [
        { page: HilosPages.DASHBOARD, label: 'Hilos' },
        { page: HilosPages.STATUS, label: 'Status' },
      ],
      pageHeadOwner: {
        page: HilosPages.STATUS,
        label: 'Status',
        icon: 'bi-activity',
        lead: 'A public status page: service health, incidents, and planned work.',
      },
      pageTabs: [
        { page: HilosPages.STATUS, label: 'Overview' },
        { page: HilosPages.STATUS_WORKS, label: 'Scheduled maintenance' },
        { page: HilosPages.STATUS_COMPONENTS, label: 'Components' },
        { page: HilosPages.STATUS_SUBSCRIBERS, label: 'Subscribers' },
        { page: HilosPages.STATUS_HISTORY, label: 'History' },
      ],
    }),
    dashboardSections: createSignal(undefined),
    resolvePath: (key) => paths[key],
    clearPageError() {},
    denyCurrentPage() {},
    awaitPageAnswer() {},
    onLeave: () => () => {},
    navigate() {},
    replacePath() {},
    start() {},
    stop() {},
  }
  return {
    context,
    router,
    sent,
    viewports,
    focuses,
    facets,
    rendered,
    registered,
    listenerCount: () =>
      [...listeners.values()].reduce((sum, bucket) => sum + bucket.size, 0),
    emit,
    connect(state: ConnectionState) {
      connectionState = state
      emit('state', state)
    },
    freeze(active: boolean) {
      protectedMode = { ...PROTECTED_MODE_INACTIVE, active }
      emit('protectedMode', protectedMode)
    },
    setOverview(data: unknown) {
      scopes.page()?.data.set('status', data)
    },
    viewer() {
      scopes.session.data.set('adminViewMode', true)
      scopes.session.entities.upsert(user, { admin: false })
    },
    reply(
      success: boolean,
      extra: Record<string, unknown> = {},
      index = sent.length - 1,
    ) {
      const request = sent[index]!
      emit(success ? 'actionSuccess' : 'actionError', {
        action: request.action,
        requestId: request.requestId,
        ...extra,
      })
    },
    dispose() {
      emit('state', 'disconnected')
      listeners.clear()
      releaseAccess()
      scopes.dropPage()
    },
  }
}

/** A raw subscriber slot normalized by the actual page response binder. */
export function subscriberRow(
  overrides: Partial<HilosStatusSubscriberRow> = {},
): TableRow {
  const subscriber: HilosStatusSubscriberRow = {
    id: 7,
    email: 'reader@example.net',
    subscribedAt: 1_800_000_000_000,
    confirmed: false,
    ...overrides,
  }
  return { rowKey: String(subscriber.id), slots: { subscriber } }
}

/** Ingest page data and windows through the real core normalizer. */
export function statusSubscribersHarness(viewer = false) {
  const h = statusHarness(HilosPages.STATUS_SUBSCRIBERS)
  if (viewer) h.viewer()
  const pages = bindPageScope(h.context.connection, h.context.scopes)
  pages.subscribe(HilosPages.STATUS_SUBSCRIBERS)
  const answer = (payload: Record<string, unknown>) =>
    h.emit('projectSignal', {
      type: 'page_response',
      data: { page: HilosPages.STATUS_SUBSCRIBERS, payload },
    })
  return {
    ...h,
    window(rows: readonly TableRow[] = [subscriberRow()], filter = {}) {
      answer({
        windows: {
          hilosStatusSubscribers: {
            rows,
            totalCount: rows.length,
            totalExact: true,
            firstAnchor: null,
            lastAnchor: null,
            limit: 25,
            sort: [{ field: 'subscribedAt', direction: 'desc' }],
            filter,
          },
        },
      })
    },
    counts(counts: HilosStatusSubscribersData) {
      answer({ data: { statusSubscribers: counts } })
    },
    delta(data: Record<string, unknown>) {
      h.emit('tableViewportDelta', {
        data: {
          page: HilosPages.STATUS_SUBSCRIBERS,
          tableKey: 'hilosStatusSubscribers',
          ...data,
        },
      })
    },
  }
}

const cleanups: (() => void)[] = []
beforeEach(() => {
  vi.useFakeTimers()
  vi.setSystemTime(1_800_000_000_000)
  applyServerTime(Date.now())
})
afterEach(() => {
  for (const cleanup of cleanups.splice(0).reverse()) cleanup()
  hilosToasts.clear()
  vi.restoreAllMocks()
  vi.useRealTimers()
})

/** Mount the actual SDK view over the real core lifecycle and scope stores. */
export function mountStatus<T>(
  view: Type<T>,
  page: string = HilosPages.STATUS,
  data?: unknown,
) {
  const h = statusHarness(page)
  if (data !== undefined) h.setOverview(data)
  return mountStatusHarness(view, h)
}

/** Mount the Angular Status view over normalized subscriber data and the real controllers. */
export function mountStatusSubscribers<T>(view: Type<T>, viewer = false) {
  return mountStatusHarness(view, statusSubscribersHarness(viewer))
}

/** A nonpersonal announcement with whole-second moments and an immutable identity. */
export const announcement: HilosStatusWorkRow = {
  id: 7,
  publicId: 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
  title: 'Database upgrade',
  description: null,
  componentKeys: ['service'],
  startsAt: Date.UTC(2031, 0, 2, 3, 4, 5),
  endsAt: Date.UTC(2031, 0, 2, 4, 5, 6),
  version: 1,
  canceledAt: null,
  consumedAt: null,
  expiredAt: null,
  lastMailQueuedAt: null,
}

export function workRow(patch: Partial<HilosStatusWorkRow> = {}): TableRow {
  const work = { ...announcement, ...patch }
  return { rowKey: String(work.id), slots: { work } }
}

export const worksData: HilosStatusWorksData = {
  components: [
    { componentKey: 'service', name: 'Application' },
    { componentKey: 'queue', name: 'Message queue' },
    { componentKey: 'database', name: 'Database' },
  ],
  upcomingCount: 1,
  nextWork: {
    id: announcement.publicId,
    title: announcement.title,
    description: announcement.description,
    componentKeys: [...announcement.componentKeys],
    startsAt: announcement.startsAt,
    endsAt: announcement.endsAt,
  },
}

/** Feed Works through the same binder and normalized viewport as a browser reply. */
export function statusWorksHarness(viewer = false) {
  const h = statusHarness(HilosPages.STATUS_WORKS)
  if (viewer) h.viewer()
  const pages = bindPageScope(h.context.connection, h.context.scopes)
  pages.subscribe(HilosPages.STATUS_WORKS)
  const answer = (payload: Record<string, unknown>) =>
    h.emit('projectSignal', {
      type: 'page_response',
      data: { page: HilosPages.STATUS_WORKS, payload },
    })
  const delta = (data: Record<string, unknown>) =>
    h.emit('tableViewportDelta', {
      data: {
        page: HilosPages.STATUS_WORKS,
        tableKey: 'hilosStatusWorks',
        ...data,
      },
    })
  let live = announcement
  return {
    ...h,
    window(
      rows: readonly TableRow[] = [workRow()],
      data = worksData,
      filter = {},
    ) {
      answer({
        data: { statusWorks: data },
        windows: {
          hilosStatusWorks: {
            rows,
            totalCount: rows.length,
            totalExact: true,
            firstAnchor: null,
            lastAnchor: null,
            limit: 25,
            sort: [{ field: 'startsAt', direction: 'asc' }],
            filter,
          },
        },
      })
    },
    data(data: HilosStatusWorksData) {
      answer({ data: { statusWorks: data } })
    },
    delta,
    update(patch: Partial<HilosStatusWorkRow>, own = false) {
      live = { ...live, ...patch }
      delta({ kind: 'row_updated', rowKey: '7', row: workRow(live), own })
    },
  }
}

/** Mount the actual Works page and its dialogs with the real core factories. */
export function mountStatusWorks<T>(view: Type<T>, viewer = false) {
  return mountStatusHarness(view, statusWorksHarness(viewer))
}

function mountStatusHarness<T, H extends ReturnType<typeof statusHarness>>(
  view: Type<T>,
  h: H,
) {
  cleanups.push(h.dispose)
  cleanups.push(bindTimeZoneState(h.context.connection))
  TestBed.configureTestingModule({
    providers: [{ provide: HILOS_ROUTER, useValue: h.router }],
  })
  const fixture = TestBed.createComponent(view)
  fixture.componentRef.setInput('context', h.context)
  cleanups.push(() => fixture.destroy())
  const detect = () => fixture.detectChanges()
  detect()
  return {
    ...h,
    fixture,
    detect,
    act(run: () => void) {
      run()
      detect()
    },
    chooseZone(timeZone: string) {
      h.emit('projectSignal', {
        type: 'handshake_response',
        data: { entities: { currentUser: { id: 1, timeZone } } },
      })
      detect()
    },
  }
}

/** Find a rendered control, including a nested dialog's content. */
export function el<T extends HTMLElement = HTMLElement>(id: string): T {
  const node = document.querySelector<T>(`[data-id="${id}"]`)
  if (node === null) throw new Error(`Missing rendered control: ${id}`)
  return node
}
export function query(id: string): HTMLElement | null {
  return document.querySelector(`[data-id="${id}"]`)
}
export function text(id: string): string {
  return el(id).textContent?.trim().replace(/\s+/g, ' ') ?? ''
}
export function enter(
  node: HTMLElement,
  modifiers: KeyboardEventInit = {},
): KeyboardEvent {
  const event = new KeyboardEvent('keydown', {
    key: 'Enter',
    bubbles: true,
    cancelable: true,
    ...modifiers,
  })
  node.dispatchEvent(event)
  return event
}
export function setText(id: string, value: string): HTMLTextAreaElement {
  const node = el<HTMLTextAreaElement>(id)
  node.value = value
  node.dispatchEvent(new Event('input', { bubbles: true }))
  return node
}
export function submit(): void {
  el('hilos-status-incident-form').dispatchEvent(
    new Event('submit', { bubbles: true, cancelable: true }),
  )
}
/** Flush only promise continuations; production clock waits are never needed. */
export async function settled<T>(fixture: ComponentFixture<T>): Promise<void> {
  for (let i = 0; i < 5; i++) await Promise.resolve()
  fixture.detectChanges()
}

/** A coherent public v2 projection; time is supplied by the test's fake clock. */
export function publicStatusFile(
  overrides: Partial<PublicStatusFile> = {},
): PublicStatusFile {
  return {
    schemaVersion: 2,
    revision: 'public-revision',
    generatedAt: Date.now() - 5000,
    config: { pollIntervalSeconds: 30, staleAfterSeconds: 120 },
    components: [
      {
        componentKey: 'site',
        name: 'Site',
        description: 'Pages and live updates.',
        state: 'operational',
      },
    ],
    candidates: [],
    outages: [],
    scheduledWorks: [],
    maintenancePeriods: [],
    ...overrides,
  }
}

/** Mount the real public SDK page; only file transport and connection events are doubles. */
export function mountPublicStatus<T>(
  view: Type<T>,
  initial: unknown = publicStatusFile(),
  connected = true,
  frozen = false,
) {
  const h = statusHarness(HilosPages.PUBLIC_STATUS)
  h.connect(connected ? 'connected' : 'disconnected')
  h.freeze(frozen)
  cleanups.push(h.dispose)
  cleanups.push(bindTimeZoneState(h.context.connection))
  let status = initial
  let heartbeat: unknown = {
    schemaVersion: 2,
    revision: 'public-revision',
    aliveAt: Date.now(),
  }
  let ok = true
  const fetchFile = vi.fn<PublicStatusFetch>(async (url) => ({
    ok,
    json: async () => (url.endsWith('/status.json') ? status : heartbeat),
  }))
  vi.stubGlobal('fetch', fetchFile)
  cleanups.push(() => vi.unstubAllGlobals())
  const baselineListenerCount = h.listenerCount()
  const fixture = TestBed.createComponent(view)
  fixture.componentRef.setInput('connection', h.context.connection)
  fixture.componentRef.setInput('actions', h.context.actions)
  cleanups.push(() => fixture.destroy())
  const detect = () => fixture.detectChanges()
  detect()
  return {
    ...h,
    fixture,
    fetchFile,
    baselineListenerCount,
    detect,
    act(run: () => void) {
      run()
      detect()
    },
    publish(next: unknown, aliveAt = Date.now()) {
      status = next
      heartbeat = { schemaVersion: 2, revision: 'public-revision', aliveAt }
      h.emit('projectSignal', { type: 'status_files_changed', data: {} })
    },
    beat(next: unknown) {
      heartbeat = next
    },
    fail() {
      ok = false
    },
    async settle() {
      await vi.advanceTimersByTimeAsync(0)
      detect()
    },
    chooseZone(timeZone: string) {
      h.emit('projectSignal', {
        type: 'handshake_response',
        data: { entities: { currentUser: { id: 1, timeZone } } },
      })
      detect()
    },
  }
}
