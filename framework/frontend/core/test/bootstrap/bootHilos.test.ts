import { afterEach, describe, expect, it, vi } from 'vitest'
import { bootHilos } from '../../src/bootstrap/bootHilos.js'
import { createAuthActions } from '../../src/auth/authActions.js'
import { createAuthGate } from '../../src/auth/authGate.js'
import { createAuthFlow } from '../../src/auth/authFlow.js'
import { ActionLifecycle } from '../../src/connection/actionLifecycle.js'
import { applyServerTime } from '../../src/session/serverClock.js'
import { createHilosConnection } from '../../src/connection/createHilosConnection.js'
import {
  isCurrentProjectSignal,
  type TableViewportDescriptor,
  type WebSocketLike,
} from '../../src/connection/HilosConnection.js'
import { UPLOAD_ACTION_INIT } from '../../src/uploads/uploadProtocol.js'
import {
  SIGNAL_TYPE_PAGE_MOVED,
  SIGNAL_TYPE_PAGE_RESPONSE,
  SIGNAL_TYPE_PAGE_SUBSCRIPTION_ERROR,
} from '../../src/protocol/constants.js'
import { createAppPageRouter } from '../../src/routing/appPageRouter.js'
import {
  createHilosRouter,
  type NavigationEnvironment,
} from '../../src/routing/HilosRouter.js'
import { lazyPage } from '../../src/routing/pageView.js'
import { createPageViewState } from '../../src/routing/pageViewState.js'
import {
  HILOS_ROUTE_DECLARATIONS,
  HilosPages,
} from '../../src/routing/hilosPages.js'
import { hilosAccountStanding } from '../../src/session/accountStanding.js'
import { hilosFrozenScreen } from '../../src/legal/legalReconsent.js'
import { hilosImpersonation } from '../../src/session/impersonation.js'
import { hilosSignedIn } from '../../src/session/signOut.js'
import { hilosAdminAccess } from '../../src/session/adminAccess.js'
import { hilosAccessLossReason } from '../../src/session/accessLoss.js'
import { ScopeManager } from '../../src/state/ScopeManager.js'
import {
  computedSignal,
  createSignal,
  subscribeSignal,
} from '../../src/state/signal.js'
import { bindPageInstanceName } from '../../src/routing/hilosPageInstanceName.js'
import {
  createHilosI18nCountryCard,
  hilosI18nCountryDisplayName,
  type HilosI18nCountryContext,
} from '../../src/admin/i18n/hilosI18nCountry.js'
import { hilosToasts } from '../../src/state/toasts.js'
import { hilosThemeMode, hilosThemePick } from '../../src/theme/themeState.js'
import {
  hilosProfileNameIdentity,
  hilosProfileNameState,
  hilosProfileNameStateKnown,
} from '../../src/profile/profileNameState.js'
import {
  hilosBrowserTimeZone,
  hilosOwnTimeZone,
  hilosTimeZone,
} from '../../src/timeZone/timeZoneState.js'
import {
  type ConnectionState,
  type HandshakeSignal,
  type HilosConnection,
  type ParseFailure,
  type ProjectSignal,
} from '../../src/base/index.js'
import {
  SESSION_ACK_REGISTERED,
  SIGNAL_HANDSHAKE_RESPONSE,
  sessionPendingAck,
  sessionUserId,
} from '../../src/session/sessionScope.js'
import { type PageResponseMatcher } from '../../src/subscription/PageSubscription.js'
import { bindPageMove } from '../../src/subscription/bindPageMove.js'
import {
  bindPageScope,
  pageSubscriptionFor,
} from '../../src/subscription/bindPageScope.js'
import { bindTableViewport } from '../../src/subscription/bindTableViewport.js'
import { type TableRow } from '../../src/state/TableRowsStore.js'
import { TableViewportController } from '../../src/table/TableViewportController.js'

const resolved = new Intl.DateTimeFormat().resolvedOptions()

/**
 * Make the engine report `timeZone` as this browser's zone.
 *
 * @param timeZone The zone the next reads report; undefined when it cannot say.
 */
function reportZone(timeZone: string | undefined): void {
  vi.spyOn(Intl.DateTimeFormat.prototype, 'resolvedOptions').mockReturnValue({
    ...resolved,
    timeZone: timeZone as string,
  })
}

/**
 * A connection double recording connect()/send() and replaying welcome and
 * project signals — the slice bootHilos and the subscriptions it binds touch.
 */
function fakeConnection() {
  const projectListeners: Array<(signal: ProjectSignal) => void> = []
  const handshakeListeners: Array<(signal: HandshakeSignal) => void> = []

  return {
    state: 'connected' as ConnectionState,
    projectTimeZone: undefined as string | undefined,
    connectCalls: 0,
    listenerEvents: [] as string[],
    sent: [] as Array<Record<string, unknown>>,
    connect(): void {
      this.connectCalls += 1
    },
    send(text: string): boolean {
      this.sent.push(JSON.parse(text) as Record<string, unknown>)

      return true
    },
    // The page subscription drops the held page frames on every page change;
    // this fake buffers nothing, so there is nothing to drop.
    forgetPageFrames(): void {},
    tableWindowDescriptors: () => ({}),
    on(event: string, listener: (payload: never) => void): () => void {
      this.listenerEvents.push(event)
      if (event === 'projectSignal') {
        projectListeners.push(listener as (signal: ProjectSignal) => void)
      }
      if (event === 'handshake') {
        handshakeListeners.push(listener as (signal: HandshakeSignal) => void)
      }

      return () => {}
    },
    emitHandshake(projectTimeZone?: string): void {
      // The real connection stores the welcome's zone before it emits.
      this.projectTimeZone = projectTimeZone
      const signal = {
        type: 'handshake',
        data: { build: 'test' },
      } as unknown as HandshakeSignal
      for (const listener of handshakeListeners) {
        listener(signal)
      }
    },
    emitProjectSignal(type: string, data: unknown): void {
      const signal = {
        kind: 'project',
        type,
        data,
        envelope: {},
      } as unknown as ProjectSignal
      for (const listener of projectListeners) {
        listener(signal)
      }
    },
  }
}

/** A DOM-free navigation environment seeded at one path. */
function fakeNavigation(pathname: string): NavigationEnvironment {
  const setPath = (next: string) => {
    pathname = next
  }
  return {
    pathname: () => pathname,
    pushState: setPath,
    replaceState: setPath,
    onPopState: () => () => {},
  }
}

function boot(
  connection: ReturnType<typeof fakeConnection>,
  actions = new ActionLifecycle(connection as unknown as HilosConnection),
) {
  const scopes = new ScopeManager()
  const router = createAppPageRouter({ main: { path: '/', admin: false } })
  const hilosConnection = connection as unknown as HilosConnection
  const hilosRouter = bootHilos({
    viewLayer: 'vue',
    connection: hilosConnection,
    actions,
    scopes,
    router,
    navigationEnvironment: fakeNavigation('/'),
  })

  return { scopes, hilosRouter }
}

/**
 * Whether a promise has settled once the microtask queue drains.
 *
 * @param promise The promise to probe without awaiting it.
 */
async function isSettled(promise: Promise<void>): Promise<boolean> {
  let settled = false
  void promise.then(() => {
    settled = true
  })
  await Promise.resolve()
  await Promise.resolve()

  return settled
}

/** A socket driven by open/message events, with every outbound frame recorded. */
function scriptedSocket() {
  const listeners = new Map<
    string,
    Array<(event: { data?: unknown }) => void>
  >()
  const sent: Array<Record<string, unknown>> = []
  const socket: WebSocketLike = {
    send: (data) => sent.push(JSON.parse(data as string)),
    close: () => {},
    addEventListener: (type, listener) => {
      const handlers = listeners.get(type) ?? []
      handlers.push(listener)
      listeners.set(type, handlers)
    },
  }
  return {
    socket,
    sent,
    open: () => {
      for (const listener of listeners.get('open') ?? []) listener({})
    },
    message: (frame: Record<string, unknown>) => {
      for (const listener of listeners.get('message') ?? []) {
        listener({ data: JSON.stringify(frame) })
      }
    },
  }
}

/**
 * @param slug The product address carried by the answer.
 * @param withWindow Whether the answer opens the products table.
 */
function productAnswer(slug: string, withWindow = true) {
  return {
    type: SIGNAL_TYPE_PAGE_RESPONSE,
    data: {
      page: 'product',
      payload: {
        data: { slug },
        windows: withWindow
          ? {
              products: {
                rows: [],
                sort: [],
                filter: {},
                limit: 20,
                totalCount: 0,
                totalExact: true,
                firstAnchor: null,
                lastAnchor: null,
              },
            }
          : undefined,
      },
    },
  }
}

function sessionAnswer(
  id: number | null,
  timeZone: string,
  serverTimeMs?: number,
) {
  return {
    type: SIGNAL_HANDSHAKE_RESPONSE,
    data: {
      entities: { currentUser: id === null ? null : { id, timeZone } },
      data: { accessLossReason: null, serverTimeMs },
    },
  }
}

function productRefusal(httpCode: 401 | 403 | 404) {
  return {
    type: SIGNAL_TYPE_PAGE_SUBSCRIPTION_ERROR,
    data: {
      page: 'product',
      httpCode,
      errorCode: { 401: 'unauthorized', 403: 'forbidden', 404: 'not_found' }[
        httpCode
      ],
      title: 'Product unavailable',
      message: 'The product could not be opened.',
    },
  }
}

function productMove(from: string, to: string, targetPage = 'product') {
  return {
    type: SIGNAL_TYPE_PAGE_MOVED,
    data: {
      page: 'product',
      params: { slug: from },
      targetPage,
      targetParams: { slug: to },
    },
  }
}

