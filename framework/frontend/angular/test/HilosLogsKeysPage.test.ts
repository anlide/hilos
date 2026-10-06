import { TestBed } from '@angular/core/testing'
import { afterEach, describe, expect, it } from 'vitest'
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
  const fixture = TestBed.createComponent(HilosLogsKeysPage)
  const scopes = new ScopeManager()
  scopes.openPage(HilosPages.LOGS_KEYS)
  fixture.componentRef.setInput('context', { connection, scopes })
  fixture.detectChanges()

  return {
    fixture,
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
  it('draws the shared bar, node filter, footer, and narrow card from the header', () => {
    const page = harness()
    page.header({ available: true, nodes: ['node-1'] })
    page.window([
      {
        rowKey: 'node-1:worker-0.log',
        key: 'worker-0.log',
        node: 'node-1',
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
