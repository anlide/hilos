import { act, cleanup, render } from '@testing-library/react'
import { afterEach, describe, expect, it, vi } from 'vitest'
import {
  createSignal,
  HIDDEN_VALUE,
  HilosPages,
  ingest,
  ScopeManager,
  type HilosConnection,
  type HilosDaemonEnvMismatchSummary,
  type HilosRouter,
} from '@hilos/core'
import { HilosDaemonEnvMismatchPage } from '../src/admin/daemon/HilosDaemonEnvMismatchPage.js'
import { HilosRouterContext } from '../src/hilosRouterContext.js'

function summary(
  overrides: Partial<
    NonNullable<HilosDaemonEnvMismatchSummary['comparison']>
  > = {},
): HilosDaemonEnvMismatchSummary {
  return {
    clustered: true,
    servingNodeId: 'n1',
    comparison: {
      nodes: ['n1', 'n2'],
      answering: ['n1', 'n2'],
      diverged: 0,
      sourcesDiffer: 0,
      perNode: [],
      ...overrides,
    },
  }
}
function harness(initial: HilosDaemonEnvMismatchSummary | null = null) {
  const scopes = new ScopeManager()
  scopes.openPage(HilosPages.DAEMON_ENV_MISMATCH)
  ingest(scopes.page()!, { data: { envMismatch: initial } })
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
  const router = {
    currentRoute: createSignal({
      page: HilosPages.DAEMON_ENV_MISMATCH,
      params: {},
      admin: true,
    }),
    currentPath: createSignal('/hilos/daemon/env-mismatch'),
    currentTitle: createSignal(''),
    pageError: createSignal(null),
    pageLoading: createSignal(false),
    pageIdentity: createSignal({
      label: 'Env mismatch',
      lead: '',
      breadcrumb: [],
      children: [],
    }),
    dashboardSections: createSignal(undefined),
    resolvePath: () => undefined,
    navigate: vi.fn(),
  } as unknown as HilosRouter
  const view = render(
    <HilosRouterContext.Provider value={router}>
      <HilosDaemonEnvMismatchPage context={{ connection, scopes }} />
    </HilosRouterContext.Provider>,
  )
  function emit(event: string, data: unknown) {
    for (const callback of listeners.get(event) ?? []) callback(data as never)
  }
  return {
    view,
    register,
    unregister,
    activeWindows,
    listeners,
    event(event: string, data: unknown) {
      act(() => emit(event, { data }))
    },
    sendViewport,
    find: (id: string): HTMLElement | null =>
      view.container.querySelector(`[data-id="${id}"]`),
    response(value: HilosDaemonEnvMismatchSummary | null) {
      act(() => {
        const data = { envMismatch: value }
        ingest(scopes.page()!, { data })
        emit('projectSignal', {
          type: 'page_response',
          data: { page: HilosPages.DAEMON_ENV_MISMATCH, payload: { data } },
        })
      })
    },
    window(tableKey: string, rows: Record<string, unknown>[]) {
      act(() =>
        emit('tableWindow', {
          data: {
            page: HilosPages.DAEMON_ENV_MISMATCH,
            tableKey,
            rows: rows.map((key) => ({
              rowKey: String(key.key),
              slots: { key },
            })),
            totalCount: rows.length,
            totalExact: true,
            limit: 25,
            sort: null,
            firstAnchor: null,
            lastAnchor: null,
            rowsBefore: 0,
          },
        }),
      )
    },
  }
}
function cell(nodeId: string, overrides: Record<string, unknown> = {}) {
  return {
    nodeId,
    state: 'known',
    type: 'string',
    source: 'env',
    digest: 'aaaa111100000000',
    ...overrides,
  }
}
function row(key: string, overrides: Record<string, unknown> = {}) {
  return {
    key,
    kind: 'diverged',
    position: 0,
    sensitive: false,
    value: null,
    cells: [cell('n1'), cell('n2')],
    ...overrides,
  }
}
const MISMATCH = 'hilosDaemonEnvMismatch'
const CELL = 'hilos-daemon-env-mismatch-cell-'
afterEach(cleanup)

