import { mount } from '@vue/test-utils'
import {
  createSignal,
  HIDDEN_VALUE,
  HilosPages,
  ingest,
  ScopeManager,
  type HilosConnection,
  type HilosRouter,
  type PageRouteMatch,
} from '@hilos/core/base'
import {
  DAEMON_ENV_ENVIRONMENT_DATA,
  DAEMON_ENV_NODE_SILENT_DATA,
} from '@hilos/core/admin/daemon'
import { describe, expect, it, vi } from 'vitest'
import { nextTick } from 'vue'

import HilosDaemonEnvPage from './HilosDaemonEnvPage.vue'
import { hilosRouterKey } from '../../hilosRouterKey.js'

function harness(diagramBuilt = false, nodeId = 'n1') {
  const scopes = new ScopeManager()
  scopes.openPage(HilosPages.DAEMON_ENV)
  const currentRoute = createSignal<PageRouteMatch>({
    page: HilosPages.DAEMON_ENV,
    params: { nodeId },
    admin: true,
  })
  const router: HilosRouter = {
    currentRoute,
    currentPath: createSignal(`/hilos/daemon/${nodeId}/env`),
    currentTitle: createSignal(''),
    pageError: createSignal(null),
    pageLoading: createSignal(false),
    pageIdentity: createSignal(undefined),
    dashboardSections: createSignal(undefined),
    resolvePath: (page) =>
      diagramBuilt && page === HilosPages.DAEMON ? '/hilos/daemon' : undefined,
    clearPageError: () => {},
    denyCurrentPage: () => {},
    awaitPageAnswer: () => {},
    onLeave: () => () => {},
    navigate: () => {},
    replacePath: () => {},
    start: () => {},
    stop: () => {},
  }
  type Listener = (signal: { type?: string; data: unknown }) => void
  const listeners = new Map<string, Set<Listener>>()
  const activeWindows = new Map<string, unknown>()
  const lifecycle: string[] = []
  const sendViewport = vi.fn()
  const connection = {
    on(event: string, listener: Listener): () => void {
      const group = listeners.get(event) ?? new Set<Listener>()
      group.add(listener)
      listeners.set(event, group)
      return () => {
        group.delete(listener)
      }
    },
    registerTableWindow(tableKey: string, sink: unknown): void {
      expect(activeWindows.has(tableKey)).toBe(false)
      activeWindows.set(tableKey, sink)
      lifecycle.push(`register:${tableKey}`)
    },
    unregisterTableWindow(tableKey: string): void {
      activeWindows.delete(tableKey)
      lifecycle.push(`unregister:${tableKey}`)
    },
    tableWindowDescriptors: () => ({}),
    sendTableViewport: sendViewport,
    sendTableRendered: vi.fn(),
  } as unknown as HilosConnection

  const view = mount(HilosDaemonEnvPage, {
    props: { context: { connection, scopes } },
    global: {
      provide: { [hilosRouterKey as symbol]: router },
      stubs: { HilosAdminPage: { template: '<main><slot /></main>' } },
    },
  })

  function emit(event: string, signal: { type?: string; data: unknown }): void {
    for (const listener of listeners.get(event) ?? []) {
      listener(signal)
    }
  }

  function keysWindowData(rows: Record<string, unknown>[]) {
    return {
      rows: rows.map((row) => ({
        rowKey: String(row.key ?? row.rowKey),
        slots: { key: row },
      })),
      totalCount: rows.length,
      totalExact: true,
      limit: 25,
      sort: null,
      firstAnchor: null,
      lastAnchor: null,
      rowsBefore: 0,
    }
  }

  function orphansWindowData(rows: Record<string, unknown>[]) {
    return {
      rows: rows.map((row) => ({
        rowKey: String(row.key ?? row.rowKey),
        slots: { orphan: row },
      })),
      totalCount: rows.length,
      totalExact: true,
      limit: 25,
      sort: null,
      firstAnchor: null,
      lastAnchor: null,
      rowsBefore: 0,
    }
  }

  return {
    view,
    lifecycle,
    activeWindows,
    listeners,
    sendViewport,
    response(
      environment: {
        nodeId: string
        cluster: boolean
        keys: Record<string, unknown>[]
      } | null,
      nodeSilent = false,
      keyRows?: Record<string, unknown>[],
      orphanRows?: Record<string, unknown>[],
    ): void {
      const data = {
        [DAEMON_ENV_ENVIRONMENT_DATA]: environment,
        [DAEMON_ENV_NODE_SILENT_DATA]: nodeSilent,
      }
      ingest(scopes.page()!, { data })
      emit('projectSignal', {
        type: 'page_response',
        data: {
          page: HilosPages.DAEMON_ENV,
          payload: {
            data,
            windows: {
              ...(keyRows === undefined
                ? {}
                : { hilosDaemonEnvKeys: keysWindowData(keyRows) }),
              ...(orphanRows === undefined
                ? {}
                : { hilosDaemonEnvOrphans: orphansWindowData(orphanRows) }),
            },
          },
        },
      })
      if (keyRows !== undefined) {
        emit('tableWindow', {
          data: {
            page: HilosPages.DAEMON_ENV,
            tableKey: 'hilosDaemonEnvKeys',
            ...keysWindowData(keyRows),
          },
        })
      }
      if (orphanRows !== undefined) {
        emit('tableWindow', {
          data: {
            page: HilosPages.DAEMON_ENV,
            tableKey: 'hilosDaemonEnvOrphans',
            ...orphansWindowData(orphanRows),
          },
        })
      }
    },
    selectNode(nextNodeId: string): void {
      currentRoute.set({
        page: HilosPages.DAEMON_ENV,
        params: { nodeId: nextNodeId },
        admin: true,
      })
      scopes.openPage(HilosPages.DAEMON_ENV)
    },
  }
}

