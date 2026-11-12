import { TestBed } from '@angular/core/testing'
import { afterEach, describe, expect, it, vi } from 'vitest'
import {
  createSignal,
  HilosPages,
  ScopeManager,
  WORKERS_HEADER_SIGNAL,
  type HilosConnection,
  type HilosLogWorkersHeader,
  type HilosRouter,
  type PageRouteMatch,
} from '@hilos/core/base'

import { HilosLogsWorkersPage } from '../src/admin/logs/HilosLogsWorkersPage.js'
import { HILOS_ROUTER } from '../src/hilosRouterToken.js'

function router(): HilosRouter {
  return {
    currentRoute: createSignal<PageRouteMatch>({
      page: HilosPages.LOGS_WORKERS,
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
    (signal: { type: string; data: HilosLogWorkersHeader }) => void
  > = []
  const windows: Array<(signal: { data: unknown }) => void> = []
  const sendTableViewport = vi.fn()
  const connection = {
    on(event: string, listener: (signal: never) => void): () => void {
      if (event === 'projectSignal')
        listener({
          type: 'page_response',
          data: {
            page: HilosPages.LOGS_WORKERS,
            payload: {
              windows: {
                hilosLogWorkers: {
                  filter: {},
                  rows: [],
                  totalCount: 0,
                  totalExact: true,
                  limit: 25,
                  sort: [{ field: 'bytes', direction: 'desc' }],
                  firstAnchor: null,
                  lastAnchor: null,
                },
              },
            },
          },
        } as never)
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
  const fixture = TestBed.createComponent(HilosLogsWorkersPage)
  const scopes = new ScopeManager()
  scopes.openPage(HilosPages.LOGS_WORKERS)
  fixture.componentRef.setInput('context', { connection, scopes })
  fixture.detectChanges()

  return {
    fixture,
    sendTableViewport,
    header(value: HilosLogWorkersHeader): void {
      for (const listener of headers)
        listener({ type: WORKERS_HEADER_SIGNAL, data: value })
      fixture.detectChanges()
    },
    window(rows: Record<string, unknown>[]): void {
      for (const listener of windows)
        listener({
          data: {
            page: HilosPages.LOGS_WORKERS,
            tableKey: 'hilosLogWorkers',
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

describe('HilosLogsWorkersPage', () => {
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
        type: 'regular',
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
          .querySelector(
            '[data-id="hilos-log-worker-open-node-1:worker-0.log"]',
          )
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
      HilosPages.LOGS_WORKERS,
      'hilosLogWorkers',
      expect.objectContaining({ filter: { node: 'node-1' } }),
    )
  })

  it('draws the shared bar, node filter, type toggle, footer, and narrow card from the header', () => {
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
        type: 'regular',
        live: true,
        batchCount: 1,
        lastBatchAt: null,
        bytes: 1024,
      },
    ])
    const root = page.fixture.nativeElement as HTMLElement
    expect(
      root.querySelector('[data-id="hilos-table-filter-node"]'),
    ).not.toBeNull()
    expect(
      root.querySelector('[data-id="hilos-table-filter-type"]'),
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
      root.querySelector('[data-id="hilos-log-worker-empty-nomatch"]'),
    ).not.toBeNull()
    expect(
      root.querySelector('[data-id="hilos-log-worker-clear-filters"]'),
    ).not.toBeNull()
  })
})
