import { ActionLifecycle } from '../../../src/connection/actionLifecycle.js'
import {
  type HilosConnection,
  type ConnectionState,
} from '../../../src/connection/HilosConnection.js'
import { PROTECTED_MODE_INACTIVE } from '../../../src/protocol/protectedMode.js'
import { HilosPages } from '../../../src/routing/hilosPages.js'
import { bindAdminAccess } from '../../../src/session/adminAccess.js'
import { ScopeManager } from '../../../src/state/ScopeManager.js'
import {
  type HilosStatusContext,
  type HilosStatusIncident,
  type HilosStatusOverviewData,
} from '../../../src/admin/status/index.js'

export function incident(
  overrides: Partial<HilosStatusIncident> = {},
): HilosStatusIncident {
  return {
    incidentId: 11,
    componentKey: 'service',
    componentName: 'Service',
    agentType: 'service',
    severity: 'major_outage',
    detectedAt: 1_800_000_000_000,
    publishAt: 1_800_000_060_000,
    publishedAt: null,
    suppressedAt: null,
    expectedInstances: null,
    liveInstances: null,
    observedAt: null,
    updates: [],
    ...overrides,
  }
}

export function overview(
  overrides: Partial<HilosStatusOverviewData> = {},
): HilosStatusOverviewData {
  return {
    healthKnown: true,
    components: { operationalCount: 0, totalCount: 1 },
    publishDelaySeconds: 60,
    maintenance: null,
    scheduledWorks: null,
    incidents: [incident()],
    subscribers: { confirmedCount: 0, pendingCount: 0 },
    ...overrides,
  }
}

export function harness(page: string = HilosPages.STATUS) {
  const listeners = new Map<string, Set<(value: unknown) => void>>()
  const sent: { action: string; data: unknown; requestId?: string }[] = []
  const viewports: unknown[] = []
  const rendered: unknown[] = []
  const registered = new Map<string, unknown>()
  const focuses: unknown[] = []
  const facets: unknown[] = []
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
    on(event: string, listener: (value: unknown) => void) {
      const bucket = listeners.get(event) ?? new Set()
      listeners.set(event, bucket)
      bucket.add(listener)
      return () => bucket.delete(listener)
    },
    sendAction(action: string, data: unknown, requestId?: string) {
      if (connectionState !== 'connected' || protectedMode.active) return false
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
    registerTableWindow(key: string, source: unknown) {
      registered.set(key, source)
    },
    unregisterTableWindow(key: string) {
      registered.delete(key)
    },
  } as unknown as HilosConnection
  const scopes = new ScopeManager()
  const scope = scopes.openPage(page)
  const user = { type: 'users', id: '1' }
  scopes.session.data.set('currentUser', user)
  scopes.session.entities.upsert(user, { admin: true })
  const releaseAccess = bindAdminAccess(scopes)
  const actions = new ActionLifecycle(connection, {
    timeoutMs: 1000,
    deferredLoadingMs: 30,
  })
  const context: HilosStatusContext = { scopes, connection, actions }
  return {
    context,
    scope,
    scopes,
    listeners,
    sent,
    viewports,
    rendered,
    registered,
    focuses,
    facets,
    releaseAccess,
    emit(event: string, value: unknown) {
      for (const listener of [...(listeners.get(event) ?? [])]) listener(value)
    },
    connect(state: ConnectionState) {
      connectionState = state
      for (const listener of [...(listeners.get('state') ?? [])])
        listener(state)
    },
    freeze(active: boolean) {
      protectedMode = { ...PROTECTED_MODE_INACTIVE, active }
      for (const listener of [...(listeners.get('protectedMode') ?? [])])
        listener(protectedMode)
    },
    setOverview(data: unknown) {
      scopes.page()?.data.set('status', data)
    },
    viewer() {
      scopes.session.data.set('adminViewMode', true)
      scopes.session.entities.upsert(user, { admin: false })
    },
  }
}
