import { openNodePage } from '../../../test/helpers/hilosDaemonNode.js'
import { mount } from '@vue/test-utils'
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
} from '@hilos/core/base'
import { DAEMON_ENV_NODE_SILENT_DATA } from '@hilos/core/admin/daemon'
import { describe, expect, it, vi } from 'vitest'
import { markRaw, nextTick } from 'vue'

import HilosDaemonEnvOrphansPage from './HilosDaemonEnvOrphansPage.vue'
import { hilosRouterKey } from '../../hilosRouterKey.js'

function harness(nodeId = 'n1') {
  const scopes = markRaw(new ScopeManager())
  openNodePage(scopes, HilosPages.DAEMON_ENV_ORPHANS)
  const currentRoute = createSignal<PageRouteMatch>({
    page: HilosPages.DAEMON_ENV_ORPHANS,
    params: { nodeId },
    admin: true,
  })
  const router: HilosRouter = {
    currentRoute,
    currentPath: createSignal(`/hilos/daemon/${nodeId}/env/orphans`),
    currentTitle: createSignal(''),
    pageError: createSignal(null),
    pageLoading: createSignal(false),
    pageIdentity: pageIdentity(scopes),
    dashboardSections: createSignal(undefined),
    resolvePath: () => undefined,
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
      activeWindows.set(tableKey, sink)
      lifecycle.push(`register:${tableKey}`)
    },
    unregisterTableWindow(tableKey: string): void {
      activeWindows.delete(tableKey)
      lifecycle.push(`unregister:${tableKey}`)
    },
    tableWindowDescriptors: () => ({}),
    sendTableViewport: vi.fn(),
    sendTableRendered: vi.fn(),
  } as unknown as HilosConnection
  const view = mount(HilosDaemonEnvOrphansPage, {
    props: { context: { connection, scopes } },
    attachTo: document.body,
    global: { provide: { [hilosRouterKey as symbol]: router } },
  })

  function emit(event: string, signal: { type?: string; data: unknown }): void {
    for (const listener of listeners.get(event) ?? []) listener(signal)
  }

  function window(rows: Record<string, unknown>[]) {
    return {
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
  }

  return {
    view,
    lifecycle,
    listeners,
    answer(nodeSilent: boolean, rows: Record<string, unknown>[]) {
      const data = {
        [DAEMON_ENV_NODE_SILENT_DATA]: nodeSilent,
        node: {
          clustered: true,
          state: nodeSilent ? 'silent' : null,
          silentSince: null,
          role: null,
        },
      }
      ingest(scopes.page()!, { data })
      const opened = window(rows)
      emit('projectSignal', {
        type: 'page_response',
        data: {
          page: HilosPages.DAEMON_ENV_ORPHANS,
          payload: {
            data,
            windows: { hilosDaemonEnvOrphans: opened },
          },
        },
      })
      emit('tableWindow', {
        data: {
          page: HilosPages.DAEMON_ENV_ORPHANS,
          tableKey: 'hilosDaemonEnvOrphans',
          ...opened,
        },
      })
    },
    selectNode(nextNodeId: string) {
      currentRoute.set({
        page: HilosPages.DAEMON_ENV_ORPHANS,
        params: { nodeId: nextNodeId },
        admin: true,
      })
      openNodePage(scopes, HilosPages.DAEMON_ENV_ORPHANS)
    },
  }
}

describe('HilosDaemonEnvOrphansPage', () => {
  it('names its one table from the page heading and shows set, hidden and similar values', async () => {
    const h = harness()
    h.answer(false, [
      {
        key: 'DB_PASSWD',
        value: { kind: 'secret', length: 19 },
        similarTo: 'DB_PASSWORD',
      },
      { key: 'PLAIN', value: HIDDEN_VALUE, similarTo: null },
    ])
    await nextTick()

    expect(h.view.findAll('h1')).toHaveLength(1)
    expect(h.view.get('h1').text()).toBe('n1')
    expect(h.view.findAll('table.table-hover')).toHaveLength(1)
    const table = h.view.get('table.table-hover')
    expect(table.attributes('aria-labelledby')).toBe(
      h.view.get('h1').attributes('id'),
    )
    expect(h.view.text()).not.toContain('Orphans in .env')
    const secret = h.view.get('[data-id="hilos-table-row-DB_PASSWD"]')
    expect(secret.text()).toContain('Set · 19 chars')
    expect(secret.text()).toContain('not in catalog')
    expect(secret.text()).toContain('DB_PASSWORD')
    expect(secret.text()).not.toContain('raw')
    const hidden = h.view.get('[data-id="hilos-table-row-PLAIN"]')
    expect(hidden.find('[data-id="hilos-hidden"]').text()).toBe('Hidden')
    expect(hidden.text()).toContain('—')
    expect(hidden.text()).not.toContain('19')
    h.view.unmount()
  })

  it('shows the live empty line and the silent line', async () => {
    const h = harness()
    h.answer(false, [])
    await nextTick()
    expect(
      h.view.get('[data-id="hilos-daemon-env-orphans-empty"]').text(),
    ).toBe('Every line in .env is declared in the catalog.')
    h.answer(true, [])
    await nextTick()
    expect(
      h.view.get('[data-id="hilos-daemon-env-orphans-empty-silent"]').text(),
    ).toBe('Nothing is read while the node is silent.')
    h.view.unmount()
  })

  it('replaces the window when the node changes and releases it on unmount', async () => {
    const h = harness()
    h.answer(false, [])
    await nextTick()
    h.selectNode('n2')
    h.answer(false, [{ key: 'NEXT', value: HIDDEN_VALUE, similarTo: null }])
    await nextTick()
    expect(h.lifecycle).toEqual([
      'register:hilosDaemonEnvOrphans',
      'unregister:hilosDaemonEnvOrphans',
      'register:hilosDaemonEnvOrphans',
    ])
    expect(h.view.get('h1').text()).toBe('n2')
    expect(h.view.find('[data-id="hilos-table-row-NEXT"]').exists()).toBe(true)
    h.view.unmount()
    expect(h.lifecycle.at(-1)).toBe('unregister:hilosDaemonEnvOrphans')
    for (const group of h.listeners.values()) expect(group.size).toBe(0)
  })
})
