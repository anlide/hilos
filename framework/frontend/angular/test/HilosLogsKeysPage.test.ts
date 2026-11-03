import { TestBed } from '@angular/core/testing'
import { afterEach, describe, expect, it, vi } from 'vitest'
import {
  createSignal,
  HilosPages,
  KEYS_HEADER_SIGNAL,
  ScopeManager,
  type HilosConnection,
  type HilosLogKeysHeader,
  type HilosRouter,
  type PageRouteMatch,
} from '@hilos/core'

import { HilosLogsKeysPage } from '../src/admin/logs/HilosLogsKeysPage.js'
import { HILOS_ROUTER } from '../src/hilosRouterToken.js'

function router(): HilosRouter {
  return {
    currentRoute: createSignal<PageRouteMatch>({
      page: HilosPages.LOGS_KEYS,
      params: {},
      admin: true,
    }),
    currentPath: createSignal(''),
    currentTitle: createSignal(''),
    pageError: createSignal(null),
    pageLoading: createSignal(false),
    pageIdentity: createSignal(undefined),
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
  } as unknown as HilosRouter
}

function harness() {
  const headers: Array<
    (signal: { type: string; data: HilosLogKeysHeader }) => void
  > = []
  const windows: Array<(signal: { data: unknown }) => void> = []
  const sendTableViewport = vi.fn()
  const connection = {
    on(event: string, listener: (signal: never) => void): () => void {
      if (event === 'projectSignal') headers.push(listener as never)
      if (event === 'tableWindow') windows.push(listener as never)
      return () => {}
    },
    registerTableWindow(): void {},
    unregisterTableWindow(): void {},
    tableWindowDescriptors: () => ({}),
    sendTableViewport,
    sendTableRendered(): void {},
    sendTableFacets(): void {},
  } as unknown as HilosConnection

  TestBed.configureTestingModule({
    providers: [{ provide: HILOS_ROUTER, useValue: router() }],
  })
  const fixture = TestBed.createComponent(HilosLogsKeysPage)
  const scopes = new ScopeManager()
  scopes.openPage(HilosPages.LOGS_KEYS)
  fixture.componentRef.setInput('context', { connection, scopes })
  fixture.detectChanges()

  return {
    fixture,
    sendTableViewport,
    header(value: HilosLogKeysHeader): void {
      for (const listener of headers)
        listener({ type: KEYS_HEADER_SIGNAL, data: value })
      fixture.detectChanges()
    },
    window(rows: Record<string, unknown>[]): void {
      for (const listener of windows)
        listener({
          data: {
            page: HilosPages.LOGS_KEYS,
            tableKey: 'hilosLogKeys',
            rows: rows.map((slot) => ({
              rowKey: String(slot.rowKey),
              slots: { stream: slot },
            })),
            totalCount: rows.length,
            totalExact: true,
            firstAnchor: rows.length > 0 ? { key: rows[0]?.key } : null,
            lastAnchor: rows.length > 0 ? { key: rows.at(-1)?.key } : null,
            limit: 20,
            rowsBefore: 0,
          },
        })
      fixture.detectChanges()
    },
  }
}

afterEach(() => TestBed.resetTestingModule())