describe('HilosDaemonEnvMismatchPage', () => {
  it('shows standalone explanation with no tables, bindings or window requests', () => {
    const h = harness({
      clustered: false,
      servingNodeId: 'standalone',
      comparison: null,
    })
    expect(
      h.find('hilos-daemon-env-mismatch-standalone')?.textContent,
    ).toContain('nothing to compare it with')
    expect(
      h.find('hilos-daemon-env-mismatch-standalone-node')?.textContent,
    ).toBe('standalone')
    expect(h.find('hilos-daemon-env-mismatch-waiting')).toBeNull()
    expect(h.find('hilos-daemon-env-mismatch-tile-answering')).toBeNull()
    expect(h.find('hilos-viewport-table')).toBeNull()
    expect(h.register).not.toHaveBeenCalled()
    expect(h.sendViewport).not.toHaveBeenCalled()
    expect(h.view.container.querySelectorAll('h1')).toHaveLength(1)
    h.view.unmount()
    expect(h.activeWindows.size).toBe(0)
  })

  it('keeps null summary and null comparison waiting rather than agreeing at zero', () => {
    const h = harness()
    expect(h.find('hilos-daemon-env-mismatch-waiting')?.textContent).toContain(
      'Waiting for the cluster picture…',
    )
    expect(h.register).not.toHaveBeenCalled()
    h.response({ clustered: true, servingNodeId: 'n1', comparison: null })
    expect(h.find('hilos-daemon-env-mismatch-waiting')).not.toBeNull()
    expect(h.find('hilos-daemon-env-mismatch-tile-diverged')).toBeNull()
    expect(h.find('hilos-daemon-env-mismatch-empty-agree')).toBeNull()
    expect(h.register).toHaveBeenCalledTimes(1)
    h.response(summary())
    h.window(MISMATCH, [])
    expect(h.find('hilos-daemon-env-mismatch-waiting')).toBeNull()
    expect(h.find('hilos-daemon-env-mismatch-empty-agree')?.textContent).toBe(
      'The answering nodes agree on every key they share.',
    )
    h.response({ clustered: true, servingNodeId: 'n1', comparison: null })
    expect(h.find('hilos-daemon-env-mismatch-waiting')).not.toBeNull()
    expect(h.find('hilos-daemon-env-mismatch-tile-diverged')).toBeNull()
  })

  it('draws four summary tiles, source-only disagreement and per-node declarations', () => {
    const h = harness(
      summary({
        nodes: ['n1', 'n2', 'n3'],
        diverged: 2,
        sourcesDiffer: 1,
        perNode: ['CLUSTER_NODE_ID'],
      }),
    )
    expect(
      h.find('hilos-daemon-env-mismatch-tile-answering')?.textContent,
    ).toContain('2 of 3')
    expect(
      h.find('hilos-daemon-env-mismatch-tile-answering')?.dataset.tone,
    ).toBe('warning')
    expect(
      h.find('hilos-daemon-env-mismatch-tile-diverged')?.dataset.tone,
    ).toBe('danger')
    expect(h.find('hilos-daemon-env-mismatch-tile-sources')?.dataset.tone).toBe(
      'warning',
    )
    expect(
      h.find('hilos-daemon-env-mismatch-tile-per-node')?.dataset.tone,
    ).toBe('')
    expect(
      h.find('hilos-daemon-env-mismatch-per-node-CLUSTER_NODE_ID')?.textContent,
    ).toBe('CLUSTER_NODE_ID')
    expect(
      h.view.container.querySelectorAll('[data-id="hilos-viewport-table"]'),
    ).toHaveLength(1)
    h.window(MISMATCH, [])
    expect(
      h.find('hilos-daemon-env-mismatch-tile-diverged')?.querySelector('.fs-5')
        ?.textContent,
    ).toBe('2')
    h.response(summary())
    expect(
      h.find('hilos-daemon-env-mismatch-tile-answering')?.dataset.tone,
    ).toBe('')
    expect(
      h.find('hilos-daemon-env-mismatch-tile-diverged')?.dataset.tone,
    ).toBe('success')
    expect(h.find('hilos-daemon-env-mismatch-tile-sources')?.dataset.tone).toBe(
      'success',
    )
    expect(h.find('hilos-daemon-env-mismatch-per-node')).toBeNull()
    expect(
      h.view.container.querySelectorAll('[data-id="hilos-viewport-table"]'),
    ).toHaveLength(1)
    h.response(summary({ answering: ['n1'] }))
    expect(h.find('hilos-daemon-env-mismatch-empty-alone')?.textContent).toBe(
      'Fewer than two nodes answer, so there is nothing to compare yet.',
    )
  })

  it('renders value, digest, undeclared, unknown and missing with textual warnings', () => {
    const h = harness(
      summary({
        nodes: ['n1', 'n2', 'n3', 'n4', 'n5', 'n6'],
        answering: ['n1', 'n2', 'n3', 'n5'],
        diverged: 1,
      }),
    )
    h.window(MISMATCH, [
      row('APP_ENV', {
        value: { kind: 'text', text: 'prod' },
        cells: [
          cell('n1', { source: 'process' }),
          cell('n2', { digest: 'bbbb222200000000' }),
          cell('n3', {
            state: 'undeclared',
            type: null,
            source: null,
            digest: null,
          }),
          cell('n4', {
            state: 'unknown',
            type: null,
            source: null,
            digest: null,
          }),
          cell('n5', { source: 'missing', digest: null }),
        ],
      }),
    ])
    for (const [node, kind] of [
      ['n1', 'value'],
      ['n2', 'digest'],
      ['n3', 'undeclared'],
      ['n4', 'unknown'],
      ['n5', 'none'],
      ['n6', 'unknown'],
    ])
      expect(h.find(`${CELL}APP_ENV-${node}`)?.dataset.kind).toBe(kind)
    expect(h.find(`${CELL}APP_ENV-n1`)?.textContent).toContain(
      'prodLaunch environment',
    )
    expect(h.find(`${CELL}APP_ENV-n2`)?.textContent).toContain('#bbbb.env')
    expect(h.find(`${CELL}APP_ENV-n2-stands-out`)?.textContent).toBe(
      'Differs from the others',
    )
    expect(h.find(`${CELL}APP_ENV-n3`)?.textContent).toContain('Not declared')
    expect(h.find(`${CELL}APP_ENV-n3-stands-out`)).not.toBeNull()
    expect(h.find(`${CELL}APP_ENV-n4`)?.textContent).toContain(
      'Silent or no environment report: unknown, not a match',
    )
    expect(h.find(`${CELL}APP_ENV-n4-stands-out`)).toBeNull()
    expect(h.find(`${CELL}APP_ENV-n5`)?.textContent).toContain('—No value')
  })

  it('uses only digests for sensitive values and respects hidden serving-node values', () => {
    const h = harness(summary({ diverged: 3 }))
    h.window(MISMATCH, [
      row('SECRET', {
        sensitive: true,
        cells: [cell('n1'), cell('n2', { digest: 'aaaa222200000000' })],
      }),
      row('HIDDEN', { value: HIDDEN_VALUE }),
      row('TYPE', {
        cells: [
          cell('n1', { type: 'integer' }),
          cell('n2', { type: 'float', digest: 'bbbb222200000000' }),
        ],
      }),
    ])
    expect(h.find(`${CELL}SECRET-n1`)?.dataset.kind).toBe('digest')
    expect(h.find(`${CELL}SECRET-n1`)?.textContent).toContain('#aaaa1')
    expect(h.find(`${CELL}SECRET-n2`)?.textContent).toContain('#aaaa2')
    expect(
      h
        .find('hilos-table-row-SECRET')
        ?.querySelector('.bi-shield-lock')
        ?.getAttribute('aria-hidden'),
    ).toBe('true')
    expect(
      h.find(`${CELL}HIDDEN-n1`)?.querySelector('[data-id="hilos-hidden"]')
        ?.textContent,
    ).toBe('Hidden')
    expect(h.find(`${CELL}HIDDEN-n2`)?.dataset.kind).toBe('digest')
    expect(h.find(`${CELL}TYPE-n1`)?.textContent).toContain('#aaaa · integer')
    expect(h.find(`${CELL}TYPE-n2`)?.textContent).toContain('#bbbb · float')
  })

  it('reuses permitted matching values across sources, including hidden, empty and boolean', () => {
    const h = harness(summary({ sourcesDiffer: 3 }))
    h.window(MISMATCH, [
      row('MAIL_HOST', {
        kind: 'sourcesDiffer',
        value: { kind: 'text', text: 'mail.example.test' },
        cells: [cell('n1'), cell('n2', { source: 'example' })],
      }),
      row('HIDDEN', { kind: 'sourcesDiffer', value: HIDDEN_VALUE }),
      row('BOOL', {
        kind: 'sourcesDiffer',
        value: { kind: 'text', text: 'false' },
        cells: [
          cell('n1', { type: 'boolean' }),
          cell('n2', { type: 'boolean' }),
        ],
      }),
      row('EMPTY', {
        kind: 'sourcesDiffer',
        value: { kind: 'text', text: '' },
      }),
    ])
    expect(h.find('hilos-daemon-env-mismatch-empty-agree')).toBeNull()
    expect(
      h.find('hilos-daemon-env-mismatch-sources-differ-MAIL_HOST')?.textContent,
    ).toBe('Sources differ')
    expect(
      h.find(`${CELL}MAIL_HOST-n2`)?.classList.contains('bg-warning-subtle'),
    ).toBe(true)
    expect(h.find(`${CELL}MAIL_HOST-n2`)?.dataset.kind).toBe('value')
    expect(h.find(`${CELL}MAIL_HOST-n2`)?.textContent).toContain(
      'mail.example.test.env.example',
    )
    expect(h.find(`${CELL}MAIL_HOST-n2-from-example`)?.textContent).toBe(
      'From .env.example',
    )
    expect(h.find(`${CELL}MAIL_HOST-n2-stands-out`)).toBeNull()
    expect(
      h.find(`${CELL}HIDDEN-n2`)?.querySelector('[data-id="hilos-hidden"]')
        ?.textContent,
    ).toBe('Hidden')
    const input = h.find(`${CELL}BOOL-n2`)?.querySelector('input')
    expect(input?.checked).toBe(false)
    expect(input?.disabled).toBe(true)
    expect(h.find(`${CELL}EMPTY-n2`)?.textContent).toBe('empty.env')
  })

  it('keeps row updates live and one bucket transition pending until Apply, then recovers a refusal', () => {
    const h = harness(summary({ diverged: 2 }))
    h.window(MISMATCH, [
      row('A', { value: { kind: 'text', text: 'before' } }),
      row('B', { position: 1 }),
    ])
    const address = { page: HilosPages.DAEMON_ENV_MISMATCH, tableKey: MISMATCH }
    const wire = (value: Record<string, unknown>) => ({
      rowKey: 'A',
      slots: { key: value },
    })
    h.event('tableViewportDelta', {
      ...address,
      kind: 'row_updated',
      rowKey: 'A',
      row: wire(row('A', { value: { kind: 'text', text: 'after' } })),
    })
    expect(h.find(`${CELL}A-n1`)?.textContent).toContain('after')
    expect(h.find('hilos-table-apply')).toBeNull()
    const order = () =>
      Array.from(
        h.view.container.querySelectorAll(
          'tbody tr[data-id^="hilos-table-row-"]',
        ),
      ).map((el) => el.getAttribute('data-id'))
    h.event('tableViewportDelta', {
      ...address,
      kind: 'row_moved',
      rowKey: 'A',
      position: 1,
      row: wire(row('A', { kind: 'sourcesDiffer', position: 1 })),
    })
    expect(order()).toEqual(['hilos-table-row-A', 'hilos-table-row-B'])
    expect(h.find('hilos-table-apply')).not.toBeNull()
    act(() => h.find('hilos-table-apply')!.click())
    expect(order()).toEqual(['hilos-table-row-B', 'hilos-table-row-A'])
    expect(
      h.find('hilos-daemon-env-mismatch-sources-differ-A')?.textContent,
    ).toBe('Sources differ')
    const labelId = h.view.container
      .querySelector('table')
      ?.getAttribute('aria-labelledby')
    expect(
      h.view.container.querySelector(`[id="${labelId}"]`)?.textContent,
    ).toBe('Env mismatch')
    expect(
      h.view.container
        .querySelector('[role="list"]')
        ?.getAttribute('aria-labelledby'),
    ).toBe(labelId)
    h.event('tableWindowRefused', { ...address, errorCode: 'unavailable' })
    expect(h.find('hilos-daemon-env-mismatch-empty-agree')).toBeNull()
    expect(h.view.container.textContent).toContain('List unavailable')
    h.window(MISMATCH, [row('A', { kind: 'sourcesDiffer' })])
    expect(h.find('hilos-daemon-env-mismatch-sources-differ-A')).not.toBeNull()
    expect(h.register).toHaveBeenCalledTimes(1)
  })

  it('updates dynamic node columns and values without rebinding, and releases all listeners', () => {
    const h = harness(summary({ diverged: 1 }))
    h.window(MISMATCH, [row('KEY')])
    expect(h.view.container.querySelector('thead')?.textContent).toContain(
      'n1 · this node',
    )
    expect(h.find(`${CELL}KEY-n2`)).not.toBeNull()
    h.response(
      summary({ nodes: ['n1', 'n3'], answering: ['n1', 'n3'], diverged: 1 }),
    )
    h.window(MISMATCH, [
      row('KEY', {
        cells: [cell('n1'), cell('n3', { digest: 'cccc111100000000' })],
      }),
    ])
    expect(h.find(`${CELL}KEY-n2`)).toBeNull()
    expect(h.find(`${CELL}KEY-n3`)?.textContent).toContain('#cccc')
    expect(h.view.container.querySelector('thead')?.textContent).toContain('n3')
    expect(h.register).toHaveBeenCalledTimes(1)
    h.response({
      clustered: false,
      servingNodeId: 'standalone',
      comparison: null,
    })
    expect(h.activeWindows.size).toBe(0)
    expect([...h.listeners.values()].every((group) => group.size === 0)).toBe(
      true,
    )
    h.response(summary())
    expect(h.register).toHaveBeenCalledTimes(2)
    h.view.unmount()
    expect(h.activeWindows.size).toBe(0)
    expect([...h.listeners.values()].every((group) => group.size === 0)).toBe(
      true,
    )
  })
})
