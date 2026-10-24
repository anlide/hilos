import {
  ActionLifecycle,
  HilosPages,
  ScopeManager,
  bindAdminAccess,
  createSignal,
  type HilosConnection,
  type HilosRouter,
  type HilosPageIdentity,
  type PageRouteMatch,
} from '@hilos/core/base'
import type {
  HilosStatusContext,
  HilosStatusIncident,
  HilosStatusOverviewData,
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
export function overview(
  overrides: Partial<HilosStatusOverviewData> = {},
): HilosStatusOverviewData {
  return {
    healthKnown: true,
    components: { operationalCount: 0, totalCount: 2 },
    publishDelaySeconds: 60,
    maintenance: null,
    incidents: [incident()],
    ...overrides,
  }
}

/** Real scopes and tracked lifecycle; only the network and route transport are fakes. */
export function statusHarness(page: string = HilosPages.STATUS) {
  const listeners = new Map<string, Set<(value: unknown) => void>>()
  const sent: { action: string; data: unknown; requestId?: string }[] = []
  const viewports: unknown[][] = []
  const registered = new Set<string>()
  const emit = (event: string, value: unknown): void => {
    for (const listener of [...(listeners.get(event) ?? [])]) listener(value)
  }
  const connection = {
    projectTimeZone: 'UTC',
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
    sendTableRendered() {
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
    registered,
    emit,
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