describe('HilosLogsKeysPage', () => {
  it('labels node rows and filters while keeping links and queries addressed by id', () => {
    const page = harness()
    const name = '<b>Узел & β</b>'
    page.header({
      available: true,
      nodes: [
        { nodeId: 'node-1', nodeName: name },
        { nodeId: 'node-2', nodeName: null },
      ],
    })
    page.window(
      ['node-1', 'node-2'].map((node) => ({
        rowKey: node + ':worker-0.log',
        key: 'worker-0.log',
        node,
        nodeName: node === 'node-1' ? name : null,
        class: 'worker',
        growthPerDay: 512,
        live: true,
        batchCount: 1,
        lastBatchAt: null,
        bytes: 1024,
      })),
    )
    const root = page.fixture.nativeElement as HTMLElement
    for (const projection of ['row', 'card']) {
      const named = root.querySelector(
        `[data-id="hilos-table-${projection}-node-1:worker-0.log"]`,
      )!
      expect(named.textContent).toContain(name)
      expect(named.textContent).toContain('node-1')
      expect(named.querySelector('b')).toBeNull()
      expect(
        named
          .querySelector('[data-id="hilos-log-key-open-node-1:worker-0.log"]')
          ?.getAttribute('href'),
      ).toBe('/hilos/logs/view/node-1/live/worker-0.log')
      const unknown = root.querySelector(
        `[data-id="hilos-table-${projection}-node-2:worker-0.log"]`,
      )!
      expect(unknown.textContent).toContain('node-2')
      expect(unknown.textContent).not.toContain(name)
      expect(unknown.textContent).not.toContain('null')
    }
    expect(
      root
        .querySelector(
          '[data-id="hilos-table-row-node-2:worker-0.log"] td:nth-child(2)',
        )
        ?.textContent?.trim(),
    ).toBe('node-2')
    const filter = root.querySelector<HTMLElement>(
      '[data-id="hilos-table-filter-node"]',
    )!
    filter
      .querySelector<HTMLButtonElement>('[data-id="hilos-dropdown-toggle"]')!
      .click()
    page.fixture.detectChanges()
    expect(
      [...filter.querySelectorAll('[role="option"]')].map((option) =>
        option.textContent?.trim(),
      ),
    ).toEqual(['All nodes', name + ' (node-1)', 'node-2'])
    filter
      .querySelector<HTMLButtonElement>('[data-id="hilos-dropdown-option-0"]')!
      .click()
    page.fixture.detectChanges()
    expect(page.sendTableViewport).toHaveBeenLastCalledWith(
      HilosPages.LOGS_KEYS,
      'hilosLogKeys',
      expect.objectContaining({ filter: { node: 'node-1' } }),
    )
  })

  it('draws the shared bar, node filter, footer, and narrow card from the header', () => {
    const page = harness()
    page.header({
      available: true,
      nodes: [{ nodeId: 'node-1', nodeName: null }],
    })
    page.window([
      {
        rowKey: 'node-1:worker-0.log',
        key: 'worker-0.log',
        node: 'node-1',
        nodeName: null,
        class: 'worker',
        live: true,
        batchCount: 1,
        lastBatchAt: null,
        bytes: 1024,
        growthPerDay: 512,
      },
    ])
    const root = page.fixture.nativeElement as HTMLElement
    expect(
      root.querySelector('[data-id="hilos-table-filter-node"]'),
    ).not.toBeNull()
    expect(
      root.querySelector('[data-id="hilos-table-sort-node"]'),
    ).not.toBeNull()
    expect(root.querySelector('[data-id="hilos-table-count"]')).not.toBeNull()
    expect(
      root.querySelector('[data-id^="hilos-table-card-"]')?.textContent,
    ).toContain('worker-0.log')
    expect(
      root.querySelector('[data-id^="hilos-table-card-"]')?.textContent,
    ).toContain('node-1')
  })

  it('uses the page wording for an empty filtered window', () => {
    const page = harness()
    page.header({ available: true, nodes: [] })
    page.window([])
    const root = page.fixture.nativeElement as HTMLElement
    const search = root.querySelector(
      '[data-id="hilos-table-search"]',
    ) as HTMLInputElement
    search.value = 'missing'
    search.dispatchEvent(new Event('input', { bubbles: true }))
    page.fixture.detectChanges()
    expect(
      root.querySelector('[data-id="hilos-log-key-empty-nomatch"]'),
    ).not.toBeNull()
    expect(
      root.querySelector('[data-id="hilos-log-key-clear-filters"]'),
    ).not.toBeNull()
  })
})
