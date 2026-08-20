// The magic-link relay's own two behaviors (HIL-607): the wait it cannot end by
// itself, and the retry that ends it. The confirm is held behind the framework's
// page-ready gate, which parks forever when nothing answers — by design, since a
// gate that gave up on its own could not say what to do next. So this screen owns
// the backstop, and the screen is where it has to be tested.
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
import { mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { hilosRouterKey } from '../hilosRouterKey.js'
import HilosMagicLinkPage from './HilosMagicLinkPage.vue'

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
 * Mount the relay for a link, with the router provided the way an app provides it.
 *
 * @param world The relay world the screen dispatches and navigates through.
 * @returns The mounted wrapper.
 */
function mountRelay(world: ReturnType<typeof relayWorld>) {
  return mount(HilosMagicLinkPage, {
    props: { context: world.context },
    global: { provide: { [hilosRouterKey as symbol]: world.router } },
  })
}

/**
 * Let every pending microtask and the render that follows it settle. A confirm
 * crosses several awaits before it shows anything — the gate, the dispatch, the
 * reply's schema parse — so the queue is drained a few times over rather than
 * the two ticks a one-await screen would need.
 *
 * @param wrapper The mounted relay to flush the render of.
 */
async function flush(wrapper: {
  vm: { $nextTick: () => Promise<void> }
}): Promise<void> {
  for (let tick = 0; tick < 10; tick += 1) {
    await Promise.resolve()
  }
  await wrapper.vm.$nextTick()
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
    vi.useRealTimers()
  })

  it('holds the confirm while nothing has answered, and shows the spinner meanwhile', async () => {
    const world = relayWorld(true)
    const wrapper = mountRelay(world)
    await flush(wrapper)

    expect(wrapper.find('[data-id="auth-magic-verifying"]').exists()).toBe(true)
    expect(wrapper.text()).toContain('Signing you in…')
    // The whole point of the gate: nothing goes out over a connection that
    // cannot carry it, so the person is never told the server refused a click
    // the server never saw.
    expect(world.dispatched).toEqual([])
  })

  it('backstops a wait that never ends, and offers a retry', async () => {
    const world = relayWorld(true)
    const wrapper = mountRelay(world)

    await vi.advanceTimersByTimeAsync(MAGIC_LINK_TIMEOUT_MS + 1)
    await flush(wrapper)

    expect(wrapper.find('[data-id="auth-magic-error"]').text()).toBe(
      'Could not reach the server. Please try again.',
    )
    expect(wrapper.find('[data-id="auth-magic-retry"]').exists()).toBe(true)
    expect(world.dispatched).toEqual([])
  })

  it('Try again repeats the step whole, and signs in once the page answers', async () => {
    const world = relayWorld(true)
    const wrapper = mountRelay(world)

    await vi.advanceTimersByTimeAsync(MAGIC_LINK_TIMEOUT_MS + 1)
    await flush(wrapper)
    // The connection has since settled: a page answered, so the gate is open and
    // the repeated step gets all the way to the wire.
    world.answerPage()
    await wrapper.find('[data-id="auth-magic-retry"]').trigger('click')
    await flush(wrapper)

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
    const wrapper = mountRelay(world)
    await flush(wrapper)

    expect(wrapper.find('[data-id="auth-magic-error"]').text()).toBe(
      'This sign-in link is invalid or expired.',
    )
    expect(wrapper.find('[data-id="auth-magic-to-login"]').exists()).toBe(true)
    expect(world.navigated).toEqual([])
  })

  it('refuses a link with no token without waiting on anything', async () => {
    window.history.replaceState(
      {},
      '',
      '/auth/magic?email=flowuser%40example.test',
    )
    const world = relayWorld(true)
    const wrapper = mountRelay(world)
    await flush(wrapper)

    expect(wrapper.find('[data-id="auth-magic-error"]').text()).toBe(
      'This sign-in link is invalid or incomplete.',
    )
    // Nothing about this link will change by asking again, so no retry is offered.
    expect(wrapper.find('[data-id="auth-magic-retry"]').exists()).toBe(false)
    expect(world.dispatched).toEqual([])
  })
})
