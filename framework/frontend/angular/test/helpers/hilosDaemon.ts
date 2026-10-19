import type { Type } from '@angular/core'
import { TestBed } from '@angular/core/testing'
import {
  ScopeManager,
  createSignal,
  createAppPageRouter,
  HilosPages,
  type HilosConnection,
  type HilosRouter,
  type PageRouteMatch,
  type ActionLifecycle,
  type HilosDaemonOverviewNode,
  type HilosDaemonOverviewData,
  type TableRow,
} from '@hilos/core'
import { vi } from 'vitest'

import { HILOS_ROUTER } from '../../src/hilosRouterToken.js'

/** A reported node with real workers and bounded measurements. */
export function daemonNode(
  nodeId: string,
  overrides: Partial<HilosDaemonOverviewNode> = {},
): HilosDaemonOverviewNode {
  return {
    nodeId,
    online: true,
    role: 'master',
    state: 'leader',
    silentSince: null,
    workers: [
      { index: 0, kind: 'regular', agentCount: 2 },
      { index: 4, kind: 'monopolistic', agentCount: 1 },
    ],
    workerRestarts24h: 0,
    uptimeMinutes: 125,
    measurements: {
      startedAt: 1700000000,
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
    ...overrides,
  }
}

/** Whole overview totals deliberately differ from a filtered matrix window. */
export function daemonPicture(
  nodes: readonly HilosDaemonOverviewNode[],
  clustered = true,
): HilosDaemonOverviewData {
  return {
    clustered,
    nodes,
    placement: {
      instances: 7,
      types: 3,
      clusterTypes: 2,
      unplacedTypes: ['unplaced'],
    },
    consensus: clustered
      ? {
          nodeId: 'n1',
          term: 7,
          leaderId: 'n1',
          onlineMasters: 1,
          masters: 5,
          quorumSize: 3,
        }
      : null,
    environmentComparison: {
      answeringNodes: clustered ? 2 : 1,
      divergedKeys: clustered ? 2 : null,
      sourcesDifferKeys: clustered ? 1 : null,
    },
  }
}

/** The SDK's real table binder over a connection whose subscriptions are counted. */
export function daemonHarness<T>(
  component: Type<T>,
  page: string,
  actions?: ActionLifecycle,
) {
  const scopes = new ScopeManager()
  const scope = scopes.openPage(page)
  const listeners = new Map<string, Set<(value: never) => void>>()
  const emit = (event: string, value: unknown): void => {
    for (const listener of listeners.get(event) ?? []) listener(value as never)
  }
  const register = vi.fn()
  const unregister = vi.fn()
  const sendViewport = vi.fn()
  const connection = {
    on(event: string, listener: (value: never) => void) {
      const group = listeners.get(event) ?? new Set()
      listeners.set(event, group)
      group.add(listener)
      return () => {
        group.delete(listener)
      }
    },
    registerTableWindow: register,
    unregisterTableWindow: unregister,
    tableWindowDescriptors: () => ({}),
    sendTableViewport: sendViewport,
    sendTableRendered: vi.fn(),
    sendTableRowFocus: vi.fn(),
    sendAction: vi.fn(),
  } as unknown as HilosConnection
  const matcher = createAppPageRouter({})
  const route = createSignal<PageRouteMatch>({
    page,
    params: page === HilosPages.DAEMON ? {} : { nodeId: 'n1' },
    admin: true,
  })
  const currentPath = createSignal(matcher.path(page, route.get().params) ?? '')
  const router = {
    currentRoute: route,
    currentPath,
    currentTitle: createSignal(''),
    pageError: createSignal(null),
    pageLoading: createSignal(false),
    pageIdentity: createSignal({
      label: 'Daemon',
      lead: '',
      breadcrumb: [{ page: HilosPages.DAEMON, label: 'Daemon' }],
      children: [
        {
          page: HilosPages.DAEMON_WORKERS,
          label: 'Workers',
          lead: '',
          icon: null,
        },
      ],
    }),
    dashboardSections: createSignal(undefined),
    resolvePath: matcher.path,
    clearPageError: vi.fn(),
    denyCurrentPage: vi.fn(),
    awaitPageAnswer: vi.fn(),
    onLeave: () => () => {},
    navigate: vi.fn(),
    replacePath: vi.fn(),
    start: vi.fn(),
    stop: vi.fn(),
  } as unknown as HilosRouter
  TestBed.configureTestingModule({
    providers: [{ provide: HILOS_ROUTER, useValue: router }],
  })
  const fixture = TestBed.createComponent(component)
  const context = { connection, scopes, actions }
  fixture.componentRef.setInput('context', context)
  fixture.detectChanges()
  const root = fixture.nativeElement as HTMLElement
  document.body.append(root)
  return {
    fixture,
    root,
    scope,
    scopes,
    context,
    router,
    route,
    connection,
    register,
    unregister,
    sendViewport,
    emit,
    detect: () => fixture.detectChanges(),
    el: (id: string) => root.querySelector<HTMLElement>(`[data-id="${id}"]`),
    listeners: () =>
      [...listeners.values()].reduce((sum, group) => sum + group.size, 0),
    window(tableKey: string, rows: readonly TableRow[]) {
      emit('tableWindow', {
        data: {
          page,
          tableKey,
          rows,
          totalCount: rows.length,
          totalExact: true,
          firstAnchor: null,
          lastAnchor: null,
        },
      })
      fixture.detectChanges()
    },
  }
}
