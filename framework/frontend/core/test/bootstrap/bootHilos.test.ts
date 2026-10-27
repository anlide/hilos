import { afterEach, describe, expect, it, vi } from 'vitest'
import { bootHilos } from '../../src/bootstrap/bootHilos.js'
import { createAuthActions } from '../../src/auth/authActions.js'
import { createAuthFlow } from '../../src/auth/authFlow.js'
import { ActionLifecycle } from '../../src/connection/actionLifecycle.js'
import { createHilosConnection } from '../../src/connection/createHilosConnection.js'
import { type WebSocketLike } from '../../src/connection/HilosConnection.js'
import { createAppPageRouter } from '../../src/routing/appPageRouter.js'
import { type NavigationEnvironment } from '../../src/routing/HilosRouter.js'
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
import { bindPageInstanceName } from '../../src/admin/identity/hilosPageInstanceName.js'
import {
  createHilosI18nCountryCard,
  hilosI18nCountryDisplayName,
  type HilosI18nCountryContext,
} from '../../src/admin/i18n/hilosI18nCountry.js'
import { hilosToasts } from '../../src/state/toasts.js'
import { hilosThemeMode, hilosThemePick } from '../../src/theme/themeState.js'
import {
  hilosBrowserTimeZone,
  hilosOwnTimeZone,
  hilosTimeZone,
} from '../../src/timeZone/timeZoneState.js'
import {
  type ConnectionState,
  type HandshakeSignal,
  type HilosConnection,
  type ProjectSignal,
} from '../../src/index.js'

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
  return {
    pathname: () => pathname,
    pushState: () => {},
    replaceState: () => {},
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
      data: { accessLossReason: 'session_ended', accountBlocked: null },
    })

    expect(hilosAccessLossReason.get()).toBe('session_ended')
    expect(scopes.page()?.data.signal('privateValue').get()).toBeUndefined()
    expect(hilosRouter.pageLoading.get()).toBe(true)
    expect(hilosRouter.currentPath.get()).toBe('/')
    expect(connection.sent).toEqual(sent)
    connection.emitProjectSignal('handshake_response', {
      entities: { currentUser: null },
      data: { accessLossReason: null, accountBlocked: null },
    })
    expect(hilosAccessLossReason.get()).toBe('session_ended')
  })

  it('binds the loss controller before connect can publish a handshake', () => {
    const connection = fakeConnection()
    connection.connect = function (): void {
      this.emitProjectSignal('handshake_response', {
        entities: { currentUser: { id: 7 } },
      })
      this.emitProjectSignal('handshake_response', {
        entities: { currentUser: null },
        data: { accessLossReason: 'session_ended' },
      })
      expect(hilosAccessLossReason.get()).toBe('session_ended')
    }

    boot(connection)
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
      data: { entities: { currentUser: { id: 1, name: 'Ada' } } },
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
      data: { entities: { currentUser } },
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
        [HilosPages.I18N_LANGUAGE, '/hilos/i18n/languages/en'],
        [HilosPages.I18N_LANGUAGE_NAMES, '/hilos/i18n/languages/en/names'],
        [HilosPages.I18N_LANGUAGE_LOCALES, '/hilos/i18n/languages/en/locales'],
        [HilosPages.I18N_COUNTRY, '/hilos/i18n/countries/us'],
        [HilosPages.I18N_COUNTRY_NAMES, '/hilos/i18n/countries/us/names'],
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
              ? { countryCode: 'us' }
              : { languageCode: 'en' },
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
              ? { countryCode: 'us' }
              : { languageCode: 'en' },
          ),
        ).toBe(path)
        expect(connection.sent).toEqual([
          {
            type: 'page_subscribe',
            page,
            params:
              page === HilosPages.I18N_COUNTRY ||
              page === HilosPages.I18N_COUNTRY_NAMES
                ? { countryCode: 'us' }
                : { languageCode: 'en' },
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
    const router = createAppPageRouter({
      [HilosPages.SETTINGS]: { path: '/hilos/settings', admin: true },
    })
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
    [HilosPages.I18N_COUNTRY, '/hilos/i18n/countries/us', 'Demo'],
    [HilosPages.I18N_COUNTRY_NAMES, '/hilos/i18n/countries/us/names', ''],
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
      router.navigate('/hilos/i18n/countries/ie/names')
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
