// The React peer of vue/src/auth/HilosMagicLinkPage.test.ts: the magic-link
// relay's own two behaviors (HIL-607), the wait it cannot end by itself and the
// retry that ends it. The confirm is held behind the framework's page-ready gate,
// which parks forever when nothing answers — by design, since a gate that gave up
// on its own could not say what to do next. So this screen owns the backstop, and
// the screen is where it has to be tested.
//
// The gate's latch is module state in @hilos/core (pageReadyGate.ts), and it only
// goes one way. Vitest gives this file its own module registry, so it starts
// unlatched here; the tests below latch it deliberately, by binding it to their
// own connection double and replaying a page answer through it — the same arrival
// `bootHilos` binds in a real app.
import {
  bindPageReady,
  createHilosAuthContext,
  createSignal,
  MAGIC_LINK_FLOW_METHOD,
  PASSWORD_FLOW_METHOD,
  ScopeManager,
  SIGNAL_TYPE_PAGE_RESPONSE,
  type ActionHandle,
  type ActionLifecycle,
  type HilosAuthContext,
  type HilosConnection,
  type HilosRouter,
  type ProjectSignal,
} from '@hilos/core'
import { act, cleanup, fireEvent, render } from '@testing-library/react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { HilosMagicLinkPage } from '../src/auth/HilosMagicLinkPage.js'
import { HilosRouterContext } from '../src/hilosRouterContext.js'

/** The same backstop the screen declares; restated so a drift shows up here. */
const MAGIC_LINK_TIMEOUT_MS = 20000

/** The dispatch calls one mounted relay made, in order. */
type Dispatched = Array<{ action: string; payload: Record<string, unknown> }>

/**
 * A relay's world: the context it dispatches over, the connection the page-ready
 * gate is bound to, the dispatch log, and the navigations it asked for.
 *
 * The connection double records `projectSignal` listeners so `answerPage` can
 * replay a page_response into them — that is what releases the gate.
 *
 * @param confirmOk Whether the backend accepts the token when it is asked.
 * @returns The mount context plus the handles each assertion reads.
 */
function relayWorld(confirmOk: boolean): {
  context: HilosAuthContext
  dispatched: Dispatched
  navigated: string[]
  router: HilosRouter
  answerPage: () => void
} {
  const dispatched: Dispatched = []
  const navigated: string[] = []
  const listeners: Array<(signal: ProjectSignal) => void> = []
  const connection = {
    on(event: string, listener: (payload: never) => void): () => void {
      if (event === 'projectSignal') {
        listeners.push(listener as (signal: ProjectSignal) => void)
      }

      return () => undefined
    },
  } as unknown as HilosConnection
  const actions = {
    dispatch: (action: string, payload: Record<string, unknown>) => {
      dispatched.push({ action, payload })

      return {
        requestId: `req-${dispatched.length}`,
        loading: createSignal(false),
        done: Promise.resolve({
          reply: confirmOk
            ? { ok: true }
            : {
                ok: false,
                message: 'This sign-in link is invalid or expired.',
              },
        }),
      } as unknown as ActionHandle
    },
  } as unknown as ActionLifecycle

  bindPageReady(connection)

  return {
    dispatched,
    navigated,
    router: {
      navigate: (pathname: string) => navigated.push(pathname),
    } as unknown as HilosRouter,
    answerPage: () => {
      const signal = {
        kind: 'project',
        type: SIGNAL_TYPE_PAGE_RESPONSE,
        data: { page: 'main', payload: {} },
        envelope: {},
      } as unknown as ProjectSignal
      for (const listener of listeners) {
        listener(signal)
      }
    },
    context: createHilosAuthContext({
      connection,
      scopes: new ScopeManager(),
      actions,
      methods: [PASSWORD_FLOW_METHOD, MAGIC_LINK_FLOW_METHOD],
      channels: [],
      oauthProviders: [],
      termsPath: '/terms',
      privacyPath: '/privacy',
    }),
  }
}

/**
 * Mount the relay for a link, with the router in context the way an app puts it
 * there.
 *
 * @param world The relay world the screen dispatches and navigates through.
 */
function mountRelay(world: ReturnType<typeof relayWorld>): void {
  render(
    <HilosRouterContext.Provider value={world.router}>
      <HilosMagicLinkPage context={world.context} />
    </HilosRouterContext.Provider>,
  )
}

