import { TestBed, type ComponentFixture } from '@angular/core/testing'
import { By } from '@angular/platform-browser'
import {
  ActionLifecycle,
  HIDDEN_VALUE,
  HilosPages,
  LOGS_FOLLOW_START_ACTION,
  LOG_VIEWER_CATALOG_SIGNAL,
  ScopeManager,
  bindAdminAccess,
  bindSessionScope,
  createSignal,
  type HilosConnection,
  type HilosLogViewerCatalog,
  type HilosRouter,
  type PageRouteMatch,
  type ProjectSignal,
} from '@hilos/core'
import { afterEach, describe, expect, it } from 'vitest'

import { HilosLogsViewPage } from '../src/admin/logs/HilosLogsViewPage.js'
import { HILOS_ROUTER } from '../src/hilosRouterToken.js'

const releases: (() => void)[] = []

afterEach(() => {
  for (const release of releases.splice(0)) release()
})

/** The address can change while this same page remains mounted. */
function router(params: PageRouteMatch['params']) {
  const currentRoute = createSignal<PageRouteMatch>({
    page: HilosPages.LOGS_VIEW,
    params,
    admin: true,
  })
  const address = {
    currentRoute,
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
  return { address, currentRoute }
}

/** A live file catalog, with the no-name node of a single-node installation. */
const CATALOG: HilosLogViewerCatalog = {
  available: true,
  clusterEnabled: false,
  nodes: [
    {
      nodeId: 'standalone',
      available: true,
      batches: [],
      streams: [
        {
          key: 'worker-0.log',
          class: 'worker',
          live: true,
          batchTimestamps: [],
        },
      ],
    },
  ],
}

/** The app's session binding, fed a guest handshake with view mode on. */
function bindViewerSession(): void {
  const listeners: ((signal: ProjectSignal) => void)[] = []
  const connection = {
    on(event: string, listener: (signal: never) => void): () => void {
      if (event === 'projectSignal')
        listeners.push(listener as (signal: ProjectSignal) => void)
      return () => {}
    },
  } as unknown as HilosConnection
  const scopes = new ScopeManager()
  bindSessionScope(connection, scopes)
  releases.push(bindAdminAccess(scopes))
  const handshake = {
    kind: 'project',
    type: 'handshake_response',
    data: { entities: { currentUser: null }, data: { adminViewMode: true } },
    envelope: {},
  } as unknown as ProjectSignal
  for (const listener of listeners) listener(handshake)
}

/** A socket that can answer the first follow with one hidden entry. */
function makeConnection(): {
  connection: HilosConnection
  pushCatalog: (catalog?: HilosLogViewerCatalog) => void
  answerFollow: () => void
} {
  const listeners: Record<string, ((signal: unknown) => void)[]> = {}
  const sent: { action: string; requestId?: string }[] = []
  const connection = {
    on(event: string, listener: (signal: never) => void): () => void {
      listeners[event] = [
        ...(listeners[event] ?? []),
        listener as (signal: unknown) => void,
      ]
      return () => {}
    },
    sendAction(action: string, _data: unknown, requestId?: string): boolean {
      sent.push({ action, requestId })
      return true
    },
  } as unknown as HilosConnection

  releases.push(() => {
    for (const listener of listeners['state'] ?? []) listener('disconnected')
  })

  return {
    connection,
    pushCatalog(catalog = CATALOG): void {
      for (const listener of listeners['projectSignal'] ?? []) {
        listener({ type: LOG_VIEWER_CATALOG_SIGNAL, data: catalog })
      }
    },
    answerFollow(): void {
      const requestId = sent
        .filter((entry) => entry.action === LOGS_FOLLOW_START_ACTION)
        .at(-1)?.requestId
      expect(requestId).toBeDefined()
      for (const listener of listeners['actionSuccess'] ?? []) {
        listener({
          kind: 'actionSuccess',
          action: LOGS_FOLLOW_START_ACTION,
          requestId,
          reply: {
            readable: true,
            lines: [
              {
                time: '2026-09-06 10:00:02.250',
                text: HIDDEN_VALUE,
                level: 'ERROR',
                isContinuation: false,
              },
              {
                time: null,
                text: HIDDEN_VALUE,
                level: 'ERROR',
                isContinuation: true,
              },
            ],
            nextCursor: null,
            hasMore: false,
          },
          envelope: { type: 'action_success', data: {} },
        })
      }
    },
  }
}

/** Two nodes and two files: each nonfirst choice must survive a late catalog. */
const BATCH = 1_786_000_000
const CLUSTER_CATALOG: HilosLogViewerCatalog = {
  available: true,
  clusterEnabled: true,
  nodes: ['alpha', 'beta'].map((nodeId) => ({
    nodeId,
    available: true,
    batches: [BATCH, BATCH + 60],
    streams: ['daemon.log', 'worker-0.log'].map((key) => ({
      key,
      class: 'worker',
      live: true,
      batchTimestamps: [BATCH, BATCH + 60],
    })),
  })),
}

/** Mount without a catalog, so the first render precedes its options. */
function mountPage(params: PageRouteMatch['params']) {
  const { address, currentRoute } = router(params)
  const socket = makeConnection()
  const actions = new ActionLifecycle(
    socket.connection as unknown as ConstructorParameters<
      typeof ActionLifecycle
    >[0],
  )
  TestBed.configureTestingModule({
    providers: [{ provide: HILOS_ROUTER, useValue: address }],
  })
  const fixture = TestBed.createComponent(HilosLogsViewPage)
  fixture.componentRef.setInput('context', {
    connection: socket.connection,
    actions,
  })
  fixture.detectChanges()

  return { ...socket, fixture, currentRoute }
}

/** Assert the browser's choice, including the single selected option. */
function selected(
  fixture: ComponentFixture<HilosLogsViewPage>,
  id: string,
  value: string,
): HTMLSelectElement {
  const control = (
    fixture.nativeElement as HTMLElement
  ).querySelector<HTMLSelectElement>(`[data-id="${id}"]`)
  expect(control).not.toBeNull()
  expect(control!.value).toBe(value)
  expect([...control!.selectedOptions].map((option) => option.value)).toEqual([
    value,
  ])
  return control!
}

/** Feed the ordinary change handler without assigning the DOM value under test. */
async function change(
  fixture: ComponentFixture<HilosLogsViewPage>,
  id: string,
  value: string,
): Promise<void> {
  fixture.debugElement
    .query(By.css(`[data-id="${id}"]`))
    .triggerEventHandler('change', { target: { value } })
  fixture.detectChanges()
  await fixture.whenStable()
  fixture.detectChanges()
}

describe('HilosLogsViewPage', () => {
  it('shows a hidden entry with its time and level and leaves following available', async () => {
    bindViewerSession()
    const { fixture, pushCatalog, answerFollow } = mountPage({
      nodeId: '-',
      source: 'live',
      stream: 'worker-0.log',
    })
    pushCatalog()
    fixture.detectChanges()
    answerFollow()
    await fixture.whenStable()
    fixture.detectChanges()

    const root = fixture.nativeElement as HTMLElement
    const entry = root.querySelector('[data-id="hilos-log-entry"]')
    expect(entry?.textContent).toContain('10:00:02.250')
    expect(entry?.textContent).toContain('ERROR')
    expect(entry?.querySelector('[data-id="hilos-hidden"]')).not.toBeNull()
    expect(
      entry?.querySelector('[data-id="hilos-log-stack-toggle"]'),
    ).toBeNull()
    for (const id of ['hilos-log-substring', 'hilos-log-search']) {
      const control = root.querySelector(`[data-id="${id}"]`)
      expect(control?.hasAttribute('disabled')).toBe(true)
      expect(control?.getAttribute('aria-describedby')).toBe(
        'hilos-view-mode-strip-text',
      )
    }
    expect(
      root
        .querySelector('[data-id="hilos-log-follow"]')
        ?.hasAttribute('disabled'),
    ).toBe(false)
  })
})

describe('HilosLogsViewPage native selections', () => {
  it.each<{
    name: string
    params: PageRouteMatch['params']
    id: string
    value: string
  }>([
    {
      name: 'live source',
      params: { nodeId: 'beta', source: 'live' },
      id: 'hilos-log-source',
      value: 'live',
    },
    {
      name: 'numeric archive source',
      params: { nodeId: 'beta', source: String(BATCH) },
      id: 'hilos-log-source',
      value: String(BATCH),
    },
    {
      name: 'explicit nonfirst stream',
      params: { nodeId: 'beta', source: 'live', stream: 'worker-0.log' },
      id: 'hilos-log-stream',
      value: 'worker-0.log',
    },
    {
      name: 'second node',
      params: { nodeId: 'beta', source: 'live' },
      id: 'hilos-log-node',
      value: 'beta',
    },
  ])(
    'renders the $name from a direct address after the catalog arrives',
    async ({ params, id, value }) => {
      const h = mountPage(params)
      h.pushCatalog(CLUSTER_CATALOG)
      await h.fixture.whenStable()
      h.fixture.detectChanges()
      selected(h.fixture, id, value)
    },
  )

  it('selects late options and keeps their keys when the catalog is replaced', async () => {
    const h = mountPage({
      nodeId: 'beta',
      source: String(BATCH),
      stream: 'worker-0.log',
    })
    h.pushCatalog({ ...CLUSTER_CATALOG, nodes: [] })
    await h.fixture.whenStable()
    h.fixture.detectChanges()
    h.pushCatalog(CLUSTER_CATALOG)
    await h.fixture.whenStable()
    h.fixture.detectChanges()
    selected(h.fixture, 'hilos-log-node', 'beta')
    selected(h.fixture, 'hilos-log-source', String(BATCH))
    selected(h.fixture, 'hilos-log-stream', 'worker-0.log')

    h.pushCatalog({
      ...CLUSTER_CATALOG,
      nodes: CLUSTER_CATALOG.nodes.map((node) => ({
        ...node,
        batches: [...node.batches, BATCH + 120],
        streams: [
          {
            key: 'new.log',
            class: 'worker',
            live: true,
            batchTimestamps: [BATCH],
          },
          ...node.streams,
        ],
      })),
    })
    await h.fixture.whenStable()
    h.fixture.detectChanges()
    selected(h.fixture, 'hilos-log-node', 'beta')
    selected(h.fixture, 'hilos-log-source', String(BATCH))
    selected(h.fixture, 'hilos-log-stream', 'worker-0.log')
  })

  it('leaves an unchosen node and stream on their prompts', async () => {
    const h = mountPage({})
    h.pushCatalog(CLUSTER_CATALOG)
    await h.fixture.whenStable()
    h.fixture.detectChanges()
    expect(
      selected(h.fixture, 'hilos-log-node', '').selectedOptions[0]?.disabled,
    ).toBe(true)
    expect(
      selected(h.fixture, 'hilos-log-stream', '').selectedOptions[0]
        ?.textContent,
    ).toContain('Choose a stream')

    await change(h.fixture, 'hilos-log-node', 'beta')
    selected(h.fixture, 'hilos-log-node', 'beta')
    selected(h.fixture, 'hilos-log-source', 'live')
    selected(h.fixture, 'hilos-log-stream', '')
  })

  it('renders changes of node, source, stream and level, then follows another address', async () => {
    const h = mountPage({ nodeId: 'alpha', source: 'live' })
    h.pushCatalog(CLUSTER_CATALOG)
    await h.fixture.whenStable()
    h.fixture.detectChanges()
    await change(h.fixture, 'hilos-log-node', 'beta')
    await change(h.fixture, 'hilos-log-source', String(BATCH))
    await change(h.fixture, 'hilos-log-stream', 'worker-0.log')
    await change(h.fixture, 'hilos-log-level', 'ERROR')
    selected(h.fixture, 'hilos-log-node', 'beta')
    selected(h.fixture, 'hilos-log-source', String(BATCH))
    selected(h.fixture, 'hilos-log-stream', 'worker-0.log')
    selected(h.fixture, 'hilos-log-level', 'ERROR')

    h.currentRoute.set({
      page: HilosPages.LOGS_VIEW,
      admin: true,
      params: { nodeId: 'alpha', source: 'live', stream: 'daemon.log' },
    })
    await h.fixture.whenStable()
    h.fixture.detectChanges()
    selected(h.fixture, 'hilos-log-node', 'alpha')
    selected(h.fixture, 'hilos-log-source', 'live')
    selected(h.fixture, 'hilos-log-stream', 'daemon.log')
    await change(h.fixture, 'hilos-log-stream', '')
    selected(h.fixture, 'hilos-log-stream', '')
  })
})
