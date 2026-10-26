import { act, cleanup, fireEvent, render } from '@testing-library/react'
import {
  HilosPages,
  ScopeManager,
  bindPageScope,
  createAppPageRouter,
  createHilosRouter,
  hilosUnbuiltPages,
  type HilosConnection,
  type HilosDaemonOverviewData,
  type HilosDaemonOverviewNode,
  type HilosDaemonDatabaseData,
  type ProjectSignal,
  type TableRow,
} from '@hilos/core'
import { afterEach, describe, expect, it, vi } from 'vitest'

import { HilosDaemonPage } from '../src/admin/daemon/HilosDaemonPage.js'
import { HilosRouterContext } from '../src/hilosRouterContext.js'

function node(
  nodeId: string,
  overrides: Partial<HilosDaemonOverviewNode> = {},
): HilosDaemonOverviewNode {
  return {
    nodeId,
    online: true,
    role: 'master',
    state: 'standby',
    silentSince: null,
    workers: [
      { index: 0, kind: 'regular', agentCount: 2 },
      { index: 4, kind: 'monopolistic', agentCount: 1 },
      { index: 9, kind: 'monopolistic', agentCount: 0 },
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

function picture(
  nodes: readonly HilosDaemonOverviewNode[],
  clustered = true,
): HilosDaemonOverviewData {
  return {
    clustered,
    nodes,
    entryPoints: null,
    placement: {
      instances: null,
      types: 3,
      clusterTypes: 2,
      unplacedTypes: null,
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

function harness(
  envBuilt = true,
  initial?: HilosDaemonOverviewData,
  initialRows?: readonly TableRow[],
  showLogLinks?: boolean,
) {
  const scopes = new ScopeManager()
  const sendAction = vi.fn()
  const send = vi.fn(() => true)
  const listeners = new Map<string, Set<(signal: never) => void>>()
  let lastResponse: ProjectSignal | undefined
  const emit = (event: string, signal: unknown) => {
    for (const listener of listeners.get(event) ?? []) listener(signal as never)
  }
  const register = vi.fn<HilosConnection['registerTableWindow']>()
  const unregister = vi.fn()
  const sendViewport = vi.fn<HilosConnection['sendTableViewport']>(() => true)
  const sendRendered = vi.fn(() => true)
  const on = vi.fn((event: string, listener: (payload: never) => void) => {
    const group = listeners.get(event) ?? new Set()
    group.add(listener)
    listeners.set(event, group)
    if (event === 'projectSignal' && lastResponse)
      listener(lastResponse as never)
    return () => {
      group.delete(listener)
    }
  })
  const connection = {
    state: 'connected',
    send,
    sendAction,
    on,
    registerTableWindow: register,
    unregisterTableWindow: unregister,
    sendTableViewport: sendViewport,
    sendTableRendered: sendRendered,
    forgetPageFrames: () => {},
    tableWindowDescriptors: () => ({}),
  } as unknown as HilosConnection
  const pages = bindPageScope(connection, scopes)
  const matcher = createAppPageRouter({})
  const unbuilt = new Set(hilosUnbuiltPages('react'))
  // Exercise the future built Env link and the current production gate separately.
  if (envBuilt) {
    unbuilt.delete(HilosPages.DAEMON_ENV)
    unbuilt.delete(HilosPages.DAEMON_ENV_MISMATCH)
  } else {
    unbuilt.add(HilosPages.DAEMON_ENV)
  }
  let pathname = '/hilos/daemon'
  const router = createHilosRouter(
    matcher,
    pages,
    {
      pathname: () => pathname,
      pushState: (next) => {
        pathname = next
      },
      replaceState: (next) => {
        pathname = next
      },
      onPopState: () => () => {},
    },
    undefined,
    unbuilt,
  )
  router.start()
  pages.releaseOnSession()
  expect(send).toHaveBeenCalledTimes(1)
  send.mockClear()
  on.mockClear()
  const navigate = vi.spyOn(router, 'navigate')
  const replacePath = vi.spyOn(router, 'replacePath')
  const page = scopes.page()!
  const update = (
    value: HilosDaemonOverviewData | null,
    database: HilosDaemonDatabaseData | null = null,
    rows?: readonly TableRow[],
  ) => {
    const signal = {
      type: 'page_response',
      data: {
        page: HilosPages.DAEMON,
        payload: {
          ...(rows === undefined
            ? {}
            : {
                windows: {
                  hilosDaemonPlacement: {
                    rows,
                    totalCount: rows.length,
                    totalExact: true,
                    firstAnchor: null,
                    lastAnchor: null,
                    limit: 25,
                    sort: [],
                    rowsBefore: 0,
                  },
                },
              }),
          data: {
            daemon: value,
            database,
            pageLabel: 'Daemon from catalog',
            pageLead: 'Technical overview',
            pageHeadOwner: {
              page: HilosPages.DAEMON,
              label: 'Daemon from catalog',
              lead: 'Technical overview',
              icon: 'bi-cpu',
            },
            pageBreadcrumb: [
              { page: HilosPages.DASHBOARD, label: 'Hilos' },
              { page: HilosPages.DAEMON, label: 'Daemon from catalog' },
            ],
            pageTabs: [
              { page: HilosPages.DAEMON, label: 'Overview' },
              { page: HilosPages.DAEMON_PLACEMENT, label: 'Agent placement' },
              ...(value?.clustered
                ? [
                    {
                      page: HilosPages.DAEMON_ENV_MISMATCH,
                      label: 'Env mismatch',
                    },
                  ]
                : []),
            ],
            pageChildren: [],
          },
        },
      },
    } as unknown as ProjectSignal
    lastResponse = signal
    emit('projectSignal', signal)
  }
  if (initial) update(initial, null, initialRows)
  const initialListenerCount = [...listeners.values()].reduce(
    (n, group) => n + group.size,
    0,
  )
  const view = render(
    <HilosRouterContext.Provider value={router}>
      <HilosDaemonPage
        context={{ scopes, connection }}
        showLogLinks={showLogLinks}
      />
    </HilosRouterContext.Provider>,
  )
  const find = (
    id: string,
    root: ParentNode = view.container,
  ): HTMLElement | null => root.querySelector(`[data-id="${id}"]`)
  const click = (id: string, root?: ParentNode): void => {
    fireEvent.click(find(id, root)!)
  }
  return {
    view,
    find,
    click,
    page,
    scopes,
    router,
    matcher,
    send,
    sendAction,
    on,
    navigate,
    replacePath,
    update,
    emit,
    register,
    unregister,
    sendViewport,
    sendRendered,
    listeners,
    initialListenerCount,
    controller: () => register.mock.calls[0]![1],
    window: (rows: readonly TableRow[], requestId?: string) =>
      emit('tableWindow', {
        data: {
          ...(requestId === undefined ? {} : { requestId }),
          page: HilosPages.DAEMON,
          tableKey: 'hilosDaemonPlacement',
          rows,
          totalCount: rows.length,
          totalExact: true,
          firstAnchor: null,
          lastAnchor: null,
          limit: 25,
          rowsBefore: 0,
        },
      }),
  }
}

afterEach(() => {
  cleanup()
  vi.useRealTimers()
})

describe('HilosDaemonPage', () => {
  it('opens Overview without any placement controller or traffic', () => {
    const h = harness(true, picture([node('n1')]))
    expect(h.register).not.toHaveBeenCalled()
    expect(h.sendViewport).not.toHaveBeenCalled()
    expect(h.sendRendered).not.toHaveBeenCalled()
    h.view.unmount()
  })

  it('consumes the lean answer and exposes each node’s real encoded Overview link', () => {
    const nodeId = 'node /?#%2F🌍'
    const h = harness(false, picture([node('n1'), node(nodeId)]), [
      { rowKey: 'chat_room', slots: {} },
    ])
    expect(h.view.container.querySelectorAll('h1')).toHaveLength(1)
    expect(h.view.container.querySelector('h1')?.textContent).toBe(
      'Daemon from catalog',
    )
    const box = [...h.view.container.querySelectorAll('a')].find(
      (item) => item.getAttribute('data-id') === `hilos-daemon-node-${nodeId}`,
    )!
    expect(box.tagName).toBe('A')
    expect(box.getAttribute('href')).toBe(
      h.router.resolvePath(HilosPages.DAEMON_NODE, { nodeId }),
    )
    expect(box.getAttribute('aria-label')).toBe(`Node ${nodeId}`)
    expect(box.getAttribute('aria-pressed')).toBeNull()
    expect(h.find('hilos-daemon-inspector')).toBeNull()
    expect(h.register).not.toHaveBeenCalled()
    expect(h.sendAction).not.toHaveBeenCalled()
    fireEvent.keyDown(box, { key: ' ' })
    expect(h.navigate).not.toHaveBeenCalled()
    fireEvent.click(box)
    expect(h.navigate).toHaveBeenCalledWith(
      h.router.resolvePath(HilosPages.DAEMON_NODE, { nodeId }),
    )
    expect(h.router.currentPath.get()).toBe(
      h.router.resolvePath(HilosPages.DAEMON_NODE, { nodeId }),
    )
    expect(h.send).toHaveBeenCalledTimes(1)
    h.view.unmount()
    expect(h.unregister).not.toHaveBeenCalled()
    expect(
      [...h.listeners.values()].reduce((n, group) => n + group.size, 0),
    ).toBe(h.initialListenerCount)
  })

  it('keeps unknown, empty and zero pictures distinct and standalone addressable', () => {
    const h = harness(false)
    expect(h.find('hilos-daemon-overview-waiting')).not.toBeNull()
    act(() => h.update(picture([])))
    expect(h.find('hilos-daemon-overview-empty')?.textContent).toBe(
      'No nodes in the picture',
    )
    act(() =>
      h.update(
        picture(
          [node('solo', { workers: null, measurements: null, standing: null })],
          false,
        ),
      ),
    )
    expect(h.find('hilos-daemon-workers-solo')?.textContent).toBe('Workers: —')
    expect(h.find('hilos-daemon-browsers-solo')?.textContent).toBe(
      'Browser connections: —',
    )
    expect(h.find('hilos-daemon-node-solo')?.getAttribute('href')).toBe(
      '/hilos/daemon/solo',
    )
    expect(h.find('hilos-daemon-node-solo')?.textContent).toContain(
      'Single installation',
    )
    expect(h.find('hilos-daemon-consensus')).toBeNull()
    const zero = node('solo')
    act(() =>
      h.update(
        picture(
          [
            {
              ...zero,
              workers: [],
              standing: { connections: 0 },
              measurements: {
                ...zero.measurements!,
                cores: 0,
                daemonCores: 0,
                masterMemory: 0,
                workersMemory: 0,
                workersMeasured: 0,
                memoryCeiling: 0,
              },
            },
          ],
          false,
        ),
      ),
    )
    expect(h.find('hilos-daemon-workers-solo')?.textContent).toBe('No workers')
    expect(h.find('hilos-daemon-browsers-solo')?.textContent).toBe(
      'Browser connections: 0',
    )
    expect(h.find('hilos-daemon-node-solo')?.textContent).toContain('0 B / 0 B')
    expect(h.find('hilos-daemon-inspector')).toBeNull()
    expect(h.view.container.textContent).not.toContain('APP_ENV')
  })

  it('draws live CPU, memory, coverage and workers and replaces stale silent metrics with time', () => {
    const h = harness(
      true,
      picture([node('n1', { state: 'leader' }), node('n2')]),
    )
    const box = () => h.find('hilos-daemon-node-n1')!
    expect(box().textContent).toContain('150%')
    expect(box().textContent).toContain('4.0 KB / 2.0 KB')
    expect(box().textContent).toContain('2 worker processes measured')
    expect(
      h
        .find('hilos-daemon-worker-n1-monopolistic-9')
        ?.getAttribute('data-occupancy'),
    ).toBe('free')
    expect(h.find('hilos-daemon-quorum')?.textContent).toContain(
      '1 of 5 · Unavailable',
    )
    act(() =>
      h.update(
        picture([
          node('n1', {
            online: false,
            state: 'silent',
            silentSince: 1_700_000_000_000,
          }),
        ]),
      ),
    )
    expect(box().getAttribute('href')).toBe('/hilos/daemon/n1')
    expect(box().textContent).toContain('Silent since')
    expect(h.find('hilos-daemon-silent-n1')?.textContent).not.toContain('—')
    expect(box().textContent).not.toContain('Daemon CPU')
    expect(box().textContent).not.toContain('Measured memory')
    expect(h.find('hilos-daemon-workers-n1')).toBeNull()
    act(() =>
      h.update(
        picture([
          node('n1', {
            online: false,
            state: 'silent',
            silentSince: null,
            measurements: null,
            workers: null,
            standing: null,
          }),
        ]),
      ),
    )
    expect(box().textContent).toContain('Silent since —')
    act(() =>
      h.update(picture([node('n1', { role: 'slave', state: 'data' })]), {
        host: '::1',
        port: 3306,
        name: 'hilos',
        socket: null,
        migrationIndex: 0,
      }),
    )
    expect(box().textContent).toContain('150%')
    expect(h.find('hilos-daemon-browsers-n1')?.textContent).toBe(
      'Does not accept browsers',
    )
    expect(h.find('hilos-daemon-database-address')?.textContent).toBe(
      'mysql://[::1]:3306/hilos',
    )
    expect(h.find('hilos-daemon-database-migration')?.textContent).toBe(
      'Migration: 0',
    )
  })

  it('keeps root boxes free of Inspector and logs when showLogLinks is false', () => {
    const h = harness(false, picture([node('n1')]), undefined, false)
    expect(h.find('hilos-daemon-inspector')).toBeNull()
    expect(h.find('hilos-daemon-node-n1')?.getAttribute('href')).toBe(
      '/hilos/daemon/n1',
    )
    expect(h.find('hilos-daemon-node-n1')?.textContent).toContain('150%')
    expect(h.find('hilos-daemon-node-log')).toBeNull()
  })
  it('uses only the selected source ports and resolves its metrics page', () => {
    const h = harness()
    const base = picture([node('n1'), node('n2')])
    for (const source of [
      { nodeId: 'n2', commandPort: 9014, httpPort: 8082 },
      { nodeId: 'n1', commandPort: 0, httpPort: null },
      null,
    ]) {
      act(() => h.update({ ...base, entryPoints: source }))
      expect(h.find('hilos-daemon-entry-command-port')!.textContent).toBe(
        source?.commandPort == null ? '—' : `:${source.commandPort}`,
      )
      expect(h.find('hilos-daemon-entry-http-port')!.textContent).toBe(
        source?.httpPort == null ? '—' : `:${source.httpPort}`,
      )
      const link = h.find('hilos-daemon-entry-metrics')
      if (source)
        expect(link?.getAttribute('href')).toBe(
          `/hilos/daemon/${source.nodeId}/http`,
        )
      else expect(link).toBeNull()
      expect(h.register).not.toHaveBeenCalled()
    }
    const resolvePath = h.router.resolvePath.bind(h.router)
    vi.spyOn(h.router, 'resolvePath').mockImplementation((page, params) =>
      page === HilosPages.DAEMON_HTTP_SERVER
        ? undefined
        : resolvePath(page, params),
    )
    act(() =>
      h.update({
        ...base,
        entryPoints: { nodeId: 'n2', commandPort: null, httpPort: 0 },
      }),
    )
    expect(h.find('hilos-daemon-entry-http-port')!.textContent).toBe(':0')
    expect(h.find('hilos-daemon-entry-metrics')).toBeNull()
  })
})
