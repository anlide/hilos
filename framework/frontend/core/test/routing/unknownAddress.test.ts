import { describe, expect, it } from 'vitest'
import {
  type ConnectionState,
  type HilosConnectionEventMap,
  type TableViewportDescriptor,
} from '../../src/connection/HilosConnection.js'
import {
  FIELD_PAGE,
  FIELD_PARAMS,
  FIELD_TYPE,
  SIGNAL_TYPE_PAGE_SUBSCRIBE,
  SIGNAL_TYPE_PAGE_UNSUBSCRIBE,
} from '../../src/protocol/constants.js'
import { PAGE_ERROR_NOT_SERVED } from '../../src/protocol/pageError.js'
import { type ProtectedModeStatus } from '../../src/protocol/protectedMode.js'
import {
  createHilosRouter,
  type NavigationEnvironment,
} from '../../src/routing/HilosRouter.js'
import { createPageRouter } from '../../src/routing/PageRouter.js'
import { ROUTE_NOT_FOUND_ERROR } from '../../src/routing/pageError.js'
import { ScopeManager } from '../../src/state/ScopeManager.js'
import { PageSubscription } from '../../src/subscription/PageSubscription.js'

/** The connection events the double replays. */
interface FakeConnectionEvents {
  state: ConnectionState
  reconnectDragging: boolean
  protectedMode: ProtectedModeStatus
  pageResubscribe: HilosConnectionEventMap['pageResubscribe']
}

/**
 * A connection double recording sent frames and replaying the two events a
 * route miss must stay quiet under: a socket change, and a leader asking the
 * previous page to subscribe again. Protected mode is accepted and never
 * raised — the manager listens for it at construction.
 */
function fakeConnection(initialState: ConnectionState = 'connected') {
  const listeners: Array<(state: ConnectionState) => void> = []
  const draggingListeners: Array<(dragging: boolean) => void> = []
  const protectedModeListeners: Array<(status: ProtectedModeStatus) => void> =
    []
  const recoveryListeners: Array<
    (signal: HilosConnectionEventMap['pageResubscribe']) => void
  > = []
  const listenersOf: {
    [K in keyof FakeConnectionEvents]: Array<
      (payload: FakeConnectionEvents[K]) => void
    >
  } = {
    state: listeners,
    reconnectDragging: draggingListeners,
    protectedMode: protectedModeListeners,
    pageResubscribe: recoveryListeners,
  }
  const sent: Array<Record<string, unknown>> = []

  return {
    state: initialState,
    reconnectDragging: false,
    sent,
    forgotPageFrames: 0,
    emitPageResubscribe(page: string, params: Record<string, unknown> = {}) {
      for (const listener of [...recoveryListeners]) {
        listener({
          kind: 'pageResubscribe',
          data: { page, params },
          envelope: { type: 'page_resubscribe', data: { page, params } },
        })
      }
    },
    forgetPageFrames(): void {
      this.forgotPageFrames += 1
    },
    setState(state: ConnectionState) {
      this.state = state
      for (const listener of [...listeners]) {
        listener(state)
      }
    },
    send(text: string): boolean {
      if (this.state !== 'connected') {
        return false
      }
      sent.push(JSON.parse(text) as Record<string, unknown>)

      return true
    },
    on<K extends keyof FakeConnectionEvents>(
      event: K,
      listener: (payload: FakeConnectionEvents[K]) => void,
    ) {
      listenersOf[event].push(listener)

      return () => {
        const index = listenersOf[event].indexOf(listener)
        if (index !== -1) {
          listenersOf[event].splice(index, 1)
        }
      }
    },
    tableWindowDescriptors(): Record<string, TableViewportDescriptor> {
      return {}
    },
  }
}

/**
 * An in-memory location. Pushes and replaces only change the pathname the
 * navigator reads back; nothing here touches a document.
 */
