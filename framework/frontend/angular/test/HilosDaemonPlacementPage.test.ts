import type { Type } from '@angular/core'
import { TestBed, type ComponentFixture } from '@angular/core/testing'
import {
  HilosPages,
  ScopeManager,
  bindAdminAccess,
  createAppPageRouter,
  createSignal,
  hilosAdminAccess,
  ingest,
  pageIdentity,
  type HilosConnection,
  type HilosRouter,
  type TableViewportController,
} from '@hilos/core/base'
import {
  type HilosDaemonOverviewData,
  type HilosDaemonPlacementRow,
} from '@hilos/core/admin/daemon'
import { afterEach, describe, expect, it, vi } from 'vitest'
import {
  HilosDaemonPlacementPage,
  HilosDaemonPage,
  HilosDaemonEnvMismatchPage,
} from '../src/admin/daemon/index.js'
import { HILOS_ROUTER } from '../src/hilosRouterToken.js'
import { DAEMON_OVERVIEW_DATA, type TableRow } from '@hilos/core'
import {
  daemonHarness,
  daemonNode,
  daemonPicture,
} from './helpers/hilosDaemon.js'

afterEach(() => vi.restoreAllMocks())

const ownerLead = 'Nodes and their processes, with agent placement across them.'

function picture(clustered = true): HilosDaemonOverviewData {
  return {
    clustered,
    nodes: (clustered ? ['n1', 'n2'] : ['standalone']).map((nodeId) => ({
      nodeId,
      online: true,
      role: 'master',
      state: 'leader',
      silentSince: null,
      workers: [],
      measurements: null,
      standing: null,
    })),
    entryPoints: null,
    placement: {
      instances: 7,
      types: 3,
      clusterTypes: 2,
      unplacedTypes: ['unplaced'],
    },
    consensus: null,
  }
}

function harness(
  clustered = true,
  viewer = false,
  page: string = HilosPages.DAEMON_PLACEMENT,
) {
  const scopes = new ScopeManager()
  const scope = scopes.openPage(page)
  ingest(scope, {
    data: {
      daemon: null,
      envMismatch: { clustered, servingNodeId: 'standalone', comparison: null },
      pageLabel: 'Agent placement',
      pageLead: 'Where agent types and instances run across nodes.',
      pageHeadOwner: {
        page: HilosPages.DAEMON,
        label: 'Daemon',
        lead: ownerLead,
        icon: 'bi-cpu',
      },
      pageBreadcrumb: [
        { page: HilosPages.DASHBOARD, label: 'Hilos' },
        { page: HilosPages.DAEMON, label: 'Daemon' },
      ],
      pageTabs: [
        { page: HilosPages.DAEMON, label: 'Overview' },
        { page: HilosPages.DAEMON_PLACEMENT, label: 'Agent placement' },
        ...(clustered
          ? [{ page: HilosPages.DAEMON_ENV_MISMATCH, label: 'Env mismatch' }]
          : []),
      ],
      pageChildren: [],
    },
  })
  ingest(scopes.session, {
    entities: { currentUser: { id: 7, admin: !viewer } },
    data: { adminViewMode: viewer },
  })
  const releaseAccess = bindAdminAccess(scopes)
  const readData = vi.spyOn(scopes, 'pageDataSignal')
  const listeners = new Map<string, Set<(value: never) => void>>()
  const register = vi.fn<HilosConnection['registerTableWindow']>()
  const unregister = vi.fn()
  const sendAction = vi.fn()
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
    sendTableViewport: vi.fn(() => true),
    sendTableRendered: vi.fn(() => true),
    sendAction,
  } as unknown as HilosConnection
  const router = {
    currentRoute: createSignal({
      page,
      params: {},
      admin: true,
    }),
    currentPath: createSignal(createAppPageRouter({}).path(page, {})!),
    pageIdentity: pageIdentity(scopes),
    pageError: createSignal(null),
    resolvePath: createAppPageRouter({}).path,
    navigate: vi.fn(),
  } as unknown as HilosRouter
  const context = { scopes, connection }
  const component: Type<unknown> =
    page === HilosPages.DAEMON
      ? HilosDaemonPage
      : page === HilosPages.DAEMON_ENV_MISMATCH
        ? HilosDaemonEnvMismatchPage
        : HilosDaemonPlacementPage
  TestBed.configureTestingModule({
    providers: [{ provide: HILOS_ROUTER, useValue: router }],
  })
  const root = document.createElement('div')
  document.body.append(root)
  let view: ComponentFixture<unknown>
  let mounted = false
  const mountPage = () => {
    mounted = true
    view = TestBed.createComponent(component)
    view.componentRef.setInput('context', context)
    view.detectChanges()
    root.append(view.nativeElement)
  }
  const unmountPage = () => {
    if (!mounted) return
    mounted = false
    view.destroy()
    root.replaceChildren()
  }
  mountPage()
  return {
    scopes,
    scope,
    detect: () => view.detectChanges(),
    register,
    unregister,
    readData,
    sendAction,
    root,
    mountPage,
    unmountPage,
    releaseAccess: () => {
      releaseAccess()
      root.remove()
    },
    listeners: () =>
      [...listeners.values()].reduce((sum, group) => sum + group.size, 0),
    controller: () =>
      register.mock.calls.at(
        -1,
      )![1] as TableViewportController<HilosDaemonPlacementRow>,
    update: (value: HilosDaemonOverviewData | null) =>
      scope.data.set('daemon', value),
    el: (id: string) => root.querySelector<HTMLElement>(`[data-id="${id}"]`),
  }
}

