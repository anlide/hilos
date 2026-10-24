import HilosDaemonEnvPage from '../../src/admin/daemon/HilosDaemonEnvPage.vue'
import HilosDaemonHttpServerPage from '../../src/admin/daemon/HilosDaemonHttpServerPage.vue'
import HilosDaemonCronPage from '../../src/admin/daemon/HilosDaemonCronPage.vue'
import HilosDaemonAgentsPage from '../../src/admin/daemon/HilosDaemonAgentsPage.vue'
import HilosDaemonWorkersPage from '../../src/admin/daemon/HilosDaemonWorkersPage.vue'
import { mount } from '@vue/test-utils'
import {
  bindPageScope,
  createAppPageRouter,
  createHilosRouter,
  HilosPages,
  hilosUnbuiltPages,
  ScopeManager,
  ingest,
  type HilosConnection,
  type ActionLifecycle,
  type ScopePayload,
} from '@hilos/core/base'
import {
  createHilosDaemonWorkersTable,
  type HilosDaemonNodeHeading,
  type HilosDaemonNodeOverviewData,
} from '@hilos/core/admin/daemon'
import { defineComponent, h, markRaw, onMounted, onUnmounted } from 'vue'
import { vi } from 'vitest'

import HilosDaemonNodePage from '../../src/admin/daemon/HilosDaemonNodePage.vue'
import HilosDaemonNodeShell from '../../src/admin/daemon/HilosDaemonNodeShell.vue'
import HilosView from '../../src/HilosView.vue'
import HilosViewportTable from '../../src/HilosViewportTable.vue'
import { hilosRouterKey } from '../../src/hilosRouterKey.js'
import { useSignal } from '../../src/useSignal.js'

/** A complete report, deliberately over both ceilings. */
export function nodeOverview(
  overrides: Partial<HilosDaemonNodeOverviewData> = {},
): HilosDaemonNodeOverviewData {
  return {
    nodeId: 'n1',
    online: true,
    workers: [
      { index: 0, kind: 'regular', agentCount: 2 },
      { index: 4, kind: 'monopolistic', agentCount: 1 },
      { index: 9, kind: 'monopolistic', agentCount: 0 },
    ],
    workerRestarts24h: 0,
    uptimeMinutes: 125,
    measurements: {
      startedAt: 1_700_000_000,
      cores: 2,
      coresBound: 'quota',
      daemonCores: 3,
      masterCores: 0.1,
      machineLoad: 25,
      masterMemory: 1024,
      workersMemory: 3072,
      workersMeasured: 2,
      memoryCeiling: 2048,
      memoryCeilingBound: 'cgroup',
      phpMemoryLimit: 8192,
    },
    standing: { sessions: 2, connections: 3 },
    environment: { missingRequired: 1, fromExample: 2, drifted: 3, orphans: 4 },
    environmentComparison: {
      answeringNodes: 2,
      divergedKeys: 2,
      sourcesDifferKeys: 1,
    },
    ...overrides,
  }
}

/** The catalog row, including the unbuilt WebSockets address. */
export const nodeTabs = [
  { page: HilosPages.DAEMON_NODE, label: 'Overview' },
  { page: HilosPages.DAEMON_WORKERS, label: 'Workers' },
  { page: HilosPages.DAEMON_AGENTS, label: 'Agents' },
  { page: HilosPages.DAEMON_CRON, label: 'Cron' },
  { page: HilosPages.DAEMON_WEBSOCKETS, label: 'WebSockets' },
  { page: HilosPages.DAEMON_HTTP_SERVER, label: 'HTTP' },
  { page: HilosPages.DAEMON_ENV, label: 'Environment' },
]

export function nodeHeading(
  overrides: Partial<HilosDaemonNodeHeading> = {},
): HilosDaemonNodeHeading {
  return {
    clustered: true,
    state: 'standby',
    silentSince: null,
    role: 'master',
    ...overrides,
  }
}

/**
 * The real navigator, page binder and heads, with a connection recording every
 * request. Shell fixtures isolate the head; child page tests mount their actual bodies.
 */
