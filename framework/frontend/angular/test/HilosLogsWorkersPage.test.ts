import { TestBed } from '@angular/core/testing'
import { afterEach, describe, expect, it } from 'vitest'
import {
  createSignal,
  HilosPages,
  ScopeManager,
  WORKERS_HEADER_SIGNAL,
  type HilosConnection,
  type HilosLogWorkersHeader,
  type HilosRouter,
  type PageRouteMatch,
} from '@hilos/core'

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
  const connection = {
    on(event: string, listener: (signal: never) => void): () => void {
      if (event === 'projectSignal') headers.push(listener as never)
      if (event === 'tableWindow') windows.push(listener as never)
      return () => {}
    },
    registerTableWindow(): void {},
    unregisterTableWindow(): void {},
    tableWindowDescriptors: () => ({}),
    sendTableViewport(): void {},
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
  it('draws the shared bar, node filter, type toggle, footer, and narrow card from the header', () => {
    const page = harness()
    page.header({ available: true, nodes: ['node-1'] })
    page.window([
      {
        rowKey: 'node-1:worker-0.log',
        key: 'worker-0.log',
        node: 'node-1',
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