describe('HilosDaemonPlacementPage', () => {
  it.each([
    [true, false],
    [false, false],
    [true, true],
    [false, true],
  ])(
    'draws the shared section head and links (cluster=%s, viewer=%s)',
    (clustered, viewer) => {
      const h = harness(clustered, viewer)
      try {
        expect(hilosAdminAccess.get()).toBe(viewer ? 'view' : 'full')
        expect(h.root.querySelectorAll('h1')).toHaveLength(1)
        expect(h.root.querySelector('h1')!.textContent?.trim()).toBe('Daemon')
        expect(
          h.root.querySelector('.bi-cpu')!.closest('[aria-hidden="true"]'),
        ).not.toBeNull()
        expect(h.el('hilos-page-head-block')!.textContent).toContain(ownerLead)
        const nav = h.root.querySelector('nav[aria-label="Daemon pages"]')!
        const links = [...nav.querySelectorAll('a')]
        expect(
          links.map((link) => [
            link.textContent?.trim(),
            link.getAttribute('href'),
          ]),
        ).toEqual(
          clustered
            ? [
                ['Overview', '/hilos/daemon'],
                ['Agent placement', '/hilos/daemon/placement'],
                ['Env mismatch', '/hilos/daemon/env-mismatch'],
              ]
            : [
                ['Overview', '/hilos/daemon'],
                ['Agent placement', '/hilos/daemon/placement'],
              ],
        )
        expect(nav.querySelectorAll('[aria-current="page"]')).toHaveLength(1)
        expect(links[1]!.getAttribute('aria-current')).toBe('page')
        expect(h.el('hilos-page-tab-hilos_daemon_placement')).toBe(links[1])
        expect(h.root.querySelector('[role="tablist"]')).toBeNull()
        expect(h.el('hilos-daemon-placement-view')!.textContent).toContain(
          'Waiting for the node picture',
        )
        expect(h.root.querySelector('table')).toBeNull()
        h.update(picture(clustered))
        h.detect()
        expect(h.root.querySelectorAll('table')).toHaveLength(1)
        expect(
          h.root.querySelector('table')!.getAttribute('aria-labelledby'),
        ).toBe(h.root.querySelector('h1')!.id)
        expect(
          h.el('hilos-daemon-placement-instances')!.textContent?.trim(),
        ).toBe('7')
        expect(h.sendAction).not.toHaveBeenCalled()
      } finally {
        h.unmountPage()
        h.releaseAccess()
      }
    },
  )

  it('reads reactive totals and node columns only from the complete daemon scope', () => {
    const h = harness()
    try {
      h.update(picture())
      h.detect()
      expect(h.el('hilos-daemon-placement-types')!.textContent?.trim()).toBe(
        '3',
      )
      expect(
        h.el('hilos-daemon-placement-cluster-types')!.textContent?.trim(),
      ).toBe('2')
      expect(h.el('hilos-daemon-placement-unplaced')!.textContent?.trim()).toBe(
        '1',
      )
      expect(h.root.querySelector('thead')!.textContent).toContain('n1 ★')
      expect(h.root.querySelector('thead')!.textContent).toContain('n2 ★')
      h.controller().setSearch('room:42')
      h.detect()
      expect(
        h.el('hilos-daemon-placement-instances')!.textContent?.trim(),
      ).toBe('7')
      const next = picture(false)
      h.update({
        ...next,
        placement: {
          instances: 0,
          types: 4,
          clusterTypes: 1,
          unplacedTypes: [],
        },
      })
      h.detect()
      expect(
        h.el('hilos-daemon-placement-instances')!.textContent?.trim(),
      ).toBe('0')
      expect(h.el('hilos-daemon-placement-types')!.textContent?.trim()).toBe(
        '4',
      )
      expect(
        h.el('hilos-daemon-placement-cluster-types')!.textContent?.trim(),
      ).toBe('1')
      expect(h.el('hilos-daemon-placement-unplaced')!.textContent?.trim()).toBe(
        '0',
      )
      expect(h.root.querySelector('thead')!.textContent).toContain('standalone')
      expect(h.root.querySelector('thead')!.textContent).not.toContain('n2')
      for (const id of [
        'hilos-daemon-database',
        'hilos-daemon-nodes-view',
        'hilos-daemon-inspector',
        'hilos-daemon-placement-tab',
        'hilos-daemon-nodes-tab',
      ]) {
        expect(h.el(id)).toBeNull()
      }
      h.update(null)
      h.detect()
      expect(h.el('hilos-daemon-placement-view')!.textContent).toContain(
        'Waiting for the node picture',
      )
      expect(h.root.querySelector('table')).toBeNull()
      expect(h.sendAction).not.toHaveBeenCalled()
      expect(h.readData).not.toHaveBeenCalledWith('database')
    } finally {
      h.unmountPage()
      h.releaseAccess()
    }
  })

  it('releases the mounted view and remounts a fresh controller without local search', () => {
    const h = harness()
    try {
      expect(h.register).toHaveBeenCalledTimes(1)
      expect(h.listeners()).toBeGreaterThan(0)
      const previous = h.controller()
      h.update(picture())
      h.detect()
      previous.setSearch('room:42')
      h.detect()
      h.unmountPage()
      expect(h.listeners()).toBe(0)
      expect(h.unregister).toHaveBeenCalledTimes(1)
      expect(h.unregister.mock.calls[0]![1]).toBe(previous)
      h.update(picture(false))
      h.detect()
      h.mountPage()

      expect(h.register).toHaveBeenCalledTimes(2)
      expect(h.controller()).not.toBe(previous)
      expect(h.controller().search.get()).toBe('')
      expect(h.el('hilos-daemon-placement-selection')).toBeNull()
      expect(h.root.querySelector('thead')!.textContent).toContain('standalone')
      h.unmountPage()
      expect(h.listeners()).toBe(0)
      expect(h.unregister).toHaveBeenCalledTimes(2)
    } finally {
      h.unmountPage()
      h.releaseAccess()
    }
  })
})

