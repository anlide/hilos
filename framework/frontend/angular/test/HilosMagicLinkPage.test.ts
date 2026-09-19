// The Angular peer of vue/src/auth/HilosMagicLinkPage.test.ts and
// react/test/HilosMagicLinkPage.test.tsx: the magic-link relay's own two
// behaviors (HIL-607), the wait it cannot end by itself and the retry that ends
// it. The confirm is held behind the framework's page-ready gate, which parks
// forever when nothing answers — by design, since a gate that gave up on its own
// could not say what to do next. So this screen owns the backstop, and the screen
// is where it has to be tested.
//
// The gate's latch is module state in @hilos/core (pageReadyGate.ts), and it only
// goes one way. Vitest gives this file its own module registry, so it starts
// unlatched here; the tests below latch it deliberately, by binding it to their
// own connection double and replaying a page answer through it — the same arrival
// `bootHilos` binds in a real app.
//
// This is the first component test of the Angular package (HIL-848). Its wiring —
// the DOM environment, the TestBed platform, and the module reset between cases —
// lives in the package's vitest.setup.ts, so a component test written after this
// one configures nothing.
import { TestBed, type ComponentFixture } from '@angular/core/testing'
import {
  bindPageReady,
  createHilosAuthContext,
  createSignal,
  ScopeManager,
  SIGNAL_TYPE_PAGE_RESPONSE,
  type ActionHandle,
  type ActionLifecycle,
  type AuthMethodEntry,
  type HilosAuthContext,
  type HilosConnection,
  type HilosRouter,
  type ProjectSignal,
} from '@hilos/core'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { HilosMagicLinkPage } from '../src/auth/HilosMagicLinkPage.js'
import { HILOS_ROUTER } from '../src/hilosRouterToken.js'

/**
 * A scope manager holding the enabled sign-in methods, the way a handshake puts
 * them in the session scope (HIL-427): the surface reads its set there.
 *
 * @param entries The enabled methods, in button order.
 */
function scopesWith(entries: readonly AuthMethodEntry[]): ScopeManager {
  const scopes = new ScopeManager()
  scopes.session.data.set('authMethods', entries)

  return scopes
}

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
      scopes: scopesWith([
        { key: 'password', name: null },
        { key: 'magic_link', name: null },
      ]),
      actions,
      channels: [],
      termsPath: '/terms',
      privacyPath: '/privacy',
    }),
  }
}

/**
 * Mount the relay for a link, with the router provided the way an app provides
 * it. The order is fixed: `context` is a required input, so it has to hold a
 * value before the first change detection reads it — and that first detection is
 * also what starts the constructor's effect, so the wait every case measures
 * begins there.
 *
 * @param world The relay world the screen dispatches and navigates through.
 * @returns The mounted fixture.
 */
function mountRelay(
  world: ReturnType<typeof relayWorld>,
): ComponentFixture<HilosMagicLinkPage> {
  TestBed.configureTestingModule({
    providers: [{ provide: HILOS_ROUTER, useValue: world.router }],
  })

  const fixture = TestBed.createComponent(HilosMagicLinkPage)
  fixture.componentRef.setInput('context', world.context)
  fixture.detectChanges()

  return fixture
}

/**
 * Let every pending microtask and the render that follows it settle. A confirm
 * crosses several awaits before it shows anything — the gate, the dispatch, the
 * reply's schema parse — so the queue is drained a few times over rather than the
 * two ticks a one-await screen would need.
 *
 * @param fixture The mounted relay to flush the render of.
 */
async function flush(
  fixture: ComponentFixture<HilosMagicLinkPage>,
): Promise<void> {
  for (let tick = 0; tick < 10; tick += 1) {
    await Promise.resolve()
  }
  fixture.detectChanges()
}

/**
 * Find a node of the mounted screen by its stable test id.
 *
 * @param fixture The mounted relay to look inside.
 * @param id The `data-id` the screen renders on the node.
 * @returns The node, or null when this state does not render it.
 */
function byId(
  fixture: ComponentFixture<HilosMagicLinkPage>,
  id: string,
): HTMLElement | null {
  return (fixture.nativeElement as HTMLElement).querySelector(
    `[data-id="${id}"]`,
  )
}

/**
 * Read the text of a node the screen is expected to be showing.
 *
 * @param fixture The mounted relay to look inside.
 * @param id The `data-id` the screen renders on the node.
 * @returns The node's text, trimmed of the template's own whitespace.
 * @throws Error When this state does not render the node at all.
 */
function textById(
  fixture: ComponentFixture<HilosMagicLinkPage>,
  id: string,
): string {
  const node = byId(fixture, id)
  if (node === null) {
    throw new Error(`the screen is not showing a node with data-id="${id}"`)
  }

  return (node.textContent ?? '').trim()
}

/**
 * Click a node the screen is expected to be offering. The template listens for
 * the native event, so a native click is the whole mechanism.
 *
 * @param fixture The mounted relay to click inside.
 * @param id The `data-id` the screen renders on the node.
 * @throws Error When this state does not render the node at all.
 */