describe('HilosDaemonEnvPage', () => {
  it('shows summary tiles, counts, and tone classes', async () => {
    const h = harness()
    h.response({
      nodeId: 'n1',
      cluster: true,
      keys: [
        {
          key: 'K1',
          type: 'string',
          required: true,
          declaredBy: 'framework',
          sensitive: false,
          perNode: false,
          source: 'missing',
          value: { kind: 'none' },
          disk: null,
        },
        {
          key: 'K2',
          type: 'string',
          required: false,
          declaredBy: 'project',
          sensitive: false,
          perNode: false,
          source: 'example',
          value: { kind: 'text', text: 'ex' },
          disk: null,
        },
        {
          key: 'K3',
          type: 'string',
          required: false,
          declaredBy: 'project',
          sensitive: false,
          perNode: false,
          source: 'env',
          value: { kind: 'text', text: 'v1' },
          disk: { source: 'process', value: { kind: 'text', text: 'v2' } },
        },
        {
          key: 'K4',
          type: 'string',
          required: false,
          declaredBy: 'framework',
          sensitive: false,
          perNode: false,
          source: 'process',
          value: { kind: 'text', text: 'ok' },
          disk: null,
        },
      ],
    })
    await nextTick()

    const keysTile = h.view.find('[data-id="hilos-daemon-env-keys"]')
    expect(keysTile.text()).toBe('4')
    expect(keysTile.classes()).not.toContain('text-danger')

    const missingTile = h.view.find('[data-id="hilos-daemon-env-missing"]')
    expect(missingTile.text()).toBe('1')
    expect(missingTile.classes()).toContain('text-danger')
    expect(
      missingTile.element.parentElement?.classList.contains('border-danger'),
    ).toBe(true)
    expect(h.view.text()).toContain('The node will not start with this set')

    const exampleTile = h.view.find('[data-id="hilos-daemon-env-example"]')
    expect(exampleTile.text()).toBe('1')
    expect(exampleTile.classes()).toContain('text-warning')
    expect(
      exampleTile.element.parentElement?.classList.contains('border-warning'),
    ).toBe(true)
    expect(h.view.text()).toContain('.env.example instead of a setting')

    const driftTile = h.view.find('[data-id="hilos-daemon-env-drift"]')
    expect(driftTile.text()).toBe('1')
    expect(driftTile.classes()).toContain('text-warning')
    expect(
      driftTile.element.parentElement?.classList.contains('border-warning'),
    ).toBe(true)
    expect(h.view.text()).toContain(
      'The file was edited, the process was not restarted',
    )

    for (const icon of h.view.findAll('.row > .col i')) {
      expect(icon.attributes('aria-hidden')).toBe('true')
    }

    h.view.unmount()
  })

  it('formats single and multiple missing and example alarms, drift notice without key suffix, and no alarms when clean', async () => {
    const h = harness()
    h.response({
      nodeId: 'n1',
      cluster: true,
      keys: [
        {
          key: 'PORT',
          type: 'integer',
          required: true,
          declaredBy: 'framework',
          sensitive: false,
          perNode: false,
          source: 'missing',
          value: { kind: 'none' },
          disk: null,
        },
        {
          key: 'SAMPLE',
          type: 'string',
          required: false,
          declaredBy: 'project',
          sensitive: false,
          perNode: false,
          source: 'example',
          value: { kind: 'text', text: 'abc' },
          disk: null,
        },
        {
          key: 'WORKER_MIN_MONOPOLISTIC',
          type: 'integer',
          required: false,
          declaredBy: 'framework',
          sensitive: false,
          perNode: false,
          source: 'process',
          value: { kind: 'text', text: '1' },
          disk: { source: 'env', value: { kind: 'text', text: '4' } },
        },
      ],
    })
    await nextTick()

    const missingAlarm1 = h.view.find(
      '[data-id="hilos-daemon-env-alarm-missing"]',
    )
    expect(missingAlarm1.text()).toBe(
      "PORT — the catalog declares it required, and it has no value in the launch environment or in the files. The node's next start — a planned restart, a move, a crash — will be refused.",
    )

    const exampleAlarm1 = h.view.find(
      '[data-id="hilos-daemon-env-alarm-example"]',
    )
    expect(exampleAlarm1.text()).toBe(
      'The value of SAMPLE came from .env.example.',
    )

    const driftAlarm = h.view.find(
      '[data-id="hilos-daemon-env-alarm-drift-WORKER_MIN_MONOPOLISTIC"]',
    )
    expect(driftAlarm.text()).toBe(
      'In the process WORKER_MIN_MONOPOLISTIC is 1; on disk it is already 4. A live daemon does not pick up a file edit: it applies when the node restarts.',
    )
    expect(driftAlarm.text()).not.toContain('монопольных')

    h.response({
      nodeId: 'n1',
      cluster: true,
      keys: [
        {
          key: 'PORT',
          type: 'integer',
          required: true,
          declaredBy: 'framework',
          sensitive: false,
          perNode: false,
          source: 'missing',
          value: { kind: 'none' },
          disk: null,
        },
        {
          key: 'HOST',
          type: 'string',
          required: true,
          declaredBy: 'framework',
          sensitive: false,
          perNode: false,
          source: 'missing',
          value: { kind: 'none' },
          disk: null,
        },
        {
          key: 'SAMPLE1',
          type: 'string',
          required: false,
          declaredBy: 'project',
          sensitive: false,
          perNode: false,
          source: 'example',
          value: { kind: 'text', text: 'abc' },
          disk: null,
        },
        {
          key: 'SAMPLE2',
          type: 'string',
          required: false,
          declaredBy: 'project',
          sensitive: false,
          perNode: false,
          source: 'example',
          value: { kind: 'text', text: 'def' },
          disk: null,
        },
      ],
    })
    await nextTick()

    const missingAlarm2 = h.view.find(
      '[data-id="hilos-daemon-env-alarm-missing"]',
    )
    expect(missingAlarm2.text()).toBe(
      "PORT, HOST — the catalog declares them required, and they have no value in the launch environment or in the files. The node's next start — a planned restart, a move, a crash — will be refused.",
    )

    const exampleAlarm2 = h.view.find(
      '[data-id="hilos-daemon-env-alarm-example"]',
    )
    expect(exampleAlarm2.text()).toBe(
      'The values of SAMPLE1, SAMPLE2 came from .env.example.',
    )

    h.response({
      nodeId: 'n1',
      cluster: true,
      keys: [
        {
          key: 'PORT',
          type: 'integer',
          required: true,
          declaredBy: 'framework',
          sensitive: false,
          perNode: false,
          source: 'env',
          value: { kind: 'text', text: '8080' },
          disk: null,
        },
      ],
    })
    await nextTick()
    expect(
      h.view.find('[data-id="hilos-daemon-env-alarm-missing"]').exists(),
    ).toBe(false)
    expect(
      h.view.find('[data-id="hilos-daemon-env-alarm-example"]').exists(),
    ).toBe(false)
    expect(h.view.findAll('[data-id^="hilos-daemon-env-alarm-"]').length).toBe(
      0,
    )

    h.view.unmount()
  })

  it('draws secret as Set · N chars, hidden mark, disabled boolean checkbox, italic numbers, empty badge, source badge and drift badge', async () => {
    const h = harness()
    const rows = [
      {
        key: 'SECRET_KEY',
        type: 'string',
        required: false,
        declaredBy: 'project',
        sensitive: true,
        perNode: false,
        source: 'env',
        value: { kind: 'secret', length: 12 },
        disk: null,
      },
      {
        key: 'HIDDEN_KEY',
        type: 'string',
        required: false,
        declaredBy: 'project',
        sensitive: true,
        perNode: false,
        source: 'env',
        value: HIDDEN_VALUE,
        disk: null,
      },
      {
        key: 'BOOL_OFF',
        type: 'boolean',
        required: false,
        declaredBy: 'framework',
        sensitive: false,
        perNode: false,
        source: 'process',
        value: { kind: 'text', text: '0' },
        disk: null,
      },
      {
        key: 'NUM_VAL',
        type: 'integer',
        required: false,
        declaredBy: 'framework',
        sensitive: false,
        perNode: false,
        source: 'env',
        value: { kind: 'text', text: '42' },
        disk: null,
      },
      {
        key: 'EMPTY_VAL',
        type: 'string',
        required: false,
        declaredBy: 'framework',
        sensitive: false,
        perNode: false,
        source: 'env',
        value: { kind: 'text', text: '' },
        disk: null,
      },
      {
        key: 'DRIFT_KEY',
        type: 'string',
        required: false,
        declaredBy: 'framework',
        sensitive: false,
        perNode: false,
        source: 'process',
        value: { kind: 'text', text: 'old' },
        disk: { source: 'env', value: { kind: 'text', text: 'new' } },
      },
    ]
    h.response(
      {
        nodeId: 'n1',
        cluster: true,
        keys: rows,
      },
      false,
      rows,
    )
    await nextTick()

    const secretRow = h.view.find('[data-id="hilos-table-row-SECRET_KEY"]')
    expect(secretRow.text()).toContain('Set · 12 chars')
    expect(secretRow.find('.bi-key').exists()).toBe(true)

    const hiddenRow = h.view.find('[data-id="hilos-table-row-HIDDEN_KEY"]')
    expect(hiddenRow.find('[data-id="hilos-hidden"]').text()).toBe('Hidden')

    const boolRow = h.view.find('[data-id="hilos-table-row-BOOL_OFF"]')
    const checkbox = boolRow.find('input[type="checkbox"]')
    expect(checkbox.attributes('disabled')).toBeDefined()
    expect((checkbox.element as HTMLInputElement).checked).toBe(false)
    expect(checkbox.attributes('aria-label')).toBe('Off')

    const numRow = h.view.find('[data-id="hilos-table-row-NUM_VAL"]')
    expect(numRow.find('code.fst-italic').text()).toBe('42')

    const emptyRow = h.view.find('[data-id="hilos-table-row-EMPTY_VAL"]')
    const emptyBadge = emptyRow.find('.badge.bg-secondary-subtle')
    expect(emptyBadge.text()).toBe('empty')
    expect(emptyBadge.attributes('title')).toBe('An empty string is a value')

    const driftRow = h.view.find('[data-id="hilos-table-row-DRIFT_KEY"]')
    expect(driftRow.text()).toContain('Launch environment')
    expect(driftRow.text()).toContain('On disk: new · .env')

    h.view.unmount()
  })

  it('renders silence line without tiles or alarms, silent empty states in both tables, and Silent node state', async () => {
    const h = harness()
    h.response(null, true, [], [])
    await nextTick()

    const silentLine = h.view.find('[data-id="hilos-daemon-env-silent"]')
    expect(silentLine.exists()).toBe(true)
    expect(silentLine.text()).toBe(
      'This node is silent. Only the node itself reads its environment, so nothing is shown until it answers again.',
    )

    expect(h.view.find('[data-id="hilos-daemon-env-keys"]').exists()).toBe(
      false,
    )
    expect(h.view.findAll('[data-id^="hilos-daemon-env-alarm-"]').length).toBe(
      0,
    )

    expect(h.view.find('[data-id="hilos-daemon-node-state"]').text()).toBe(
      'Silent',
    )

    expect(
      h.view.find('[data-id="hilos-daemon-env-keys-empty-silent"]').text(),
    ).toBe('Nothing is read while the node is silent.')
    expect(
      h.view.find('[data-id="hilos-daemon-env-orphans-empty-silent"]').text(),
    ).toBe('Nothing is read while the node is silent.')

    h.view.unmount()
  })

  it('renders Single installation on standalone and diagram link without state badge in cluster', async () => {
    const solo = harness(true, 'standalone')
    solo.response({
      nodeId: 'standalone',
      cluster: false,
      keys: [],
    })
    await nextTick()
    expect(solo.view.find('[data-id="hilos-daemon-node-solo"]').text()).toBe(
      'Single installation',
    )
    expect(solo.view.find('[data-id="hilos-daemon-node-state"]').exists()).toBe(
      false,
    )
    expect(
      solo.view.find('[data-id="hilos-daemon-node-diagram"]').exists(),
    ).toBe(false)
    solo.view.unmount()

    const cluster = harness(true, 'n1')
    cluster.response({
      nodeId: 'n1',
      cluster: true,
      keys: [],
    })
    await nextTick()
    expect(
      cluster.view
        .find('[data-id="hilos-daemon-node-diagram"]')
        .attributes('href'),
    ).toBe('/hilos/daemon')
    expect(
      cluster.view.find('[data-id="hilos-daemon-node-state"]').exists(),
    ).toBe(false)
    cluster.view.unmount()
  })

  it('renders 5 ladder rows in resolution order with unnumbered No value', async () => {
    const h = harness()
    await nextTick()

    const ladder = h.view.find('[data-id="hilos-daemon-env-ladder"]')
    const rows = ladder.findAll('tbody tr')
    expect(rows).toHaveLength(5)

    expect(rows[0]?.findAll('td')[0]?.text()).toBe('1')
    expect(rows[0]?.text()).toContain('Launch environment')

    expect(rows[1]?.findAll('td')[0]?.text()).toBe('2')
    expect(rows[1]?.text()).toContain('.env')

    expect(rows[2]?.findAll('td')[0]?.text()).toBe('3')
    expect(rows[2]?.text()).toContain('.env.example')

    expect(rows[3]?.findAll('td')[0]?.text()).toBe('4')
    expect(rows[3]?.text()).toContain('Catalog default')

    expect(rows[4]?.findAll('td')[0]?.text()).toBe('')
    expect(rows[4]?.text()).toContain('No value')

    h.view.unmount()
  })

  it('disposes both old table bindings and registers new ones when nodeId changes', async () => {
    const h = harness()
    h.response({ nodeId: 'n1', cluster: true, keys: [] }, false, [], [])
    await nextTick()

    expect(
      h.sendViewport.mock.calls
        .filter((c) => c[1] === 'hilosDaemonEnvKeys')
        .at(-1)?.[2].filter,
    ).toEqual({ node: 'n1' })
    expect(
      h.sendViewport.mock.calls
        .filter((c) => c[1] === 'hilosDaemonEnvOrphans')
        .at(-1)?.[2].filter,
    ).toEqual({ node: 'n1' })

    const oldKeysWindow = h.activeWindows.get('hilosDaemonEnvKeys')
    const oldOrphansWindow = h.activeWindows.get('hilosDaemonEnvOrphans')

    h.selectNode('n2')
    h.response({ nodeId: 'n2', cluster: true, keys: [] }, false, [], [])
    await nextTick()

    expect(h.lifecycle).toEqual([
      'register:hilosDaemonEnvKeys',
      'register:hilosDaemonEnvOrphans',
      'unregister:hilosDaemonEnvKeys',
      'unregister:hilosDaemonEnvOrphans',
      'register:hilosDaemonEnvKeys',
      'register:hilosDaemonEnvOrphans',
    ])
    expect(h.activeWindows.get('hilosDaemonEnvKeys')).not.toBe(oldKeysWindow)
    expect(h.activeWindows.get('hilosDaemonEnvOrphans')).not.toBe(
      oldOrphansWindow,
    )

    expect(
      h.sendViewport.mock.calls
        .filter((c) => c[1] === 'hilosDaemonEnvKeys')
        .at(-1)?.[2].filter,
    ).toEqual({ node: 'n2' })
    expect(
      h.sendViewport.mock.calls
        .filter((c) => c[1] === 'hilosDaemonEnvOrphans')
        .at(-1)?.[2].filter,
    ).toEqual({ node: 'n2' })

    h.view.unmount()
    expect(h.activeWindows.size).toBe(0)
    for (const group of h.listeners.values()) {
      expect(group.size).toBe(0)
    }
  })

  it('renders default orphans empty message and attention empty state on keys table', async () => {
    const h = harness()
    h.response({ nodeId: 'n1', cluster: true, keys: [] }, false, [], [])
    await nextTick()

    expect(
      h.view.find('[data-id="hilos-daemon-env-orphans-empty"]').text(),
    ).toBe('Every line in .env is declared in the catalog.')

    expect(
      h.view.find('[data-id="hilos-daemon-env-keys-empty-attention"]').exists(),
    ).toBe(false)
    expect(h.view.find('[data-id="hilos-table-empty"]').text()).toContain(
      'No rows.',
    )

    const toggle = h.view.find('[data-id="hilos-table-filter-attention"]')
    if (toggle.exists()) {
      await toggle.setValue(true)
      await nextTick()
      expect(
        h.view.find('[data-id="hilos-daemon-env-keys-empty-attention"]').text(),
      ).toBe('No key needs attention.')
    }

    h.view.unmount()
  })
})
