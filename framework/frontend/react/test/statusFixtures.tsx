// Shared Status fixtures for the React section: real scopes, admin access and
// tracked action lifecycle; only the network and route transport are fakes.
// Mirrors framework/frontend/vue/test/statusFixtures.ts.
import { StrictMode } from 'react'
import type { ReactElement } from 'react'
import { act, render } from '@testing-library/react'
import { vi } from 'vitest'
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
import {
  createHilosStatusWorkCancel,
  createHilosStatusWorkCreate,
  createHilosStatusWorkEdit,
  createHilosStatusWorksState,
  createHilosStatusWorksTable,
  type HilosStatusContext,
  type HilosStatusHistoryData,
  type HilosStatusHistoryRow,
  type HilosStatusIncident,
  type HilosStatusOverviewData,
  type HilosStatusSubscriberRow,
  type HilosStatusSubscribersData,
  type HilosStatusWorkRow,
  type HilosStatusWorksData,
} from '@hilos/core/admin/status'
import {
  createStatusSubscription,
  type PublicStatusFetch,
  type PublicStatusHeartbeat,
} from '@hilos/core/status'

import { HilosAdminPage } from '../src/HilosAdminPage.js'
import { HilosRouterContext } from '../src/hilosRouterContext.js'

/**
 * One open incident as the server sends it: a major outage still waiting for
 * publication.
 *
 * @param overrides Fields that differ from the default incident.
 */
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

/**
 * The Overview page data with one open incident.
 *
 * @param overrides Fields that differ from the default overview.
 */
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

/**
 * A raw four-field subscriber slot, normalized by the actual viewport binding.
 *
 * @param overrides Fields that differ from the default subscriber.
 */
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

/**
 * The Status harness on the Subscribers page, with real page ingestion, so the
 * counts fan out independently of the viewport's Apply.
 *
 * @param viewer Whether the signed-in person views the admin view mode.
 */
export function statusSubscribersHarness(viewer = false) {
  const h = statusHarness(HilosPages.STATUS_SUBSCRIBERS)
  if (viewer) h.viewer()
  const pages = bindPageScope(h.context.connection, h.context.scopes)
  pages.subscribe(HilosPages.STATUS_SUBSCRIBERS)
  const answer = (payload: Record<string, unknown>) =>
    act(() => {
      h.emit('projectSignal', {
        type: 'page_response',
        data: { page: HilosPages.STATUS_SUBSCRIBERS, payload },
      })
    })
  return {
    ...h,
    /**
     * Answer the page with a window of subscriber rows.
     *
     * @param rows The rows of the window.
     * @param filter The filter the window was chosen by.
     */
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
    /**
     * Answer the page with new subscriber counts.
     *
     * @param counts The counts, or null when they are unavailable.
     */
    counts(counts: HilosStatusSubscribersData) {
      answer({ data: { statusSubscribers: counts } })
    },
    /**
     * Push one viewport delta of the subscribers table.
     *
     * @param data The delta's fields beside its page and table.
     */
    delta(data: Record<string, unknown>) {
      act(() => {
        h.emit('tableViewportDelta', {
          data: {
            page: HilosPages.STATUS_SUBSCRIBERS,
            tableKey: 'hilosStatusSubscribers',
            ...data,
          },
        })
      })
    },
  }
}

/** One upcoming announcement as the Works table's work slot carries it. */
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

/**
 * A raw work slot, normalized by the actual viewport binding.
 *
 * @param patch Fields that differ from the default announcement.
 */
export function workRow(patch: Partial<HilosStatusWorkRow> = {}): TableRow {
  const work = { ...announcement, ...patch }
  return { rowKey: String(work.id), slots: { work } }
}

/** The Works page data: a catalog of three components and the nearest work. */
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

/**
 * The Status harness on the Works page, with real page ingestion of the
 * catalog, the window of works and its row deltas.
 *
 * @param viewer Whether the signed-in person views the admin view mode.
 */