function fakeEnvironment(initial: string): NavigationEnvironment {
  let pathname = initial
  let popListener: (() => void) | null = null

  return {
    pathname: () => pathname,
    pushState: (next) => {
      pathname = next
    },
    replaceState: (next) => {
      pathname = next
    },
    onPopState: (listener) => {
      popListener = listener

      return () => {
        if (popListener === listener) {
          popListener = null
        }
      }
    },
  }
}

const pageRouter = createPageRouter({
  main: { path: '/', admin: false },
  user: { path: '/user/{id}', admin: false },
  draft: { path: '/draft', admin: false },
})

function subscribeFrame(
  page: string,
  params: Record<string, unknown> = {},
): Record<string, unknown> {
  return {
    [FIELD_TYPE]: SIGNAL_TYPE_PAGE_SUBSCRIBE,
    [FIELD_PAGE]: page,
    [FIELD_PARAMS]: params,
  }
}

function unsubscribeFrame(page: string): Record<string, unknown> {
  return {
    [FIELD_TYPE]: SIGNAL_TYPE_PAGE_UNSUBSCRIBE,
    [FIELD_PAGE]: page,
  }
}

function mount(pathname: string, unbuilt: ReadonlySet<string> = new Set()) {
  const connection = fakeConnection()
  const scopes = new ScopeManager()
  const pages = new PageSubscription(connection, scopes)
  const navigator = createHilosRouter(
    pageRouter,
    pages,
    fakeEnvironment(pathname),
    undefined,
    unbuilt,
  )

  return { connection, scopes, pages, navigator }
}

/** Stand on `/`, let the session answer, then open an address no route claims. */
function leaveHome() {
  const mounted = mount('/')
  mounted.navigator.start()
  mounted.pages.releaseOnSession()
  const forgottenBeforeLeave = mounted.connection.forgotPageFrames
  mounted.navigator.navigate('/nope')

  return { ...mounted, forgottenBeforeLeave }
}

