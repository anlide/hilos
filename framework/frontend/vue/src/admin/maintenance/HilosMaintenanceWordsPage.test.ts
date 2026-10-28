import { mount } from '@vue/test-utils'
import { markRaw, nextTick } from 'vue'
import { afterEach, describe, expect, it } from 'vitest'
import { HilosPages, ScopeManager, createSignal } from '@hilos/core/base'
import type {
  ActionLifecycle,
  HilosConnection,
  HilosPageIdentity,
  HilosRouter,
  PageRouteMatch,
} from '@hilos/core/base'
import {
  HILOS_MAINTENANCE_WORDS_COPY,
  HILOS_MAINTENANCE_WORDS_TABLE,
  type HilosMaintenanceContext,
} from '@hilos/core/admin/maintenance'

import HilosMaintenanceWordsPage from './HilosMaintenanceWordsPage.vue'
import { hilosRouterKey } from '../../hilosRouterKey.js'

function router(): HilosRouter {
  return {
    currentRoute: createSignal<PageRouteMatch>({
      page: HilosPages.MAINTENANCE_WORDS,
      params: {},
      admin: true,
    }),
    currentPath: createSignal('/hilos/maintenance/words'),
    currentTitle: createSignal(''),
    pageError: createSignal(null),
    pageLoading: createSignal(false),
    pageIdentity: createSignal<HilosPageIdentity | undefined>({
      label: 'Words',
      lead: 'Words used to announce maintenance on visitor and admin screens.',
      breadcrumb: [
        { page: HilosPages.DASHBOARD, label: 'Hilos' },
        { page: HilosPages.MAINTENANCE, label: 'Maintenance' },
      ],
      children: [],
      pageHeadOwner: {
        page: HilosPages.MAINTENANCE,
        label: 'Maintenance',
        lead: 'Node freeze under a destructive operation and manual maintenance: its state, the verifier circle, closing the system to visitors, and the way out of the window.',
        icon: 'bi-tools',
      },
      pageTabs: [
        { page: HilosPages.MAINTENANCE, label: 'Overview' },
        { page: HilosPages.MAINTENANCE_CIRCLE, label: 'Circle' },
        { page: HilosPages.MAINTENANCE_WORDS, label: 'Words' },
      ],
    }),
    dashboardSections: createSignal(undefined),
    resolvePath: (page) =>
      (
        ({
          [HilosPages.DASHBOARD]: '/hilos',
          [HilosPages.MAINTENANCE]: '/hilos/maintenance',
          [HilosPages.MAINTENANCE_CIRCLE]: '/hilos/maintenance/circle',
          [HilosPages.MAINTENANCE_WORDS]: '/hilos/maintenance/words',
        }) as Record<string, string>
      )[page],
    clearPageError: () => {},
    denyCurrentPage: () => {},
    awaitPageAnswer: () => {},
    onLeave: () => () => {},
    navigate: () => {},
    replacePath: () => {},
    start: () => {},
    stop: () => {},
  }
}

interface WordsStub {
  operation: string
  title?: string | null
  message?: string | null
  bannerMessage?: string | null
  usesDefault?: boolean
}

function wireRow(stub: WordsStub): {
  rowKey: string
  slots: Record<string, unknown>
} {
  return {
    rowKey: stub.operation,
    slots: {
      stub: {
        operation: stub.operation,
        title: stub.title ?? null,
        message: stub.message ?? null,
        bannerMessage: stub.bannerMessage ?? null,
        usesDefault: stub.usesDefault ?? false,
      },
    },
  }
}

function seededContext(initial: WordsStub[] = []): {
  context: HilosMaintenanceContext
  registered: Set<string>
  activeListeners: () => number
  serveWindow: (rows: WordsStub[]) => void
} {
  const rows = initial.slice()
  const scopes = new ScopeManager()
  scopes.openPage(HilosPages.MAINTENANCE_WORDS)
  const listeners = new Map<
    string,
    Set<(signal: { type?: string; data: unknown }) => void>
  >()
  const registered = new Set<string>()

  const emit = (
    event: string,
    payload: { type?: string; data: unknown },
  ): void => {
    for (const listener of [...(listeners.get(event) ?? [])]) {
      listener(payload)
    }
  }

  const serveWindow = (nextRows: WordsStub[]): void => {
    emit('projectSignal', {
      type: 'page_response',
      data: {
        page: HilosPages.MAINTENANCE_WORDS,
        payload: {
          windows: {
            [HILOS_MAINTENANCE_WORDS_TABLE]: {
              rows: nextRows.map(wireRow),
              totalCount: nextRows.length,
              totalExact: true,
              firstAnchor: null,
              lastAnchor: null,
              offset: 0,
              limit: 10,
            },
          },
        },
      },
    })
  }

  const connection = {
    projectTimeZone: 'UTC',
    on(
      event: string,
      listener: (signal: { type?: string; data: unknown }) => void,
    ): () => void {
      const bucket = listeners.get(event) ?? new Set()
      listeners.set(event, bucket)
      bucket.add(listener)

      return () => {
        bucket.delete(listener)
      }
    },
    registerTableWindow(tableKey: string): void {
      registered.add(tableKey)
      serveWindow(rows)
    },
    unregisterTableWindow(tableKey: string): void {
      registered.delete(tableKey)
    },
    sendTableViewport(): boolean {
      return true
    },
    sendTableRendered(): boolean {
      return true
    },
  }

  const actions = {
    dispatch() {
      throw new Error('No actions in Words')
    },
  } as unknown as ActionLifecycle

  return {
    context: {
      connection: connection as unknown as HilosConnection,
      scopes,
      actions,
    },
    registered,
    activeListeners: () => {
      let count = 0
      for (const bucket of listeners.values()) {
        count += bucket.size
      }
      return count
    },
    serveWindow,
  }
}

