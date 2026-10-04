import { TestBed } from '@angular/core/testing'
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

/** The address of the live file the fixture opens. */
function router(): HilosRouter {
  return {
    currentRoute: createSignal<PageRouteMatch>({
      page: HilosPages.LOGS_VIEW,
      params: { nodeId: '-', source: 'live', stream: 'worker-0.log' },
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

/** A live file catalog, with the no-name node of a single-node installation. */
const CATALOG = {
  available: true,
  nodes: [
    {
      nodeId: '',
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
  pushCatalog: () => void
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

  return {
    connection,
    pushCatalog(): void {
      for (const listener of listeners['projectSignal'] ?? []) {
        listener({ type: LOG_VIEWER_CATALOG_SIGNAL, data: CATALOG })
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

describe('HilosLogsViewPage', () => {
  it('shows a hidden entry with its time and level and leaves following available', async () => {
    bindViewerSession()
    const { connection, pushCatalog, answerFollow } = makeConnection()
    TestBed.configureTestingModule({
      providers: [{ provide: HILOS_ROUTER, useValue: router() }],
    })
    const fixture = TestBed.createComponent(HilosLogsViewPage)
    fixture.componentRef.setInput('context', {
      connection,
      actions: new ActionLifecycle(
        connection as unknown as ConstructorParameters<
          typeof ActionLifecycle
        >[0],
      ),
    })
    fixture.detectChanges()
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