describe('a real subscription on an unknown address', () => {
  it('sends nothing for a cold address no route claims', () => {
    const { connection, scopes, pages, navigator } = mount('/nope')

    navigator.start()
    pages.releaseOnSession()

    expect(connection.sent).toEqual([])
    expect(navigator.currentRoute.get().page).toBeNull()
    expect(navigator.pageLoading.get()).toBe(false)
    expect(navigator.pageError.get()).toBe(ROUTE_NOT_FOUND_ERROR)
    expect(navigator.pageError.get()?.errorCode).toBe(
      ROUTE_NOT_FOUND_ERROR.errorCode,
    )
    expect(navigator.pageError.get()?.errorCode).not.toBe(PAGE_ERROR_NOT_SERVED)
    expect(pages.pageKey()).toBeUndefined()
    expect(scopes.page()).toBeUndefined()
  })

  it('unsubscribes the known page it leaves and does not subscribe the miss', () => {
    const { connection, scopes, pages, navigator, forgottenBeforeLeave } =
      leaveHome()

    expect(connection.sent).toEqual([
      subscribeFrame('main'),
      unsubscribeFrame('main'),
    ])
    expect(connection.forgotPageFrames).toBe(forgottenBeforeLeave + 1)
    expect(navigator.currentRoute.get()).toEqual({
      page: null,
      params: {},
      admin: false,
    })
    expect(navigator.pageLoading.get()).toBe(false)
    expect(navigator.pageError.get()).toBe(ROUTE_NOT_FOUND_ERROR)
    expect(pages.pageKey()).toBeUndefined()
    expect(scopes.page()).toBeUndefined()
  })

  it('drops a late answer for the page it left', () => {
    const { scopes, pages, navigator } = leaveHome()

    expect(
      pages.ingestPageResponse('main', { data: { greeting: 'late' } }),
    ).toBe(false)
    expect(
      pages.handleSubscriptionError({
        page: 'main',
        httpCode: 403,
        errorCode: 'forbidden',
        title: 'Forbidden',
        message: 'late',
      }),
    ).toBe(false)

    expect(scopes.page()).toBeUndefined()
    expect(pages.pageError.get()).toBeNull()
    expect(navigator.pageError.get()).toBe(ROUTE_NOT_FOUND_ERROR)
    expect(navigator.currentRoute.get().page).toBeNull()
  })

  it('stays unsubscribed when the session, the leader, or the socket returns', () => {
    const { connection, scopes, pages, navigator } = leaveHome()
    const sent = connection.sent.length

    pages.releaseOnSession()
    connection.emitPageResubscribe('main')
    connection.setState('disconnected')
    connection.setState('connected')
    pages.releaseOnSession()
    connection.emitPageResubscribe('main', {})

    expect(connection.sent).toHaveLength(sent)
    expect(pages.pageKey()).toBeUndefined()
    expect(scopes.page()).toBeUndefined()
    expect(navigator.currentRoute.get().page).toBeNull()
    expect(navigator.pageError.get()).toBe(ROUTE_NOT_FOUND_ERROR)
  })

  it('subscribes the real home page when the address comes back', () => {
    const { connection, scopes, pages, navigator } = leaveHome()

    navigator.navigate('/')

    expect(connection.sent).toEqual([
      subscribeFrame('main'),
      unsubscribeFrame('main'),
      subscribeFrame('main'),
    ])
    expect(navigator.currentRoute.get()).toEqual({
      page: 'main',
      params: {},
      admin: false,
    })
    expect(navigator.pageError.get()).toBeNull()
    expect(navigator.pageLoading.get()).toBe(true)
    expect(pages.pageKey()).toBe('main')
    expect(scopes.page()?.key).toBe('main')
  })

  it('keeps a known object subscribed through its server 404', () => {
    const { connection, scopes, pages, navigator } = mount('/user/9')
    navigator.start()
    pages.releaseOnSession()

    expect(
      pages.handleSubscriptionError({
        page: 'user',
        httpCode: 404,
        errorCode: 'not_found',
        title: 'Page Not Found',
        message: 'No such user',
      }),
    ).toBe(true)

    expect(pages.pageKey()).toBe('user')
    expect(scopes.page()?.key).toBe('user')
    expect(connection.sent).toEqual([subscribeFrame('user', { id: '9' })])
    expect(navigator.currentRoute.get().page).toBe('user')
    expect(navigator.pageError.get()?.errorCode).toBe('not_found')
    expect(navigator.pageError.get()).not.toBe(ROUTE_NOT_FOUND_ERROR)
  })

  it('still refuses an unbuilt page as not served', () => {
    const { connection, pages, navigator } = mount('/', new Set(['draft']))
    navigator.start()
    pages.releaseOnSession()

    navigator.navigate('/draft')

    expect(connection.sent).toEqual([
      subscribeFrame('main'),
      unsubscribeFrame('main'),
    ])
    expect(pages.pageKey()).toBeUndefined()
    expect(navigator.currentRoute.get().page).toBe('draft')
    expect(pages.pageError.get()?.errorCode).toBe(PAGE_ERROR_NOT_SERVED)
    expect(navigator.pageError.get()?.errorCode).toBe(PAGE_ERROR_NOT_SERVED)
    expect(navigator.pageError.get()).not.toBe(ROUTE_NOT_FOUND_ERROR)
  })

  it('still holds a 401 when the subscribed page answers with data', () => {
    const { scopes, pages, navigator } = mount('/')
    navigator.start()
    pages.releaseOnSession()
    pages.handleSubscriptionError({
      page: 'main',
      httpCode: 401,
      errorCode: 'unauthorized',
      title: 'Unauthorized',
      message: 'Authentication required',
    })

    expect(pages.ingestPageResponse('main', { data: { name: 'Ada' } })).toBe(
      true,
    )

    expect(navigator.pageError.get()?.httpCode).toBe(401)
    expect(pages.pageError.get()?.httpCode).toBe(401)
    expect(pages.pageKey()).toBe('main')
    expect(scopes.page()?.data.signal('name').get()).toBe('Ada')
  })
})