function clickById(
  fixture: ComponentFixture<HilosMagicLinkPage>,
  id: string,
): void {
  const node = byId(fixture, id)
  if (node === null) {
    throw new Error(`the screen is not offering a node with data-id="${id}"`)
  }
  node.click()
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
    const fixture = mountRelay(world)
    await flush(fixture)

    expect(textById(fixture, 'auth-magic-verifying')).toContain(
      'Signing you in…',
    )
    // The whole point of the gate: nothing goes out over a connection that
    // cannot carry it, so the person is never told the server refused a click
    // the server never saw.
    expect(world.dispatched).toEqual([])
  })

  it('backstops a wait that never ends, and offers a retry', async () => {
    const world = relayWorld(true)
    const fixture = mountRelay(world)

    await vi.advanceTimersByTimeAsync(MAGIC_LINK_TIMEOUT_MS + 1)
    await flush(fixture)

    expect(textById(fixture, 'auth-magic-error')).toBe(
      'Could not reach the server. Please try again.',
    )
    expect(byId(fixture, 'auth-magic-retry')).not.toBeNull()
    expect(world.dispatched).toEqual([])
  })

  it('ignores a wait that gave up after its attempt was superseded', async () => {
    // The third resume point, which no case of the Vue or React peers reaches:
    // a wait that timed out for an attempt nobody is waiting on any more.
    //
    // The view going away would be the honest trigger, but it proves nothing —
    // a suppressed error on a destroyed screen has no observable consequence,
    // and the case would be inert in exactly the way this leaf exists to avoid.
    // So the attempt is superseded the other legal way, by restarting the effect
    // with a different context. The trigger is deliberately synthetic: in an app
    // the context is the same object for the life of the screen. What the case
    // proves is the line, not a journey a person takes.
    const first = relayWorld(true)
    const fixture = mountRelay(first)

    await vi.advanceTimersByTimeAsync(5000)
    // The same screen is handed a different context: the effect restarts, the
    // attempt counter moves on, and the first wait belongs to nobody.
    const second = relayWorld(true)
    fixture.componentRef.setInput('context', second.context)
    fixture.detectChanges()
    // Only the FIRST attempt's backstop expires: the second one started five
    // seconds later and is still waiting.
    await vi.advanceTimersByTimeAsync(MAGIC_LINK_TIMEOUT_MS - 5000 + 1)
    await flush(fixture)

    expect(byId(fixture, 'auth-magic-verifying')).not.toBeNull()
    expect(byId(fixture, 'auth-magic-error')).toBeNull()
    expect(first.dispatched).toEqual([])
  })

  it('Try again repeats the step whole, and signs in once the page answers', async () => {
    const world = relayWorld(true)
    const fixture = mountRelay(world)

    await vi.advanceTimersByTimeAsync(MAGIC_LINK_TIMEOUT_MS + 1)
    await flush(fixture)
    // The connection has since settled: a page answered, so the gate is open and
    // the repeated step gets all the way to the wire.
    world.answerPage()
    clickById(fixture, 'auth-magic-retry')
    await flush(fixture)

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
    const fixture = mountRelay(world)
    await flush(fixture)

    expect(textById(fixture, 'auth-magic-error')).toBe(
      'This sign-in link is invalid or expired.',
    )
    expect(byId(fixture, 'auth-magic-to-login')).not.toBeNull()
    expect(world.navigated).toEqual([])
  })

  it('refuses a link with no token without waiting on anything', async () => {
    window.history.replaceState(
      {},
      '',
      '/auth/magic?email=flowuser%40example.test',
    )
    const world = relayWorld(true)
    const fixture = mountRelay(world)
    await flush(fixture)

    expect(textById(fixture, 'auth-magic-error')).toBe(
      'This sign-in link is invalid or incomplete.',
    )
    // Nothing about this link will change by asking again, so no retry is offered.
    expect(byId(fixture, 'auth-magic-retry')).toBeNull()
    expect(world.dispatched).toEqual([])
  })

  it('drops a retry whose view went away, leaving the token unspent', async () => {
    // The page is answered before the mount, so the gate is open throughout and a
    // retry reaches the wire through one microtask — which is the window this
    // asserts on.
    const world = relayWorld(false)
    world.answerPage()
    const fixture = mountRelay(world)
    await flush(fixture)

    expect(byId(fixture, 'auth-magic-retry')).not.toBeNull()
    // Deliberately not flushed afterwards: draining the queue here would let the
    // retry reach the dispatch, and that window is the very thing this asserts on.
    clickById(fixture, 'auth-magic-retry')
    // The person leaves while the retry is still in flight. The token is
    // one-time, so the attempt they walked away from must neither spend it nor
    // navigate the page they went to.
    fixture.destroy()
    // Drained by hand rather than through `flush`, whose contract takes a live
    // fixture to run change detection on.
    for (let tick = 0; tick < 10; tick += 1) {
      await Promise.resolve()
    }

    expect(world.dispatched).toHaveLength(1)
    expect(world.navigated).toEqual([])
  })

  it('ignores the answer to a confirm whose view went away', async () => {
    // The other half of the guard: a confirm already on the wire is not recalled,
    // so this one succeeds — but its answer belongs to a view that is gone, and
    // acting on it would navigate the person out of wherever they went instead.
    const world = relayWorld(true)
    world.answerPage()
    const fixture = mountRelay(world)
    // Stop inside the window the guard covers: the confirm has reached the wire,
    // its answer has not been read yet. Driven by the dispatch actually showing
    // up rather than by a tick count, so the window is not missed by an await
    // more or less along the way.
    for (let tick = 0; tick < 20 && world.dispatched.length === 0; tick += 1) {
      await Promise.resolve()
    }

    expect(world.dispatched).toHaveLength(1)

    fixture.destroy()
    for (let tick = 0; tick < 10; tick += 1) {
      await Promise.resolve()
    }

    expect(world.navigated).toEqual([])
  })
})