function placementRow(rowKey = 'room', nodeIds = ['n1', 'n2']): TableRow {
  return {
    rowKey,
    slots: {
      type: {
        agentType: rowKey,
        placement: 'policy',
        idle: false,
        nodes: nodeIds.map((nodeId) => ({
          nodeId,
          agentIds: [`${rowKey}:${nodeId}`],
        })),
        unplacedAgentIds: [],
      },
    },
  }
}

function liveHarness() {
  return daemonHarness(HilosDaemonPlacementPage, HilosPages.DAEMON_PLACEMENT)
}
function liveSeed(h: ReturnType<typeof liveHarness>): void {
  h.scope.data.set(
    DAEMON_OVERVIEW_DATA,
    daemonPicture([daemonNode('n1'), daemonNode('n2', { state: 'standby' })]),
  )
  h.detect()
}
describe('HilosDaemonPlacementPage live windows', () => {
  it('draws inline details under the selected type and inside its card, preserving data-only updates', () => {
    const h = liveHarness()
    liveSeed(h)
    const rows = [placementRow(), placementRow('other')]
    h.emit('projectSignal', {
      type: 'page_response',
      data: {
        page: HilosPages.DAEMON_PLACEMENT,
        payload: {
          windows: {
            hilosDaemonPlacement: {
              rows,
              totalCount: 2,
              totalExact: true,
              firstAnchor: null,
              lastAnchor: null,
              limit: 25,
              sort: [],
              rowsBefore: 0,
            },
          },
        },
      },
    })
    h.detect()
    h.detect()
    const wide =
      h.root.querySelector('[data-id="hilos-table-scroll"]') ??
      h.root.querySelector('table')!
    const cell = wide.querySelector<HTMLButtonElement>(
      '[data-id="hilos-daemon-placement-cell-room-n1"]',
    )!
    cell.click()
    h.detect()
    expect(cell.getAttribute('aria-expanded')).toBe('true')
    const row = h.root.querySelector('[data-id="hilos-table-row-room"]')!
    expect(row.nextElementSibling?.getAttribute('data-id')).toBe(
      'hilos-table-row-detail-room',
    )
    expect(
      row.nextElementSibling?.querySelector(
        '[data-id="hilos-daemon-placement-selection"]',
      )?.textContent,
    ).toContain('room:n1')
    const cards = h.el('hilos-table-cards')!
    expect(
      cards.querySelector(
        '[data-id="hilos-table-card-room"] [data-id="hilos-daemon-placement-selection"]',
      ),
    ).not.toBeNull()
    h.detect()
    h.detect()
    h.scope.data.set(DAEMON_OVERVIEW_DATA, {
      ...daemonPicture([daemonNode('n1'), daemonNode('n2')]),
      entryPoints: { nodeId: 'n2', commandPort: 9014, httpPort: 8082 },
    })
    h.detect()
    expect(cell.getAttribute('aria-expanded')).toBe('true')
    expect(h.register).toHaveBeenCalledTimes(1)
    wide
      .querySelector<HTMLButtonElement>(
        '[data-id="hilos-daemon-placement-cell-room-n2"]',
      )!
      .click()
    h.detect()
    expect(row.nextElementSibling?.textContent).toContain('room:n2')
    wide
      .querySelector<HTMLButtonElement>(
        '[data-id="hilos-daemon-placement-cell-other-n1"]',
      )!
      .click()
    h.detect()
    expect(
      wide.querySelectorAll('[data-id="hilos-daemon-placement-selection"]'),
    ).toHaveLength(1)
    expect(row.nextElementSibling?.getAttribute('data-id')).not.toBe(
      'hilos-table-row-detail-room',
    )
    wide
      .querySelector<HTMLButtonElement>(
        '[data-id="hilos-daemon-placement-collapse"]',
      )!
      .click()
    h.detect()
    expect(
      wide.querySelector('[data-id="hilos-daemon-placement-selection"]'),
    ).toBeNull()
    h.fixture.destroy()
    expect(h.listeners()).toBe(0)
  })

  it('refreshes dynamic node cells and closes details when a node falls silent or disappears', () => {
    const h = liveHarness()
    liveSeed(h)
    h.window('hilosDaemonPlacement', [placementRow()])
    h.detect()
    h.el('hilos-daemon-placement-cell-room-n1')!.click()
    h.detect()
    h.scope.data.set(
      DAEMON_OVERVIEW_DATA,
      daemonPicture([
        daemonNode('n1', { state: 'silent', online: false }),
        daemonNode('n3'),
      ]),
    )
    h.detect()
    expect(h.el('hilos-daemon-placement-selection')).toBeNull()
    expect(h.el('hilos-daemon-placement-cell-room-n2')).toBeNull()
    expect(
      [...h.root.querySelectorAll('th')].some((header) =>
        header.textContent?.includes('n3'),
      ),
    ).toBe(true)
    expect(h.el('hilos-daemon-placement-instances')?.textContent).toContain('7')
    const current = daemonPicture([daemonNode('n1'), daemonNode('n3')])
    h.scope.data.set(DAEMON_OVERVIEW_DATA, {
      ...current,
      placement: { ...current.placement, instances: 11 },
    })
    h.detect()
    expect(h.el('hilos-daemon-placement-instances')?.textContent).toContain(
      '11',
    )
    h.fixture.destroy()
    expect(h.listeners()).toBe(0)
  })
})