describe('bootHilos', () => {
  afterEach(() => {
    // The stack is the shared singleton every boot binds to.
    hilosToasts.clear()
    vi.unstubAllGlobals()
  })

  it('binds live access loss at ordinary boot without project opt-in', () => {
    const connection = fakeConnection()
    const { scopes, hilosRouter } = boot(connection)
    connection.emitProjectSignal('handshake_response', {
      entities: { currentUser: { id: 7 } },
      data: { accountBlocked: null },
    })
    connection.emitProjectSignal('page_response', {
      page: 'main',
      payload: { data: { privateValue: 'private' } },
    })
    expect(scopes.page()?.data.signal('privateValue').get()).toBe('private')
    const sent = [...connection.sent]

    connection.emitProjectSignal('handshake_response', {
      entities: { currentUser: null },
      data: {
        accessLossReason: 'ended_by_administrator',
        accountBlocked: null,
      },
    })

    expect(hilosAccessLossReason.get()).toBe('ended_by_administrator')
    expect(scopes.page()?.data.signal('privateValue').get()).toBeUndefined()
    expect(hilosRouter.pageLoading.get()).toBe(true)
    expect(hilosRouter.currentPath.get()).toBe('/')
    expect(connection.sent).toEqual(sent)
    connection.emitProjectSignal('handshake_response', {
      entities: { currentUser: null },
      data: { accessLossReason: null, accountBlocked: null },
    })
    expect(hilosAccessLossReason.get()).toBe('ended_by_administrator')
  })

  it('binds the loss controller before connect can publish a handshake', () => {
    const connection = fakeConnection()
    connection.connect = function (): void {
      this.emitProjectSignal('handshake_response', {
        entities: { currentUser: { id: 7 } },
      })
      this.emitProjectSignal('handshake_response', {
        entities: { currentUser: null },
        data: { accessLossReason: 'ended_by_administrator' },
      })
      expect(hilosAccessLossReason.get()).toBe('ended_by_administrator')
    }

    boot(connection)
  })

  it('refuses a handshake that omits accessLossReason without publishing its identity', () => {
    const socket = scriptedSocket()
    const { connection, actions } = createHilosConnection({
      url: 'ws://test/ws',
      webSocketFactory: () => socket.socket,
    })
    const scopes = new ScopeManager()
    bootHilos({
      connection,
      actions,
      scopes,
      viewLayer: 'vue',
      router: createAppPageRouter({ main: { path: '/', admin: false } }),
      navigationEnvironment: fakeNavigation('/'),
    })
    const failures: ParseFailure[] = []
    connection.on('parseFailure', (failure) => {
      failures.push(failure)
    })
    const userId = sessionUserId(scopes)

    socket.open()
    socket.message({ type: 'handshake', data: { build: 'test' } })
    socket.message({
      type: 'handshake_response',
      data: {
        entities: { currentUser: { id: 7, name: 'Ada' } },
        data: { accessLossReason: null },
      },
    })
    expect(userId.get()).toBe(7)

    socket.message({
      type: 'handshake_response',
      data: {
        entities: { currentUser: { id: 9, name: 'Other' } },
        data: { accountBlocked: null },
      },
    })
    expect(failures).toEqual([
      expect.objectContaining({
        kind: 'invalid-signal-data',
        type: 'handshake_response',
      }),
    ])
    expect(userId.get()).toBe(7)

    socket.message({
      type: 'handshake_response',
      data: {
        entities: { currentUser: { id: 8, name: 'Bea' } },
        data: { accessLossReason: null },
      },
    })
    expect(userId.get()).toBe(8)
    expect(failures).toHaveLength(1)
  })

  it('holds early Continue on a new socket until its page subscription is sent', async () => {
    const sockets: Array<ReturnType<typeof scriptedSocket>> = []
    const { connection, actions } = createHilosConnection({
      url: 'ws://test/ws',
      webSocketFactory: () => {
        const socket = scriptedSocket()
        sockets.push(socket)
        return socket.socket
      },
    })
    const scopes = new ScopeManager()
    bootHilos({
      connection,
      actions,
      scopes,
      viewLayer: 'vue',
      router: createAppPageRouter({ main: { path: '/', admin: false } }),
      navigationEnvironment: fakeNavigation('/'),
    })
    const flow = createAuthFlow({
      ...createAuthActions({ connection, actions, scopes, channels: [] }),
      authMethods: createSignal([]),
      channels: [],
    })
    const welcome = { type: 'handshake', data: { build: 'test-build' } }
    const session = {
      type: 'handshake_response',
      data: {
        entities: { currentUser: { id: 1, name: 'Ada' } },
        data: { accessLossReason: null },
      },
    }
    try {
      const first = sockets[0]!
      first.open()
      first.message(welcome)
      first.message(session)
      expect(first.sent).toEqual([
        { type: 'page_subscribe', page: 'main', params: {} },
      ])
      connection.close()
      connection.connect()
      const current = sockets[1]!
      current.open()
      current.message(welcome)
      expect(connection.state).toBe('connected')
      flow.applyExternal({ step: 'done' })

      const submitted = flow.submit()
      await flow.submit()
      expect(flow.pending.get()).toBe(true)
      // No page_response is needed: the sent subscription is the ordering gate.
      expect(current.sent).toEqual([])
      current.message(session)
      expect(current.sent).toEqual([
        { type: 'page_subscribe', page: 'main', params: {} },
        {
          type: 'action',
          action: 'hilos_dismiss_session_ack',
          data: {},
          requestId: expect.any(String),
        },
      ])
      expect(flow.pending.get()).toBe(true)
      current.message({
        type: 'action_success',
        data: { action: 'hilos_dismiss_session_ack' },
        requestId: current.sent[1]!.requestId,
        outcome: 'success',
      })
      await submitted
      expect(flow.pending.get()).toBe(false)
      expect(flow.error.get()).toBeNull()
      expect(current.sent).toHaveLength(2)
    } finally {
      flow.reset()
      connection.close()
    }
  })

  it('binds cached theme and applies it before connect, with no DOM required', () => {
    const attributes = new Map<string, string>()
    vi.stubGlobal('localStorage', {
      getItem: (key: string) => (key === 'hilos.theme.pick' ? 'dark' : null),
      setItem: () => {},
    })
    vi.stubGlobal('document', {
      documentElement: {
        setAttribute: (key: string, value: string) =>
          attributes.set(key, value),
      },
    })
    const connection = fakeConnection()
    const connect = connection.connect
    connection.connect = function (): void {
      expect(hilosThemePick.get()).toBe('dark')
      expect(hilosThemeMode.get()).toBe('dark')
      expect(attributes.get('data-bs-theme')).toBe('dark')
      connect.call(this)
    }
    boot(connection)
    expect(connection.connectCalls).toBe(1)

    vi.stubGlobal('document', undefined)
    expect(() => boot(fakeConnection())).not.toThrow()
  })

  it('binds theme before connect after session scope, using the supplied action lifecycle', () => {
    vi.stubGlobal('localStorage', {
      getItem: (key: string) => (key === 'hilos.theme.pick' ? 'dark' : null),
      setItem: () => {},
    })
    const connection = fakeConnection()
    const actions = new ActionLifecycle(
      connection as unknown as HilosConnection,
    )
    const dispatch = vi.spyOn(actions, 'dispatch').mockReturnValue({
      requestId: 'adopt',
      loading: createSignal(false),
      done: Promise.resolve({}),
    })
    const connect = connection.connect
    connection.connect = function (): void {
      connect.call(this)
      // The first response can arrive as soon as connect starts. Both bindings
      // must already exist, with the session settings ingested before theme.
      this.emitProjectSignal('handshake_response', {
        entities: {
          currentUser: { id: 1, themePick: null, themePickInitialized: false },
        },
        data: {
          themeSettings: { switchingEnabled: false, defaultTheme: 'light' },
        },
      })
      expect(hilosThemePick.get()).toBe('dark')
      expect(hilosThemeMode.get()).toBe('light')
      expect(dispatch).toHaveBeenCalledExactlyOnceWith('hilos_theme_adopt', {
        themePick: 'dark',
      })
    }
    boot(connection, actions)
    expect(connection.connectCalls).toBe(1)
    expect(
      connection.listenerEvents.filter((event) => event === 'actionSuccess'),
    ).toHaveLength(1)
    expect(
      connection.listenerEvents.filter((event) => event === 'actionError'),
    ).toHaveLength(1)
  })

  it.each([true, false])(
    'holds synchronous open STATE before mount with configured session identity (STATE first: %s)',
    (stateFirst) => {
      const socket = scriptedSocket()
      const { connection, actions } = createHilosConnection({
        url: 'ws://test/ws',
        webSocketFactory: () => socket.socket,
      })
      const scopes = new ScopeManager()
      const change = {
        requestId: 'before-mount',
        name: 'New name',
        phase: 'checking',
        refusal: null,
      }
      const state = {
        type: 'hilos_profile_name_state',
        data: { userId: 7, change },
      }
      const connect = connection.connect.bind(connection)
      const open = vi.spyOn(connection, 'connect').mockImplementation(() => {
        expect(hilosProfileNameStateKnown.get()).toBe(false)
        connect()
        socket.open()
        socket.message({ type: 'handshake', data: { build: 'test' } })
        if (stateFirst) socket.message(state)
        socket.message({
          type: 'handshake_response',
          data: {
            data: { accountStanding: null, accessLossReason: null },
            entities: {
              currentUser: { id: 99, name: 'Other slot' },
              me: { id: 7, name: 'Current person' },
            },
          },
        })
        if (!stateFirst) socket.message(state)
        expect(hilosProfileNameIdentity.get()).toBe(7)
        expect(hilosProfileNameState.get()).toEqual({ userId: 7, change })
        expect(hilosProfileNameStateKnown.get()).toBe(true)
      })
      try {
        bootHilos({
          connection,
          actions,
          scopes,
          viewLayer: 'vue',
          router: createAppPageRouter({ main: { path: '/', admin: false } }),
          session: { currentUserSlot: 'me', currentUserEntityType: 'person' },
          navigationEnvironment: fakeNavigation('/'),
        })
        // Only now could a page mount. It reads the already held answer.
        expect(hilosProfileNameState.get()).toEqual({ userId: 7, change })
        expect(scopes.session.data.signal('me').get()).toEqual({
          type: 'person',
          id: 7,
        })
        expect(open).toHaveBeenCalledOnce()
        expect(socket.sent).toEqual([
          { type: 'page_subscribe', page: 'main', params: {} },
        ])
      } finally {
        open.mockRestore()
        connection.close()
      }
    },
  )

  it("binds the reader's zone before connect after session scope, for the configured slot", () => {
    reportZone('Europe/Warsaw')
    const connection = fakeConnection()
    connection.projectTimeZone = 'America/New_York'
    const connect = connection.connect
    connection.connect = function (): void {
      expect(hilosOwnTimeZone.get()).toBeNull()
      expect(hilosBrowserTimeZone.get()).toBe('Europe/Warsaw')
      expect(hilosTimeZone.get()).toBe('Europe/Warsaw')
      connect.call(this)
      // The welcome and the session response can arrive as soon as connect
      // starts; the binding must already follow both.
      this.emitHandshake('Asia/Tokyo')
      this.emitProjectSignal('handshake_response', {
        entities: {
          currentUser: { id: 1, timeZone: 'Europe/Lisbon' },
          me: { id: 1, timeZone: 'America/Chicago' },
        },
      })
      expect(hilosOwnTimeZone.get()).toBe('America/Chicago')
      expect(hilosTimeZone.get()).toBe('America/Chicago')
    }
    try {
      bootHilos({
        viewLayer: 'vue',
        connection: connection as unknown as HilosConnection,
        actions: new ActionLifecycle(connection as unknown as HilosConnection),
        scopes: new ScopeManager(),
        router: createAppPageRouter({ main: { path: '/', admin: false } }),
        session: { currentUserSlot: 'me' },
        navigationEnvironment: fakeNavigation('/'),
      })
      expect(connection.connectCalls).toBe(1)
      expect(connection.listenerEvents.indexOf('handshake')).toBeGreaterThan(
        connection.listenerEvents.indexOf('projectSignal'),
      )

      connection.emitProjectSignal('handshake_response', {
        entities: { currentUser: null, me: null },
      })
      expect(hilosTimeZone.get()).toBe('Europe/Warsaw')
      reportZone(undefined)
      connection.emitHandshake('Asia/Tokyo')
      expect(hilosTimeZone.get()).toBe('Asia/Tokyo')
    } finally {
      vi.restoreAllMocks()
    }
  })

  it("follows the reader's zone off real welcome and session frames, across a reconnect", () => {
    const sockets: Array<ReturnType<typeof scriptedSocket>> = []
    const { connection, actions } = createHilosConnection({
      url: 'ws://test/ws',
      webSocketFactory: () => {
        const socket = scriptedSocket()
        sockets.push(socket)
        return socket.socket
      },
    })
    reportZone(undefined)
    bootHilos({
      connection,
      actions,
      scopes: new ScopeManager(),
      viewLayer: 'vue',
      router: createAppPageRouter({ main: { path: '/', admin: false } }),
      navigationEnvironment: fakeNavigation('/'),
    })
    const own: Array<string | null> = []
    const effective: string[] = []
    const stopOwn = subscribeSignal(hilosOwnTimeZone, (zone) => own.push(zone))
    const stopEffective = subscribeSignal(hilosTimeZone, (zone) =>
      effective.push(zone),
    )
    const answer = (currentUser: unknown) => ({
      type: 'handshake_response',
      data: {
        entities: { currentUser },
        data: { accessLossReason: null },
      },
    })
    const tokyo = answer({ id: 1, name: 'Ada', timeZone: 'Asia/Tokyo' })
    try {
      const first = sockets[0]!
      first.open()
      expect(hilosTimeZone.get()).toBe('UTC')
      first.message({
        type: 'handshake',
        data: { build: 'test-build', projectTimeZone: 'America/New_York' },
      })
      expect(hilosOwnTimeZone.get()).toBeNull()
      expect(hilosBrowserTimeZone.get()).toBeNull()
      expect(hilosTimeZone.get()).toBe('America/New_York')

      reportZone('Europe/Warsaw')
      first.message(tokyo)
      expect(hilosOwnTimeZone.get()).toBe('Asia/Tokyo')
      expect(hilosBrowserTimeZone.get()).toBe('Europe/Warsaw')
      expect(hilosTimeZone.get()).toBe('Asia/Tokyo')

      first.message(tokyo)
      expect(own).toEqual(['Asia/Tokyo'])
      expect(effective).toEqual(['America/New_York', 'Asia/Tokyo'])

      first.message(answer({ id: 1, name: 'Ada', timeZone: null }))
      expect(hilosOwnTimeZone.get()).toBeNull()
      expect(hilosTimeZone.get()).toBe('Europe/Warsaw')

      first.message(tokyo)
      first.message(answer(null))
      expect(hilosOwnTimeZone.get()).toBeNull()
      expect(hilosTimeZone.get()).toBe('Europe/Warsaw')

      // The browser stops answering, so the project zone is all that would
      // stand between the reader and UTC.
      reportZone(undefined)
      connection.close()
      connection.connect()
      const second = sockets[1]!
      second.open()
      second.message({ type: 'handshake', data: { build: 'test-build' } })
      expect(hilosBrowserTimeZone.get()).toBeNull()
      expect(hilosTimeZone.get()).toBe('UTC')

      expect(own).toEqual(['Asia/Tokyo', null, 'Asia/Tokyo', null])
      expect(effective).toEqual([
        'America/New_York',
        'Asia/Tokyo',
        'Europe/Warsaw',
        'Asia/Tokyo',
        'Europe/Warsaw',
        'UTC',
      ])
    } finally {
      stopOwn()
      stopEffective()
      connection.close()
      vi.restoreAllMocks()
    }
  })

  it.each(['early listener', 'session scope', 'repeated answer'] as const)(
    'prepares Analytics after the session before sending during %s',
    (boundary) => {
      reportZone('UTC')
      vi.spyOn(Date, 'now').mockReturnValue(Date.parse('2026-11-23T23:15:00Z'))
      const first = scriptedSocket()
      const second = scriptedSocket()
      const { connection, actions } = createHilosConnection({
        url: 'ws://test/ws',
        webSocketFactory: vi
          .fn()
          .mockReturnValueOnce(first.socket)
          .mockReturnValueOnce(second.socket),
      })
      const scopes = new ScopeManager()
      const analytics = HilosPages.ANALYTICS
      const serverTimeMs = Date.parse('2026-11-24T02:45:00Z')
      const answer = sessionAnswer(7, 'Asia/Tokyo', serverTimeMs)
      const params = {
        timeZone: 'Asia/Tokyo',
        weekStartTs: String(Date.parse('2026-11-22T15:00:00Z')),
        dayStartTs: String(Date.parse('2026-11-23T15:00:00Z')),
        hourStartTs: String(Date.parse('2026-11-24T02:00:00Z')),
      }
      const analyticsFrames = (socket: ReturnType<typeof scriptedSocket>) =>
        socket.sent.filter((frame) => frame.page === analytics)
      let openDuringAnswer = true
      const open = () => {
        if (!openDuringAnswer) return
        openDuringAnswer = false
        if (boundary === 'repeated answer') {
          router.navigate(HILOS_ROUTE_DECLARATIONS[analytics].path)
        } else {
          bindPageScope(connection, new ScopeManager()).subscribe(analytics)
        }
        expect(analyticsFrames(first)).toEqual([])
      }
      connection.on('projectSignal', (signal) => {
        if (
          signal.type === SIGNAL_HANDSHAKE_RESPONSE &&
          boundary !== 'session scope'
        )
          open()
      })
      const router = bootHilos({
        connection,
        actions,
        scopes,
        viewLayer: 'vue',
        router: createAppPageRouter({ main: { path: '/', admin: false } }),
        navigationEnvironment: fakeNavigation('/'),
      })
      const stopScope = subscribeSignal(sessionUserId(scopes), () => {
        if (boundary === 'session scope') open()
      })

      try {
        first.open()
        if (boundary === 'repeated answer') {
          openDuringAnswer = false
          first.message(sessionAnswer(7, 'America/New_York'))
          expect(first.sent).toEqual([
            { type: 'page_subscribe', page: 'main', params: {} },
          ])
          openDuringAnswer = true
        }
        first.message(answer)
        const frame = { type: 'page_subscribe', page: analytics, params }
        expect(analyticsFrames(first)).toEqual([frame])

        const late = bindPageScope(connection, new ScopeManager())
        late.subscribe(analytics)
        expect(analyticsFrames(first)).toEqual([frame, frame])
        connection.close()
        connection.connect()
        second.open()
        expect(second.sent).toEqual([])
        second.message(sessionAnswer(8, 'Europe/Warsaw'))
        expect(second.sent).toEqual([frame])
      } finally {
        stopScope()
        router.stop()
        connection.close()
        vi.restoreAllMocks()
        applyServerTime(Date.now())
      }
    },
  )

  it.each(['early signal', 'first scope write', 'identity write'] as const)(
    'stops the old session response after a socket change in %s',
    (boundary) => {
      const first = scriptedSocket()
      const second = scriptedSocket()
      const { connection, actions } = createHilosConnection({
        url: 'ws://test/ws',
        webSocketFactory: vi
          .fn()
          .mockReturnValueOnce(first.socket)
          .mockReturnValueOnce(second.socket),
      })
      const scopes = new ScopeManager()
      const changeSocket = () => {
        connection.close()
        connection.connect()
        second.open()
      }
      const stopEarly = connection.on('projectSignal', (signal) => {
        if (
          boundary === 'early signal' &&
          signal.type === SIGNAL_HANDSHAKE_RESPONSE
        ) {
          stopEarly()
          changeSocket()
        }
      })
      const router = bootHilos({
        connection,
        actions,
        scopes,
        viewLayer: 'vue',
        router: createAppPageRouter({ main: { path: '/', admin: false } }),
        navigationEnvironment: fakeNavigation('/'),
      })
      const userId = sessionUserId(scopes)
      const stopScope = subscribeSignal(
        scopes.session.data.signal('serverTimeMs'),
        () => {
          if (boundary === 'first scope write') {
            stopScope()
            changeSocket()
          }
        },
      )
      const stopUser = subscribeSignal(userId, () => {
        if (boundary === 'identity write') {
          stopUser()
          changeSocket()
        }
      })

      try {
        first.open()
        first.message(sessionAnswer(7, 'Asia/Tokyo', Date.now() + 60_000))
        expect(userId.get()).toBe(boundary === 'identity write' ? 7 : null)
        expect(hilosOwnTimeZone.get()).toBeNull()
        expect(first.sent).toEqual([])
        expect(second.sent).toEqual([])

        second.message(sessionAnswer(8, 'America/New_York', Date.now()))
        expect(userId.get()).toBe(8)
        expect(hilosOwnTimeZone.get()).toBe('America/New_York')
        expect(second.sent).toEqual([
          { type: 'page_subscribe', page: 'main', params: {} },
        ])
      } finally {
        stopEarly()
        stopUser()
        stopScope()
        router.stop()
        connection.close()
        applyServerTime(Date.now())
      }
    },
  )

  it.each(['buildMismatch', 'protectedMode', 'handshake'] as const)(
    'drops the rest of a welcome when a %s listener replaces its socket',
    (event) => {
      const first = scriptedSocket()
      const second = scriptedSocket()
      const { connection, actions } = createHilosConnection({
        url: 'ws://test/ws',
        onBuildMismatch: () => {},
        onProtectedModeLift: () => {},
        webSocketFactory: vi
          .fn()
          .mockReturnValueOnce(first.socket)
          .mockReturnValueOnce(second.socket),
      })
      reportZone(undefined)
      const welcome = vi.fn()
      let armed = false
      const stop = connection.on(event, () => {
        if (!armed) return
        armed = false
        connection.close()
        connection.connect()
        second.open()
      })
      connection.on('handshake', welcome)
      const router = bootHilos({
        connection,
        actions,
        scopes: new ScopeManager(),
        viewLayer: 'vue',
        router: createAppPageRouter({ main: { path: '/', admin: false } }),
        navigationEnvironment: fakeNavigation('/'),
      })
      try {
        first.open()
        first.message({ type: 'handshake', data: { build: 'test' } })
        welcome.mockClear()
        armed = true
        first.message({
          type: 'handshake',
          data: {
            build: event === 'buildMismatch' ? 'old' : 'test',
            projectTimeZone: 'Asia/Tokyo',
            protectedMode:
              event === 'protectedMode' ? { active: true } : undefined,
          },
        })
        expect(welcome).not.toHaveBeenCalled()
        expect(hilosTimeZone.get()).toBe('UTC')
        expect(second.sent).toEqual([])

        second.message({
          type: 'handshake',
          data: { build: 'test', projectTimeZone: 'America/New_York' },
        })
        expect(welcome).toHaveBeenCalledOnce()
        expect(hilosTimeZone.get()).toBe('America/New_York')
      } finally {
        stop()
        router.stop()
        connection.close()
        vi.restoreAllMocks()
      }
    },
  )

  it('keeps manual session release for a direct binding and lets a late direct binding open', () => {
    const first = scriptedSocket()
    const second = scriptedSocket()
    const { connection } = createHilosConnection({
      url: 'ws://test/ws',
      webSocketFactory: vi
        .fn()
        .mockReturnValueOnce(first.socket)
        .mockReturnValueOnce(second.socket),
    })
    const pages = bindPageScope(connection, new ScopeManager())
    pages.subscribe('product', { slug: 'a' })
    try {
      connection.connect()
      first.open()
      first.message(sessionAnswer(7, 'Asia/Tokyo'))
      expect(first.sent).toEqual([])
      pages.releaseOnSession()
      expect(first.sent).toHaveLength(1)

      const late = bindPageScope(connection, new ScopeManager())
      late.subscribe('product', { slug: 'b' })
      expect(first.sent).toHaveLength(2)
      connection.close()
      connection.connect()
      second.open()
      second.message(sessionAnswer(7, 'Asia/Tokyo'))
      expect(second.sent).toEqual([])
      late.releaseOnSession()
      late.releaseOnSession()
      expect(second.sent).toEqual([
        { type: 'page_subscribe', page: 'product', params: { slug: 'b' } },
      ])
    } finally {
      connection.close()
    }
  })

  it.each([false, true])(
    'cold roles entry, project view = %s',
    (projectView) => {
      const connection = fakeConnection()
      const hilosConnection = connection as unknown as HilosConnection
      const router = bootHilos({
        viewLayer: 'vue',
        projectViews: projectView ? [HilosPages.ROLES] : [],
        connection: hilosConnection,
        actions: new ActionLifecycle(hilosConnection),
        scopes: new ScopeManager(),
        router: createAppPageRouter({ main: { path: '/', admin: false } }),
        navigationEnvironment: fakeNavigation('/hilos/roles'),
        appName: 'Demo',
      })
      expect(connection.sent).toEqual([])
      expect(router.pageError.get()?.errorCode).toBe(
        projectView ? undefined : 'not_served',
      )
      expect(router.pageLoading.get()).toBe(projectView)
      if (!projectView) expect(router.currentTitle.get()).toBe('Demo')
      connection.emitProjectSignal('handshake_response', {
        entities: { currentUser: { id: 1, name: 'Ada', admin: true } },
      })
      connection.emitProjectSignal('handshake_response', {
        entities: { currentUser: null },
      })
      expect(connection.sent).toEqual(
        projectView
          ? [{ type: 'page_subscribe', page: HilosPages.ROLES, params: {} }]
          : [],
      )
      if (!projectView)
        expect(router.pageError.get()?.errorCode).toBe('not_served')
    },
  )

  it.each(['vue', 'react', 'angular'] as const)(
    'subscribes only completed i18n section and list views in %s',
    (viewLayer) => {
      for (const page of [HilosPages.I18N_ENTITIES]) {
        const connection = fakeConnection()
        const hilosConnection = connection as unknown as HilosConnection
        const router = bootHilos({
          viewLayer,
          connection: hilosConnection,
          actions: new ActionLifecycle(hilosConnection),
          scopes: new ScopeManager(),
          router: createAppPageRouter({ main: { path: '/', admin: false } }),
          navigationEnvironment: fakeNavigation(
            HILOS_ROUTE_DECLARATIONS[page].path,
          ),
        })

        expect(router.currentRoute.get().page).toBe(page)
        expect(router.pageError.get()?.errorCode).toBe('not_served')
        expect(router.resolvePath(page)).toBeUndefined()
        connection.emitProjectSignal('handshake_response', {
          entities: { currentUser: { id: 1, name: 'Ada', admin: true } },
        })
        expect(connection.sent).toEqual([])
      }
      for (const page of [
        HilosPages.I18N,
        HilosPages.I18N_LANGUAGES,
        HilosPages.I18N_COUNTRIES,
      ]) {
        const connection = fakeConnection()
        const hilosConnection = connection as unknown as HilosConnection
        const router = bootHilos({
          viewLayer,
          connection: hilosConnection,
          actions: new ActionLifecycle(hilosConnection),
          scopes: new ScopeManager(),
          router: createAppPageRouter({ main: { path: '/', admin: false } }),
          navigationEnvironment: fakeNavigation(
            HILOS_ROUTE_DECLARATIONS[page].path,
          ),
        })

        expect(router.currentRoute.get().page).toBe(page)
        expect(router.pageError.get()?.errorCode).toBeUndefined()
        expect(router.resolvePath(page)).toBe(
          HILOS_ROUTE_DECLARATIONS[page].path,
        )
        expect(connection.sent).toEqual([])
        connection.emitProjectSignal('handshake_response', {
          entities: { currentUser: { id: 1, name: 'Ada', admin: true } },
        })
        expect(connection.sent).toEqual([
          { type: 'page_subscribe', page, params: {} },
        ])
      }
    },
  )

  it.each(['vue', 'react', 'angular'] as const)(
    'subscribes exactly the completed i18n detail views in %s',
    (viewLayer) => {
      for (const [page, path] of [
        [HilosPages.I18N_LANGUAGE, '/hilos/i18n/language/11'],
        [HilosPages.I18N_LANGUAGE_NAMES, '/hilos/i18n/language/11/names'],
        [HilosPages.I18N_LANGUAGE_LOCALES, '/hilos/i18n/language/11/locales'],
        [HilosPages.I18N_COUNTRY, '/hilos/i18n/country/102'],
        [HilosPages.I18N_COUNTRY_NAMES, '/hilos/i18n/country/102/names'],
      ] as const) {
        const connection = fakeConnection()
        const hilosConnection = connection as unknown as HilosConnection
        const router = bootHilos({
          viewLayer,
          connection: hilosConnection,
          actions: new ActionLifecycle(hilosConnection),
          scopes: new ScopeManager(),
          router: createAppPageRouter({ main: { path: '/', admin: false } }),
          navigationEnvironment: fakeNavigation(path),
        })
        expect(router.currentRoute.get()).toMatchObject({
          page,
          params:
            page === HilosPages.I18N_COUNTRY ||
            page === HilosPages.I18N_COUNTRY_NAMES
              ? { countryId: '102' }
              : { languageId: '11' },
        })
        expect(connection.sent).toEqual([])
        connection.emitProjectSignal('handshake_response', {
          entities: { currentUser: { id: 1, name: 'Ada', admin: true } },
        })
        expect(router.pageError.get()?.errorCode).toBeUndefined()
        expect(
          router.resolvePath(
            page,
            page === HilosPages.I18N_COUNTRY ||
              page === HilosPages.I18N_COUNTRY_NAMES
              ? { countryId: '102' }
              : { languageId: '11' },
          ),
        ).toBe(path)
        expect(connection.sent).toEqual([
          {
            type: 'page_subscribe',
            page,
            params:
              page === HilosPages.I18N_COUNTRY ||
              page === HilosPages.I18N_COUNTRY_NAMES
                ? { countryId: '102' }
                : { languageId: '11' },
          },
        ])
      }
    },
  )

  it('opens the connection and subscribes the located page', () => {
    const connection = fakeConnection()
    const { hilosRouter } = boot(connection)

    expect(connection.connectCalls).toBe(1)
    expect(hilosRouter.currentRoute.get().page).toBe('main')
    // The route resolves at once, but the frame waits: a subscribe that overtook
    // the handshake was judged against a connection nobody had heard of yet.
    expect(connection.sent).toEqual([])

    connection.emitProjectSignal('handshake_response', {
      entities: { currentUser: { id: 1, name: 'Ada' } },
    })

    expect(connection.sent).toEqual([
      { type: 'page_subscribe', page: 'main', params: {} },
    ])
  })

  it('binds the session scope so a handshake response resolves the user', () => {
    const connection = fakeConnection()
    const { scopes } = boot(connection)

    connection.emitProjectSignal('handshake_response', {
      entities: { currentUser: { id: 1, name: 'Ada' } },
    })

    const ref = scopes.session.data.signal('currentUser').get() as
      { type: string; id: number } | undefined
    expect(ref?.type).toBe('user')
  })

  it('empties the toast stack when the handshake says the session has nobody', () => {
    const connection = fakeConnection()
    boot(connection)
    connection.emitProjectSignal('handshake_response', {
      entities: { currentUser: { id: 1, name: 'Ada' } },
    })
    hilosToasts.push('saved')

    connection.emitProjectSignal('handshake_response', {
      entities: { currentUser: null },
    })

    expect(hilosToasts.toasts.get()).toEqual([])
  })

  it('resolves the current title from the project titles and app name', () => {
    const connection = fakeConnection()
    const scopes = new ScopeManager()
    const router = createAppPageRouter({ main: { path: '/', admin: false } })
    const hilosConnection = connection as unknown as HilosConnection
    const hilosRouter = bootHilos({
      viewLayer: 'vue',
      connection: hilosConnection,
      actions: new ActionLifecycle(hilosConnection),
      scopes,
      router,
      navigationEnvironment: fakeNavigation('/'),
      pageTitles: { main: 'Home' },
      appName: 'Demo',
    })

    expect(hilosRouter.currentTitle.get()).toBe('Home · Demo')
  })

  it('renames the tab when the page answers with its heading', () => {
    // The title closure reads the identity signal, so currentTitle recomputes on
    // the answer, not only on the navigation. Before it lands the title is empty
    // on purpose: the shell would otherwise announce the app name and then the
    // page name, two page-change announcements for one navigation.
    const connection = fakeConnection()
    const scopes = new ScopeManager()
    const router = createAppPageRouter({})
    const hilosConnection = connection as unknown as HilosConnection
    const hilosRouter = bootHilos({
      viewLayer: 'vue',
      connection: hilosConnection,
      actions: new ActionLifecycle(hilosConnection),
      scopes,
      router,
      navigationEnvironment: fakeNavigation('/hilos/settings'),
      appName: 'Demo',
    })
    hilosRouter.start()

    expect(hilosRouter.currentTitle.get()).toBe('')

    connection.emitProjectSignal('page_response', {
      page: HilosPages.SETTINGS,
      payload: { data: { pageLabel: 'Settings' } },
    })

    expect(hilosRouter.currentTitle.get()).toBe('Settings · Demo')

    hilosRouter.navigate('/no-such-address')

    expect(hilosRouter.currentTitle.get()).toBe('Page Not Found · Demo')
    expect(hilosRouter.pageError.get()?.errorCode).toBe('route_not_found')
  })

  it.each([
    [HilosPages.I18N_COUNTRY, '/hilos/i18n/country/102', 'Demo'],
    [HilosPages.I18N_COUNTRY_NAMES, '/hilos/i18n/country/102/names', ''],
  ])(
    'binds the live owner title on %s from one complete answer',
    (page, path, appName) => {
      const connection = fakeConnection()
      const scopes = new ScopeManager()
      const context: HilosI18nCountryContext = {
        scopes,
        connection: connection as unknown as HilosConnection,
        actions: new ActionLifecycle(connection as unknown as HilosConnection),
      }
      const router = bootHilos({
        ...context,
        viewLayer: 'vue',
        router: createAppPageRouter({ main: { path: '/', admin: false } }),
        navigationEnvironment: fakeNavigation(path),
        appName,
      })
      router.start()
      const titles: string[] = []
      const stop = subscribeSignal(router.currentTitle, (title) =>
        titles.push(title),
      )
      const expected = (label: string) =>
        appName === '' ? label : `${label} · ${appName}`
      expect(router.currentTitle.get()).toBe('')
      const card = {
        countryId: 102,
        code: 'us',
        currencySymbol: '$',
        currencyCode: 'USD',
        enabled: false,
        summary: {
          name: 'United States',
          isOwn: false,
          defaultLocaleId: null,
          defaultLocaleCode: null,
          defaultLocale: 'missing',
          localeCount: 0,
          nameCount: 0,
          locales: [],
          catalogDefaultLocaleCode: null,
          canDelete: false,
          deleteReason: 'known',
        },
      }
      const data = {
        pageLabel: page === HilosPages.I18N_COUNTRY ? 'Country' : 'Names',
        pageLead: 'Tab lead',
        pageHeadOwner: {
          page: HilosPages.I18N_COUNTRY,
          label: 'Country',
          lead: 'Owner lead',
        },
        pageBreadcrumb: [{ page: HilosPages.I18N_COUNTRY, label: 'Country' }],
        countryCard: card,
      }
      connection.emitProjectSignal('page_response', { page, payload: { data } })
      expect(router.currentTitle.get()).toBe(expected('Country'))

      // Lazy view setup happens after the response; it binds the existing source.
      const liveCard = createHilosI18nCountryCard(context)
      const dispose = bindPageInstanceName(
        scopes,
        HilosPages.I18N_COUNTRY,
        computedSignal(() => {
          const current = liveCard.get()
          return current === null
            ? undefined
            : hilosI18nCountryDisplayName(current)
        }),
      )
      expect(router.currentTitle.get()).toBe(expected('United States'))
      expect(titles.at(-1)).toBe(expected('United States'))
      for (const name of ['Renamed', null, '']) {
        connection.emitProjectSignal('page_response', {
          page,
          payload: {
            data: {
              ...data,
              countryCard: { ...card, summary: { ...card.summary, name } },
            },
          },
        })
        expect(router.currentTitle.get()).toBe(
          expected(name == null || name === '' ? 'Unnamed country' : name),
        )
        expect(router.currentPath.get()).toBe(path)
      }
      router.navigate('/hilos/i18n/country/140/names')
      expect(router.currentTitle.get()).toBe('')
      connection.emitProjectSignal('page_response', {
        page: HilosPages.I18N_COUNTRY_NAMES,
        payload: {
          data: {
            ...data,
            pageLabel: 'Names',
            countryCard: {
              ...card,
              countryId: 140,
              code: 'ie',
              summary: { ...card.summary, name: 'Ireland' },
            },
          },
        },
      })
      expect(router.currentTitle.get()).toBe(expected('Ireland'))
      dispose()
      expect(router.currentTitle.get()).toBe(expected('Country'))
      connection.emitProjectSignal('subscription_page_error', {
        page: HilosPages.I18N_COUNTRY_NAMES,
        httpCode: 404,
        errorCode: 'not_found',
        title: 'Page Not Found',
        message: 'Missing',
      })
      expect(router.currentTitle.get()).toBe(appName)
      router.navigate('/no-such-address')
      expect(router.currentTitle.get()).toBe(expected('Page Not Found'))
      stop()
      router.stop()
    },
  )

  it('publishes and replays only the answer matching the booted page address', async () => {
    vi.resetModules()
    const { bootHilos: bootFresh } =
      await import('../../src/bootstrap/bootHilos.js')
    const { createHilosConnection: createConnection } =
      await import('../../src/connection/createHilosConnection.js')
    const { whenPageReady } =
      await import('../../src/subscription/pageReadyGate.js')
    const socket = scriptedSocket()
    const { connection, actions } = createConnection({
      url: 'ws://test/ws',
      webSocketFactory: () => socket.socket,
    })
    const page = 'product'
    const scopes = new ScopeManager()
    const matcher = vi.fn<PageResponseMatcher>(
      (params, payload) => params.slug === payload.data?.slug,
    )
    const receive = (slug: string) => socket.message(productAnswer(slug))
    const live = vi.fn()
    connection.on('projectSignal', (signal) => {
      if (signal.type === SIGNAL_TYPE_PAGE_RESPONSE) live(signal.data)
    })
    const router = bootFresh({
      connection,
      actions,
      scopes,
      viewLayer: 'vue',
      router: createAppPageRouter({
        [page]: { path: '/product/{slug}', admin: false },
      }),
      pageResponseMatchers: new Map([[page, matcher]]),
      navigationEnvironment: fakeNavigation('/product/a'),
    })
    const waiting = whenPageReady()
    const readReplay = () => {
      const listener = vi.fn()
      connection.on('projectSignal', listener)()

      return listener
    }
    try {
      socket.open()
      socket.message({
        type: 'handshake_response',
        data: { entities: { currentUser: null } },
      })
      router.navigate('/product/b')

      receive('a')
      expect(router.pageLoading.get()).toBe(true)
      expect(await isSettled(waiting)).toBe(false)
      expect(live).not.toHaveBeenCalled()
      expect(readReplay()).not.toHaveBeenCalled()

      receive('b')
      expect(await isSettled(waiting)).toBe(true)
      expect(router.pageLoading.get()).toBe(false)
      expect(scopes.page()?.data.signal('slug').get()).toBe('b')
      expect(live).toHaveBeenCalledOnce()
      expect(readReplay()).toHaveBeenCalledWith(
        expect.objectContaining({ data: live.mock.calls[0]?.[0] }),
      )
      expect(matcher).toHaveBeenCalledTimes(2)

      receive('a')
      expect(scopes.page()?.data.signal('slug').get()).toBe('b')
      expect(readReplay()).toHaveBeenCalledWith(
        expect.objectContaining({ data: live.mock.calls[0]?.[0] }),
      )

      socket.message({
        type: SIGNAL_TYPE_PAGE_SUBSCRIPTION_ERROR,
        data: {
          page,
          httpCode: 404,
          errorCode: 'not_found',
          title: 'Page Not Found',
          message: 'Missing',
        },
      })
      receive('a')
      expect(router.pageError.get()?.httpCode).toBe(404)
      expect(readReplay()).not.toHaveBeenCalled()
      receive('b')
      expect(router.pageError.get()).toBeNull()
      expect(scopes.page()?.data.signal('slug').get()).toBe('b')
    } finally {
      router.stop()
      connection.close()
    }
  })

  it.each([
    { boundary: 'table preparation', matched: true },
    { boundary: 'early listener', matched: true },
    { boundary: 'scope ingest', matched: true },
    { boundary: 'early listener', matched: false },
    { boundary: 'scope ingest', matched: false },
  ] as const)(
    'stops an answer after navigation in $boundary with matcher $matched',
    async ({ boundary, matched }) => {
      vi.resetModules()
      const { bootHilos: bootFresh } =
        await import('../../src/bootstrap/bootHilos.js')
      const { createHilosConnection: createConnection } =
        await import('../../src/connection/createHilosConnection.js')
      const { whenPageReady } =
        await import('../../src/subscription/pageReadyGate.js')
      const { bindTableViewport: bindViewport } =
        await import('../../src/subscription/bindTableViewport.js')
      const { uploadFile } = await import('../../src/uploads/hilosUploads.js')
      const socket = scriptedSocket()
      const { connection, actions } = createConnection({
        url: 'ws://test/ws',
        webSocketFactory: () => socket.socket,
      })
      const scopes = new ScopeManager()
      const matcher = vi.fn<PageResponseMatcher>(
        (params, payload) => params.slug === payload.data?.slug,
      )
      const early = vi.fn()
      connection.on('projectSignal', (signal) => {
        if (signal.type !== SIGNAL_TYPE_PAGE_RESPONSE) return
        early()
        if (boundary === 'early listener') navigate()
      })
      const router = bootFresh({
        connection,
        actions,
        scopes,
        viewLayer: 'vue',
        router: createAppPageRouter({
          product: { path: '/product/{slug}', admin: false },
        }),
        pageResponseMatchers: matched
          ? new Map([['product', matcher]])
          : undefined,
        navigationEnvironment: fakeNavigation('/product/a'),
      })
      const waiting = whenPageReady()
      let moved = false
      const navigate = () => {
        if (moved) return
        moved = true
        router.navigate('/product/b')
      }
      const controller = new TableViewportController<TableRow>({
        resolve: (row) => row,
        sendViewport: () => {},
        frame: { columns: [] },
      })
      if (boundary === 'table preparation') {
        vi.spyOn(controller, 'withPresentationUpdate').mockImplementation(
          (_change, update) => {
            navigate()
            return update()
          },
        )
      }
      const window = vi.spyOn(controller, 'ingestSubscriptionWindow')
      const stopTable = bindViewport(
        connection,
        scopes,
        { page: 'product', tableKey: 'products' },
        controller,
      )
      const late = vi.fn()
      const diagnostic = vi.fn()
      connection.on('signal', (signal) => {
        if (
          signal.kind === 'project' &&
          signal.type === SIGNAL_TYPE_PAGE_RESPONSE
        ) {
          diagnostic(signal.data)
        }
      })
      connection.on('projectSignal', (signal) => {
        if (signal.type === SIGNAL_TYPE_PAGE_RESPONSE) late(signal.data)
      })
      const scope = scopes.page()!
      const stopScope = subscribeSignal(scope.data.signal('slug'), () => {
        if (boundary === 'scope ingest') navigate()
      })

      try {
        socket.open()
        socket.message({
          type: 'handshake_response',
          data: { entities: { currentUser: null } },
        })
        const uploadStarts = () =>
          socket.sent.filter((frame) => frame.action === UPLOAD_ACTION_INIT)
        uploadFile('products', new File(['card picture'], 'card.txt'))
        expect(uploadStarts()).toHaveLength(0)
        socket.message(productAnswer('a'))
        expect(matcher).toHaveBeenCalledTimes(matched ? 1 : 0)
        expect(diagnostic).toHaveBeenCalledOnce()
        expect(uploadStarts()).toHaveLength(boundary === 'scope ingest' ? 1 : 0)
        expect(early).toHaveBeenCalledTimes(
          boundary === 'table preparation' ? 0 : 1,
        )
        expect(late).not.toHaveBeenCalled()
        expect(window).not.toHaveBeenCalled()
        expect(scopes.page()?.data.signal('slug').get()).toBeUndefined()
        expect(router.pageLoading.get()).toBe(true)
        const replay = vi.fn()
        connection.on('projectSignal', replay)()
        expect(replay).not.toHaveBeenCalled()
        // The latch runs after scope ingestion. A synchronous navigation from
        // that ingestion must leave it waiting for B.
        expect(await isSettled(waiting)).toBe(false)

        socket.message(productAnswer('b'))
        expect(scopes.page()?.data.signal('slug').get()).toBe('b')
        expect(router.pageLoading.get()).toBe(false)
        expect(await isSettled(waiting)).toBe(true)
        expect(late).toHaveBeenCalledOnce()
        expect(window).toHaveBeenCalledOnce()
        expect(uploadStarts()).toHaveLength(1)
      } finally {
        stopScope()
        stopTable()
        router.stop()
        connection.close()
      }
    },
  )

  it('stops an ordinary page answer during socket replacement and accepts the new answer', () => {
    const first = scriptedSocket()
    const second = scriptedSocket()
    const { connection } = createHilosConnection({
      url: 'ws://test/ws',
      webSocketFactory: vi
        .fn()
        .mockReturnValueOnce(first.socket)
        .mockReturnValueOnce(second.socket),
    })
    const stop = connection.on('projectSignal', (signal) => {
      if (signal.type !== SIGNAL_TYPE_PAGE_RESPONSE) return
      stop()
      connection.close()
      connection.connect()
      second.open()
    })
    const pages = bindPageScope(connection, new ScopeManager())
    const scope = pages.subscribe('product', { slug: 'a' })
    const slug = scope.data.signal('slug')
    const late = vi.fn()
    connection.on('projectSignal', late)
    try {
      connection.connect()
      first.open()
      pages.releaseOnSession()
      first.message(productAnswer('old'))

      expect(slug.get()).toBeUndefined()
      expect(pages.pageLoading.get()).toBe(true)
      expect(late).not.toHaveBeenCalled()
      expect(second.sent).toEqual([])

      pages.releaseOnSession()
      second.message(productAnswer('a'))
      expect(slug.get()).toBe('a')
      expect(pages.pageLoading.get()).toBe(false)
      expect(late).toHaveBeenCalledOnce()
    } finally {
      connection.close()
    }
  })

  it.each([
    { httpCode: 404, matcher: true, boundary: 'binding' },
    { httpCode: 401, matcher: false, boundary: 'opening' },
    { httpCode: 404, matcher: false, boundary: 'error observer' },
    { httpCode: 404, matcher: true, boundary: 'renewed wait' },
    { httpCode: 401, matcher: true, boundary: 'socket' },
  ] as const)(
    'delivers $httpCode only to its original owner during a change at $boundary',
    async ({ httpCode, matcher, boundary }) => {
      vi.resetModules()
      const { createHilosConnection: createConnection } =
        await import('../../src/connection/createHilosConnection.js')
      const { bindPageScope: bindScope } =
        await import('../../src/subscription/bindPageScope.js')
      const { bindPageReady, whenPageReady } =
        await import('../../src/subscription/pageReadyGate.js')
      const { bindUploads, uploadFile } =
        await import('../../src/uploads/hilosUploads.js')
      const first = scriptedSocket()
      const second = scriptedSocket()
      const { connection, actions } = createConnection({
        url: 'ws://test/ws',
        webSocketFactory: vi
          .fn()
          .mockReturnValueOnce(first.socket)
          .mockReturnValueOnce(second.socket),
      })
      const matchers = matcher
        ? new Map<string, PageResponseMatcher>([
            [
              'product',
              (params, payload) => params.slug === payload.data?.slug,
            ],
          ])
        : undefined
      const options = { pageResponseMatchers: matchers }
      let scopes = new ScopeManager()
      let pages: ReturnType<typeof bindScope>
      const replaceOpening = () => {
        if (boundary === 'binding') {
          scopes = new ScopeManager()
          pages = bindScope(connection, scopes, options)
        }
        pages.subscribe('product', { slug: 'b' })
      }
      const stopEarly = connection.on('projectSignal', (signal) => {
        if (
          signal.type !== SIGNAL_TYPE_PAGE_SUBSCRIPTION_ERROR ||
          boundary === 'error observer' ||
          boundary === 'renewed wait'
        )
          return
        stopEarly()
        if (boundary === 'socket') {
          connection.close()
          connection.connect()
          second.open()
        }
        replaceOpening()
      })
      pages = bindScope(connection, scopes, options)
      pages.subscribe('product', { slug: 'a' })
      const stopError = subscribeSignal(pages.pageError, (error) => {
        if (error === null) return
        if (boundary === 'renewed wait') {
          stopError()
          pages.awaitPageAnswer()
        } else if (boundary === 'error observer') {
          stopError()
          replaceOpening()
        }
      })
      const stopReady = bindPageReady(connection)
      const waiting = whenPageReady()
      const unbindUploads = bindUploads(
        connection,
        actions,
        createSignal<number | null>(null),
      )
      const target = boundary === 'socket' ? second : first
      const uploadStarts = () =>
        target.sent.filter((frame) => frame.action === UPLOAD_ACTION_INIT)
      try {
        connection.connect()
        first.open()
        first.message(sessionAnswer(7, 'Asia/Tokyo'))
        pages.releaseOnSession()
        uploadFile('products', new File(['picture'], 'product.txt'))
        first.message(productRefusal(httpCode))

        expect(pages.pageError.get()).toBeNull()
        expect(pages.pageLoading.get()).toBe(true)
        expect(await isSettled(waiting)).toBe(false)
        expect(uploadStarts()).toEqual([])
        const opening = scopes.page()
        expect(opening?.data.signal('slug').get()).toBeUndefined()

        if (boundary === 'socket') {
          second.message(sessionAnswer(7, 'Asia/Tokyo'))
          pages.releaseOnSession()
          uploadFile('products', new File(['picture'], 'product.txt'))
        }
        target.message(productRefusal(httpCode))
        expect(pages.pageError.get()?.httpCode).toBe(httpCode)
        expect(pages.pageLoading.get()).toBe(false)
        expect(scopes.page()).not.toBe(opening)
        expect(await isSettled(waiting)).toBe(true)
        expect(uploadStarts()).toHaveLength(1)

        const slug = boundary === 'renewed wait' ? 'a' : 'b'
        target.message(productAnswer(slug, false))
        expect(scopes.page()?.data.signal('slug').get()).toBe(slug)
        expect(pages.pageError.get()?.httpCode ?? null).toBe(
          httpCode === 401 ? 401 : null,
        )
        pages.clearPageError()
        expect(pages.pageError.get()).toBeNull()
      } finally {
        stopEarly()
        stopError()
        stopReady()
        unbindUploads()
        connection.close()
      }
    },
  )

  it.each(['page_moved', 'page_resubscribe'] as const)(
    'keeps a %s with its original binding before accepting its replacement’s frame',
    (type) => {
      const socket = scriptedSocket()
      const { connection } = createHilosConnection({
        url: 'ws://test/ws',
        webSocketFactory: () => socket.socket,
      })
      const frame =
        type === 'page_moved'
          ? productMove('a', 'b')
          : { type, data: { page: 'product', params: { slug: 'a' } } }
      const routes = createAppPageRouter({
        product: { path: '/product/{slug}', admin: false },
      })
      const scopes = new ScopeManager()
      const scopeB = new ScopeManager()
      let routerB: ReturnType<typeof createHilosRouter> | undefined
      const before = type === 'page_moved' ? 'projectSignal' : 'pageResubscribe'
      const moved = vi.fn()
      const afterMove = vi.fn()
      const stopEarly = connection.on(before, (signal) => {
        if (signal.kind === 'project' && signal.type !== type) return
        stopEarly()
        const pagesB = bindPageScope(connection, scopeB)
        routerB = createHilosRouter(
          routes,
          pagesB,
          fakeNavigation('/product/a'),
        )
        pagesB.onPageMoved(moved)
        bindPageMove(pagesB, routerB)
        pagesB.onPageMoved(afterMove)
        routerB.start()
      })
      const pagesA = bindPageScope(connection, scopes)
      const routerA = createHilosRouter(
        routes,
        pagesA,
        fakeNavigation('/product/a'),
      )
      bindPageMove(pagesA, routerA)
      routerA.start()
      try {
        connection.connect()
        socket.open()
        socket.message(sessionAnswer(7, 'Asia/Tokyo'))
        pagesA.releaseOnSession()
        socket.message(frame)

        expect(routerA.currentPath.get()).toBe('/product/a')
        expect(routerB?.currentPath.get()).toBe('/product/a')
        expect(moved).not.toHaveBeenCalled()
        expect(afterMove).not.toHaveBeenCalled()
        expect(socket.sent).toEqual([
          { type: 'page_subscribe', page: 'product', params: { slug: 'a' } },
          { type: 'page_subscribe', page: 'product', params: { slug: 'a' } },
        ])
        const scope = scopeB.page()
        const window: TableViewportDescriptor = {
          limit: 20,
          filter: { search: 'in stock' },
          sort: null,
          anchor: null,
          anchorDirection: 'after',
          pageIndex: 2,
        }
        connection.registerTableWindow('products', { descriptor: () => window })
        socket.message(frame)

        const slug = type === 'page_moved' ? 'b' : 'a'
        expect(routerB?.currentPath.get()).toBe('/product/' + slug)
        expect(moved).toHaveBeenCalledTimes(type === 'page_moved' ? 1 : 0)
        if (type === 'page_moved') {
          expect(afterMove).toHaveBeenCalledExactlyOnceWith({
            page: 'product',
            params: { slug: 'b' },
          })
        } else {
          expect(afterMove).not.toHaveBeenCalled()
        }
        expect(scopeB.page()).toBe(scope)
        expect(socket.sent.at(-1)).toEqual({
          type: 'page_subscribe',
          page: 'product',
          params: { slug },
          tableWindows: { products: window },
        })
        expect(socket.sent).toHaveLength(3)
      } finally {
        stopEarly()
        routerA.stop()
        routerB?.stop()
        connection.close()
      }
    },
  )

  it.each([
    { boundary: 'project listener', slug: 'a' },
    { boundary: 'route observer', slug: 'a' },
    { boundary: 'resubscribe window descriptor', slug: 'a' },
    { boundary: 'move window descriptor', slug: 'b' },
    { boundary: 'newer navigation', slug: 'c' },
    { boundary: 'completed move', slug: 'b' },
  ] as const)(
    'keeps the browser and the next subscription together after $boundary',
    ({ boundary, slug }) => {
      const first = scriptedSocket()
      const second = scriptedSocket()
      const { connection, actions } = createHilosConnection({
        url: 'ws://test/ws',
        webSocketFactory: vi
          .fn()
          .mockReturnValueOnce(first.socket)
          .mockReturnValueOnce(second.socket),
      })
      const changeSocket = () => {
        connection.close()
        connection.connect()
      }
      const stopEarly = connection.on('projectSignal', (signal) => {
        if (
          boundary === 'project listener' &&
          signal.type === SIGNAL_TYPE_PAGE_MOVED
        )
          changeSocket()
      })
      const scopes = new ScopeManager()
      const navigation = fakeNavigation('/product/a')
      const router = bootHilos({
        connection,
        actions,
        scopes,
        viewLayer: 'vue',
        router: createAppPageRouter({
          product: { path: '/product/{slug}', admin: false },
        }),
        navigationEnvironment: navigation,
      })
      const stopRoute = subscribeSignal(router.currentRoute, (route) => {
        if (route.params.slug !== 'b') return
        if (boundary === 'route observer' || boundary === 'newer navigation') {
          changeSocket()
        }
        if (boundary === 'newer navigation') router.navigate('/product/c')
      })
      const observe = vi.fn()
      const stopMove = pageSubscriptionFor(connection).onPageMoved(observe)

      try {
        first.open()
        first.message(sessionAnswer(7, 'Asia/Tokyo'))
        const scope = scopes.page()
        if (
          boundary === 'resubscribe window descriptor' ||
          boundary === 'move window descriptor'
        ) {
          const source = {
            descriptor: () => {
              connection.unregisterTableWindow('products', source)
              changeSocket()
              return null
            },
          }
          connection.registerTableWindow('products', source)
        }
        if (boundary === 'resubscribe window descriptor') {
          first.message({
            type: 'page_resubscribe',
            data: { page: 'product', params: { slug: 'a' } },
          })
        } else {
          first.message(productMove('a', 'b'))
        }
        expect(navigation.pathname()).toBe('/product/' + slug)
        expect(router.currentPath.get()).toBe('/product/' + slug)
        expect(router.currentRoute.get()).toMatchObject({
          page: 'product',
          params: { slug },
        })
        const subscription = {
          type: 'page_subscribe',
          page: 'product',
          params: { slug },
        }
        if (boundary === 'completed move') {
          expect(first.sent).toHaveLength(2)
          expect(first.sent.at(-1)).toEqual(subscription)
          expect(observe).toHaveBeenCalledExactlyOnceWith({
            page: 'product',
            params: { slug },
          })
          changeSocket()
        } else {
          expect(first.sent).toHaveLength(1)
          expect(observe).not.toHaveBeenCalled()
        }
        expect(second.sent).toEqual([])
        if (boundary !== 'newer navigation') expect(scopes.page()).toBe(scope)

        second.open()
        second.message(sessionAnswer(7, 'America/New_York'))
        expect(second.sent).toEqual([subscription])
      } finally {
        stopEarly()
        stopRoute()
        stopMove()
        router.stop()
        connection.close()
      }
    },
  )

  it.each(['accept', 'interrupt', 'navigate'] as const)(
    'retains the matching address owner when a move observer chooses to %s',
    (outcome) => {
      const first = scriptedSocket()
      const second = scriptedSocket()
      const { connection, actions } = createHilosConnection({
        url: 'ws://test/ws',
        webSocketFactory: vi
          .fn()
          .mockReturnValueOnce(first.socket)
          .mockReturnValueOnce(second.socket),
      })
      const scopes = new ScopeManager()
      const navigation = fakeNavigation('/product/a')
      const router = bootHilos({
        connection,
        actions,
        scopes,
        viewLayer: 'vue',
        router: createAppPageRouter({
          product: { path: '/product/{slug}', admin: false },
        }),
        navigationEnvironment: navigation,
      })
      const reconnect = () => {
        connection.close()
        connection.connect()
      }
      const canonicalPath = '/product/%62'
      const observeMove = vi.fn()
      const stopMove = pageSubscriptionFor(connection).onPageMoved(observeMove)
      const stopRoute = subscribeSignal(router.currentRoute, (route) => {
        if (route.params.slug !== 'b') return
        stopRoute()
        if (outcome === 'navigate') {
          router.navigate(canonicalPath)
          return
        }
        router.replacePath(canonicalPath)
        if (outcome === 'interrupt') reconnect()
      })

      try {
        first.open()
        first.message(sessionAnswer(7, 'Asia/Tokyo'))
        first.message(productRefusal(404))
        const scope = scopes.page()
        const error = router.pageError.get()
        const window: TableViewportDescriptor = {
          limit: 20,
          filter: { search: 'in stock' },
          sort: null,
          anchor: null,
          anchorDirection: 'after',
          pageIndex: 2,
        }
        connection.registerTableWindow('products', {
          descriptor: (opening) => (opening ? null : window),
        })

        first.message(productMove('a', 'b'))

        const interrupted = outcome === 'interrupt'
        const path = interrupted ? '/product/a' : canonicalPath
        const slug = interrupted ? 'a' : 'b'
        expect(navigation.pathname()).toBe(path)
        expect(router.currentPath.get()).toBe(path)
        expect(router.currentRoute.get()).toMatchObject({
          page: 'product',
          params: { slug },
        })
        const subscriptionA = {
          type: 'page_subscribe',
          page: 'product',
          params: { slug: 'a' },
        }
        const subscriptionB = { ...subscriptionA, params: { slug: 'b' } }
        if (outcome === 'navigate') {
          expect(first.sent).toEqual([subscriptionA, subscriptionB])
          expect(scopes.page()).not.toBe(scope)
          expect(router.pageLoading.get()).toBe(true)
          expect(router.pageError.get()).toBeNull()
        } else {
          expect(scopes.page()).toBe(scope)
          expect(router.pageLoading.get()).toBe(false)
          expect(router.pageError.get()).toBe(error)
        }
        if (interrupted) {
          expect(first.sent).toEqual([subscriptionA])
          expect(observeMove).not.toHaveBeenCalled()
          expect(second.sent).toEqual([])
          second.open()
          second.message(sessionAnswer(7, 'Asia/Tokyo'))
          expect(second.sent).toEqual([
            { ...subscriptionA, tableWindows: { products: window } },
          ])
          expect(scopes.page()).toBe(scope)
        } else {
          expect(observeMove).toHaveBeenCalledExactlyOnceWith({
            page: 'product',
            params: { slug: 'b' },
          })
          if (outcome === 'accept') {
            expect(first.sent).toEqual([
              subscriptionA,
              { ...subscriptionB, tableWindows: { products: window } },
            ])
          }
        }
      } finally {
        stopMove()
        stopRoute()
        router.stop()
        connection.close()
      }
    },
  )

  it.each(['dismiss', 'upgrade', 'accept'] as const)(
    'resumes the opening that owns a 401 when the move observer runs %s',
    (outcome) => {
      const first = scriptedSocket()
      const second = scriptedSocket()
      const { connection, actions } = createHilosConnection({
        url: 'ws://test/ws',
        webSocketFactory: vi
          .fn()
          .mockReturnValueOnce(first.socket)
          .mockReturnValueOnce(second.socket),
      })
      const scopes = new ScopeManager()
      const navigation = fakeNavigation('/product/a')
      const router = bootHilos({
        connection,
        actions,
        scopes,
        viewLayer: 'vue',
        router: createAppPageRouter({
          product: { path: '/product/{slug}', admin: false },
          category: { path: '/category/{slug}', admin: false },
        }),
        navigationEnvironment: navigation,
      })
      const state = createPageViewState<string>(router)
      state.setPages({ product: 'Product view', category: 'Category view' })
      const gate = createAuthGate({
        router,
        currentUserId: sessionUserId(scopes),
        pendingAck: sessionPendingAck(scopes),
      })
      const signedIn = sessionAnswer(7, 'Asia/Tokyo')
      const stopRoute = subscribeSignal(router.currentRoute, (route) => {
        if (route.page !== 'category') return
        expect(state.loading.get()).toBe(true)
        expect(router.pageError.get()).toBeNull()
        expect(state.error.get()).toBeNull()
        if (outcome === 'upgrade') {
          first.message(signedIn)
        } else {
          gate.dismiss()
        }
        expect(gate.modalOpen.get()).toBe(false)
        expect(router.pageLoading.get()).toBe(true)
        expect(router.pageError.get()).toBeNull()
        expect(state.error.get()).toBeNull()
        if (outcome === 'accept') {
          expect(pageSubscriptionFor(connection).pageError.get()).toBeNull()
        } else {
          connection.close()
          connection.connect()
        }
      })

      try {
        first.open()
        first.message(sessionAnswer(null, 'Asia/Tokyo'))
        first.message(productRefusal(401))
        const scope = scopes.page()
        if (outcome !== 'upgrade') {
          first.message({
            ...signedIn,
            data: {
              ...signedIn.data,
              data: {
                ...signedIn.data.data,
                pendingAck: SESSION_ACK_REGISTERED,
              },
            },
          })
        } else {
          gate.requireAuth()
        }
        first.message(productAnswer('a', false))
        expect(gate.modalOpen.get()).toBe(true)
        expect(router.pageError.get()?.httpCode).toBe(401)
        expect(scopes.page()).toBe(scope)

        const window: TableViewportDescriptor = {
          limit: 20,
          filter: { search: 'in stock' },
          sort: null,
          anchor: null,
          anchorDirection: 'after',
          pageIndex: 2,
        }
        connection.registerTableWindow('products', {
          descriptor: (opening) => (opening ? null : window),
        })
        const subscriptionA = {
          type: 'page_subscribe',
          page: 'product',
          params: { slug: 'a' },
        }
        first.message(productMove('a', 'b', 'category'))

        if (outcome === 'accept') {
          expect(first.sent).toEqual([
            subscriptionA,
            { type: 'page_subscribe', page: 'category', params: { slug: 'b' } },
          ])
          expect(scopes.page()).not.toBe(scope)
          const refusal = productRefusal(401)
          first.message({
            ...refusal,
            data: { ...refusal.data, page: 'category' },
          })
          first.message(signedIn)
          expect(router.pageError.get()).toMatchObject({
            page: 'category',
            httpCode: 401,
          })
          return
        }

        expect(navigation.pathname()).toBe('/product/a')
        expect(first.sent).toEqual([subscriptionA])
        expect(second.sent).toEqual([])
        expect(scopes.page()).toBe(scope)
        expect(router.pageError.get()).toBeNull()
        expect(state.error.get()).toBeNull()
        expect(state.loading.get()).toBe(false)
        expect(gate.modalOpen.get()).toBe(false)

        second.open()
        second.message(signedIn)
        expect(second.sent).toEqual([
          { ...subscriptionA, tableWindows: { products: window } },
        ])
        second.message(productAnswer('a', false))
        expect(scopes.page()).toBe(scope)
        expect(state.view.get()).toBe('Product view')
        expect(state.loading.get()).toBe(false)
      } finally {
        stopRoute()
        state.dispose()
        router.stop()
        connection.close()
      }
    },
  )

  it('waits for a new answer after an admin grant to the refused opening behind a move', () => {
    const first = scriptedSocket()
    const second = scriptedSocket()
    const { connection, actions } = createHilosConnection({
      url: 'ws://test/ws',
      webSocketFactory: vi
        .fn()
        .mockReturnValueOnce(first.socket)
        .mockReturnValueOnce(second.socket),
    })
    const scopes = new ScopeManager()
    const navigation = fakeNavigation('/product/a')
    const router = bootHilos({
      connection,
      actions,
      scopes,
      viewLayer: 'vue',
      router: createAppPageRouter({
        product: { path: '/product/{slug}', admin: true },
        category: { path: '/category/{slug}', admin: false },
      }),
      navigationEnvironment: navigation,
    })
    const state = createPageViewState<string>(router)
    state.setPages({ product: 'Product view', category: 'Category view' })
    const signedIn = sessionAnswer(7, 'Asia/Tokyo')
    const grant = {
      ...signedIn,
      data: {
        ...signedIn.data,
        data: { ...signedIn.data.data, adminViewMode: false },
        entities: {
          currentUser: { id: 7, admin: true, timeZone: 'Asia/Tokyo' },
        },
      },
    }
    const stopRoute = subscribeSignal(router.currentRoute, (route) => {
      if (route.page !== 'category') return
      expect(route.admin).toBe(false)
      expect(router.pageError.get()).toBeNull()
      expect(state.error.get()).toBeNull()
      first.message(grant)
      expect(hilosAdminAccess.get()).toBe('full')
      expect(router.pageError.get()).toBeNull()
      expect(state.error.get()).toBeNull()
      expect(state.loading.get()).toBe(true)
      connection.close()
      connection.connect()
    })

    try {
      first.open()
      first.message(signedIn)
      first.message(productRefusal(403))
      expect(router.pageError.get()?.httpCode).toBe(403)
      expect(state.loading.get()).toBe(false)
      const refusedScope = scopes.page()
      const window: TableViewportDescriptor = {
        limit: 20,
        filter: { search: 'in stock' },
        sort: null,
        anchor: null,
        anchorDirection: 'after',
        pageIndex: 2,
      }
      const source = {
        descriptor: () => window,
        retainWindow: () => ({ current: window, opening: window }),
      }
      connection.registerTableWindow('products', source)
      const stopLoading = subscribeSignal(
        pageSubscriptionFor(connection).pageLoading,
        (loading) => {
          if (loading) connection.unregisterTableWindow('products', source)
        },
      )
      try {
        first.message(productMove('a', 'b', 'category'))
      } finally {
        stopLoading()
      }

      expect(navigation.pathname()).toBe('/product/a')
      expect(router.pageError.get()).toBeNull()
      expect(state.error.get()).toBeNull()
      expect(state.loading.get()).toBe(true)
      const waitingScope = scopes.page()
      expect(waitingScope).not.toBe(refusedScope)
      const subscriptionA = {
        type: 'page_subscribe',
        page: 'product',
        params: { slug: 'a' },
      }
      expect(first.sent).toEqual([subscriptionA])

      second.open()
      second.message(grant)
      expect(second.sent).toEqual([
        { ...subscriptionA, tableWindows: { products: window } },
      ])
      expect(scopes.page()).toBe(waitingScope)
      expect(state.loading.get()).toBe(true)
      second.message(productAnswer('a', false))
      expect(state.loading.get()).toBe(false)
      expect(state.view.get()).toBe('Product view')
    } finally {
      stopRoute()
      state.dispose()
      router.stop()
      connection.close()
    }
  })

  it.each([
    { opening: 'a', status: 401 },
    { opening: 'c', status: 404 },
  ] as const)(
    'keeps the newer refusal when retaining controls during a grant ($opening)',
    ({ opening, status }) => {
      const first = scriptedSocket()
      const second = scriptedSocket()
      const { connection, actions } = createHilosConnection({
        url: 'ws://test/ws',
        webSocketFactory: vi
          .fn()
          .mockReturnValueOnce(first.socket)
          .mockReturnValueOnce(second.socket),
      })
      const scopes = new ScopeManager()
      const navigation = fakeNavigation('/product/a')
      const router = bootHilos({
        connection,
        actions,
        scopes,
        viewLayer: 'vue',
        router: createAppPageRouter({
          product: { path: '/product/{slug}', admin: true },
          category: { path: '/category/{slug}', admin: false },
        }),
        navigationEnvironment: navigation,
      })
      const pages = pageSubscriptionFor(connection)
      const signedIn = sessionAnswer(7, 'Asia/Tokyo')
      const grant = {
        ...signedIn,
        data: {
          ...signedIn.data,
          data: { ...signedIn.data.data, adminViewMode: false },
          entities: {
            currentUser: { id: 7, admin: true, timeZone: 'Asia/Tokyo' },
          },
        },
      }
      const deliverRefusal = () => {
        if (opening === 'c') router.navigate('/product/c')
        first.message(productRefusal(status))
        return { error: pages.pageError.get(), scope: scopes.page() }
      }
      let refused: ReturnType<typeof deliverRefusal> | undefined
      const stopRoute = subscribeSignal(router.currentRoute, (route) => {
        if (route.page !== 'category') return
        first.message(grant)
        expect(hilosAdminAccess.get()).toBe('full')
        connection.close()
        connection.connect()
      })

      try {
        first.open()
        first.message(signedIn)
        first.message(productRefusal(403))
        const originalScope = scopes.page()
        expect(router.pageError.get()?.httpCode).toBe(403)

        const window: TableViewportDescriptor = {
          limit: 20,
          filter: { search: 'in stock' },
          sort: null,
          anchor: null,
          anchorDirection: 'after',
          pageIndex: 2,
        }
        const retainWindow = vi.fn(() => {
          refused = deliverRefusal()
          return { current: window, opening: window }
        })
        const source = { descriptor: () => null, retainWindow }
        const later = vi.fn(() => null)
        connection.registerTableWindow('products', source)
        connection.registerTableWindow('availability', {
          descriptor: () => null,
          retainWindow: later,
        })
        first.message(productMove('a', 'b', 'category'))
        connection.unregisterTableWindow('products', source)

        expect(retainWindow).toHaveBeenCalledTimes(1)
        expect(refused?.error?.httpCode).toBe(status)
        expect(pages.pageError.get()).toBe(refused?.error)
        expect(router.pageError.get()).toBe(refused?.error)
        expect(scopes.page()).toBe(refused?.scope)
        expect(scopes.page()).not.toBe(originalScope)
        expect(router.pageLoading.get()).toBe(false)
        expect(navigation.pathname()).toBe(`/product/${opening}`)
        expect(later).not.toHaveBeenCalled()
        expect(connection.tableWindowDescriptors()).toEqual({})

        const subscribe = (slug: string) => ({
          type: 'page_subscribe',
          page: 'product',
          params: { slug },
        })
        expect(first.sent).toEqual(
          opening === 'c' ? [subscribe('a'), subscribe('c')] : [subscribe('a')],
        )
        second.open()
        second.message(grant)
        expect(second.sent).toEqual([subscribe(opening)])
        expect(router.pageError.get()).toBe(refused?.error)
        expect(scopes.page()).toBe(refused?.scope)
      } finally {
        stopRoute()
        router.stop()
        connection.close()
      }
    },
  )

  it.each(['a', 'different'] as const)(
    'lets only an applied answer finish the grant wait during a move (%s)',
    (answerSlug) => {
      const first = scriptedSocket()
      const { connection, actions } = createHilosConnection({
        url: 'ws://test/ws',
        webSocketFactory: () => first.socket,
      })
      const scopes = new ScopeManager()
      const navigation = fakeNavigation('/product/a')
      const router = bootHilos({
        connection,
        actions,
        scopes,
        viewLayer: 'vue',
        router: createAppPageRouter({
          product: { path: '/product/{slug}', admin: true },
          category: { path: '/category/{slug}', admin: false },
        }),
        pageResponseMatchers: new Map<string, PageResponseMatcher>([
          ['product', (params, payload) => params.slug === payload.data?.slug],
        ]),
        navigationEnvironment: navigation,
      })
      const signedIn = sessionAnswer(7, 'Asia/Tokyo')
      const grant = {
        ...signedIn,
        data: {
          ...signedIn.data,
          data: { ...signedIn.data.data, adminViewMode: false },
          entities: {
            currentUser: { id: 7, admin: true, timeZone: 'Asia/Tokyo' },
          },
        },
      }
      const stopRoute = subscribeSignal(router.currentRoute, (route) => {
        if (route.page === 'category') first.message(grant)
      })
      const lateAnswer = vi.fn()
      const stopAnswer = connection.on('projectSignal', (signal) => {
        if (signal.type === SIGNAL_TYPE_PAGE_RESPONSE) {
          lateAnswer(isCurrentProjectSignal(signal))
        }
      })
      const newController = () =>
        new TableViewportController<TableRow>({
          resolve: (row) => row,
          sendViewport: () => {},
          frame: { columns: [] },
        })
      const bindProducts = (viewport: TableViewportController<TableRow>) =>
        bindTableViewport(
          connection,
          scopes,
          { page: 'product', tableKey: 'products' },
          viewport,
        )
      const controller = newController()
      const window = {
        limit: 20,
        filter: { search: 'in stock' },
        sort: null,
        anchor: null,
        anchorDirection: 'after',
        pageIndex: 2,
      } satisfies TableViewportDescriptor
      const continuation = { current: window, opening: window }
      const earlier = {
        descriptor: () => null,
        retainWindow: vi.fn(() => continuation),
      }
      const ingestWindow = vi.spyOn(controller, 'ingestSubscriptionWindow')
      const servedWindow = {
        rows: [],
        totalCount: 0,
        totalExact: true,
        firstAnchor: null,
        lastAnchor: null,
        limit: 12,
        sort: [{ field: 'name', direction: 'asc' }],
        filter: {},
        rowsBefore: 0,
      }
      const response = productAnswer(answerSlug)
      const answer = {
        ...response,
        data: {
          ...response.data,
          payload: {
            ...response.data.payload,
            data: { slug: answerSlug, name: 'Product A' },
            windows: { products: servedWindow },
          },
        },
      }
      let stopWindow: (() => void) | undefined

      try {
        first.open()
        first.message(signedIn)
        first.message(productRefusal(403))
        const scope = scopes.page()
        expect(router.pageError.get()?.httpCode).toBe(403)

        // The answer carries products only, so the earlier source cannot
        // consume its own continuation from the answer.
        connection.registerTableWindow('availability', earlier)
        stopWindow = bindProducts(controller)
        const retainWindow = vi
          .spyOn(controller, 'retainWindow')
          .mockImplementationOnce(() => {
            first.message(answer)
            return continuation
          })
        first.message(productMove('a', 'b', 'category'))

        expect(earlier.retainWindow).toHaveBeenCalledOnce()
        expect(retainWindow).toHaveBeenCalledOnce()
        expect(navigation.pathname()).toBe('/product/a')
        expect(router.currentPath.get()).toBe('/product/a')
        expect(router.pageError.get()).toBeNull()
        expect(first.sent).toEqual([
          { type: 'page_subscribe', page: 'product', params: { slug: 'a' } },
        ])
        stopWindow()
        stopWindow = undefined
        connection.unregisterTableWindow('availability', earlier)

        if (answerSlug === 'a') {
          expect(connection.tableWindowDescriptors()).toEqual({})
          expect(scopes.page()).toBe(scope)
          expect(scope?.data.signal('slug').get()).toBe('a')
          expect(scope?.data.signal('name').get()).toBe('Product A')
          expect(router.pageLoading.get()).toBe(false)
          expect(lateAnswer.mock.calls).toEqual([[true]])
          const expectedWindow = [
            [],
            0,
            true,
            null,
            null,
            12,
            [{ field: 'name', direction: 'asc' }],
            {},
            [],
            0,
          ]
          expect(ingestWindow).toHaveBeenCalledExactlyOnceWith(
            ...expectedWindow,
          )
          expect(controller.loaded.get()).toBe(true)

          const remounted = newController()
          const replay = vi.spyOn(remounted, 'ingestSubscriptionWindow')
          const stopReplay = bindProducts(remounted)
          try {
            expect(replay).toHaveBeenCalledExactlyOnceWith(...expectedWindow)
            expect(remounted.loaded.get()).toBe(true)
            expect(scopes.page()).toBe(scope)
            expect(first.sent).toEqual([
              {
                type: 'page_subscribe',
                page: 'product',
                params: { slug: 'a' },
              },
            ])
          } finally {
            stopReplay()
            remounted.dispose()
          }
        } else {
          expect(connection.tableWindowDescriptors()).toEqual({
            availability: window,
            products: window,
          })
          expect(scopes.page()).not.toBe(scope)
          expect(router.pageLoading.get()).toBe(true)
          expect(lateAnswer).not.toHaveBeenCalled()
          expect(ingestWindow).not.toHaveBeenCalled()
        }
      } finally {
        stopWindow?.()
        controller.dispose()
        stopAnswer()
        stopRoute()
        router.stop()
        connection.close()
      }
    },
  )

  const historyCases = [
    {
      action: 'replace',
      phase: 'write',
      entry: '/product/%61',
      path: '/product/%61',
      disconnect: false,
      newScope: false,
    },
    {
      action: 'replace',
      phase: 'write',
      entry: '/product/%61',
      path: '/product/%61',
      disconnect: true,
      newScope: false,
    },
    {
      action: 'none',
      phase: 'write',
      entry: null,
      path: '/product/a',
      disconnect: true,
      newScope: false,
    },
    {
      action: 'navigate',
      phase: 'write',
      entry: '/product/c',
      path: '/product/c',
      disconnect: false,
      newScope: true,
    },
    {
      action: 'navigate',
      phase: 'write',
      entry: '/product/%62',
      path: '/product/%62',
      disconnect: false,
      newScope: true,
    },
    {
      action: 'navigate',
      phase: 'rollback',
      entry: '/product/c',
      path: '/product/c',
      disconnect: true,
      newScope: true,
    },
    {
      action: 'navigate',
      phase: 'rollback',
      entry: '/product/%62',
      path: '/product/%62',
      disconnect: true,
      newScope: true,
    },
    {
      action: 'replace',
      phase: 'rollback',
      entry: '/product/%61',
      path: '/product/%61',
      disconnect: true,
      newScope: false,
    },
    {
      action: 'replace',
      phase: 'rollback',
      entry: '/product/%62',
      path: '/product/a',
      disconnect: true,
      newScope: false,
    },
    {
      action: 'replace',
      phase: 'rollback',
      entry: '/product/%62',
      path: '/product/a',
      disconnect: true,
      newScope: false,
      compose: 'rollback',
    },
    {
      action: 'replace',
      phase: 'rollback',
      entry: '/product/%62',
      path: '/product/%61',
      disconnect: true,
      newScope: false,
      compose: 'source',
    },
    {
      action: 'replace',
      phase: 'rollback',
      entry: '/product/%62',
      path: '/product/%61',
      disconnect: true,
      newScope: false,
      compose: 'restore-source',
    },
    {
      action: 'replace',
      phase: 'rollback',
      entry: '/product/%62',
      path: '/product/%62',
      disconnect: true,
      newScope: true,
      compose: 'correction',
    },
    {
      action: 'replace',
      phase: 'rollback',
      entry: '/product/%62',
      path: '/product/%62',
      disconnect: true,
      newScope: true,
      compose: 'source',
    },
    {
      action: 'replace',
      phase: 'observe',
      entry: '/product/%62',
      path: '/product/%62',
      disconnect: false,
      newScope: false,
      compose: 'source',
    },
    {
      action: 'replace',
      phase: 'target-correction',
      entry: '/product/%62',
      path: '/product/%62',
      disconnect: false,
      newScope: false,
      compose: 'source',
    },
    {
      action: 'replace',
      phase: 'target-correction',
      entry: '/product/%62',
      path: '/product/%61',
      disconnect: true,
      newScope: false,
      compose: 'source',
    },
    {
      action: 'replace',
      phase: 'target-correction',
      entry: '/product/%62',
      path: '/product/%61',
      disconnect: false,
      newScope: false,
      compose: 'reverse',
    },
    {
      action: 'replace',
      phase: 'target-correction',
      entry: '/product/%62',
      path: '/product/%61',
      disconnect: false,
      newScope: false,
      compose: 'restore-source',
    },
    {
      action: 'replace',
      phase: 'nested',
      entry: '/product/%62',
      path: '/product/a',
      disconnect: true,
      newScope: false,
    },
    {
      action: 'replace',
      phase: 'nested',
      entry: '/product/%62',
      path: '/product/d',
      disconnect: false,
      newScope: false,
    },
    {
      action: 'replace',
      phase: 'source-reentry',
      entry: '/product/%62',
      path: '/product/%61',
      disconnect: true,
      newScope: false,
    },
    {
      action: 'replace',
      phase: 'source-reentry',
      entry: '/product/%62',
      path: '/product/%62',
      disconnect: false,
      newScope: false,
    },
    {
      action: 'replace',
      phase: 'nested-write',
      entry: '/product/%64',
      path: '/product/%64',
      disconnect: false,
      newScope: false,
    },
    {
      action: 'replace',
      phase: 'nested-write',
      entry: '/product/%64',
      path: '/product/%61',
      disconnect: true,
      newScope: false,
    },
    {
      action: 'replace',
      phase: 'nested-correction',
      entry: '/product/%64',
      path: '/product/%64',
      disconnect: false,
      newScope: false,
    },
    {
      action: 'replace',
      phase: 'nested-correction',
      entry: '/product/%64',
      path: '/product/%61',
      disconnect: true,
      newScope: false,
    },
    {
      action: 'replace',
      phase: 'nested-correction',
      entry: '/product/d',
      path: '/product/%61',
      disconnect: false,
      newScope: false,
      compose: 'reverse',
    },
    {
      action: 'navigate',
      phase: 'nested-correction',
      entry: '/product/c',
      path: '/product/c',
      disconnect: false,
      newScope: true,
    },
    {
      action: 'navigate',
      phase: 'nested-correction',
      entry: '/product/%64',
      path: '/product/%64',
      disconnect: false,
      newScope: true,
    },
    {
      action: 'rebind',
      phase: 'nested-correction',
      entry: null,
      path: '/product/%61',
      disconnect: false,
      newScope: true,
    },
    {
      action: 'replace',
      phase: 'write',
      entry: '/product/%62',
      path: '/product/%62',
      disconnect: false,
      newScope: false,
      compose: 'source',
    },
    {
      action: 'replace',
      phase: 'write',
      entry: '/product/%62',
      path: '/product/%61',
      disconnect: true,
      newScope: false,
      compose: 'source',
    },
    {
      action: 'replace',
      phase: 'write',
      entry: '/product/%62',
      path: '/product/%61',
      disconnect: true,
      newScope: false,
      compose: 'source',
      disconnectOnCorrection: true,
    },
    {
      action: 'replace',
      phase: 'write',
      entry: '/product/%62',
      path: '/product/%62',
      disconnect: false,
      newScope: true,
      compose: 'source',
    },
    {
      action: 'replace',
      phase: 'write',
      entry: '/product/%62',
      path: '/product/%62',
      disconnect: false,
      newScope: false,
    },
    {
      action: 'replace',
      phase: 'write',
      entry: '/product/%62',
      path: '/product/a',
      disconnect: true,
      newScope: false,
    },
    {
      action: 'replace',
      phase: 'write',
      entry: '/category/%62',
      path: '/category/%62',
      disconnect: false,
      newScope: true,
      crossPage: true,
    },
    {
      action: 'replace',
      phase: 'rollback',
      entry: '/category/%62',
      path: '/product/a',
      disconnect: true,
      newScope: false,
      crossPage: true,
    },
    {
      action: 'replace',
      phase: 'rollback',
      entry: '/product/%61',
      path: '/product/%61',
      disconnect: true,
      newScope: true,
      crossPage: true,
    },
    {
      action: 'rebind',
      phase: 'write',
      entry: null,
      path: '/product/a',
      disconnect: false,
      newScope: true,
    },
  ] as const

  it.each(
    historyCases.flatMap((scenario) => {
      const timings =
        scenario.phase === 'observe'
          ? (['before'] as const)
          : (['before', 'after'] as const)
      return timings.map((timing) => ({
        ...scenario,
        compose: 'compose' in scenario ? scenario.compose : null,
        disconnectOnCorrection: 'disconnectOnCorrection' in scenario,
        timing,
      }))
    }),
  )(
    'keeps history and subscriptions aligned ($action $entry $timing $phase, disconnect: $disconnect, during correction: $disconnectOnCorrection, composition: $compose, new scope: $newScope)',
    (scenario) => {
      const {
        action,
        phase,
        entry,
        path,
        disconnect,
        disconnectOnCorrection,
        newScope,
        compose,
        timing,
      } = scenario
      const crossPage = 'crossPage' in scenario
      const nestedAfterPublication =
        phase === 'nested-correction' || phase === 'nested-write'
      const first = scriptedSocket()
      const second = scriptedSocket()
      const { connection, actions } = createHilosConnection({
        url: 'ws://test/ws',
        webSocketFactory: vi
          .fn()
          .mockReturnValueOnce(first.socket)
          .mockReturnValueOnce(second.socket),
      })
      const scopes = new ScopeManager()
      const navigation = fakeNavigation('/product/a')
      const push = vi.spyOn(navigation, 'pushState')
      const router = bootHilos({
        connection,
        actions,
        scopes,
        viewLayer: 'vue',
        router: createAppPageRouter({
          product: { path: '/product/{slug}', admin: false },
          category: { path: '/category/{slug}', admin: false },
        }),
        navigationEnvironment: navigation,
      })
      const state = createPageViewState<string>(router)
      state.setPages({ product: 'Product view', category: 'Category view' })
      const window = {
        limit: 20,
        filter: {},
        sort: null,
        anchor: null,
        anchorDirection: 'after',
        pageIndex: 2,
      } satisfies TableViewportDescriptor
      const replaceSocket = () => {
        connection.close()
        connection.connect()
      }
      const targetPage = crossPage ? 'category' : 'product'
      const target = `/${targetPage}/b`
      const opensTarget =
        compose === 'correction' || (compose === 'source' && newScope)
      let scopeAtOpen: ReturnType<ScopeManager['page']> | undefined
      const rewriteSourceAndTarget = () => {
        router.replacePath('/product/%61')
        router.replacePath('/product/%62')
      }
      const stopPath = subscribeSignal(router.currentPath, (next) => {
        if (next !== target) return
        stopPath()
        if (phase === 'rollback') replaceSocket()
        if (phase === 'observe') rewriteSourceAndTarget()
        if (phase === 'source-reentry') router.replacePath('/product/%61')
        if (nestedAfterPublication) first.message(productMove('a', 'd'))
      })
      const callbackPath = {
        write: target,
        observe: target,
        rollback: '/product/a',
        'target-correction': entry,
        nested: '/product/d',
        'nested-write': '/product/d',
        'source-reentry': '/product/%61',
        'nested-correction': '/product/%64',
      }[phase]
      const callbackLimit =
        compose === 'rollback' || compose === 'correction' ? 2 : 1
      let historyCallbacks = 0
      let sourceWrites = 0
      let wroteTarget = false
      let wroteNestedTarget = false
      const replace = navigation.replaceState
      navigation.replaceState = (next) => {
        // Force the first write to leave a correction for the second callback.
        const firstCallback = next === callbackPath && historyCallbacks === 0
        const writeFirst =
          firstCallback && compose === 'restore-source'
            ? false
            : (firstCallback && compose === 'correction') || timing === 'after'
        if (writeFirst) replace(next)
        if (!wroteTarget && next === target) {
          wroteTarget = true
          if (phase === 'nested') first.message(productMove('a', 'd'))
          if (phase === 'target-correction') router.replacePath('/product/%62')
        }
        if (
          phase === 'nested-correction' &&
          next === '/product/d' &&
          !wroteNestedTarget
        ) {
          wroteNestedTarget = true
          router.replacePath('/product/%64')
        }
        if (
          compose === 'restore-source' &&
          next === '/product/%61' &&
          ++sourceWrites === 2
        ) {
          router.replacePath('/product/%62')
        }
        if (
          phase !== 'observe' &&
          next === callbackPath &&
          historyCallbacks < callbackLimit
        ) {
          historyCallbacks += 1
          if (compose === 'restore-source') {
            router.replacePath('/product/%61')
          } else {
            if (
              compose === 'source' ||
              (nestedAfterPublication && compose !== 'reverse')
            ) {
              router.replacePath('/product/%61')
            }
            if (action === 'replace') router.replacePath(entry)
            if (compose === 'reverse') router.replacePath('/product/%61')
          }
          if (action === 'navigate') router.navigate(entry)
          if (action === 'rebind') {
            if (nestedAfterPublication) router.replacePath(callbackPath)
            bindPageScope(connection, scopes)
          }
          if (opensTarget && (compose === 'source' || historyCallbacks === 2)) {
            router.navigate(target)
            router.replacePath('/product/%62')
          }
          scopeAtOpen = scopes.page()
          if (disconnect && phase !== 'rollback' && !disconnectOnCorrection) {
            replaceSocket()
          }
        }
        if (disconnectOnCorrection && next === entry) {
          router.replacePath(target)
          replaceSocket()
        }
        if (!writeFirst) replace(next)
      }
      const writes = vi.spyOn(navigation, 'replaceState')

      try {
        first.open()
        const session = sessionAnswer(7, 'Asia/Tokyo')
        first.message(session)
        first.message(productAnswer('a', false))
        const scope = scopes.page()
        connection.registerTableWindow('products', {
          descriptor: () => (scopes.page() === scope ? window : null),
        })
        first.message(productMove('a', 'b', targetPage))

        const [page, encodedSlug] = path.slice(1).split('/')
        const slug = decodeURIComponent(encodedSlug!)
        expect(navigation.pathname()).toBe(path)
        expect(router.currentPath.get()).toBe(path)
        expect(router.currentRoute.get()).toMatchObject({
          page,
          params: { slug },
        })
        expect(push).toHaveBeenCalledTimes(
          action === 'navigate' || opensTarget ? 1 : 0,
        )
        if (disconnectOnCorrection) {
          expect(
            writes.mock.calls.filter(([next]) => next === target),
          ).toHaveLength(2)
        }
        if (compose === 'correction' || compose === 'rollback') {
          expect(historyCallbacks).toBe(
            compose === 'correction' || timing === 'after' ? 2 : 1,
          )
        }
        if (compose === 'restore-source') expect(sourceWrites).toBe(2)
        if (
          phase === 'target-correction' ||
          phase === 'nested' ||
          phase === 'source-reentry' ||
          nestedAfterPublication
        ) {
          expect(historyCallbacks).toBe(1)
        }
        expect(router.pageError.get()).toBeNull()
        if (newScope) {
          expect(scopes.page()).not.toBe(scope)
          expect(scopes.page()).toBe(scopeAtOpen)
          if (action === 'rebind') expect(scopes.page()).toBeUndefined()
          else expect(router.pageLoading.get()).toBe(true)
        } else {
          expect(scopes.page()).toBe(scope)
          expect(router.pageLoading.get()).toBe(false)
          expect(state.view.get()).toBe('Product view')
        }

        const subscribeA = {
          type: 'page_subscribe',
          page: 'product',
          params: { slug: 'a' },
        }
        const expected = { ...subscribeA, page, params: { slug } }
        const withWindow = { ...expected, tableWindows: { products: window } }
        expect(first.sent).toEqual(
          disconnect || action === 'rebind' || slug === 'a'
            ? [subscribeA]
            : [subscribeA, newScope ? expected : withWindow],
        )
        expect(second.sent).toEqual([])
        if (disconnect) {
          second.open()
          second.message(session)
          expect(second.sent).toEqual([newScope ? expected : withWindow])
        }
        if (compose === 'rollback' || (nestedAfterPublication && disconnect)) {
          const localPath = nestedAfterPublication
            ? '/product/%64'
            : '/product/%62'
          router.replacePath(localPath)
          expect(navigation.pathname()).toBe(localPath)
          expect(router.currentPath.get()).toBe(localPath)
          expect(router.currentRoute.get().params).toEqual({
            slug: nestedAfterPublication ? 'd' : 'b',
          })
          expect(scopes.page()).toBe(scope)
          expect(second.sent).toEqual([withWindow])
        }
      } finally {
        stopPath()
        state.dispose()
        router.stop()
        connection.close()
      }
    },
  )

  it.each([
    { order: 'module-first', canonicalize: false },
    { order: 'server-first', canonicalize: true },
  ] as const)(
    'restores the visible page after interruption and waits for both arrivals on the accepted move ($order)',
    async ({ order, canonicalize }) => {
      const first = scriptedSocket()
      const second = scriptedSocket()
      const { connection, actions } = createHilosConnection({
        url: 'ws://test/ws',
        webSocketFactory: vi
          .fn()
          .mockReturnValueOnce(first.socket)
          .mockReturnValueOnce(second.socket),
      })
      const scopes = new ScopeManager()
      const navigation = fakeNavigation('/product/a')
      const router = bootHilos({
        connection,
        actions,
        scopes,
        viewLayer: 'vue',
        router: createAppPageRouter({
          product: { path: '/product/{slug}', admin: false },
          category: { path: '/category/{slug}', admin: false },
        }),
        navigationEnvironment: navigation,
      })
      let loadCategory!: (view: string) => void
      const category = new Promise<string>((resolve) => {
        loadCategory = resolve
      })
      const state = createPageViewState<string>(router)
      state.setPages({
        product: 'Product view',
        category: lazyPage(() => category),
      })
      const observeMove = vi.fn()
      const stopMove = pageSubscriptionFor(connection).onPageMoved(observeMove)
      const interrupted = vi.fn()
      const stopRoute = subscribeSignal(router.currentRoute, (route) => {
        if (route.page !== 'category') return
        expect(state.loading.get()).toBe(true)
        expect(state.view.get()).toBeUndefined()
        interrupted()
        connection.close()
        connection.connect()
      })
      const pathA = canonicalize ? '/product/%61' : '/product/a'
      const stopLeave = router.onLeave(() => {
        stopLeave()
        if (canonicalize) router.replacePath(pathA)
      })

      try {
        first.open()
        first.message(sessionAnswer(7, 'Asia/Tokyo'))
        first.message(productAnswer('a', false))
        const scope = scopes.page()
        expect(state.view.get()).toBe('Product view')
        expect(state.loading.get()).toBe(false)

        const crossPageMove = productMove('a', 'b', 'category')
        first.message(crossPageMove)
        expect(interrupted).toHaveBeenCalledTimes(1)
        expect(navigation.pathname()).toBe(pathA)
        expect(router.currentPath.get()).toBe(pathA)
        expect(router.currentRoute.get()).toMatchObject({
          page: 'product',
          params: { slug: 'a' },
        })
        expect(first.sent).toHaveLength(1)
        expect(second.sent).toEqual([])
        expect(observeMove).not.toHaveBeenCalled()
        expect(scopes.page()).toBe(scope)
        expect(state.view.get()).toBe('Product view')
        expect(state.loading.get()).toBe(false)
        expect(state.error.get()).toBeNull()

        second.open()
        second.message(sessionAnswer(7, 'Asia/Tokyo'))
        expect(second.sent).toEqual([
          { type: 'page_subscribe', page: 'product', params: { slug: 'a' } },
        ])
        expect(scopes.page()).toBe(scope)
        expect(state.view.get()).toBe('Product view')
        expect(state.loading.get()).toBe(false)
        expect(state.error.get()).toBeNull()

        second.message(productAnswer('a', false))
        stopRoute()
        second.message(crossPageMove)
        expect(second.sent.at(-1)).toEqual({
          type: 'page_subscribe',
          page: 'category',
          params: { slug: 'b' },
        })
        expect(second.sent).toHaveLength(2)
        expect(scopes.page()).not.toBe(scope)
        expect(state.loading.get()).toBe(true)
        expect(state.view.get()).toBeUndefined()

        const answer = productAnswer('b', false)
        const answerCategory = () => {
          second.message({
            ...answer,
            data: { ...answer.data, page: 'category' },
          })
        }
        if (order === 'module-first') {
          loadCategory('Category view')
          await category
        } else {
          answerCategory()
          expect(router.pageLoading.get()).toBe(false)
        }
        expect(state.loading.get()).toBe(true)
        expect(state.view.get()).toBeUndefined()
        expect(state.error.get()).toBeNull()

        if (order === 'module-first') {
          answerCategory()
        } else {
          loadCategory('Category view')
          await category
        }
        expect(state.view.get()).toBe('Category view')
        expect(state.loading.get()).toBe(false)
      } finally {
        stopLeave()
        stopRoute()
        stopMove()
        state.dispose()
        router.stop()
        connection.close()
      }
    },
  )

  it('keeps cold auth return waiting when session release reenters on a new socket', async () => {
    vi.resetModules()
    const { bootHilos: bootFresh } =
      await import('../../src/bootstrap/bootHilos.js')
    const { createHilosConnection: createConnection } =
      await import('../../src/connection/createHilosConnection.js')
    const { whenPageReady } =
      await import('../../src/subscription/pageReadyGate.js')
    const first = scriptedSocket()
    const second = scriptedSocket()
    const { connection, actions } = createConnection({
      url: 'ws://test/ws',
      webSocketFactory: vi
        .fn()
        .mockReturnValueOnce(first.socket)
        .mockReturnValueOnce(second.socket),
    })
    const router = bootFresh({
      connection,
      actions,
      scopes: new ScopeManager(),
      viewLayer: 'vue',
      router: createAppPageRouter({
        product: { path: '/product/{slug}', admin: false },
      }),
      navigationEnvironment: fakeNavigation('/product/a'),
    })
    const source = {
      descriptor: () => {
        connection.unregisterTableWindow('products', source)
        connection.close()
        connection.connect()
        second.open()
        router.navigate('/auth/magic')
        return null
      },
    }
    connection.registerTableWindow('products', source)
    const waiting = whenPageReady()
    try {
      first.open()
      first.message(sessionAnswer(7, 'Asia/Tokyo'))
      expect(router.currentPath.get()).toBe('/auth/magic')
      expect(await isSettled(waiting)).toBe(false)

      second.message(sessionAnswer(8, 'America/New_York'))
      expect(await isSettled(waiting)).toBe(true)
    } finally {
      router.stop()
      connection.close()
    }
  })

  it.each([true, false])(
    'replaces a booted binding on the same socket and waits again on the next (matcher: %s)',
    (matchSecond) => {
      const firstSocket = scriptedSocket()
      const secondSocket = scriptedSocket()
      const factory = vi
        .fn()
        .mockReturnValueOnce(firstSocket.socket)
        .mockReturnValueOnce(secondSocket.socket)
      const { connection, actions } = createHilosConnection({
        url: 'ws://test/ws',
        webSocketFactory: factory,
      })
      const matcher = vi.fn<PageResponseMatcher>(
        (params, payload) => params.slug === payload.data?.slug,
      )
      const matchers = new Map([['product', matcher]])
      const firstScopes = new ScopeManager()
      const router = bootHilos({
        connection,
        actions,
        scopes: firstScopes,
        viewLayer: 'vue',
        router: createAppPageRouter({
          product: { path: '/product/{slug}', admin: false },
        }),
        pageResponseMatchers: matchers,
        navigationEnvironment: fakeNavigation('/product/a'),
      })
      const handshake = {
        type: SIGNAL_HANDSHAKE_RESPONSE,
        data: {
          entities: { currentUser: null },
          data: { accessLossReason: null },
        },
      }
      try {
        firstSocket.open()
        firstSocket.message(handshake)
        firstSocket.message(productAnswer('a'))
        const firstScope = firstScopes.page()
        expect(firstScope?.data.signal('slug').get()).toBe('a')

        const secondScopes = new ScopeManager()
        const second = bindPageScope(connection, secondScopes, {
          pageResponseMatchers: matchSecond ? matchers : undefined,
        })
        const secondScope = second.subscribe('product', { slug: 'b' })
        const replay = vi.fn()
        connection.on('projectSignal', replay)()
        expect(replay).not.toHaveBeenCalled()
        expect(
          firstSocket.sent.filter((frame) => frame.type === 'page_subscribe'),
        ).toEqual([
          expect.objectContaining({ page: 'product', params: { slug: 'a' } }),
          expect.objectContaining({ page: 'product', params: { slug: 'b' } }),
        ])
        expect(second.pageLoading.get()).toBe(true)
        expect(secondScope.data.signal('slug').get()).toBeUndefined()
        firstSocket.message(productAnswer('b'))
        expect(second.pageLoading.get()).toBe(false)
        expect(secondScope.data.signal('slug').get()).toBe('b')

        connection.close()
        connection.connect()
        secondSocket.open()
        const thirdScopes = new ScopeManager()
        const third = bindPageScope(connection, thirdScopes, {
          pageResponseMatchers: matchSecond ? matchers : undefined,
        })
        const thirdScope = third.subscribe('product', { slug: 'c' })
        expect(secondSocket.sent).toEqual([])
        expect(third.pageLoading.get()).toBe(true)
        secondSocket.message(handshake)
        expect(
          secondSocket.sent.filter((frame) => frame.type === 'page_subscribe'),
        ).toEqual([
          expect.objectContaining({ page: 'product', params: { slug: 'c' } }),
        ])
        firstSocket.message(productAnswer('c'))
        expect(thirdScope.data.signal('slug').get()).toBeUndefined()

        secondSocket.message({
          type: SIGNAL_TYPE_PAGE_SUBSCRIPTION_ERROR,
          data: {
            page: 'product',
            httpCode: 401,
            errorCode: 'unauthorized',
            title: 'Sign In Required',
            message: 'Sign in.',
          },
        })
        expect(second.pageError.get()).toBeNull()
        expect(third.pageError.get()?.httpCode).toBe(401)
        if (matchSecond) {
          secondSocket.message(productAnswer('a'))
          expect(thirdScopes.page()?.data.signal('slug').get()).toBeUndefined()
        }

        secondSocket.message(productAnswer('c'))
        expect(firstScope?.data.signal('slug').get()).toBe('a')
        expect(thirdScopes.page()?.data.signal('slug').get()).toBe('c')
        expect(third.pageError.get()?.httpCode).toBe(401)
        third.clearPageError()
        expect(third.pageError.get()).toBeNull()
      } finally {
        router.stop()
        connection.close()
      }
    },
  )

  it('follows a move requested by a synchronous observer of the opening scope', () => {
    const socket = scriptedSocket()
    const { connection } = createHilosConnection({
      url: 'ws://test/ws',
      webSocketFactory: () => socket.socket,
    })
    const scopes = new ScopeManager()
    const matcher = vi.fn<PageResponseMatcher>(
      (params, payload) => params.slug === payload.data?.slug,
    )
    const pages = bindPageScope(connection, scopes, {
      pageResponseMatchers: new Map([['product', matcher]]),
    })
    const stop = subscribeSignal(
      computedSignal(() => scopes.page()),
      () => pages.followMove({ slug: 'b' }),
    )
    try {
      connection.connect()
      socket.open()
      pages.releaseOnSession()
      const scope = pages.subscribe('product', { slug: 'a' })

      socket.message(productAnswer('b'))

      expect(matcher).toHaveBeenCalledExactlyOnceWith(
        { slug: 'b' },
        expect.objectContaining({ data: { slug: 'b' } }),
      )
      expect(scope.data.signal('slug').get()).toBe('b')
      expect(pages.pageLoading.get()).toBe(false)
      expect(socket.sent).toEqual([
        expect.objectContaining({
          type: 'page_subscribe',
          page: 'product',
          params: { slug: 'b' },
        }),
      ])
    } finally {
      stop()
      connection.close()
    }
  })

  it('starts late uploads from a replay only while its page answer belongs to the current socket', async () => {
    vi.resetModules()
    const { bindPageReady, whenPageReady } =
      await import('../../src/subscription/pageReadyGate.js')
    const { createHilosConnection: createConnection } =
      await import('../../src/connection/createHilosConnection.js')
    const { bindPageScope: bindScope } =
      await import('../../src/subscription/bindPageScope.js')
    const { bindUploads, uploadFile } =
      await import('../../src/uploads/hilosUploads.js')
    const first = scriptedSocket()
    const second = scriptedSocket()
    const factory = vi
      .fn()
      .mockReturnValueOnce(first.socket)
      .mockReturnValueOnce(second.socket)
    const { connection, actions } = createConnection({
      url: 'ws://test/ws',
      webSocketFactory: factory,
    })
    const pages = bindScope(connection, new ScopeManager())
    const userId = createSignal<number | null>(null)
    const initFrames = (socket: ReturnType<typeof scriptedSocket>) =>
      socket.sent.filter((frame) => frame.action === UPLOAD_ACTION_INIT)
    const selectFile = () => uploadFile('picture', new File(['a'], 'a.png'))
    let unbind: (() => void) | undefined
    let stopReady: (() => void) | undefined
    try {
      connection.connect()
      first.open()
      pages.releaseOnSession()
      pages.subscribe('product', { slug: 'a' })
      first.message(productAnswer('a'))
      unbind = bindUploads(connection, actions, userId)
      selectFile()
      expect(initFrames(first)).toHaveLength(1)
      unbind()

      connection.close()
      connection.connect()
      second.open()
      const replay = vi.fn()
      connection.on('projectSignal', replay)()
      stopReady = bindPageReady(connection)
      const waiting = whenPageReady()
      expect(await isSettled(waiting)).toBe(false)
      expect(replay).toHaveBeenCalledExactlyOnceWith(
        expect.objectContaining({ type: SIGNAL_TYPE_PAGE_RESPONSE }),
      )
      unbind = bindUploads(connection, actions, userId)
      selectFile()
      expect(initFrames(second)).toHaveLength(0)

      pages.releaseOnSession()
      expect(initFrames(second)).toHaveLength(0)
      second.message(productAnswer('a'))
      expect(initFrames(second)).toHaveLength(1)
      expect(await isSettled(waiting)).toBe(true)
    } finally {
      stopReady?.()
      unbind?.()
      connection.close()
    }
  })

  it.each([true, false])(
    'replays a window only while its opening and params still own it (matcher: %s)',
    (matched) => {
      const socket = scriptedSocket()
      const { connection } = createHilosConnection({
        url: 'ws://test/ws',
        webSocketFactory: () => socket.socket,
      })
      const scopes = new ScopeManager()
      const matcher = vi.fn<PageResponseMatcher>(
        (params, payload) => params.slug === payload.data?.slug,
      )
      const pages = bindPageScope(connection, scopes, {
        pageResponseMatchers: matched
          ? new Map([['product', matcher]])
          : undefined,
      })
      pages.subscribe('product', { slug: 'a' })
      connection.connect()
      socket.open()
      pages.releaseOnSession()
      const controller = new TableViewportController<TableRow>({
        resolve: (row) => row,
        sendViewport: () => {},
        frame: { columns: [] },
      })
      const window = vi.spyOn(controller, 'ingestSubscriptionWindow')
      const bindWindow = () =>
        bindTableViewport(
          connection,
          scopes,
          { page: 'product', tableKey: 'products' },
          controller,
        )
      const readReplay = () => {
        const replay = vi.fn()
        connection.on('projectSignal', replay)()
        return replay
      }
      let stopOpeningWindow: (() => void) | undefined
      const stopScope = subscribeSignal(
        computedSignal(() => scopes.page()),
        () => {
          stopOpeningWindow?.()
          stopOpeningWindow = bindWindow()
        },
      )

      try {
        socket.message(productAnswer('a'))
        const stopWindow = bindWindow()
        expect(window).toHaveBeenCalledOnce()
        expect(matcher).toHaveBeenCalledTimes(matched ? 1 : 0)
        expect(readReplay()).toHaveBeenCalledOnce()
        expect(matcher).toHaveBeenCalledTimes(matched ? 1 : 0)

        pages.followMove({ slug: 'b' })
        expect(window).toHaveBeenCalledOnce()
        expect(readReplay()).not.toHaveBeenCalled()
        expect(socket.sent.at(-1)).toEqual(
          expect.objectContaining({
            params: { slug: 'b' },
            tableWindows: expect.objectContaining({
              products: expect.anything(),
            }),
          }),
        )
        const stopLateWindow = bindWindow()
        expect(window).toHaveBeenCalledOnce()
        stopLateWindow()
        socket.message(productAnswer('b', false))
        expect(readReplay()).not.toHaveBeenCalled()

        socket.message(productAnswer('b'))
        expect(window).toHaveBeenCalledTimes(2)
        stopWindow()
        pages.subscribe('product', { slug: 'b' })
        expect(window).toHaveBeenCalledTimes(2)
        expect(readReplay()).not.toHaveBeenCalled()
        socket.message(productAnswer('b'))
        expect(window).toHaveBeenCalledTimes(3)
      } finally {
        stopScope()
        stopOpeningWindow?.()
        connection.close()
      }
    },
  )

  it('opens the page-ready latch on an auth return once the session answers', async () => {
    vi.resetModules()
    const { bootHilos: bootFresh } =
      await import('../../src/bootstrap/bootHilos.js')
    const { whenPageReady } =
      await import('../../src/subscription/pageReadyGate.js')
    const connection = fakeConnection()
    const scopes = new ScopeManager()
    const hilosConnection = connection as unknown as HilosConnection
    const hilosRouter = bootFresh({
      viewLayer: 'vue',
      connection: hilosConnection,
      actions: new ActionLifecycle(hilosConnection),
      scopes,
      router: createAppPageRouter({ main: { path: '/', admin: false } }),
      navigationEnvironment: fakeNavigation('/auth/magic'),
      pageTitles: { main: 'Home' },
      appName: 'Demo',
    })
    const waiting = whenPageReady()

    expect(hilosRouter.currentRoute.get()).toEqual({
      page: null,
      params: {},
      admin: false,
    })
    expect(hilosRouter.currentTitle.get()).toBe('')
    expect(hilosRouter.pageLoading.get()).toBe(false)

    expect(await isSettled(waiting)).toBe(false)
    expect(connection.sent).toEqual([])

    connection.emitProjectSignal('handshake_response', {
      entities: { currentUser: null },
    })

    expect(await isSettled(waiting)).toBe(true)
    expect(connection.sent).toEqual([])
  })

  it('leaves the page-ready latch shut on a plain miss', async () => {
    vi.resetModules()
    const { bootHilos: bootFresh } =
      await import('../../src/bootstrap/bootHilos.js')
    const { whenPageReady } =
      await import('../../src/subscription/pageReadyGate.js')
    const connection = fakeConnection()
    const scopes = new ScopeManager()
    const hilosConnection = connection as unknown as HilosConnection
    const hilosRouter = bootFresh({
      viewLayer: 'vue',
      connection: hilosConnection,
      actions: new ActionLifecycle(hilosConnection),
      scopes,
      router: createAppPageRouter({ main: { path: '/', admin: false } }),
      navigationEnvironment: fakeNavigation('/nope'),
      appName: 'Demo',
    })
    const waiting = whenPageReady()

    connection.emitProjectSignal('handshake_response', {
      entities: { currentUser: null },
    })

    expect(await isSettled(waiting)).toBe(false)
    expect(hilosRouter.currentTitle.get()).toBe('Page Not Found · Demo')
    expect(connection.sent).toEqual([])
  })

  it('titles an unknown address immediately, before the page answers', () => {
    const connection = fakeConnection()
    const scopes = new ScopeManager()
    const router = createAppPageRouter({
      main: { path: '/', admin: false },
    })
    const hilosConnection = connection as unknown as HilosConnection
    const hilosRouter = bootHilos({
      viewLayer: 'vue',
      connection: hilosConnection,
      actions: new ActionLifecycle(hilosConnection),
      scopes,
      router,
      navigationEnvironment: fakeNavigation('/missing'),
      pageTitles: { main: 'Home' },
      appName: 'Demo',
    })

    expect(hilosRouter.currentTitle.get()).toBe('Page Not Found · Demo')
    expect(hilosRouter.pageLoading.get()).toBe(false)
  })

  it('binds the impersonation strip so it follows the handshake', () => {
    const connection = fakeConnection()
    boot(connection)

    connection.emitProjectSignal('handshake_response', {
      entities: {
        currentUser: { id: 2, name: 'Bob' },
        impersonatedBy: { id: 1, name: 'Ada' },
      },
    })
    expect(hilosImpersonation.get()).toEqual({
      userName: 'Bob',
      tone: 'warning',
      viewOnly: false,
    })

    connection.emitProjectSignal('handshake_response', {
      entities: { currentUser: { id: 1, name: 'Ada' }, impersonatedBy: null },
    })
    expect(hilosImpersonation.get()).toBeNull()
  })

  it('binds the account standing so it follows the handshake (HIL-945)', () => {
    const connection = fakeConnection()
    boot(connection)

    connection.emitProjectSignal('handshake_response', {
      data: {
        accountStanding: {
          shown: 'frozen',
          blocked: false,
          frozen: true,
          deletionEffectiveAt: null,
          lapsed: [{ document: 'terms', deadline: '2026-09-01' }],
          window: [],
          mergedInto: null,
          mergedIntoName: null,
        },
      },
      entities: { currentUser: { id: 1, name: 'Ada' } },
    })
    expect(hilosAccountStanding.get()?.shown).toBe('frozen')
    // The re-consent screen follows the same handshake (HIL-500).
    expect(hilosFrozenScreen.get()).toBe(true)

    connection.emitProjectSignal('handshake_response', {
      data: { accountStanding: null },
      entities: { currentUser: null },
    })
    expect(hilosAccountStanding.get()).toBeNull()
  })

  it('binds the sign-out control so it follows the handshake', () => {
    const connection = fakeConnection()
    boot(connection)

    connection.emitProjectSignal('handshake_response', {
      entities: { currentUser: { id: 1, name: 'Ada' } },
    })
    expect(hilosSignedIn.get()).toBe(true)

    connection.emitProjectSignal('handshake_response', {
      entities: { currentUser: null },
    })
    expect(hilosSignedIn.get()).toBe(false)
  })

  it('binds the admin access so it follows the handshake (HIL-1253)', () => {
    const connection = fakeConnection()
    boot(connection)

    connection.emitProjectSignal('handshake_response', {
      entities: { currentUser: null },
      data: { adminViewMode: true },
    })
    expect(hilosAdminAccess.get()).toBe('view')

    connection.emitProjectSignal('handshake_response', {
      entities: { currentUser: { id: 1, name: 'Ada', admin: true } },
      data: { adminViewMode: true },
    })
    expect(hilosAdminAccess.get()).toBe('full')

    connection.emitProjectSignal('handshake_response', {
      entities: { currentUser: null },
      data: { adminViewMode: false },
    })
    expect(hilosAdminAccess.get()).toBe('none')
  })

  it('binds the page scope so a page_response lands in the page scope', () => {
    const connection = fakeConnection()
    const { scopes } = boot(connection)

    connection.emitProjectSignal('page_response', {
      page: 'main',
      payload: { data: { greeting: 'hi' } },
    })

    expect(scopes.page()?.data.signal('greeting').get()).toBe('hi')
  })
})
