import { openNodePage } from './helpers/hilosDaemonNode.js'
import { act, cleanup, render } from '@testing-library/react'
import { afterEach, describe, expect, it, vi } from 'vitest'
import {
  createSignal,
  HIDDEN_VALUE,
  HilosPages,
  ingest,
  ScopeManager,
  pageIdentity,
  type HilosConnection,
  type HilosRouter,
  type PageRouteMatch,
} from '@hilos/core'
import { HilosDaemonEnvOrphansPage } from '../src/admin/daemon/HilosDaemonEnvOrphansPage.js'
import { HilosRouterContext } from '../src/hilosRouterContext.js'

afterEach(cleanup)

function harness() {
  const scopes = new ScopeManager()
  openNodePage(scopes, HilosPages.DAEMON_ENV_ORPHANS)
  const currentRoute = createSignal<PageRouteMatch>({
    page: HilosPages.DAEMON_ENV_ORPHANS,
    params: { nodeId: 'n1' },
    admin: true,
  })
  const router = {
    currentRoute,
    currentPath: createSignal('/hilos/daemon/n1/env/orphans'),
    currentTitle: createSignal(''),
    pageError: createSignal(null),
    pageLoading: createSignal(false),
    pageIdentity: pageIdentity(scopes),
    dashboardSections: createSignal(undefined),
    resolvePath: () => undefined,
    navigate: vi.fn(),
  } as unknown as HilosRouter
  const listeners = new Map<string, Set<(payload: never) => void>>()
  const lifecycle: string[] = []
  const connection = {
    on(event: string, callback: (payload: never) => void) {
      const group = listeners.get(event) ?? new Set()
      group.add(callback)
      listeners.set(event, group)
      return () => {
        group.delete(callback)
      }
    },
    registerTableWindow(tableKey: string) {
      lifecycle.push(`register:${tableKey}`)
    },
    unregisterTableWindow(tableKey: string) {
      lifecycle.push(`unregister:${tableKey}`)
    },
    tableWindowDescriptors: () => ({}),
    sendTableViewport: vi.fn(),
    sendTableRendered: vi.fn(),
  } as unknown as HilosConnection
  const view = render(
    <HilosRouterContext.Provider value={router}>
      <HilosDaemonEnvOrphansPage context={{ connection, scopes }} />
    </HilosRouterContext.Provider>,
  )
  function emit(value: unknown) {
    for (const callback of listeners.get('projectSignal') ?? [])
      callback(value as never)
  }
  return {
    view,
    lifecycle,
    listeners,
    find: (id: string) => view.container.querySelector(`[data-id="${id}"]`),
    answer(nodeSilent: boolean, rows: Record<string, unknown>[]) {
      act(() => {
        const data = {
          nodeSilent,
          node: {
            clustered: true,
            state: nodeSilent ? 'silent' : null,
            silentSince: null,
            role: null,
          },
        }
        ingest(scopes.page()!, { data })
        const opened = {
          rows: rows.map((row) => ({
            rowKey: String(row.key),
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
        emit({
          type: 'page_response',
          data: {
            page: HilosPages.DAEMON_ENV_ORPHANS,
            payload: {
              data,
              windows: { hilosDaemonEnvOrphans: opened },
            },
          },
        })
        for (const callback of listeners.get('tableWindow') ?? []) {
          callback({
            data: {
              page: HilosPages.DAEMON_ENV_ORPHANS,
              tableKey: 'hilosDaemonEnvOrphans',
              ...opened,
            },
          } as never)
        }
      })
    },
    selectNode(nodeId: string) {
      act(() => {
        openNodePage(scopes, HilosPages.DAEMON_ENV_ORPHANS)
        currentRoute.set({
          page: HilosPages.DAEMON_ENV_ORPHANS,
          params: { nodeId },
          admin: true,
        })
      })
    },
  }
}

describe('HilosDaemonEnvOrphansPage', () => {
  it('names its one table from the page heading and shows set, hidden and similar values', () => {
    const h = harness()
    h.answer(false, [
      {
        key: 'DB_PASSWD',
        value: { kind: 'secret', length: 19 },
        similarTo: 'DB_PASSWORD',
      },
      { key: 'PLAIN', value: HIDDEN_VALUE, similarTo: null },
    ])
    const headings = [...h.view.container.querySelectorAll('h1')]
    expect(headings).toHaveLength(1)
    expect(headings[0]?.textContent).toBe('n1')
    const tables = [...h.view.container.querySelectorAll('table.table-hover')]
    expect(tables).toHaveLength(1)
    expect(tables[0]?.getAttribute('aria-labelledby')).toBe(headings[0]?.id)
    expect(h.view.container.textContent).not.toContain('Orphans in .env')
    const secret = h.find('hilos-table-row-DB_PASSWD')
    expect(secret?.textContent).toContain('Set · 19 chars')
    expect(secret?.textContent).toContain('not in catalog')
    expect(secret?.textContent).toContain('DB_PASSWORD')
    const hidden = h.find('hilos-table-row-PLAIN')
    expect(hidden?.querySelector('[data-id="hilos-hidden"]')?.textContent).toBe(
      'Hidden',
    )
    expect(hidden?.textContent).toContain('—')
    expect(hidden?.textContent).not.toContain('19')
  })

  it('shows the live empty line and the silent line', () => {
    const h = harness()
    h.answer(false, [])
    expect(h.find('hilos-daemon-env-orphans-empty')?.textContent).toBe(
      'Every line in .env is declared in the catalog.',
    )
    h.answer(true, [])
    expect(h.find('hilos-daemon-env-orphans-empty-silent')?.textContent).toBe(
      'Nothing is read while the node is silent.',
    )
  })

  it('replaces the window when the node changes and releases it on unmount', () => {
    const h = harness()
    h.answer(false, [])
    h.selectNode('n2')
    h.answer(false, [{ key: 'NEXT', value: HIDDEN_VALUE, similarTo: null }])
    expect(h.lifecycle).toEqual([
      'register:hilosDaemonEnvOrphans',
      'unregister:hilosDaemonEnvOrphans',
      'register:hilosDaemonEnvOrphans',
    ])
    expect(h.view.container.querySelector('h1')?.textContent).toBe('n2')
    expect(h.find('hilos-table-row-NEXT')).not.toBeNull()
    h.view.unmount()
    expect(h.lifecycle.at(-1)).toBe('unregister:hilosDaemonEnvOrphans')
    expect([...h.listeners.values()].every((group) => group.size === 0)).toBe(
      true,
    )
  })
})