/**
 * Let every pending microtask and the render that follows it settle. A confirm
 * crosses several awaits before it shows anything — the gate, the dispatch, the
 * reply's schema parse — so the queue is drained a few times over rather than the
 * two ticks a one-await screen would need.
 */
async function flush(): Promise<void> {
  await act(async () => {
    for (let tick = 0; tick < 10; tick += 1) {
      await Promise.resolve()
    }
  })
}

function byId(id: string): HTMLElement | null {
  return document.querySelector(`[data-id="${id}"]`)
}

describe('HilosMagicLinkPage', () => {
  beforeEach(() => {
    vi.useFakeTimers()
    window.history.replaceState(
      {},
      '',
      '/auth/magic?email=flowuser%40example.test&token=tok-1',
    )
  })

  afterEach(() => {
    cleanup()
    vi.useRealTimers()
  })

  it('holds the confirm while nothing has answered, and shows the spinner meanwhile', async () => {
    const world = relayWorld(true)
    mountRelay(world)
    await flush()

    expect(byId('auth-magic-verifying')).not.toBeNull()
    expect(byId('auth-magic')?.textContent).toContain('Signing you in…')
    // The whole point of the gate: nothing goes out over a connection that cannot
    // carry it, so the person is never told the server refused a click the server
    // never saw.
    expect(world.dispatched).toEqual([])
  })

  it('backstops a wait that never ends, and offers a retry', async () => {
    const world = relayWorld(true)
    mountRelay(world)

    await act(async () => {
      await vi.advanceTimersByTimeAsync(MAGIC_LINK_TIMEOUT_MS + 1)
    })
    await flush()

    expect(byId('auth-magic-error')?.textContent).toBe(
      'Could not reach the server. Please try again.',
    )
    expect(byId('auth-magic-retry')).not.toBeNull()
    expect(world.dispatched).toEqual([])
  })

  it('Try again repeats the step whole, and signs in once the page answers', async () => {
    const world = relayWorld(true)
    mountRelay(world)

    await act(async () => {
      await vi.advanceTimersByTimeAsync(MAGIC_LINK_TIMEOUT_MS + 1)
    })
    await flush()
    // The connection has since settled: a page answered, so the gate is open and
    // the repeated step gets all the way to the wire.
    world.answerPage()
    fireEvent.click(byId('auth-magic-retry') as Element)
    await flush()

    expect(world.dispatched).toHaveLength(1)
    expect(world.dispatched[0]?.payload).toMatchObject({
      email: 'flowuser@example.test',
      token: 'tok-1',
    })
    expect(world.navigated).toEqual(['/'])
  })

  it('shows the backend reason for a token it rejects, with a way back', async () => {
    const world = relayWorld(false)
    world.answerPage()
    mountRelay(world)
    await flush()

    expect(byId('auth-magic-error')?.textContent).toBe(
      'This sign-in link is invalid or expired.',
    )
    expect(byId('auth-magic-to-login')).not.toBeNull()
    expect(world.navigated).toEqual([])
  })

  it('refuses a link with no token without waiting on anything', async () => {
    window.history.replaceState(
      {},
      '',
      '/auth/magic?email=flowuser%40example.test',
    )
    const world = relayWorld(true)
    mountRelay(world)
    await flush()

    expect(byId('auth-magic-error')?.textContent).toBe(
      'This sign-in link is invalid or incomplete.',
    )
    // Nothing about this link will change by asking again, so no retry is
    // offered.
    expect(byId('auth-magic-retry')).toBeNull()
    expect(world.dispatched).toEqual([])
  })

  it('drops a retry whose view went away, leaving the token unspent', async () => {
    // Last in the file on purpose: the page-ready latch is module state that only
    // goes one way, so by here the gate is open and a retry reaches the wire
    // through one microtask — which is the window this asserts on.
    const world = relayWorld(false)
    world.answerPage()
    mountRelay(world)
    await flush()

    expect(byId('auth-magic-retry')).not.toBeNull()
    fireEvent.click(byId('auth-magic-retry') as Element)
    // The person leaves while the retry is still in flight. The token is
    // one-time, so the attempt they walked away from must neither spend it nor
    // navigate the page they went to.
    cleanup()
    await flush()

    expect(world.dispatched).toHaveLength(1)
    expect(world.navigated).toEqual([])
  })
})