export function statusWorksHarness(viewer = false) {
  const h = statusHarness(HilosPages.STATUS_WORKS)
  if (viewer) h.viewer()
  const pages = bindPageScope(h.context.connection, h.context.scopes)
  pages.subscribe(HilosPages.STATUS_WORKS)
  const answer = (payload: Record<string, unknown>) =>
    act(() => {
      h.emit('projectSignal', {
        type: 'page_response',
        data: { page: HilosPages.STATUS_WORKS, payload },
      })
    })
  const delta = (data: Record<string, unknown>) =>
    act(() => {
      h.emit('tableViewportDelta', {
        data: {
          page: HilosPages.STATUS_WORKS,
          tableKey: 'hilosStatusWorks',
          ...data,
        },
      })
    })
  const router: HilosRouter = {
    ...h.router,
    currentPath: createSignal('/hilos/status/works'),
    pageIdentity: createSignal<HilosPageIdentity | undefined>({
      ...h.router.pageIdentity.get()!,
      label: 'Scheduled maintenance',
      pageTabs: [
        ...h.router.pageIdentity.get()!.pageTabs!,
        { page: HilosPages.STATUS_WORKS, label: 'Scheduled maintenance' },
      ],
    }),
    resolvePath: (page) =>
      page === HilosPages.STATUS_WORKS
        ? '/hilos/status/works'
        : h.router.resolvePath(page),
  }
  let live = announcement
  return {
    ...h,
    router,
    /**
     * Render Works windows inside the real admin page shell, which tells the
     * controls of the admin view mode whether a viewer stands on the page.
     *
     * @param view The windows and whatever opens them.
     * @param strict Whether to wrap it in StrictMode.
     */
    mount(view: ReactElement, strict = false) {
      const tree = (
        <HilosRouterContext.Provider value={router}>
          <HilosAdminPage page={HilosPages.STATUS_WORKS}>{view}</HilosAdminPage>
        </HilosRouterContext.Provider>
      )
      return render(strict ? <StrictMode>{tree}</StrictMode> : tree)
    },
    /**
     * Answer the page with its catalog and a window of works.
     *
     * @param rows The rows of the window.
     * @param data The catalog and summary of the page answer.
     * @param filter The filter the window was chosen by.
     */
    window(
      rows: readonly TableRow[] = [workRow()],
      data: HilosStatusWorksData = worksData,
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
    /**
     * Answer the page with new page data alone.
     *
     * @param data The catalog and summary, or null when unavailable.
     */
    data(data: HilosStatusWorksData) {
      answer({ data: { statusWorks: data } })
    },
    delta,
    /**
     * Change the default announcement elsewhere and push its row update.
     *
     * @param patch The fields that changed, on top of earlier changes.
     * @param own Whether this tab made the change.
     */
    update(patch: Partial<HilosStatusWorkRow>, own = false) {
      live = { ...live, ...patch }
      delta({ kind: 'row_updated', rowKey: '7', row: workRow(live), own })
    },
  }
}

/** One completed incident as the History table's history slot carries it. */
export const completedIncident: HilosStatusHistoryRow = {
  id: 'incident:7',
  kind: 'incident',
  sourceId: 7,
  startedAt: Date.UTC(2031, 0, 2, 3, 4, 5),
  endedAt: Date.UTC(2031, 0, 2, 4, 5, 6),
  completionObservedAt: null,
  publishedAt: Date.UTC(2031, 0, 2, 3, 4, 15),
  outcome: 'resolved',
  title: '<b>Saved service</b>',
  componentKeys: ['removed'],
  severity: 'major_outage',
  announcementKnown: null,
  workPublicId: null,
}

/** One completed maintenance whose announcement and exact end are both lost. */
export const completedMaintenance: HilosStatusHistoryRow = {
  id: 'maintenance:7',
  kind: 'maintenance',
  sourceId: 7,
  startedAt: completedIncident.startedAt,
  endedAt: null,
  completionObservedAt: completedIncident.startedAt + 90000,
  publishedAt: null,
  outcome: 'maintenance_completed',
  title: '<script>Upgrade</script>',
  componentKeys: ['removed'],
  severity: null,
  announcementKnown: false,
  workPublicId: null,
}

/**
 * A raw history slot, normalized by the actual viewport binding.
 *
 * @param fact The completed fact the slot carries; the incident by default.
 */
export function historyRow(
  fact: HilosStatusHistoryRow = completedIncident,
): TableRow {
  return { rowKey: fact.id, slots: { history: fact } }
}

/** The History page data: a catalog of one component. */
export const historyData: HilosStatusHistoryData = {
  components: [{ componentKey: 'removed', name: 'removed' }],
}

/**
 * The Status harness on the History page, with real page ingestion of the
 * catalog, the window of completed facts and its row deltas.
 *
 * @param viewer Whether the signed-in person views the admin view mode.
 */
export function statusHistoryHarness(viewer = false) {
  const h = statusHarness(HilosPages.STATUS_HISTORY)
  if (viewer) h.viewer()
  const pages = bindPageScope(h.context.connection, h.context.scopes)
  pages.subscribe(HilosPages.STATUS_HISTORY)
  const answer = (payload: Record<string, unknown>) =>
    act(() => {
      h.emit('projectSignal', {
        type: 'page_response',
        data: { page: HilosPages.STATUS_HISTORY, payload },
      })
    })
  const router: HilosRouter = {
    ...h.router,
    currentPath: createSignal('/hilos/status/history'),
    pageIdentity: createSignal<HilosPageIdentity | undefined>({
      ...h.router.pageIdentity.get()!,
      label: 'History',
      pageTabs: [
        ...h.router.pageIdentity.get()!.pageTabs!,
        { page: HilosPages.STATUS_HISTORY, label: 'History' },
      ],
    }),
  }
  return {
    ...h,
    router,
    /**
     * Answer the page with its catalog and a window of completed facts.
     *
     * @param rows The rows of the window.
     * @param data The catalog of the page answer, or null when unavailable.
     * @param filter The filter the window was chosen by.
     */
    window(
      rows: readonly TableRow[] = [
        historyRow(),
        historyRow(completedMaintenance),
      ],
      data: HilosStatusHistoryData = historyData,
      filter = {},
    ) {
      answer({
        data: { statusHistory: data },
        windows: {
          hilosStatusHistory: {
            rows,
            totalCount: rows.length,
            totalExact: true,
            firstAnchor: null,
            lastAnchor: null,
            limit: 25,
            sort: [{ field: 'startedAt', direction: 'desc' }],
            filter,
          },
        },
      })
    },
    /**
     * Answer the page with new page data alone.
     *
     * @param data The catalog, or anything the page may carry instead of it.
     */
    data(data: unknown) {
      answer({ data: { statusHistory: data } })
    },
    /**
     * Push one viewport delta of the history table.
     *
     * @param data The delta's fields beside its page and table.
     */
    delta(data: Record<string, unknown>) {
      act(() => {
        h.emit('tableViewportDelta', {
          data: {
            page: HilosPages.STATUS_HISTORY,
            tableKey: 'hilosStatusHistory',
            ...data,
          },
        })
      })
    },
  }
}

/**
 * The Works page's core generation, created and started the way the page does
 * in its effect: the catalog state, the table and its three windows — schedule,
 * change and cancel.
 *
 * @param context The Status context on the Works page.
 */
export function statusWorksWindows(context: HilosStatusContext) {
  const state = createHilosStatusWorksState(context)
  const table = createHilosStatusWorksTable(context)
  const create = createHilosStatusWorkCreate(context, state)
  const edit = createHilosStatusWorkEdit(context, table, state)
  const cancel = createHilosStatusWorkCancel(context, table)
  state.start()
  table.start()
  create.start()
  edit.start()
  cancel.start()
  return {
    state,
    table,
    create,
    edit,
    cancel,
    /** Release the generation in the reverse order of its start. */
    dispose() {
      cancel.dispose()
      edit.dispose()
      create.dispose()
      table.dispose()
      state.dispose()
    },
  }
}

/**
 * The public Status subscription over the harness wire: the real core
 * controller on the harness connection and tracked lifecycle, created but not
 * started — the public page starts it in its own effect. The browser address
 * carries the fragment an emailed link opens the page with.
 *
 * @param fragment The address fragment the page opens with, `#…` or empty.
 */
export function statusSubscriptionHarness(fragment = '') {
  window.history.replaceState({}, '', `/status${fragment}`)
  const h = statusHarness(HilosPages.PUBLIC_STATUS)
  const controller = createStatusSubscription({
    connection: h.context.connection,
    actions: h.context.actions,
  })
  return {
    ...h,
    controller,
    /**
     * Accept a sent public action with the reply its schema reads.
     *
     * @param reply The reply the action carries back.
     * @param index Which sent action to answer; the last one by default.
     */
    answer(reply: unknown, index = h.sent.length - 1) {
      act(() => h.reply(true, { reply }, index))
    },
    /** Release the controller, then the wire and the scopes under it. */
    dispose() {
      controller.dispose()
      h.dispose()
    },
  }
}

/** The revision both public files of a coherent pair carry. */
export const PUBLIC_STATUS_REVISION = 'public-revision'

/**
 * The public Status page's surroundings: the harness wire and the two nginx
 * files behind a stubbed fetch. The page creates, starts and disposes its own
 * file reader and subscription; the test decides what the files say, whether
 * they answer, and the state of the connection.
 *
 * @param initial The status file the first reads find.
 * @param aliveAt The heartbeat moment the first reads find, and the default of
 *   a later publication.
 */
export function publicStatusHarness(initial: unknown, aliveAt: number) {
  const h = statusHarness(HilosPages.PUBLIC_STATUS)
  let status = initial
  let heartbeat: PublicStatusHeartbeat = {
    schemaVersion: 3,
    revision: PUBLIC_STATUS_REVISION,
    aliveAt,
  }
  let available = true
  const fetchFile = vi.fn<PublicStatusFetch>(async (url) => ({
    ok: available,
    json: async () => (url.endsWith('/status.json') ? status : heartbeat),
  }))
  vi.stubGlobal('fetch', fetchFile)
  return {
    ...h,
    fetchFile,
    /**
     * Accept a sent public action with the reply its schema reads.
     *
     * @param reply The reply the action carries back.
     * @param index Which sent action to answer; the last one by default.
     */
    answer(reply: unknown, index = h.sent.length - 1) {
      act(() => h.reply(true, { reply }, index))
    },
    /**
     * Replace the pair on disk without a hint, as a socket that is down
     * delivers none.
     *
     * @param next The new status file.
     * @param at The new heartbeat moment.
     */
    write(next: unknown, at = aliveAt) {
      status = next
      heartbeat = { ...heartbeat, aliveAt: at }
    },
    /**
     * Publish a new pair and send the change hint a live socket delivers.
     *
     * @param next The new status file.
     * @param at The new heartbeat moment.
     */
    publish(next: unknown, at = aliveAt) {
      status = next
      heartbeat = { ...heartbeat, aliveAt: at }
      act(() => {
        h.emit('projectSignal', { type: 'status_files_changed', data: {} })
      })
    },
    /** Make every following file read fail. */
    fail() {
      available = false
    },
    /** Let the following file reads succeed again. */
    recover() {
      available = true
    },
    /** Release the wire and the scopes, then the stubbed fetch. */
    dispose() {
      h.dispose()
      vi.unstubAllGlobals()
    },
  }
}

/**
 * Real scopes and tracked lifecycle; only the network and route transport are
 * fakes.
 *
 * @param page The Status page the scope and the route stand on.
 */
export function statusHarness(page: string = HilosPages.STATUS) {
  const listeners = new Map<string, Set<(value: unknown) => void>>()
  const sent: { action: string; data: unknown; requestId?: string }[] = []
  const viewports: unknown[][] = []
  const focuses: unknown[][] = []
  const facets: unknown[][] = []
  // One entry per live table-window registration, so a second StrictMode
  // generation that forgot to unregister shows up as a second entry.
  const registered = new Set<unknown>()
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
    sendTableRendered() {
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
    registerTableWindow(key: string, sink: unknown) {
      registered.add(sink)
    },
    unregisterTableWindow(key: string, sink: unknown) {
      registered.delete(sink)
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
    [HilosPages.PUBLIC_STATUS]: '/status',
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
      hilosUnbuiltPages('react').has(key) ? undefined : paths[key],
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
    registered,
    emit,
    /**
     * How many listeners the wire holds for one event right now.
     *
     * @param event The connection event.
     */
    listening(event: string): number {
      return listeners.get(event)?.size ?? 0
    },
    /**
     * Render a Status view under the harness router, optionally in StrictMode.
     *
     * @param view The view to render.
     * @param strict Whether to wrap it in StrictMode.
     */
    mount(view: ReactElement, strict = false) {
      const tree = (
        <HilosRouterContext.Provider value={router}>
          {view}
        </HilosRouterContext.Provider>
      )
      return render(strict ? <StrictMode>{tree}</StrictMode> : tree)
    },
    /**
     * Move the connection to another state.
     *
     * @param state The new connection state.
     */
    connect(state: ConnectionState) {
      act(() => {
        connectionState = state
        emit('state', state)
      })
    },
    /**
     * Raise or lower the node's protected mode.
     *
     * @param active Whether the freeze holds.
     */
    freeze(active: boolean) {
      act(() => {
        protectedMode = { ...PROTECTED_MODE_INACTIVE, active }
        emit('protectedMode', protectedMode)
      })
    },
    /**
     * Replace the page data the page answer carries.
     *
     * @param data The new `status` page data.
     */
    setOverview(data: unknown) {
      act(() => {
        scopes.page()?.data.set('status', data)
      })
    },
    /** Turn the signed-in administrator into a viewer of the admin view mode. */
    viewer() {
      act(() => {
        scopes.session.data.set('adminViewMode', true)
        scopes.session.entities.upsert(user, { admin: false })
      })
    },
    /**
     * Answer a sent action on the wire.
     *
     * @param success Whether the server accepted it.
     * @param extra Further fields of the answer, such as the refusal reason.
     * @param index Which sent action to answer; the last one by default.
     */
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
