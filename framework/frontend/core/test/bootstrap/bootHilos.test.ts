import { describe, expect, it } from 'vitest'
import { bootHilos } from '../../src/bootstrap/bootHilos.js'
import { createAppPageRouter } from '../../src/routing/appPageRouter.js'
import { type NavigationEnvironment } from '../../src/routing/HilosRouter.js'
import { ScopeManager } from '../../src/state/ScopeManager.js'
import {
  type ConnectionState,
  type HilosConnection,
  type ProjectSignal,
} from '../../src/index.js'

/**
 * A connection double recording connect()/send() and replaying project signals —
 * the slice bootHilos and the subscriptions it binds touch.
 */
function fakeConnection() {
  const projectListeners: Array<(signal: ProjectSignal) => void> = []

  return {
    state: 'connected' as ConnectionState,
    connectCalls: 0,
    sent: [] as Array<Record<string, unknown>>,
    connect(): void {
      this.connectCalls += 1
    },
    send(text: string): boolean {
      this.sent.push(JSON.parse(text) as Record<string, unknown>)

      return true
    },
    on(event: string, listener: (payload: never) => void): () => void {
      if (event === 'projectSignal') {
        projectListeners.push(listener as (signal: ProjectSignal) => void)
      }

      return () => {}
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
    onPopState: () => () => {},
  }
}

function boot(connection: ReturnType<typeof fakeConnection>) {
  const scopes = new ScopeManager()
  const router = createAppPageRouter({ main: '/' }, { fallback: 'main' })
  const hilosRouter = bootHilos({
    connection: connection as unknown as HilosConnection,
    scopes,
    router,
    navigationEnvironment: fakeNavigation('/'),
  })

  return { scopes, hilosRouter }
}

describe('bootHilos', () => {
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
      | { type: string; id: number }
      | undefined
    expect(ref?.type).toBe('user')
  })

  it('resolves the current title from the project titles and app name', () => {
    const connection = fakeConnection()
    const scopes = new ScopeManager()
    const router = createAppPageRouter({ main: '/' }, { fallback: 'main' })
    const hilosRouter = bootHilos({
      connection: connection as unknown as HilosConnection,
      scopes,
      router,
      navigationEnvironment: fakeNavigation('/'),
      pageTitles: { main: 'Home' },
      appName: 'Demo',
    })

    expect(hilosRouter.currentTitle.get()).toBe('Home · Demo')
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
