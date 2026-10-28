import { nodeTabs, openNodePage } from './hilosDaemonNode.js'
import type { Type } from '@angular/core'
import { TestBed } from '@angular/core/testing'
import {
  ScopeManager,
  pageIdentity,
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
    measurements: {
      cores: 2,
      daemonCores: 3,
      masterMemory: 1024,
      workersMemory: 3072,
      workersMeasured: 2,
      memoryCeiling: 2048,
    },
    standing: { connections: 3 },
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
    entryPoints: null,
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
  }
}

/** The SDK's real table binder over a connection whose subscriptions are counted. */
export function daemonHarness<T>(
  component: Type<T>,
  page: string,
  actions?: ActionLifecycle,
  projectTimeZone?: string,
) {
  const scopes = new ScopeManager()
  const isNodePage = nodeTabs.some((tab) => tab.page === page)
  const scope = isNodePage ? openNodePage(scopes, page) : scopes.openPage(page)
  const listeners = new Map<string, Set<(value: never) => void>>()
  let currentProjectTimeZone = projectTimeZone
  const emit = (event: string, value: unknown): void => {
    for (const listener of listeners.get(event) ?? []) listener(value as never)
  }
  const register = vi.fn()
  const unregister = vi.fn()
  const sendViewport = vi.fn()
  const connection = {
    get projectTimeZone() {
      return currentProjectTimeZone
    },
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
  if (!isNodePage) {
    scope.data.set('pageLabel', 'Daemon')
    scope.data.set('pageHeadOwner', {
      page: HilosPages.DAEMON,
      label: 'Daemon',
      lead: 'Nodes and their processes, with agent placement across them.',
      icon: 'bi-cpu',
    })
    scope.data.set('pageBreadcrumb', [
      { page: HilosPages.DASHBOARD, label: 'Hilos' },
      { page: HilosPages.DAEMON, label: 'Daemon' },
    ])
    scope.data.set('pageTabs', [
      { page: HilosPages.DAEMON, label: 'Overview' },
      { page: HilosPages.DAEMON_PLACEMENT, label: 'Agent placement' },
      { page: HilosPages.DAEMON_ENV_MISMATCH, label: 'Env mismatch' },
    ])
    scope.data.set('pageChildren', [])
  }
  const router = {
    currentRoute: route,
    currentPath,
    currentTitle: createSignal(''),
    pageError: createSignal(null),
    pageLoading: createSignal(false),
    pageIdentity: pageIdentity(scopes),
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
    setProjectTimeZone: (timeZone: string | undefined) => {
      currentProjectTimeZone = timeZone
    },
    register,
    unregister,
    sendViewport,
    emit,
    detect: () => fixture.detectChanges(),
    el: (id: string) => root.querySelector<HTMLElement>(`[data-id="${id}"]`),
    listeners: (event?: string) =>
      event === undefined
        ? [...listeners.values()].reduce((sum, group) => sum + group.size, 0)
        : (listeners.get(event)?.size ?? 0),
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
