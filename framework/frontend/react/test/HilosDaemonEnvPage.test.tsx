import { act, cleanup, fireEvent, render } from '@testing-library/react'
import { afterEach, describe, expect, it, vi } from 'vitest'
import {
  createSignal,
  HIDDEN_VALUE,
  HilosPages,
  ingest,
  ScopeManager,
  type HilosConnection,
  type HilosRouter,
  type PageRouteMatch,
} from '@hilos/core'
import { HilosDaemonEnvPage } from '../src/admin/daemon/HilosDaemonEnvPage.js'
import { HilosRouterContext } from '../src/hilosRouterContext.js'

function harness() {
  const scopes = new ScopeManager()
  scopes.openPage(HilosPages.DAEMON_ENV)
  const currentRoute = createSignal<PageRouteMatch>({
    page: HilosPages.DAEMON_ENV,
    params: { nodeId: 'n1' },
    admin: true,
  })
  const router = {
    currentRoute,
    currentPath: createSignal('/hilos/daemon/n1/env'),
    currentTitle: createSignal(''),
    pageError: createSignal(null),
    pageLoading: createSignal(false),
    pageIdentity: createSignal({
      label: 'Environment',
      lead: '',
      breadcrumb: [],
      children: [],
    }),
    dashboardSections: createSignal(undefined),
    resolvePath: (page: string) =>
      page === HilosPages.DAEMON ? '/hilos/daemon' : undefined,
    navigate: vi.fn(),
  } as unknown as HilosRouter
  const listeners = new Map<string, Set<(payload: never) => void>>()
  const activeWindows = new Map<string, unknown>()
  const register = vi.fn((table: string, sink: unknown) => {
    expect(activeWindows.has(table)).toBe(false)
    activeWindows.set(table, sink)
  })
  const unregister = vi.fn((table: string) => {
    activeWindows.delete(table)
  })
  const sendViewport = vi.fn()
  const connection = {
    on(event: string, callback: (payload: never) => void) {
      const group = listeners.get(event) ?? new Set()
      group.add(callback)
      listeners.set(event, group)
      return () => {
        group.delete(callback)
      }
    },
    registerTableWindow: register,
    unregisterTableWindow: unregister,
    tableWindowDescriptors: () => ({}),
    sendTableViewport: sendViewport,
    sendTableRendered: vi.fn(),
  } as unknown as HilosConnection
  const view = render(
    <HilosRouterContext.Provider value={router}>
      <HilosDaemonEnvPage context={{ connection, scopes }} />
    </HilosRouterContext.Provider>,
  )
  function emit(event: string, value: unknown) {
    for (const callback of listeners.get(event) ?? []) callback(value as never)
  }
  function windowData(rows: Record<string, unknown>[], slot: string) {
    return {
      rows: rows.map((row) => ({
        rowKey: String(row.key),
        slots: { [slot]: row },
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
    register,
    unregister,
    activeWindows,
    listeners,
    sendViewport,
    find: (id: string): HTMLElement | null =>
      view.container.querySelector(`[data-id="${id}"]`),
    response(
      environment: {
        nodeId: string
        cluster: boolean
        keys: Record<string, unknown>[]
      } | null,
      nodeSilent = false,
      keys?: Record<string, unknown>[],
      orphans?: Record<string, unknown>[],
    ) {
      act(() => {
        const data = { environment, nodeSilent }
        ingest(scopes.page()!, { data })
        emit('projectSignal', {
          type: 'page_response',
          data: {
            page: HilosPages.DAEMON_ENV,
            payload: {
              data,
              windows: {
                ...(keys === undefined
                  ? {}
                  : { hilosDaemonEnvKeys: windowData(keys, 'key') }),
                ...(orphans === undefined
                  ? {}
                  : { hilosDaemonEnvOrphans: windowData(orphans, 'orphan') }),
              },
            },
          },
        })
        if (keys !== undefined)
          emit('tableWindow', {
            data: {
              page: HilosPages.DAEMON_ENV,
              tableKey: 'hilosDaemonEnvKeys',
              ...windowData(keys, 'key'),
            },
          })
        if (orphans !== undefined)
          emit('tableWindow', {
            data: {
              page: HilosPages.DAEMON_ENV,
              tableKey: 'hilosDaemonEnvOrphans',
              ...windowData(orphans, 'orphan'),
            },
          })
      })
    },
    window(rows: Record<string, unknown>[]) {
      act(() =>
        emit('tableWindow', {
          data: {
            page: HilosPages.DAEMON_ENV,
            tableKey: 'hilosDaemonEnvKeys',
            ...windowData(rows, 'key'),
          },
        }),
      )
    },
    refuse() {
      act(() =>
        emit('tableWindowRefused', {
          data: {
            page: HilosPages.DAEMON_ENV,
            tableKey: 'hilosDaemonEnvKeys',
            errorCode: 'source_unavailable',
          },
        }),
      )
    },
    selectNode(nodeId: string) {
      act(() => {
        scopes.openPage(HilosPages.DAEMON_ENV)
        currentRoute.set({
          page: HilosPages.DAEMON_ENV,
          params: { nodeId },
          admin: true,
        })
      })
    },
  }
}
function key(
  key: string,
  overrides: Record<string, unknown> = {},
): Record<string, unknown> {
  return {
    key,
    type: 'string',
    required: false,
    declaredBy: 'framework',
    sensitive: false,
    perNode: false,
    source: 'env',
    value: { kind: 'text', text: 'value' },
    disk: null,
    ...overrides,
  }
}
function environment(
  keys: Record<string, unknown>[] = [],
  nodeId = 'n1',
  cluster = true,
) {
  return { nodeId, cluster, keys }
}
function valueCell(h: ReturnType<typeof harness>, name: string) {
  return h.find(`hilos-table-row-${name}`)!.querySelectorAll('td')[2]!
}
afterEach(cleanup)

describe('HilosDaemonEnvPage', () => {
  it('reads summary independently of the window, shows all alarms and keeps their room', () => {
    const h = harness()
    const room = h.find('hilos-daemon-env-alarms-room')!
    const twin = room.querySelector('.invisible')!
    expect(twin.getAttribute('aria-hidden')).toBe('true')
    expect(twin.querySelector('[data-id], [role], [aria-live]')).toBeNull()
    const rows = [
      key('PORT', {
        required: true,
        source: 'missing',
        value: { kind: 'none' },
      }),
      key('SAMPLE', { source: 'example' }),
      key('WORKER_MIN_MONOPOLISTIC', {
        type: 'integer',
        source: 'process',
        value: { kind: 'text', text: '1' },
        disk: { source: 'env', value: { kind: 'text', text: '4' } },
      }),
      key('PLAIN'),
    ]
    h.response(environment(rows), false, rows, [])
    expect(h.view.container.querySelectorAll('h1')).toHaveLength(1)
    expect(h.find('hilos-daemon-env-keys')?.textContent).toBe('4')
    for (const id of ['missing', 'example', 'drift'])
      expect(h.find(`hilos-daemon-env-${id}`)?.textContent).toBe('1')
    expect(
      h
        .find('hilos-daemon-env-missing')
        ?.classList.contains('text-danger-emphasis'),
    ).toBe(true)
    expect(
      h
        .find('hilos-daemon-env-example')
        ?.parentElement?.classList.contains('border-warning'),
    ).toBe(true)
    expect(h.find('hilos-daemon-env-alarm-missing')?.textContent).toBe(
      "PORT — the catalog declares it required, and it has no value in the launch environment or in the files. The node's next start — a planned restart, a move, a crash — will be refused.",
    )
    expect(h.find('hilos-daemon-env-alarm-example')?.textContent).toBe(
      'The value of SAMPLE came from .env.example.',
    )
    expect(
      h.find('hilos-daemon-env-alarm-drift-WORKER_MIN_MONOPOLISTIC')
        ?.textContent,
    ).toBe(
      'In the process WORKER_MIN_MONOPOLISTIC is 1; on disk it is already 4. A live daemon does not pick up a file edit: it applies when the node restarts.',
    )
    expect(
      h.find('hilos-daemon-env-alarms')?.classList.contains('overflow-auto'),
    ).toBe(true)
    expect(h.find('hilos-daemon-env-alarms')?.getAttribute('tabindex')).toBe(
      '0',
    )
    h.window([key('PLAIN')])
    expect(h.find('hilos-daemon-env-keys')?.textContent).toBe('4')
    h.response(
      environment([
        ...rows,
        key('HOST', { required: true, source: 'missing' }),
        key('SAMPLE2', { source: 'example' }),
      ]),
    )
    expect(h.find('hilos-daemon-env-alarm-missing')?.textContent).toContain(
      'PORT, HOST — the catalog declares them required, and they have no value',
    )
    expect(h.find('hilos-daemon-env-alarm-example')?.textContent).toBe(
      'The values of SAMPLE, SAMPLE2 came from .env.example.',
    )
    h.response(environment([key('PLAIN')]))
    expect(h.find('hilos-daemon-env-alarms')).toBeNull()
    expect(
      h.find('hilos-daemon-env-alarms-room')?.querySelector('.invisible'),
    ).toBe(twin)
    for (const id of ['missing', 'example', 'drift'])
      expect(h.find(`hilos-daemon-env-${id}`)?.textContent).toBe('0')
  })

  it('renders hidden, masked, empty, typed and absent values with sources and drift', () => {
    const h = harness()
    const rows = [
      key('SECRET', { sensitive: true, value: { kind: 'secret', length: 12 } }),
      key('HIDDEN', { value: HIDDEN_VALUE }),
      key('EMPTY', { value: { kind: 'text', text: '' } }),
      key('ON', {
        type: 'boolean',
        value: { kind: 'text', text: 'yes' },
        source: 'default',
      }),
      key('OFF', { type: 'boolean', value: { kind: 'text', text: '0' } }),
      key('NUMBER', {
        type: 'integer',
        value: { kind: 'text', text: '42' },
        perNode: true,
        declaredBy: 'project',
      }),
      key('MISSING', {
        required: true,
        source: 'missing',
        value: { kind: 'none' },
      }),
      key('OPTIONAL', { value: { kind: 'none' } }),
      key('SECRET_NONE', { sensitive: true, value: { kind: 'none' } }),
      key('DRIFT', {
        source: 'process',
        disk: { source: 'env', value: { kind: 'text', text: 'new' } },
      }),
    ]
    h.response(environment(rows), false, rows, [])
    expect(valueCell(h, 'SECRET').textContent).toBe('Set · 12 chars.env')
    expect(
      valueCell(h, 'HIDDEN').querySelector('[data-id="hilos-hidden"]')
        ?.textContent,
    ).toBe('Hidden')
    expect(
      valueCell(h, 'EMPTY').querySelector(
        '[title="An empty string is a value"]',
      )?.textContent,
    ).toBe('empty')
    for (const [name, checked] of [
      ['ON', true],
      ['OFF', false],
    ] as const) {
      const input = valueCell(h, name).querySelector('input')!
      expect(input.checked).toBe(checked)
      expect(input.disabled).toBe(true)
      expect(input.getAttribute('aria-label')).toBe(checked ? 'On' : 'Off')
      expect(input.tabIndex).toBe(-1)
    }
    expect(
      valueCell(h, 'NUMBER').querySelector('code.fst-italic')?.textContent,
    ).toBe('42')
    expect(h.find('hilos-table-row-NUMBER')?.textContent).toContain(
      'Belongs to the node: each node has its own value',
    )
    expect(h.find('hilos-table-row-NUMBER')?.textContent).toContain('Project')
    expect(
      valueCell(h, 'MISSING').querySelector('.text-danger-emphasis')
        ?.textContent,
    ).toBe('—')
    expect(
      valueCell(h, 'OPTIONAL').querySelector('.text-body-secondary')
        ?.textContent,
    ).toBe('—')
    expect(valueCell(h, 'SECRET_NONE').textContent).toContain('Not set')
    expect(valueCell(h, 'DRIFT').textContent).toContain(
      'Launch environmentOn disk: new · .env',
    )
    h.window([
      key('NUMBER', { type: 'integer', value: { kind: 'text', text: '43' } }),
    ])
    expect(
      valueCell(h, 'NUMBER').querySelector('code.fst-italic')?.textContent,
    ).toBe('43')
  })

  it('keeps orphan values length-only or hidden and gives only the similar name', () => {
    const h = harness()
    h.response(
      environment(),
      false,
      [],
      [
        {
          key: 'DB_PASSWD',
          value: { kind: 'secret', length: 19 },
          similarTo: 'DB_PASSWORD',
        },
        {
          key: 'HILOS_LOG_LEVEL',
          value: { kind: 'secret', length: 5 },
          similarTo: null,
        },
      ],
    )
    const row = h.find('hilos-table-row-DB_PASSWD')!
    expect(row.textContent).toContain('Set · 19 charsnot in catalogDB_PASSWORD')
    expect(h.find('hilos-table-row-HILOS_LOG_LEVEL')?.textContent).toContain(
      'Set · 5 charsnot in catalog—',
    )
    expect(row.textContent).not.toMatch(/typo|renamed/i)
    h.response(
      environment(),
      false,
      [],
      [{ key: 'DB_PASSWD', value: HIDDEN_VALUE, similarTo: 'DB_PASSWORD' }],
    )
    expect(
      h
        .find('hilos-table-row-DB_PASSWD')
        ?.querySelector('[data-id="hilos-hidden"]')?.textContent,
    ).toBe('Hidden')
    expect(h.find('hilos-table-row-DB_PASSWD')?.textContent).not.toContain('19')
  })

  it('distinguishes waiting, silence and known empty, using no inferred node state', () => {
    const h = harness()
    expect(h.find('hilos-daemon-env-keys')).toBeNull()
    expect(h.find('hilos-table-loading')).not.toBeNull()
    expect(h.find('hilos-daemon-node-state')).toBeNull()
    h.response(null, true, [], [])
    expect(h.find('hilos-daemon-env-silent')?.textContent).toBe(
      'This node is silent. Only the node itself reads its environment, so nothing is shown until it answers again.',
    )
    expect(h.find('hilos-daemon-node-state')?.textContent).toBe('Silent')
    expect(h.find('hilos-daemon-env-keys')).toBeNull()
    expect(h.find('hilos-daemon-env-keys-empty-silent')?.textContent).toBe(
      'Nothing is read while the node is silent.',
    )
    expect(h.find('hilos-daemon-env-orphans-empty-silent')?.textContent).toBe(
      'Nothing is read while the node is silent.',
    )
    h.response(environment(), false, [], [])
    expect(h.find('hilos-daemon-env-keys')?.textContent).toBe('0')
    expect(h.find('hilos-daemon-node-state')).toBeNull()
    expect(h.find('hilos-daemon-node-diagram')?.getAttribute('href')).toBe(
      '/hilos/daemon',
    )
    expect(h.find('hilos-daemon-env-orphans-empty')?.textContent).toBe(
      'Every line in .env is declared in the catalog.',
    )
    h.response(environment([], 'standalone', false), false, [], [])
    expect(h.find('hilos-daemon-node-solo')?.textContent).toBe(
      'Single installation',
    )
    expect(h.find('hilos-daemon-node-diagram')).toBeNull()
    expect(h.find('hilos-daemon-env-silent')).toBeNull()
  })

  it('uses the five reading sources in order and leaves No value unnumbered', () => {
    const h = harness()
    const rows = [
      ...h.find('hilos-daemon-env-ladder')!.querySelectorAll('tbody tr'),
    ]
    expect(rows).toHaveLength(5)
    expect(rows.map((row) => row.querySelector('td')?.textContent)).toEqual([
      '1',
      '2',
      '3',
      '4',
      '',
    ])
    expect(rows.map((row) => row.querySelector('.badge')?.textContent)).toEqual(
      [
        'Launch environment',
        '.env',
        '.env.example',
        'Catalog default',
        'No value',
      ],
    )
    expect(rows.every((row) => !!row.querySelector('[title]'))).toBe(true)
  })

  it('starts with all keys and sends attention and search through real window controls', () => {
    const h = harness()
    h.response(environment(), false, [], [])
    const toggle = h.find('hilos-table-filter-attention') as HTMLInputElement
    expect(toggle.checked).toBe(false)
    expect(h.sendViewport).toHaveBeenCalledWith(
      HilosPages.DAEMON_ENV,
      'hilosDaemonEnvKeys',
      expect.objectContaining({ filter: { node: 'n1' } }),
    )
    fireEvent.click(toggle)
    expect(h.sendViewport).toHaveBeenLastCalledWith(
      HilosPages.DAEMON_ENV,
      'hilosDaemonEnvKeys',
      expect.objectContaining({ filter: { node: 'n1', attention: true } }),
    )
    h.window([])
    expect(h.find('hilos-daemon-env-keys-empty-attention')?.textContent).toBe(
      'No key needs attention.',
    )
    fireEvent.change(h.find('hilos-table-search')!, {
      target: { value: 'APP_ENV' },
    })
    expect(h.sendViewport).toHaveBeenLastCalledWith(
      HilosPages.DAEMON_ENV,
      'hilosDaemonEnvKeys',
      expect.objectContaining({
        filter: { node: 'n1', attention: true, search: 'APP_ENV' },
      }),
    )
    h.window([])
    expect(h.find('hilos-daemon-env-keys')?.textContent).toBe('0')
  })

  it('shows a refused window as unavailable and recovers on the next window', () => {
    const h = harness()
    h.response(environment([key('LIVE')]), false, [key('LIVE')], [])
    h.refuse()
    expect(h.find('hilos-table-unavailable-title')?.textContent).toBe(
      'List unavailable',
    )
    expect(h.find('hilos-table-unavailable-hint')?.textContent).toBe(
      'The rows of this list could not be fetched. The rest of the page still works.',
    )
    expect(h.find('hilos-table-row-LIVE')).toBeNull()
    expect(h.find('hilos-daemon-env-keys')?.textContent).toBe('1')
    h.window([key('LIVE')])
    expect(h.find('hilos-table-unavailable')).toBeNull()
    expect(h.find('hilos-table-row-LIVE')).not.toBeNull()
  })

  it('releases both bindings on node changes, resets filters and cleans up on unmount', () => {
    const h = harness()
    h.response(environment([key('OLD')]), false, [key('OLD')], [])
    const old = [...h.activeWindows.values()]
    fireEvent.click(h.find('hilos-table-filter-attention')!)
    h.selectNode('n2')
    h.response(environment([key('NEW')], 'n2'), false, [key('NEW')], [])
    expect(h.register).toHaveBeenCalledTimes(4)
    expect(h.unregister.mock.calls).toEqual([
      ['hilosDaemonEnvKeys'],
      ['hilosDaemonEnvOrphans'],
    ])
    expect(
      [...h.activeWindows.values()].every((sink) => !old.includes(sink)),
    ).toBe(true)
    expect(h.listeners.get('tableWindow')?.size).toBe(2)
    expect(h.find('hilos-table-row-OLD')).toBeNull()
    expect(h.find('hilos-table-row-NEW')).not.toBeNull()
    expect(h.find('hilos-daemon-node-id')?.textContent).toBe('n2')
    expect(
      (h.find('hilos-table-filter-attention') as HTMLInputElement).checked,
    ).toBe(false)
    expect(h.sendViewport).toHaveBeenLastCalledWith(
      HilosPages.DAEMON_ENV,
      'hilosDaemonEnvOrphans',
      expect.objectContaining({ filter: { node: 'n2' } }),
    )
    h.view.unmount()
    expect(h.activeWindows.size).toBe(0)
    expect([...h.listeners.values()].every((group) => group.size === 0)).toBe(
      true,
    )
  })
})
