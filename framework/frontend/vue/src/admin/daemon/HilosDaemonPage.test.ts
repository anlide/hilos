import { mount } from '@vue/test-utils'
import {
  HilosPages,
  ScopeManager,
  bindPageScope,
  bindTimeZoneState,
  hilosTimeZone,
  bindAdminAccess,
  createAppPageRouter,
  createHilosRouter,
  hilosAdminAccess,
  hilosUnbuiltPages,
  type HilosConnection,
  type ProjectSignal,
  type TableRow,
} from '@hilos/core/base'
import {
  type HilosDaemonOverviewData,
  type HilosDaemonOverviewNode,
  type HilosDaemonDatabaseData,
} from '@hilos/core/admin/daemon'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { nextTick } from 'vue'

import HilosDaemonPage from './HilosDaemonPage.vue'
import { hilosRouterKey } from '../../hilosRouterKey.js'

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
    clock: null,
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
  const unbuilt = new Set(hilosUnbuiltPages('vue'))
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
  const view = mount(HilosDaemonPage, {
    attachTo: document.body,
    props: {
      context: { scopes, connection },
    },
    global: { provide: { [hilosRouterKey as symbol]: router } },
  })
  return {
    view,
    page,
    scopes,
    connection,
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

afterEach(() => vi.restoreAllMocks())

describe('HilosDaemonPage', () => {
  it('opens Overview without any placement controller or traffic', () => {
    const h = harness(true, picture([node('n1')]))
    expect(h.register).not.toHaveBeenCalled()
    expect(h.sendViewport).not.toHaveBeenCalled()
    expect(h.sendRendered).not.toHaveBeenCalled()
    h.view.unmount()
  })

  it('shows the server’s clock projections and clears a recovered warning through page updates', async () => {
    const at = Date.parse('2026-10-09T10:11:12Z')
    vi.spyOn(Date, 'now').mockReturnValue(Date.parse('2026-11-02T12:00:00Z'))
    const clock = {
      reference: false,
      offsetMs: -120,
      measuredAt: at,
      thresholdMs: 300,
      beyond: false,
    }
    const h = harness()
    const release = bindTimeZoneState(h.connection)
    try {
      h.emit('projectSignal', {
        type: 'handshake_response',
        data: { entities: { currentUser: { id: 3, timeZone: 'UTC' } } },
      })
      h.update(
        picture([
          node('n1', {
            state: 'leader',
            clock: {
              ...clock,
              reference: true,
              offsetMs: null,
              measuredAt: null,
            },
          }),
          node('n2', { clock }),
          node('n3', { clock: { ...clock, offsetMs: null, measuredAt: null } }),
          node('n4', { clock: { ...clock, offsetMs: 812, beyond: true } }),
        ]),
      )
      await nextTick()
      const date = new Intl.DateTimeFormat(undefined, {
        timeZone: 'UTC',
        dateStyle: 'medium',
      }).format(at)
      const time = new Intl.DateTimeFormat(undefined, {
        timeZone: 'UTC',
        hour: '2-digit',
        minute: '2-digit',
      }).format(at)
      expect(h.view.get('[data-id="hilos-daemon-clock-n1"]').text()).toBe(
        'Clock: reference',
      )
      expect(h.view.get('[data-id="hilos-daemon-clock-n2"]').text()).toBe(
        `Clock: -120 ms to the leader, measured ${date}, ${time}`,
      )
      expect(h.view.get('[data-id="hilos-daemon-clock-n3"]').text()).toBe(
        'Clock: not measured yet',
      )
      expect(h.view.get('[data-id="hilos-daemon-clock-n4"]').text()).toBe(
        `Clock: +812 ms to the leader, measured ${date}, ${time}`,
      )
      for (const nodeId of ['n1', 'n2', 'n3']) {
        expect(
          h.view
            .find(`[data-id="hilos-daemon-clock-warning-${nodeId}"]`)
            .exists(),
        ).toBe(false)
      }
      const warning = h.view.get('[data-id="hilos-daemon-clock-warning-n4"]')
      expect(warning.text()).toBe(
        'Clock 812 ms off the leader (threshold 300 ms)',
      )
      expect(warning.get('i').attributes('aria-hidden')).toBe('true')
      expect(warning.element.closest('[aria-hidden="true"]')).toBeNull()
      expect(
        h.view
          .get('[data-id="hilos-daemon-node-n4"]')
          .attributes('aria-describedby'),
      ).toBe(warning.attributes('id'))

      h.update(picture([node('n4', { clock: { ...clock, offsetMs: 0 } })]))
      await nextTick()
      expect(h.view.get('[data-id="hilos-daemon-clock-n4"]').text()).toBe(
        `Clock: +0 ms to the leader, measured ${date}, ${time}`,
      )
      expect(
        h.view.find('[data-id="hilos-daemon-clock-warning-n4"]').exists(),
      ).toBe(false)
      expect(
        h.view
          .get('[data-id="hilos-daemon-node-n4"]')
          .attributes('aria-describedby'),
      ).toBeUndefined()
      h.update(picture([node('n4', { clock: { ...clock, offsetMs: 812 } })]))
      await nextTick()
      expect(
        h.view.find('[data-id="hilos-daemon-clock-warning-n4"]').exists(),
      ).toBe(false)
      h.update(picture([node('n4')]))
      await nextTick()
      expect(h.view.find('[data-id="hilos-daemon-clock-n4"]').exists()).toBe(
        false,
      )
      expect(h.send).not.toHaveBeenCalled()
      expect(h.sendAction).not.toHaveBeenCalled()
      expect(h.register).not.toHaveBeenCalled()
    } finally {
      h.view.unmount()
      release()
    }
  })

  it('scrolls only the focused node’s Clock lines with unmodified Left and Right, preserving native activation', async () => {
    const clock = {
      reference: false,
      offsetMs: 812,
      measuredAt: 1_700_000_000_000,
      thresholdMs: 300,
      beyond: true,
    }
    const nodeId = 'node /?#%2F🌍'
    const h = harness(
      true,
      picture([node(nodeId, { clock }), node('n2', { clock })]),
    )
    try {
      const link = h.view.get(`[data-id="hilos-daemon-node-${nodeId}"]`)
        .element as HTMLAnchorElement
      const lines = h.view.get(`[data-id="hilos-daemon-clock-${nodeId}"]`)
        .element.parentElement!
      const otherLines = h.view.get('[data-id="hilos-daemon-clock-n2"]').element
        .parentElement!
      // No layout is emulated: the native scrolling requests are the contract.
      const scroll = vi.spyOn(lines, 'scrollBy').mockImplementation(() => {})
      const otherScroll = vi.spyOn(otherLines, 'scrollBy')
      link.focus()
      expect(document.activeElement).toBe(link)
      expect(link.querySelector('[tabindex], button, input, a')).toBeNull()
      for (const key of ['ArrowRight', 'ArrowRight', 'ArrowLeft']) {
        const event = new KeyboardEvent('keydown', {
          key,
          bubbles: true,
          cancelable: true,
        })
        link.dispatchEvent(event)
        expect(event.defaultPrevented).toBe(true)
      }
      expect(scroll).toHaveBeenCalledTimes(3)
      const steps = scroll.mock.calls.map(
        ([options]) => (options as ScrollToOptions).left!,
      )
      expect(steps[0]).toBeGreaterThan(0)
      expect(steps[1]).toBeGreaterThan(0)
      expect(steps[2]).toBeLessThan(0)
      expect(otherScroll).not.toHaveBeenCalled()
      expect(document.activeElement).toBe(link)

      scroll.mockClear()
      for (const options of [
        { key: 'Enter' },
        { key: 'Tab' },
        { key: 'ArrowUp' },
        { key: 'ArrowDown' },
        { key: 'Home' },
        { key: 'End' },
        { key: ' ' },
        { key: 'ArrowRight', altKey: true },
        { key: 'ArrowLeft', ctrlKey: true },
        { key: 'ArrowRight', metaKey: true },
        { key: 'ArrowLeft', shiftKey: true },
      ]) {
        const event = new KeyboardEvent('keydown', {
          ...options,
          bubbles: true,
          cancelable: true,
        })
        link.dispatchEvent(event)
        expect(event.defaultPrevented).toBe(false)
      }
      const claimed = new KeyboardEvent('keydown', {
        key: 'ArrowRight',
        bubbles: true,
        cancelable: true,
      })
      claimed.preventDefault()
      link.dispatchEvent(claimed)
      expect(scroll).not.toHaveBeenCalled()
      expect(h.navigate).not.toHaveBeenCalled()
      for (const modifier of ['altKey', 'ctrlKey', 'metaKey', 'shiftKey']) {
        const click = new MouseEvent('click', {
          [modifier]: true,
          bubbles: true,
          cancelable: true,
        })
        link.dispatchEvent(click)
        expect(click.defaultPrevented).toBe(false)
      }
      expect(h.navigate).not.toHaveBeenCalled()
      // happy-dom does not synthesize Enter's native click on an anchor.
      await h.view
        .get(`[data-id="hilos-daemon-node-${nodeId}"]`)
        .trigger('keydown', { key: 'Enter' })
      link.dispatchEvent(
        new MouseEvent('click', {
          detail: 0,
          bubbles: true,
          cancelable: true,
        }),
      )
      expect(h.navigate).toHaveBeenCalledWith(
        h.router.resolvePath(HilosPages.DAEMON_NODE, { nodeId }),
      )
      expect(h.router.currentPath.get()).toBe(link.getAttribute('href'))
      expect(h.sendAction).not.toHaveBeenCalled()
    } finally {
      h.view.unmount()
    }
  })

  it('leaves arrow keys native when a node has no Clock lines to read', async () => {
    const h = harness(true, picture([node('n1')]))
    try {
      for (const value of [
        picture([node('n1')]),
        picture([node('n1', { online: false, state: 'silent' })]),
        picture([node('n1')], false),
      ]) {
        h.update(value)
        await nextTick()
        const link = h.view.get('[data-id="hilos-daemon-node-n1"]')
          .element as HTMLAnchorElement
        link.focus()
        for (const key of ['ArrowLeft', 'ArrowRight']) {
          const event = new KeyboardEvent('keydown', {
            key,
            bubbles: true,
            cancelable: true,
          })
          link.dispatchEvent(event)
          expect(event.defaultPrevented).toBe(false)
        }
      }
      expect(h.navigate).not.toHaveBeenCalled()
      expect(h.send).not.toHaveBeenCalled()
      expect(h.sendAction).not.toHaveBeenCalled()
    } finally {
      h.view.unmount()
    }
  })

  it('updates a mounted silent node in the reader zone without requesting another picture', async () => {
    const resolved = new Intl.DateTimeFormat().resolvedOptions()
    vi.spyOn(Intl.DateTimeFormat.prototype, 'resolvedOptions').mockReturnValue({
      ...resolved,
      timeZone: 'America/New_York',
    })
    const at = Date.parse('2026-10-09T10:11:12Z')
    vi.spyOn(Date, 'now').mockReturnValue(Date.parse('2026-11-02T12:00:00Z'))
    const h = harness(
      true,
      picture([
        node('n1', { online: false, state: 'silent', silentSince: at }),
        node('n2', {
          clock: {
            reference: false,
            offsetMs: 120,
            measuredAt: at,
            thresholdMs: 300,
            beyond: false,
          },
        }),
      ]),
    )
    const release = bindTimeZoneState(h.connection)
    try {
      await nextTick()
      const line = h.view.get('[data-id="hilos-daemon-silent-n1"]')
      const clockLine = h.view.get('[data-id="hilos-daemon-clock-n2"]')
      const page = h.scopes.page()
      const registrations = h.register.mock.calls.length
      const requests = h.sendViewport.mock.calls.length
      for (const [own, zone] of [
        ['UTC', 'UTC'],
        ['Europe/Kyiv', 'Europe/Kyiv'],
        [null, 'America/New_York'],
      ] as const) {
        h.emit('projectSignal', {
          type: 'handshake_response',
          data: { entities: { currentUser: { id: 3, timeZone: own } } },
        })
        await nextTick()
        const date = new Intl.DateTimeFormat(undefined, {
          timeZone: zone,
          dateStyle: 'medium',
        }).format(at)
        const time = new Intl.DateTimeFormat(undefined, {
          timeZone: zone,
          hour: '2-digit',
          minute: '2-digit',
        }).format(at)
        expect(hilosTimeZone.get()).toBe(zone)
        expect(line.text()).toBe(`Silent since ${date}, ${time}`)
        expect(clockLine.text()).toBe(
          `Clock: +120 ms to the leader, measured ${date}, ${time}`,
        )
        expect(h.view.get('[data-id="hilos-daemon-silent-n1"]').element).toBe(
          line.element,
        )
        expect(h.view.get('[data-id="hilos-daemon-clock-n2"]').element).toBe(
          clockLine.element,
        )
        expect(h.scopes.page()).toBe(page)
        expect(h.register).toHaveBeenCalledTimes(registrations)
        expect(h.sendViewport).toHaveBeenCalledTimes(requests)
        expect(h.send).not.toHaveBeenCalled()
      }
    } finally {
      h.view.unmount()
      release()
    }
  })

  it('reads the lean root, identity and database from one answer and distinguishes unknown, empty and zero', async () => {
    const h = harness()
    expect(
      h.view.find('[data-id="hilos-daemon-overview-waiting"]').exists(),
    ).toBe(true)
    h.update(picture([]))
    await nextTick()
    expect(h.view.get('[data-id="hilos-daemon-overview-empty"]').text()).toBe(
      'No nodes in the picture',
    )
    h.update(
      picture([
        node('n1', { workers: null, measurements: null, standing: null }),
      ]),
    )
    await nextTick()
    expect(h.view.get('[data-id="hilos-daemon-workers-n1"]').text()).toBe(
      'Workers: —',
    )
    expect(h.view.get('[data-id="hilos-daemon-node-n1"]').text()).toContain(
      'Daemon CPU— / — cores',
    )
    expect(h.view.get('[data-id="hilos-daemon-browsers-n1"]').text()).toBe(
      'Browser connections: —',
    )
    const zero = node('n1')
    h.update(
      picture([
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
      ]),
    )
    await nextTick()
    expect(h.view.get('[data-id="hilos-daemon-workers-n1"]').text()).toBe(
      'No workers',
    )
    expect(h.view.get('[data-id="hilos-daemon-browsers-n1"]').text()).toBe(
      'Browser connections: 0',
    )
    expect(h.view.get('[data-id="hilos-daemon-node-n1"]').text()).toContain(
      '0 B / 0 B',
    )
    expect(h.view.findAll('h1')).toHaveLength(1)
    expect(h.view.get('h1').text()).toBe('Daemon from catalog')
    expect(h.view.find('[data-id="hilos-daemon-inspector"]').exists()).toBe(
      false,
    )
    expect(h.send).not.toHaveBeenCalled()
    expect(h.sendAction).not.toHaveBeenCalled()
    expect(h.register).not.toHaveBeenCalled()
    h.view.unmount()
  })

  it('uses each node’s real encoded Overview link and normal router navigation', async () => {
    const h = harness()
    const nodeId = 'node /?#%2F🌍'
    h.update(
      picture([
        node('n1', { state: 'leader' }),
        node(nodeId, {
          clock: {
            reference: false,
            offsetMs: 812,
            measuredAt: 1_700_000_000_000,
            thresholdMs: 300,
            beyond: true,
          },
        }),
      ]),
    )
    await nextTick()
    const boxes = h.view.findAll('[data-id="hilos-daemon-nodes"] a')
    expect(boxes).toHaveLength(2)
    for (const [index, id] of ['n1', nodeId].entries()) {
      expect(boxes[index]!.element.tagName).toBe('A')
      expect(boxes[index]!.attributes('href')).toBe(
        h.router.resolvePath(HilosPages.DAEMON_NODE, { nodeId: id }),
      )
      expect(boxes[index]!.attributes('aria-label')).toBe(`Node ${id}`)
      expect(boxes[index]!.attributes('aria-pressed')).toBeUndefined()
    }
    const descriptionId = boxes[1]!.attributes('aria-describedby')!
    expect(descriptionId).not.toMatch(/\s/)
    expect(document.getElementById(descriptionId)?.textContent?.trim()).toBe(
      'Clock 812 ms off the leader (threshold 300 ms)',
    )
    await boxes[1]!.trigger('keydown', { key: ' ' })
    expect(h.navigate).not.toHaveBeenCalled()
    await boxes[1]!.trigger('click')
    expect(h.navigate).toHaveBeenCalledWith(
      h.router.resolvePath(HilosPages.DAEMON_NODE, { nodeId }),
    )
    expect(h.router.currentPath.get()).toBe(
      h.router.resolvePath(HilosPages.DAEMON_NODE, { nodeId }),
    )
    expect(h.send).toHaveBeenCalledTimes(1)
    expect(h.sendAction).not.toHaveBeenCalled()
    h.view.unmount()
  })

  it('shows silence and its time instead of stale metrics, then recovers the live diagram', async () => {
    const h = harness()
    const silent = node('n1', {
      online: false,
      state: 'silent',
      silentSince: 1_700_000_000_000,
      clock: {
        reference: false,
        offsetMs: 812,
        measuredAt: 1_700_000_000_000,
        thresholdMs: 300,
        beyond: true,
      },
    })
    h.update(picture([silent]))
    await nextTick()
    const box = () => h.view.get('[data-id="hilos-daemon-node-n1"]')
    expect(box().attributes('href')).toBe('/hilos/daemon/n1')
    expect(box().text()).toContain('Silent since')
    expect(
      h.view.get('[data-id="hilos-daemon-silent-n1"]').text(),
    ).not.toContain('—')
    expect(box().text()).not.toContain('Daemon CPU')
    expect(box().text()).not.toContain('Measured memory')
    expect(h.view.find('[data-id="hilos-daemon-workers-n1"]').exists()).toBe(
      false,
    )
    expect(h.view.find('[data-id="hilos-daemon-clock-n1"]').exists()).toBe(
      false,
    )
    expect(
      h.view.find('[data-id="hilos-daemon-clock-warning-n1"]').exists(),
    ).toBe(false)
    expect(box().attributes('aria-describedby')).toBeUndefined()
    h.update(picture([node('n1')]))
    await nextTick()
    expect(box().text()).toContain('Daemon CPU3 / 2 cores')
    expect(box().text()).toContain('150%')
    expect(box().text()).toContain('4.0 KB / 2.0 KB')
    expect(box().text()).toContain('2 worker processes measured')
    expect(
      h.view
        .get('[data-id="hilos-daemon-worker-n1-monopolistic-9"]')
        .attributes('data-occupancy'),
    ).toBe('free')
    h.update(picture([node('n1', { role: 'slave', state: 'data' })]))
    await nextTick()
    expect(h.view.get('[data-id="hilos-daemon-browsers-n1"]').text()).toBe(
      'Does not accept browsers',
    )
    h.update(
      picture([
        node('n1', {
          online: false,
          state: 'silent',
          silentSince: null,
          workers: null,
          measurements: null,
          standing: null,
        }),
      ]),
    )
    await nextTick()
    expect(box().text()).toContain('Silent since —')
    expect(box().text()).not.toContain('Daemon CPU')
    expect(h.view.find('[data-id="hilos-daemon-inspector"]').exists()).toBe(
      false,
    )
    h.view.unmount()
  })

  it('keeps standalone and viewer boxes as links without inspector or log actions', async () => {
    const h = harness()
    const release = bindAdminAccess(h.scopes)
    try {
      h.scopes.session.data.set('adminViewMode', true)
      h.update(picture([node('standalone', { state: null })], false))
      await nextTick()
      expect(hilosAdminAccess.get()).toBe('view')
      const box = h.view.get('[data-id="hilos-daemon-node-standalone"]')
      expect(box.attributes('href')).toBe('/hilos/daemon/standalone')
      expect(box.text()).toContain('Single installation')
      expect(box.text()).not.toContain('Clock')
      expect(
        h.view.find('[data-id="hilos-daemon-clock-standalone"]').exists(),
      ).toBe(false)
      expect(h.view.find('[data-id="hilos-daemon-consensus"]').exists()).toBe(
        false,
      )
      expect(h.view.find('[data-id="hilos-daemon-inspector"]').exists()).toBe(
        false,
      )
      expect(h.view.find('[data-id="hilos-daemon-node-log"]').exists()).toBe(
        false,
      )
      expect(h.view.text()).not.toContain('APP_ENV')
      expect(h.sendAction).not.toHaveBeenCalled()
    } finally {
      release()
      h.view.unmount()
    }
  })

  it('shows safe primary database fields independently of node updates', async () => {
    const h = harness()
    h.update(picture([node('n1'), node('n2')]), {
      host: '::1',
      port: 3306,
      name: 'hilos',
      socket: null,
      migrationIndex: null,
    })
    await nextTick()
    expect(
      h.view.find('[data-id="hilos-daemon-database-address"]').text(),
    ).toBe('mysql://[::1]:3306/hilos')
    expect(
      h.view.find('[data-id="hilos-daemon-database-migration"]').text(),
    ).toBe('Migration: —')
    h.page.data.set('daemon', picture([node('n2')]))
    await nextTick()
    expect(
      h.view.find('[data-id="hilos-daemon-database-address"]').text(),
    ).toBe('mysql://[::1]:3306/hilos')
    h.page.data.set('database', {
      host: 'db',
      port: 3306,
      name: 'hilos',
      socket: '/run/mysql.sock',
      migrationIndex: 0,
    })
    await nextTick()
    expect(
      h.view.find('[data-id="hilos-daemon-database-address"]').text(),
    ).toBe('hilos')
    expect(h.view.find('[data-id="hilos-daemon-database-socket"]').text()).toBe(
      'Unix socket: /run/mysql.sock',
    )
    expect(
      h.view.find('[data-id="hilos-daemon-database-migration"]').text(),
    ).toBe('Migration: 0')
    h.view.unmount()
  })
  it('uses only the selected source ports and resolves its metrics page', async () => {
    const h = harness()
    const base = picture([node('n1'), node('n2')])
    for (const source of [
      { nodeId: 'n2', commandPort: 9014, httpPort: 8082 },
      { nodeId: 'n1', commandPort: 0, httpPort: null },
      null,
    ]) {
      h.update({ ...base, entryPoints: source })
      await nextTick()
      expect(
        h.view.get('[data-id="hilos-daemon-entry-command-port"]').text(),
      ).toBe(source?.commandPort == null ? '—' : `:${source.commandPort}`)
      expect(
        h.view.get('[data-id="hilos-daemon-entry-http-port"]').text(),
      ).toBe(source?.httpPort == null ? '—' : `:${source.httpPort}`)
      const link = h.view.find('[data-id="hilos-daemon-entry-metrics"]')
      expect(link.exists()).toBe(source !== null)
      if (source)
        expect(link.attributes('href')).toBe(
          `/hilos/daemon/${source.nodeId}/http`,
        )
      expect(h.register).not.toHaveBeenCalled()
    }
    const resolvePath = h.router.resolvePath.bind(h.router)
    vi.spyOn(h.router, 'resolvePath').mockImplementation((page, params) =>
      page === HilosPages.DAEMON_HTTP_SERVER
        ? undefined
        : resolvePath(page, params),
    )
    h.update({
      ...base,
      entryPoints: { nodeId: 'n2', commandPort: null, httpPort: 0 },
    })
    await nextTick()
    expect(h.view.get('[data-id="hilos-daemon-entry-http-port"]').text()).toBe(
      ':0',
    )
    expect(h.view.find('[data-id="hilos-daemon-entry-metrics"]').exists()).toBe(
      false,
    )
    h.view.unmount()
  })
})
