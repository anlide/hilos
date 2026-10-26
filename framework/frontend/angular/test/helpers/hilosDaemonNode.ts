import { HilosDaemonEnvPage } from '../../src/admin/daemon/HilosDaemonEnvPage.js'
import { HilosDaemonEnvOrphansPage } from '../../src/admin/daemon/HilosDaemonEnvOrphansPage.js'
import { HilosDaemonHttpServerPage } from '../../src/admin/daemon/HilosDaemonHttpServerPage.js'
import { HilosDaemonCronPage } from '../../src/admin/daemon/HilosDaemonCronPage.js'
import { HilosDaemonAgentsPage } from '../../src/admin/daemon/HilosDaemonAgentsPage.js'
import { HilosDaemonWorkersPage } from '../../src/admin/daemon/HilosDaemonWorkersPage.js'
import {
  Component,
  DestroyRef,
  inject,
  InjectionToken,
  Input,
} from '@angular/core'
import { TestBed } from '@angular/core/testing'
import {
  bindPageScope,
  createAppPageRouter,
  createHilosRouter,
  HilosPages,
  hilosUnbuiltPages,
  ScopeManager,
  ingest,
  TableViewportController,
  type HilosConnection,
  type ActionLifecycle,
  type ScopePayload,
} from '@hilos/core/base'
import {
  createHilosDaemonWorkersTable,
  type HilosDaemonAgentsContext,
  type HilosDaemonNodeHeading,
  type HilosDaemonNodeOverviewData,
} from '@hilos/core/admin/daemon'
import { afterEach, vi } from 'vitest'
import { HilosDaemonNodePage } from '../../src/admin/daemon/HilosDaemonNodePage.js'
import { HilosDaemonNodeShell } from '../../src/admin/daemon/HilosDaemonNodeShell.js'
import { HilosView } from '../../src/HilosView.js'
import { HilosViewportTable } from '../../src/HilosViewportTable.js'
import { LoadingButton } from '../../src/LoadingButton.js'
import { HILOS_ROUTER } from '../../src/hilosRouterToken.js'
import { hilosSignal } from '../../src/hilosSignal.js'

interface NodeBench {
  context: HilosDaemonAgentsContext
  child: boolean
  shell: boolean
  operations: boolean
  workers: ReturnType<typeof createHilosDaemonWorkersTable> | undefined
}
const NODE_BENCH = new InjectionToken<NodeBench>('NodeBench')

/** The caller supplies a template; its real table and controls need the shell’s DI. */
@Component({
  selector: 'test-node-content',
  imports: [
    HilosDaemonWorkersPage,
    HilosDaemonAgentsPage,
    HilosDaemonCronPage,
    HilosDaemonHttpServerPage,
    HilosDaemonEnvPage,
    HilosDaemonEnvOrphansPage,
    HilosDaemonNodePage,
    HilosDaemonNodeShell,
    HilosViewportTable,
    LoadingButton,
  ],
  template: `
    @if (bench.child) {
      @switch (route().page) {
        @case (pages.DAEMON_WORKERS) {
          <hilos-daemon-workers-page [context]="context" />
        }
        @case (pages.DAEMON_AGENTS) {
          <hilos-daemon-agents-page [context]="context" />
        }
        @case (pages.DAEMON_CRON) {
          <hilos-daemon-cron-page [context]="context" />
        }
        @case (pages.DAEMON_HTTP_SERVER) {
          <hilos-daemon-http-server-page [context]="context" />
        }
        @case (pages.DAEMON_ENV) {
          <hilos-daemon-env-page [context]="context" />
        }
        @case (pages.DAEMON_ENV_ORPHANS) {
          <hilos-daemon-env-orphans-page [context]="context" />
        }
      }
    } @else if (bench.shell) {
      <hilos-daemon-node-shell [context]="context" [page]="route().page ?? ''">
        <ng-template #pageBody>
          @if (operations; as table) {
            <hilos-viewport-table [controller]="table" />
          } @else if (bench.workers; as workers) {
            <hilos-viewport-table [controller]="workers.controller" />
            <button
              hilosLoadingButton
              data-id="node-child-operation"
              (click)="mutate()"
            >
              Change node
            </button>
          } @else {
            <p data-id="node-child-body">Child body</p>
          }
        </ng-template>
      </hilos-daemon-node-shell>
    } @else {
      <hilos-daemon-node-page [context]="context" />
    }
  `,
})
class NodeContent {
  readonly pages = HilosPages
  readonly bench = inject(NODE_BENCH)
  @Input() context = this.bench.context
  readonly route = hilosSignal(inject(HILOS_ROUTER).currentRoute)
  readonly mutate = vi.fn()
  readonly operations = this.bench.operations
    ? new TableViewportController({
        sendViewport: vi.fn(),
        resolve: (row) => ({
          rowKey: row.rowKey,
          nodeId: String(row.slots['nodeId']),
        }),
        frame: {
          columns: [{ key: 'nodeId', label: 'Node' }],
          bulkActions: [
            {
              key: 'change',
              label: 'Change node',
              refusalTitle: 'Could not change node',
              run: () => {
                this.mutate()
                throw new Error('A viewer must never dispatch this operation')
              },
            },
          ],
        },
      })
    : undefined
  constructor() {
    this.bench.workers?.start()
    inject(DestroyRef).onDestroy(() => this.bench.workers?.dispose())
  }
}

@Component({
  selector: 'test-node-outlet',
  imports: [HilosView],
  template: `<hilos-view [pages]="views" />`,
})
class NodeOutlet {
  readonly views = {
    [inject(HILOS_ROUTER).currentRoute.get().page!]: NodeContent,
  }
}

const releases: Array<() => void> = []
afterEach(() => {
  for (const release of releases.splice(0)) release()
})

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
  { page: HilosPages.DAEMON_ENV_ORPHANS, label: 'Env orphans' },
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

/** Real navigator and page subscription; only the connection transport is replaced. */
export function nodeHarness(
  options: {
    page?: string
    nodeId?: string
    child?: boolean
    shell?: boolean
    workers?: boolean
    operations?: boolean
    outlet?: boolean
    unavailable?: readonly string[]
    initial?: boolean
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
  const unbuilt = new Set(hilosUnbuiltPages('angular'))
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
  const context = { connection, scopes, actions: {} as ActionLifecycle }
  const workers = options.workers
    ? createHilosDaemonWorkersTable(context, options.nodeId ?? 'n1')
    : undefined
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
  if (options.initial) answer(nodeOverview({ nodeId: options.nodeId ?? 'n1' }))
  TestBed.resetTestingModule()
  TestBed.configureTestingModule({
    providers: [
      { provide: HILOS_ROUTER, useValue: router },
      {
        provide: NODE_BENCH,
        useValue: {
          context,
          child: options.child === true,
          shell: options.shell ?? false,
          operations: options.operations ?? false,
          workers,
        },
      },
    ],
  })
  const fixture = options.outlet
    ? TestBed.createComponent(NodeOutlet)
    : TestBed.createComponent(NodeContent)
  fixture.detectChanges()
  const root = fixture.nativeElement as HTMLElement
  document.body.append(root)
  const destroy = () => {
    fixture.destroy()
    root.remove()
  }
  releases.push(() => {
    destroy()
    router.stop()
    listeners.clear()
  })
  const act = (run: () => unknown) => {
    const result = run()
    fixture.detectChanges()
    if (result instanceof Promise)
      return result.then(async () => {
        await fixture.whenStable()
        fixture.detectChanges()
      })
  }
  return {
    fixture,
    root,
    act,
    view: { unmount: destroy },
    detect: () => fixture.detectChanges(),
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