describe('Daemon section head parity', () => {
  it.each([HilosPages.DAEMON, HilosPages.DAEMON_ENV_MISMATCH])(
    'shares the adopted head on %s',
    (page) => {
      for (const clustered of [true, false]) {
        const h = harness(clustered, false, page)
        try {
          h.detect()
          expect(h.root.querySelectorAll('h1')).toHaveLength(1)
          expect(h.root.querySelector('h1')!.textContent?.trim()).toBe('Daemon')
          expect(h.root.querySelector('.bi-cpu')).not.toBeNull()
          expect(h.el('hilos-page-head-block')!.textContent).toContain(
            ownerLead,
          )
          const nav = h.root.querySelector('nav[aria-label="Daemon pages"]')!
          expect(nav.querySelectorAll('a')).toHaveLength(clustered ? 3 : 2)
          const current = nav.querySelectorAll('[aria-current="page"]')
          expect(current).toHaveLength(
            !clustered && page === HilosPages.DAEMON_ENV_MISMATCH ? 0 : 1,
          )
          expect(h.el('hilos-page-tab-hilos_daemon_node')).toBeNull()
          if (page === HilosPages.DAEMON) {
            expect(h.register).not.toHaveBeenCalled()
            expect(h.root.querySelector('table')).toBeNull()
          } else if (!clustered) {
            expect(
              h.el('hilos-daemon-env-mismatch-standalone-node')!.textContent,
            ).toContain('standalone')
          }
        } finally {
          h.unmountPage()
          h.releaseAccess()
          TestBed.resetTestingModule()
        }
      }
    },
  )
})
