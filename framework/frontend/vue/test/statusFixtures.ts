import {
  ActionLifecycle,
  HilosPages,
  ScopeManager,
  bindAdminAccess,
  bindPageScope,
  createSignal,
  hilosUnbuiltPages,
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
  HilosStatusSubscribersData,
  HilosStatusSubscriberRow,
} from '@hilos/core/admin/status'

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

/** A raw four-field subscriber slot, normalized by the actual viewport binding. */
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

/** Real page ingestion keeps count fan-out independent of the viewport's Apply. */
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
    get state() {
      return connectionState
    },
    get protectedMode() {
      return protectedMode
    },
    forgetPageFrames() {},
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
        { page: HilosPages.STATUS_COMPONENTS, label: 'Components' },
        { page: HilosPages.STATUS_SUBSCRIBERS, label: 'Subscribers' },
      ],
    }),
    dashboardSections: createSignal(undefined),
    resolvePath: (key) =>
      hilosUnbuiltPages('vue').has(key) ? undefined : paths[key],
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