export function nodeHarness(
  options: {
    page?: string
    nodeId?: string
    child?: boolean
    shell?: boolean
    workers?: boolean
    outlet?: boolean
    unavailable?: readonly string[]
  } = {},
) {
  const scopes = new ScopeManager()
  const listeners = new Map<string, Set<(payload: never) => void>>()
  const send = vi.fn(() => true)
  const sendAction = vi.fn()
  const registerTableWindow = vi.fn()
  const sendViewport = vi.fn<HilosConnection['sendTableViewport']>(() => true)
  const sendRendered = vi.fn(() => true)
  const connection = {
    state: 'connected',
    send,
    sendAction,
    registerTableWindow,
    unregisterTableWindow: vi.fn(),
    sendTableViewport: sendViewport,
    sendTableRendered: sendRendered,
    forgetPageFrames: vi.fn(),
    tableWindowDescriptors: () => ({}),
    on: (event: string, listener: (payload: never) => void) => {
      const group = listeners.get(event) ?? new Set()
      group.add(listener)
      listeners.set(event, group)
      return () => group.delete(listener)
    },
  } as unknown as HilosConnection
  const emit = (event: string, payload: unknown) => {
    for (const listener of listeners.get(event) ?? [])
      listener(payload as never)
  }
  const pages = bindPageScope(connection, scopes)
  const matcher = createAppPageRouter({})
  const unbuilt = new Set(hilosUnbuiltPages('vue'))
  for (const key of options.unavailable ?? []) unbuilt.add(key)
  const key = options.page ?? HilosPages.DAEMON_NODE
  let pathname = matcher.path(key, { nodeId: options.nodeId ?? 'n1' })!
  const router = createHilosRouter(
    matcher,
    pages,
    {
      pathname: () => pathname,
      pushState: (path) => {
        pathname = path
      },
      replaceState: (path) => {
        pathname = path
      },
      onPopState: () => () => {},
    },
    undefined,
    unbuilt,
  )
  router.start()
  pages.releaseOnSession()
  send.mockClear()
  const context = markRaw({
    connection,
    scopes,
    actions: {} as ActionLifecycle,
  })
  const workers = options.workers
    ? createHilosDaemonWorkersTable(context, options.nodeId ?? 'n1')
    : undefined
  const childPages = {
    [HilosPages.DAEMON_WORKERS]: HilosDaemonWorkersPage,
    [HilosPages.DAEMON_AGENTS]: HilosDaemonAgentsPage,
    [HilosPages.DAEMON_CRON]: HilosDaemonCronPage,
    [HilosPages.DAEMON_HTTP_SERVER]: HilosDaemonHttpServerPage,
    [HilosPages.DAEMON_ENV]: HilosDaemonEnvPage,
  }
  const Content = defineComponent({
    setup() {
      const route = useSignal(router.currentRoute)
      onMounted(() => workers?.start())
      onUnmounted(() => workers?.dispose())
      return () =>
        options.child
          ? h(childPages[route.value.page as keyof typeof childPages], {
              context,
            })
          : options.shell
            ? h(
                HilosDaemonNodeShell,
                { context, page: route.value.page! },
                {
                  default: () =>
                    workers
                      ? h(HilosViewportTable, {
                          controller: workers.controller,
                        })
                      : h('p', { 'data-id': 'node-child-body' }, 'Child body'),
                },
              )
            : h(HilosDaemonNodePage, { context })
    },
  })
  const view = mount(options.outlet ? HilosView : Content, {
    ...(options.outlet
      ? { props: { pages: { [key]: markRaw(Content) } } }
      : {}),
    attachTo: document.body,
    global: { provide: { [hilosRouterKey as symbol]: router } },
  })
  const publish = (
    data: Record<string, unknown>,
    payload: Omit<ScopePayload, 'data'> = {},
  ) => {
    emit('projectSignal', {
      type: 'page_response',
      data: {
        page: router.currentRoute.get().page,
        payload: { ...payload, data },
      },
    })
  }
  const answer = (
    overview: HilosDaemonNodeOverviewData | null = nodeOverview(),
    heading: HilosDaemonNodeHeading = nodeHeading(),
  ) =>
    publish({
      node: heading,
      overview,
      pageLabel: nodeTabs.find(
        (tab) => tab.page === router.currentRoute.get().page,
      )?.label,
      pageLead: '',
      pageHeadOwner: {
        page: HilosPages.DAEMON_NODE,
        label: 'Node',
        lead: 'One node.',
        icon: 'bi-hdd-network',
      },
      pageBreadcrumb: [
        { page: HilosPages.DASHBOARD, label: 'Hilos' },
        { page: HilosPages.DAEMON, label: 'Daemon' },
        { page: HilosPages.DAEMON_NODE, label: 'Node' },
      ],
      pageTabs: nodeTabs,
      pageChildren: [],
    })
  const refuse = () =>
    emit('projectSignal', {
      type: 'subscription_page_error',
      data: {
        page: router.currentRoute.get().page,
        httpCode: 403,
        errorCode: 'forbidden',
        title: 'Access denied',
        message: 'This page is closed.',
      },
    })
  return {
    view,
    scopes,
    pages,
    router,
    connection,
    send,
    sendAction,
    registerTableWindow,
    sendViewport,
    sendRendered,
    workers,
    emit,
    publish,
    answer,
    refuse,
  }
}

/** Open a node tab with the catalog identity sent in its initial answer. */
export function openNodePage(scopes: ScopeManager, page: string) {
  const tab = nodeTabs.find((entry) => entry.page === page)
  if (!tab) throw new Error('Expected a node tab in this fixture.')
  const scope = scopes.openPage(page)
  ingest(scope, {
    data: {
      node: { clustered: true, state: null, silentSince: null, role: null },
      pageLabel: page === HilosPages.DAEMON_NODE ? 'Node' : tab.label,
      pageLead: 'One node.',
      pageHeadOwner: {
        page: HilosPages.DAEMON_NODE,
        label: 'Node',
        lead: 'One node: processor, memory, environment summary, and maintenance.',
        icon: 'bi-hdd-network',
      },
      pageBreadcrumb: [
        { page: HilosPages.DASHBOARD, label: 'Hilos' },
        { page: HilosPages.DAEMON, label: 'Daemon' },
        { page: HilosPages.DAEMON_NODE, label: 'Node' },
      ],
      pageTabs: nodeTabs,
      pageChildren: [],
    },
  })
  return scope
}
