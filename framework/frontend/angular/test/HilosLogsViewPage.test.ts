import { TestBed, type ComponentFixture } from '@angular/core/testing'
import { By } from '@angular/platform-browser'
import {
  ActionLifecycle,
  HIDDEN_VALUE,
  HILOS_ROUTE_DECLARATIONS,
  HilosPages,
  LOGS_FOLLOW_START_ACTION,
  LOGS_READ_LINES_ACTION,
  LOG_LINES_APPENDED_SIGNAL,
  LOG_VIEWER_CATALOG_SIGNAL,
  ScopeManager,
  bindAdminAccess,
  bindSessionScope,
  createPageRouter,
  createSignal,
  type HilosConnection,
  type HilosLogViewerCatalog,
  type HilosRouter,
  type PageRouteMatch,
  type ProjectSignal,
} from '@hilos/core'
import { afterEach, describe, expect, it, vi } from 'vitest'

import { HilosLogsViewPage } from '../src/admin/logs/HilosLogsViewPage.js'
import { HILOS_ROUTER } from '../src/hilosRouterToken.js'

const releases: (() => void)[] = []

afterEach(() => {
  for (const release of releases.splice(0)) release()
})

/** The address can change while this same page remains mounted. */
function router(params: PageRouteMatch['params']) {
  const routes = createPageRouter(HILOS_ROUTE_DECLARATIONS)
  const path = routes.path(HilosPages.LOGS_VIEW, params) ?? ''
  const currentRoute = createSignal(routes.match(path))
  const currentPath = createSignal(path)
  const publish = (nextPath: string): void => {
    currentRoute.set(routes.match(nextPath))
    currentPath.set(nextPath)
  }
  const address = {
    currentRoute,
    currentPath,
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
    navigate: vi.fn(publish),
    replacePath: vi.fn(publish),
    start: () => {},
    stop: () => {},
  } satisfies HilosRouter
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

/** A socket carrying catalogs, read replies and appended lines to the page. */
function makeConnection(): {
  connection: HilosConnection
  sent: { action: string; data: unknown; requestId?: string }[]
  pushCatalog: (catalog?: HilosLogViewerCatalog) => void
  answer: (requestId: string | undefined, reply: unknown) => void
  refuse: (requestId: string | undefined, reason: string) => void
  pushAppended: (followId: string, text: string | typeof HIDDEN_VALUE) => void
  answerFollow: () => void
} {
  const listeners: Record<string, ((signal: unknown) => void)[]> = {}
  const sent: { action: string; data: unknown; requestId?: string }[] = []
  const connection = {
    on(event: string, listener: (signal: never) => void): () => void {
      listeners[event] = [
        ...(listeners[event] ?? []),
        listener as (signal: unknown) => void,
      ]
      return () => {}
    },
    sendAction(action: string, data: unknown, requestId?: string): boolean {
      sent.push({ action, data, requestId })
      return true
    },
  } as unknown as HilosConnection

  releases.push(() => {
    for (const listener of listeners['state'] ?? []) listener('disconnected')
  })

  return {
    connection,
    sent,
    pushCatalog(catalog = CATALOG): void {
      for (const listener of listeners['projectSignal'] ?? []) {
        listener({ type: LOG_VIEWER_CATALOG_SIGNAL, data: catalog })
      }
    },
    refuse(requestId: string | undefined, reason: string): void {
      const action = sent.find((frame) => frame.requestId === requestId)?.action
      expect(action).toBeDefined()
      for (const listener of listeners['actionError'] ?? []) {
        listener({
          kind: 'actionError',
          action,
          requestId,
          reason,
          envelope: { type: 'action_error', data: {} },
        })
      }
    },
    answer(requestId: string | undefined, reply: unknown): void {
      const action = sent.find((frame) => frame.requestId === requestId)?.action
      expect(action).toBeDefined()
      for (const listener of listeners['actionSuccess'] ?? []) {
        listener({
          kind: 'actionSuccess',
          action,
          requestId,
          reply,
          envelope: { type: 'action_success', data: {} },
        })
      }
    },
    pushAppended(followId: string, text: string | typeof HIDDEN_VALUE): void {
      for (const listener of listeners['projectSignal'] ?? []) {
        listener({
          type: LOG_LINES_APPENDED_SIGNAL,
          data: {
            followId,
            lines: [
              {
                time: '2026-09-06 10:00:02.250',
                text,
                level: 'INFO',
                isContinuation: false,
              },
            ],
            rotated: false,
            skippedBytes: null,
            stopped: false,
          },
        })
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

/** A full live address whose stream initially exists only in the archive. */
const ARCHIVED_LIVE_FILE = {
  nodeId: 'beta',
  source: 'live',
  stream: 'worker-0.log',
}

/** Keep the catalog honest: the selected stream has no live file. */
function archivedStreamCatalog(): HilosLogViewerCatalog {
  return {
    ...CLUSTER_CATALOG,
    nodes: CLUSTER_CATALOG.nodes.map((node) => ({
      ...node,
      streams: node.streams.map((stream) => ({
        ...stream,
        live: stream.key !== ARCHIVED_LIVE_FILE.stream,
      })),
    })),
  }
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

  return { ...socket, fixture, currentRoute, address }
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

/** Flush the core mirrors and render their Angular options and pane. */
async function settled(
  fixture: ComponentFixture<HilosLogsViewPage>,
): Promise<void> {
  fixture.detectChanges()
  await fixture.whenStable()
  fixture.detectChanges()
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

describe('HilosLogsViewPage missing selections', () => {
  it.each([true, false])(
    'keeps the missing live name selected with known archive=%s',
    async (hasArchive) => {
      const h = mountPage(ARCHIVED_LIVE_FILE)
      selected(h.fixture, 'hilos-log-stream', 'worker-0.log')
      const picture = archivedStreamCatalog()
      if (!hasArchive) {
        picture.nodes[1].batches = []
        picture.nodes[1].streams[1].batchTimestamps = []
      }
      h.pushCatalog(picture)
      h.answer(h.sent.at(-1)?.requestId, {
        readable: false,
        lines: [],
        nextCursor: null,
        hasMore: false,
      })
      await settled(h.fixture)

      const stream = selected(h.fixture, 'hilos-log-stream', 'worker-0.log')
      expect([...stream.options].map((option) => option.value)).toEqual([
        '',
        'daemon.log',
        'worker-0.log',
      ])
      const root = h.fixture.nativeElement as HTMLElement
      expect(
        root.querySelector('[data-id="hilos-log-empty-missing"]')?.textContent,
      ).toContain('This file cannot be read.')
      expect(
        root.querySelector('[data-id="hilos-log-empty-unchosen"]'),
      ).toBeNull()
      expect(
        root.querySelector('[data-id="hilos-log-latest-batch"]') !== null,
      ).toBe(hasArchive)
      selected(h.fixture, 'hilos-log-source', 'live')
      expect(h.address.currentPath.get()).toBe(
        '/hilos/logs/view/beta/live/worker-0.log',
      )
      expect(h.address.navigate).not.toHaveBeenCalled()
      expect(h.address.replacePath).not.toHaveBeenCalled()
    },
  )

  it.each([false, true])(
    'opens the latest batch only on a link click (view mode: %s)',
    async (viewMode) => {
      if (viewMode) bindViewerSession()
      const h = mountPage(ARCHIVED_LIVE_FILE)
      const livePath = '/hilos/logs/view/beta/live/worker-0.log'
      h.pushCatalog(archivedStreamCatalog())
      h.answer(h.sent.at(-1)?.requestId, {
        readable: false,
        lines: [],
        nextCursor: null,
        hasMore: false,
      })
      await settled(h.fixture)

      selected(h.fixture, 'hilos-log-stream', 'worker-0.log')
      const root = h.fixture.nativeElement as HTMLElement
      expect(
        root.querySelector('[data-id="hilos-log-empty-missing"]'),
      ).not.toBeNull()
      expect(
        root.querySelector('[data-id="hilos-log-empty-unchosen"]'),
      ).toBeNull()
      const link = root.querySelector<HTMLAnchorElement>(
        '[data-id="hilos-log-latest-batch"]',
      )!
      const latestBatch = BATCH + 60
      const batchPath = `/hilos/logs/view/beta/${latestBatch}/worker-0.log`
      expect(link.tagName).toBe('A')
      expect(link.textContent?.trim()).toBe('Open latest batch')
      expect(link.getAttribute('href')).toBe(batchPath)
      expect(link.getAttribute('aria-disabled')).toBeNull()
      expect(link.tabIndex).toBe(0)
      expect(h.address.navigate).not.toHaveBeenCalled()
      expect(h.address.replacePath).not.toHaveBeenCalled()
      expect(h.address.currentPath.get()).toBe(livePath)

      link.click()
      await settled(h.fixture)

      expect(h.address.navigate).toHaveBeenCalledExactlyOnceWith(batchPath)
      expect(h.address.currentPath.get()).toBe(batchPath)
      const source = selected(
        h.fixture,
        'hilos-log-source',
        String(latestBatch),
      )
      expect(source.selectedOptions[0].textContent?.trim()).toBe(
        `Batch — ${new Date(latestBatch * 1000).toLocaleString()}`,
      )
      expect(h.sent.at(-1)?.action).toBe(LOGS_READ_LINES_ACTION)
      expect(h.sent.at(-1)?.data).toMatchObject({
        nodeId: 'beta',
        source: 'batch',
        batchTimestamp: latestBatch,
        stream: 'worker-0.log',
      })
      expect(
        root.querySelector<HTMLInputElement>('[data-id="hilos-log-follow"]')
          ?.disabled,
      ).toBe(true)
      expect(
        h.sent.filter((frame) => frame.action === LOGS_FOLLOW_START_ACTION),
      ).toHaveLength(1)
      expect(
        root.querySelector('[data-id="hilos-log-latest-batch"]'),
      ).toBeNull()

      h.answer(h.sent.at(-1)?.requestId, {
        readable: true,
        lines: [
          {
            time: null,
            text: viewMode ? HIDDEN_VALUE : 'Archived line.',
            level: 'INFO',
            isContinuation: false,
          },
        ],
        nextCursor: null,
        hasMore: false,
      })
      await settled(h.fixture)
      if (viewMode) {
        expect(root.querySelector('[data-id="hilos-hidden"]')).not.toBeNull()
        expect(root.textContent).not.toContain('Archived line.')
        for (const id of ['hilos-log-substring', 'hilos-log-search']) {
          expect(
            root.querySelector(`[data-id="${id}"]`)?.hasAttribute('disabled'),
          ).toBe(true)
        }
      } else {
        expect(
          root.querySelector('[data-id="hilos-log-entry"]')?.textContent,
        ).toContain('Archived line.')
      }

      await change(h.fixture, 'hilos-log-source', 'live')
      selected(h.fixture, 'hilos-log-source', 'live')
      selected(h.fixture, 'hilos-log-stream', 'worker-0.log')
      expect(h.address.currentPath.get()).toBe(livePath)
      expect(h.sent.at(-1)?.action).toBe(LOGS_FOLLOW_START_ACTION)
      expect(h.sent.at(-1)?.data).toMatchObject({
        nodeId: 'beta',
        stream: 'worker-0.log',
      })
    },
  )

  it('keeps a full unknown stream through a late catalog without guessing a file', async () => {
    const h = mountPage({ ...ARCHIVED_LIVE_FILE, stream: 'unknown.log' })
    selected(h.fixture, 'hilos-log-stream', 'unknown.log')
    h.answer(h.sent.at(-1)?.requestId, {
      readable: false,
      lines: [],
      nextCursor: null,
      hasMore: false,
    })
    await settled(h.fixture)
    const root = h.fixture.nativeElement as HTMLElement
    expect(
      root.querySelector('[data-id="hilos-log-empty-missing"]'),
    ).not.toBeNull()

    h.pushCatalog(archivedStreamCatalog())
    await settled(h.fixture)

    const stream = selected(h.fixture, 'hilos-log-stream', 'unknown.log')
    expect([...stream.options].map((option) => option.value)).toEqual([
      '',
      'daemon.log',
      'unknown.log',
    ])
    expect(
      root.querySelector('[data-id="hilos-log-empty-missing"]'),
    ).not.toBeNull()
    expect(
      root.querySelector('[data-id="hilos-log-empty-unchosen"]'),
    ).toBeNull()
    expect(root.querySelector('[data-id="hilos-log-latest-batch"]')).toBeNull()
    expect(h.address.currentPath.get()).toBe(
      '/hilos/logs/view/beta/live/unknown.log',
    )
    expect(h.address.navigate).not.toHaveBeenCalled()
    expect(h.address.replacePath).not.toHaveBeenCalled()
  })

  it.each([false, true])(
    'restores live lines after a late catalog without duplicate options in view mode=%s',
    async (viewMode) => {
      if (viewMode) bindViewerSession()
      const h = mountPage(ARCHIVED_LIVE_FILE)
      const followId = h.sent.at(-1)?.requestId ?? ''
      selected(h.fixture, 'hilos-log-stream', 'worker-0.log')
      h.answer(followId, {
        readable: false,
        lines: [],
        nextCursor: null,
        hasMore: false,
      })
      await settled(h.fixture)
      const root = h.fixture.nativeElement as HTMLElement
      expect(
        root.querySelector('[data-id="hilos-log-latest-batch"]'),
      ).toBeNull()
      h.pushCatalog(archivedStreamCatalog())
      await settled(h.fixture)
      expect(
        root.querySelector('[data-id="hilos-log-empty-missing"]'),
      ).not.toBeNull()
      expect(
        root.querySelector('[data-id="hilos-log-empty-unchosen"]'),
      ).toBeNull()
      selected(h.fixture, 'hilos-log-stream', 'worker-0.log')
      expect(
        root
          .querySelector('[data-id="hilos-log-latest-batch"]')
          ?.getAttribute('href'),
      ).toBe(`/hilos/logs/view/beta/${BATCH + 60}/worker-0.log`)
      expect(h.address.currentPath.get()).toBe(
        '/hilos/logs/view/beta/live/worker-0.log',
      )
      expect(h.address.navigate).not.toHaveBeenCalled()
      expect(h.address.replacePath).not.toHaveBeenCalled()

      h.pushCatalog(CLUSTER_CATALOG)
      h.pushAppended(
        followId,
        viewMode ? HIDDEN_VALUE : 'The live file now exists.',
      )
      await settled(h.fixture)

      const stream = selected(h.fixture, 'hilos-log-stream', 'worker-0.log')
      expect([...stream.options].map((option) => option.value)).toEqual([
        '',
        'daemon.log',
        'worker-0.log',
      ])
      const entry = root.querySelector('[data-id="hilos-log-entry"]')
      expect(entry).not.toBeNull()
      if (viewMode) {
        expect(entry?.querySelector('[data-id="hilos-hidden"]')).not.toBeNull()
        expect(root.textContent).not.toContain('The live file now exists.')
        for (const id of ['hilos-log-substring', 'hilos-log-search']) {
          expect(
            root.querySelector(`[data-id="${id}"]`)?.hasAttribute('disabled'),
          ).toBe(true)
        }
      } else {
        expect(entry?.textContent).toContain('The live file now exists.')
      }
      expect(
        root.querySelector('[data-id="hilos-log-empty-missing"]'),
      ).toBeNull()
      expect(
        root.querySelector('[data-id="hilos-log-latest-batch"]'),
      ).toBeNull()
      expect(h.address.currentPath.get()).toBe(
        '/hilos/logs/view/beta/live/worker-0.log',
      )
      expect(h.address.navigate).not.toHaveBeenCalled()
      expect(h.address.replacePath).not.toHaveBeenCalled()
      expect(
        h.sent.filter((frame) => frame.action === LOGS_FOLLOW_START_ACTION),
      ).toHaveLength(1)
    },
  )

  describe.each(['select', 'route'] as const)(
    'after a missing file, %s',
    (navigation) => {
      it.each(['lines', 'silent', 'missing', 'refusal'] as const)(
        'waits for the selected file before showing its %s outcome',
        async (outcome) => {
          const h = mountPage(ARCHIVED_LIVE_FILE)
          h.pushCatalog(archivedStreamCatalog())
          const oldId = h.sent.at(-1)?.requestId ?? ''
          h.answer(oldId, {
            readable: false,
            lines: [],
            nextCursor: null,
            hasMore: false,
          })
          await settled(h.fixture)
          const root = h.fixture.nativeElement as HTMLElement
          expect(
            root.querySelector('[data-id="hilos-log-empty-missing"]'),
          ).not.toBeNull()
          expect(
            root
              .querySelector('[data-id="hilos-log-latest-batch"]')
              ?.getAttribute('href'),
          ).toBe(`/hilos/logs/view/beta/${BATCH + 60}/worker-0.log`)

          if (navigation === 'select') {
            const stream = selected(
              h.fixture,
              'hilos-log-stream',
              'worker-0.log',
            )
            stream.value = 'daemon.log'
            stream.dispatchEvent(new Event('change', { bubbles: true }))
          } else {
            h.address.navigate('/hilos/logs/view/beta/live/daemon.log')
          }
          await settled(h.fixture)
          const currentId = h.sent.at(-1)?.requestId ?? ''
          expect(currentId).not.toBe(oldId)
          expect(h.sent.at(-1)?.action).toBe(LOGS_FOLLOW_START_ACTION)
          selected(h.fixture, 'hilos-log-node', 'beta')
          selected(h.fixture, 'hilos-log-source', 'live')
          selected(h.fixture, 'hilos-log-stream', 'daemon.log')
          expect(
            root.querySelector<HTMLInputElement>('[data-id="hilos-log-follow"]')
              ?.checked,
          ).toBe(true)
          expect(
            root
              .querySelector('[data-id="hilos-log-earlier"]')
              ?.hasAttribute('disabled'),
          ).toBe(true)
          for (const id of [
            'hilos-log-empty-missing',
            'hilos-log-latest-batch',
            'hilos-log-refusal',
          ]) {
            expect(root.querySelector(`[data-id="${id}"]`)).toBeNull()
          }

          h.pushAppended(oldId, 'Old file frame.')
          await settled(h.fixture)
          expect(root.querySelector('[data-id="hilos-log-entry"]')).toBeNull()
          if (outcome === 'refusal') {
            h.refuse(currentId, 'The selected node is offline.')
          } else {
            h.answer(currentId, {
              readable: outcome !== 'missing',
              lines:
                outcome === 'lines'
                  ? [
                      {
                        time: null,
                        text: 'Selected file line.',
                        level: 'INFO',
                        isContinuation: false,
                      },
                    ]
                  : [],
              nextCursor: outcome === 'lines' ? 100 : null,
              hasMore: outcome === 'lines',
            })
          }
          await settled(h.fixture)
          selected(h.fixture, 'hilos-log-stream', 'daemon.log')
          expect(
            root.querySelector('[data-id="hilos-log-empty-missing"]') !== null,
          ).toBe(outcome === 'missing')
          expect(
            root.querySelector('[data-id="hilos-log-latest-batch"]') !== null,
          ).toBe(outcome === 'missing')
          expect(
            root.querySelector('[data-id="hilos-log-refusal"]') !== null,
          ).toBe(outcome === 'refusal')
          if (outcome === 'missing') {
            expect(
              root
                .querySelector('[data-id="hilos-log-latest-batch"]')
                ?.getAttribute('href'),
            ).toBe(`/hilos/logs/view/beta/${BATCH + 60}/daemon.log`)
          } else if (outcome === 'refusal') {
            expect(
              root
                .querySelector('[data-id="hilos-log-refusal"]')
                ?.textContent?.trim(),
            ).toBe('The selected node is offline.')
          } else if (outcome === 'lines') {
            expect(
              root.querySelector('[data-id="hilos-log-entry"]')?.textContent,
            ).toContain('Selected file line.')
            expect(
              root
                .querySelector('[data-id="hilos-log-earlier"]')
                ?.hasAttribute('disabled'),
            ).toBe(false)
          } else {
            expect(
              root.querySelector('[data-id="hilos-log-empty-silent"]'),
            ).not.toBeNull()
          }
          expect(root.textContent).not.toContain('Old file frame.')
        },
      )
    },
  )

  it('keeps node and batch without guessing a stream on a partial URL', async () => {
    const h = mountPage({ nodeId: 'beta', source: String(BATCH) })
    expect(
      selected(h.fixture, 'hilos-log-source', String(BATCH)).selectedOptions[0]
        ?.textContent,
    ).toContain('unavailable')
    h.pushCatalog(archivedStreamCatalog())
    await settled(h.fixture)

    expect(h.address.currentPath.get()).toBe(`/hilos/logs/view/beta/${BATCH}`)
    expect(
      selected(h.fixture, 'hilos-log-source', String(BATCH)).selectedOptions[0]
        ?.textContent,
    ).not.toContain('unavailable')
    selected(h.fixture, 'hilos-log-stream', '')
    const root = h.fixture.nativeElement as HTMLElement
    expect(
      root.querySelector('[data-id="hilos-log-empty-unchosen"]'),
    ).not.toBeNull()
    expect(root.querySelector('[data-id="hilos-log-latest-batch"]')).toBeNull()
    expect(h.sent).toEqual([])
    expect(h.address.navigate).not.toHaveBeenCalled()
    expect(h.address.replacePath).not.toHaveBeenCalled()
  })

  it('marks a vanished selected batch unavailable until it returns without duplicates', async () => {
    const h = mountPage({ ...ARCHIVED_LIVE_FILE, source: String(BATCH) })
    h.pushCatalog(archivedStreamCatalog())
    await settled(h.fixture)
    const source = selected(h.fixture, 'hilos-log-source', String(BATCH))
    expect(source.selectedOptions[0]?.textContent).not.toContain('unavailable')

    const nextPicture = archivedStreamCatalog()
    nextPicture.nodes[1].batches = [BATCH + 60]
    nextPicture.nodes[1].streams[1].batchTimestamps = [BATCH + 60]
    h.pushCatalog(nextPicture)
    h.answer(h.sent.at(-1)?.requestId, {
      readable: false,
      lines: [],
      nextCursor: null,
      hasMore: false,
    })
    await settled(h.fixture)

    selected(h.fixture, 'hilos-log-source', String(BATCH))
    expect(source.querySelectorAll(`option[value="${BATCH}"]`)).toHaveLength(1)
    expect(source.selectedOptions[0]?.textContent?.trim()).toBe(
      `Batch — ${new Date(BATCH * 1000).toLocaleString()} (unavailable)`,
    )
    selected(h.fixture, 'hilos-log-stream', 'worker-0.log')
    const root = h.fixture.nativeElement as HTMLElement
    expect(
      root.querySelector('[data-id="hilos-log-empty-missing"]'),
    ).not.toBeNull()
    expect(root.querySelector('[data-id="hilos-log-latest-batch"]')).toBeNull()

    h.pushCatalog(archivedStreamCatalog())
    await settled(h.fixture)

    selected(h.fixture, 'hilos-log-source', String(BATCH))
    expect(source.querySelectorAll(`option[value="${BATCH}"]`)).toHaveLength(1)
    expect(source.selectedOptions[0]?.textContent?.trim()).toBe(
      `Batch — ${new Date(BATCH * 1000).toLocaleString()}`,
    )
  })

  it('keeps the explicit archive stream when returning to live and follows that exact file', async () => {
    const h = mountPage({ ...ARCHIVED_LIVE_FILE, source: String(BATCH) })
    h.pushCatalog(archivedStreamCatalog())
    await settled(h.fixture)
    expect(h.sent.at(-1)?.action).toBe(LOGS_READ_LINES_ACTION)

    await change(h.fixture, 'hilos-log-source', 'live')

    selected(h.fixture, 'hilos-log-source', 'live')
    selected(h.fixture, 'hilos-log-stream', 'worker-0.log')
    expect(h.sent.at(-1)?.action).toBe(LOGS_FOLLOW_START_ACTION)
    expect(h.sent.at(-1)?.data).toEqual({
      nodeId: 'beta',
      stream: 'worker-0.log',
      level: null,
      substring: null,
    })
    const root = h.fixture.nativeElement as HTMLElement
    expect(
      root.querySelector('[data-id="hilos-log-empty-unchosen"]'),
    ).toBeNull()
    expect(h.address.currentPath.get()).toBe(
      '/hilos/logs/view/beta/live/worker-0.log',
    )
  })
})