const mounted: ReturnType<typeof mount>[] = []

afterEach(() => {
  for (const wrapper of mounted.splice(0)) {
    wrapper.unmount()
  }
})

async function mountPage(context: HilosMaintenanceContext) {
  const wrapper = mount(HilosMaintenanceWordsPage, {
    props: { context: markRaw(context) },
    attachTo: document.body,
    global: { provide: { [hilosRouterKey as symbol]: router() } },
  })
  mounted.push(wrapper)
  await nextTick()

  return wrapper
}

describe('HilosMaintenanceWordsPage', () => {
  it('mounts with the router harness and sets up table and navigation attributes', async () => {
    const { context } = seededContext([])
    const wrapper = await mountPage(context)

    expect(
      wrapper.find('[data-id="hilos-maintenance-words-table"]').exists(),
    ).toBe(true)

    const breadcrumb = document.querySelector('[data-id="hilos-breadcrumb"]')
    expect(breadcrumb?.textContent).toContain('Maintenance')

    const activeTab = document.querySelector(
      '[data-id="hilos-page-tabs"] [aria-current="page"]',
    )
    expect(activeTab?.textContent?.trim()).toBe('Words')

    const title = document.querySelector('[data-id="hilos-table-title"]')
    expect(title?.textContent?.trim()).toBe(HILOS_MAINTENANCE_WORDS_COPY.title)
  })

  it('renders an ordinary row with operation, title, message, bannerMessage, and operation span', async () => {
    const { context } = seededContext([
      {
        operation: 'manual_maintenance',
        title: 'Maintenance in progress',
        message: 'The system is currently undergoing planned maintenance.',
        bannerMessage: 'Maintenance underway',
        usesDefault: false,
      },
    ])
    await mountPage(context)

    const operationSpan = document.querySelector(
      'table [data-id="hilos-maintenance-words-row-manual_maintenance"]',
    )
    expect(operationSpan).not.toBeNull()
    expect(operationSpan?.textContent?.trim()).toBe('manual_maintenance')

    const row = document.querySelector('table tbody tr')
    expect(row).not.toBeNull()
    expect(row?.textContent).toContain('Maintenance in progress')
    expect(row?.textContent).toContain(
      'The system is currently undergoing planned maintenance.',
    )
    expect(row?.textContent).toContain('Maintenance underway')
  })

  it('renders a fallback row with colspan merged tail and default copy', async () => {
    const { context } = seededContext([
      {
        operation: 'backup',
        title: null,
        message: null,
        bannerMessage: null,
        usesDefault: true,
      },
    ])
    await mountPage(context)

    const operationSpan = document.querySelector(
      'table [data-id="hilos-maintenance-words-row-backup"]',
    )
    expect(operationSpan).not.toBeNull()
    expect(operationSpan?.textContent?.trim()).toBe('backup')

    const messageCell = document.querySelector(
      'table [data-id="hilos-table-row-message"]',
    )
    expect(messageCell).not.toBeNull()
    expect(messageCell?.getAttribute('colspan')).toBe('3')
    expect(messageCell?.textContent?.trim()).toBe(
      HILOS_MAINTENANCE_WORDS_COPY.noOwnEntry,
    )
  })

  it('binds table on mount and disposes listeners and table registration on unmount', async () => {
    const { context, registered, activeListeners } = seededContext([])
    expect(registered.has(HILOS_MAINTENANCE_WORDS_TABLE)).toBe(false)
    expect(activeListeners()).toBe(0)

    const wrapper = await mountPage(context)
    expect(registered.has(HILOS_MAINTENANCE_WORDS_TABLE)).toBe(true)
    expect(activeListeners()).toBeGreaterThan(0)

    wrapper.unmount()
    expect(registered.has(HILOS_MAINTENANCE_WORDS_TABLE)).toBe(false)
    expect(activeListeners()).toBe(0)
  })
})
