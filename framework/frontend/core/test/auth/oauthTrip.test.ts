// @vitest-environment happy-dom
// Covers the OAuth trip machine (HIL-633): the window opened in the click, the
// authorize URL substituted into it, the courier message that brings the provider's
// return home, and the five ways a trip ends. The environment is a DOM one because
// the machine IS browser code — a window, a message listener, a poll — even though
// it lives in the framework-agnostic core; `window.open` is stubbed rather than
// exercised, so what is tested is the machine's reaction to a window, not the
// emulator's idea of one. The trip id deciding whose authorize URL the window
// follows came later (HIL-707), and the closed window that waits for a return it
// posted before closing later still (HIL-926).
import { afterEach, describe, expect, it, vi } from 'vitest'
import { type HilosConnection } from '../../src/connection/HilosConnection.js'
import {
  ActionError,
  type ActionHandle,
  type ActionLifecycle,
} from '../../src/connection/actionLifecycle.js'
import {
  AUTH_ACTION_LINK_OAUTH_START,
  AUTH_ACTION_OAUTH_START,
} from '../../src/auth/authProtocol.js'
import { createHilosAuthContext } from '../../src/auth/authContext.js'
import {
  createOAuthLogin,
  OAUTH_POPUP_BLOCKED_MESSAGE,
  OAUTH_RETURN_MESSAGE_TYPE,
  OAUTH_WINDOW_POLL_MS,
  type HilosOAuthLogin,
  type OAuthTripOutcome,
} from '../../src/auth/oauthLogin.js'
import {
  OAUTH_AUTHORIZE_SIGNAL,
  OAUTH_REASON_LINK_DUPLICATE,
  OAUTH_REASON_ACCOUNT_BLOCKED,
  OAUTH_REASON_LINK_OK,
  OAUTH_REASON_REAUTH_REQUIRED,
  OAUTH_RESULT_SIGNAL,
} from '../../src/auth/oauthSignals.js'
import { SIGNAL_TYPE_PAGE_RESPONSE } from '../../src/protocol/constants.js'
import { type ProjectSignal } from '../../src/protocol/parseSignal.js'
import { ScopeManager } from '../../src/state/ScopeManager.js'
import { createSignal } from '../../src/state/signal.js'
import { bindPageReady } from '../../src/subscription/pageReadyGate.js'

/** The provider the fixture's trips are for. */
const GITHUB = 'oauth:github'

/** A second provider, so a start over a live trip can name a different one. */
const GOOGLE = 'oauth:google'

/** The absolute URL the daemon answers a start with. */
const AUTHORIZE_URL = 'https://github.test/login/oauth/authorize?state=abc'

/** Where the machine stashes the provider for the cold path to read back. */
const PROVIDER_STORAGE_KEY = 'hilos.oauth.provider'

/** A stand-in for the browser window a trip opens, recording what was done to it. */
interface ProviderWindow {
  closed: boolean
  close(): void
  location: { replace(url: string): void }
  /** Every URL the machine put into the window, in order. */
  visited: string[]
}

/**
 * Build the window `window.open` is stubbed to hand back.
 *
 * @returns The recording window double.
 */
function providerWindow(): ProviderWindow {
  const win: ProviderWindow = {
    closed: false,
    close: () => {
      win.closed = true
    },
    location: {
      replace: (url: string) => {
        win.visited.push(url)
      },
    },
    visited: [],
  }

  return win
}

/** One test's world: the bound client, the wire doubles, and what they saw. */
interface TripWorld {
  oauth: HilosOAuthLogin
  /** The window the next start is handed, or null to play a blocked pop-up. */
  opened: ProviderWindow | null
  /**
   * What the provider stash held at the instant the window was opened — the only
   * thing a window inherits, since it is handed a COPY of session storage taken
   * right then. Null until a start opens one.
   */
  stashedAtOpen: string | null
  /** Actions the machine dispatched, in order. */
  dispatched: Array<{ action: string; payload: Record<string, unknown> }>
  /** Outcomes the machine reported, in order. */
  outcomes: OAuthTripOutcome[]
  /** Refuse the next start action with this message, or null to accept it. */
  refuseStart: string | null
  /** Drop the callback with its connection instead of accepting it. */
  dropCallback: boolean
  /** What the server answers a presented trip key with (HIL-1044). */
  resumeKnown: boolean
  /** Hold the next start's answer back, for the test to refuse when it chooses. */
  holdStart: boolean
  /** Refuse a held start, the way a slow backend refusal lands. */
  refuseHeldStart(message: string): void
  /** Deliver a project signal the way the connection would. */
  emit(type: string, data: Record<string, unknown>): void
  /** Move the connection to a state, the way its `state` event reports it. */
  setState(state: string): void
  /** Report the repair of the connection dragging, the way its event does (HIL-831). */
  setDragging(dragging: boolean): void
  /** Sign the session in, the way the handshake fan-out does. */
  signIn(userId: number): void
  /** Deliver a courier message from a window at an origin. */
  courier(
    message: Record<string, unknown>,
    from?: { source?: unknown; origin?: string },
  ): void
  /** Drop every registration this world made. */
  unbind(): void
}

let active: TripWorld | null = null

/**
 * Stand up one trip world: a connection that fans signals out, an action
 * lifecycle that records and answers, a session scope to sign in through, and the
 * three boot bindings the machine needs.
 *
 * @returns The world, already bound.
 */
function tripWorld(): TripWorld {
  const listeners: Array<(signal: ProjectSignal) => void> = []
  const stateListeners: Array<(state: string) => void> = []
  const draggingListeners: Array<(dragging: boolean) => void> = []
  const messageListeners: Array<(event: MessageEvent) => void> = []
  const held: Array<(reason: unknown) => void> = []
  const scopes = new ScopeManager()
  // The providers' names arrive with the enabled set, the way a handshake puts
  // them in the session scope (HIL-427); the waiting copy reads them there.
  scopes.session.data.set('authMethods', [
    { key: GITHUB, name: 'GitHub' },
    { key: GOOGLE, name: 'Google' },
  ])

  const connection = {
    on(event: string, listener: (payload: never) => void): () => void {
      if (event === 'reconnectDragging') {
        const onDragging = listener as unknown as (dragging: boolean) => void
        draggingListeners.push(onDragging)

        return () => {
          const at = draggingListeners.indexOf(onDragging)
          if (at >= 0) {
            draggingListeners.splice(at, 1)
          }
        }
      }
      if (event === 'state') {
        const onState = listener as unknown as (state: string) => void
        stateListeners.push(onState)

        return () => {
          const at = stateListeners.indexOf(onState)
          if (at >= 0) {
            stateListeners.splice(at, 1)
          }
        }
      }
      const typed = listener as unknown as (signal: ProjectSignal) => void
      if (event !== 'projectSignal') {
        return () => undefined
      }
      listeners.push(typed)

      return () => {
        const at = listeners.indexOf(typed)
        if (at >= 0) {
          listeners.splice(at, 1)
        }
      }
    },
  } as unknown as HilosConnection

  const actions = {
    dispatch: (action: string, payload: Record<string, unknown>) => {
      world.dispatched.push({ action, payload })
      if (action === 'hilos_oauth_resume') {
        return {
          requestId: `req-${world.dispatched.length}`,
          loading: createSignal(false),
          done: Promise.resolve({ reply: { known: world.resumeKnown } }),
        } as unknown as ActionHandle
      }
      if (action === 'hilos_oauth_callback' && world.dropCallback) {
        return {
          requestId: `req-${world.dispatched.length}`,
          loading: createSignal(false),
          done: Promise.reject(
            new ActionError(action, 'disconnected', 'Not connected.'),
          ),
        } as unknown as ActionHandle
      }
      const refusal = world.refuseStart
      const done = world.holdStart
        ? new Promise((_resolve, reject) => {
            held.push(reject)
          })
        : refusal === null
          ? Promise.resolve({})
          : Promise.reject(new Error(refusal))

      return {
        requestId: `req-${world.dispatched.length}`,
        loading: createSignal(false),
        done,
      } as unknown as ActionHandle
    },
  } as unknown as ActionLifecycle

  const context = createHilosAuthContext({
    connection,
    scopes,
    actions,
    channels: [],
    termsPath: '/terms',
    privacyPath: '/privacy',
  })

  // The message listener the machine registers goes through the real window, so
  // capture it here rather than fighting the emulator's MessageEvent shape.
  const realAdd = window.addEventListener.bind(window)
  const realRemove = window.removeEventListener.bind(window)
  vi.spyOn(window, 'addEventListener').mockImplementation(
    (type: string, listener: unknown, options?: unknown): void => {
      if (type === 'message') {
        messageListeners.push(listener as (event: MessageEvent) => void)

        return
      }
      realAdd(
        type as keyof WindowEventMap,
        listener as EventListener,
        options as boolean,
      )
    },
  )
  vi.spyOn(window, 'removeEventListener').mockImplementation(
    (type: string, listener: unknown, options?: unknown): void => {
      if (type === 'message') {
        const at = messageListeners.indexOf(
          listener as (event: MessageEvent) => void,
        )
        if (at >= 0) {
          messageListeners.splice(at, 1)
        }

        return
      }
      realRemove(
        type as keyof WindowEventMap,
        listener as EventListener,
        options as boolean,
      )
    },
  )
  vi.spyOn(window, 'open').mockImplementation(() => {
    world.stashedAtOpen = sessionStorage.getItem(PROVIDER_STORAGE_KEY)

    return world.opened as unknown as Window | null
  })
  // What the machine posts to ITSELF — the notice of a closed provider window —
  // goes the way a posted message goes: as a task of its own, to the listener
  // caught above, from this very window.
  vi.spyOn(window, 'postMessage').mockImplementation(
    (message: unknown): void => {
      setTimeout(() => {
        world.courier(message as Record<string, unknown>, { source: window })
      }, 0)
    },
  )

  const oauth = createOAuthLogin(context)
  const stopTrip = oauth.bindOAuthTrip()
  const stopReady = bindPageReady(connection)
  const stopOutcomes = oauth.subscribeOAuthOutcome((outcome) => {
    world.outcomes.push(outcome)
  })

  const world: TripWorld = {
    oauth,
    opened: providerWindow(),
    stashedAtOpen: null,
    dispatched: [],
    outcomes: [],
    refuseStart: null,
    dropCallback: false,
    resumeKnown: true,
    holdStart: false,
    refuseHeldStart(message) {
      held.shift()?.(new Error(message))
    },
    emit(type, data) {
      const signal = {
        kind: 'project',
        type,
        data,
        envelope: {},
      } as unknown as ProjectSignal
      for (const listener of [...listeners]) {
        listener(signal)
      }
    },
    setState(state) {
      for (const listener of [...stateListeners]) {
        listener(state)
      }
    },
    setDragging(dragging) {
      for (const listener of [...draggingListeners]) {
        listener(dragging)
      }
    },
    signIn(userId) {
      scopes.session.data.set('currentUser', {
        type: 'user',
        id: String(userId),
      })
    },
    courier(message, from = {}) {
      const event = {
        data: message,
        origin: from.origin ?? window.location.origin,
        source: from.source ?? world.opened,
      } as unknown as MessageEvent
      for (const listener of [...messageListeners]) {
        listener(event)
      }
    },
    unbind() {
      stopOutcomes()
      stopTrip()
      stopReady()
    },
  }

  // Latch the page-ready gate the exchange waits on: the main window answered its
  // page long before anybody clicked a provider.
  world.emit(SIGNAL_TYPE_PAGE_RESPONSE, { page: 'main', payload: {} })
  active = world

  return world
}

/**
 * The trip id the machine last put on the wire.
 *
 * Read back from the dispatch rather than written as a literal: the trip counter
 * is module state of `oauthLogin`, so it keeps counting across the tests of this
 * file and no test can know its own number in advance.
 *
 * @param world The world whose last dispatch to read.
 * @returns The trip id that dispatch carried.
 */
function lastTripId(world: TripWorld): string {
  const last = world.dispatched[world.dispatched.length - 1]

  return String(last.payload.tripId)
}

/**
 * Take a trip to the point where the provider window is showing its consent
 * screen: started, accepted, authorize URL delivered.
 *
 * @param world The world to run the trip in.
 * @param intent Whether to start a sign-in or a profile link.
 */
async function reachProvider(
  world: TripWorld,
  intent: 'login' | 'link' = 'login',
): Promise<void> {
  const started =
    intent === 'login'
      ? world.oauth.startOAuthLogin(GITHUB)
      : world.oauth.startOAuthLink(GITHUB)
  await started
  world.emit(OAUTH_AUTHORIZE_SIGNAL, {
    acceptKey: 'accept-1',
    authorizeUrl: AUTHORIZE_URL,
    tripId: lastTripId(world),
    provider: GITHUB,
  })
}

/**
 * Take a trip all the way to its exchange leg: the provider returned, the courier
 * delivered, the callback dispatched.
 *
 * @param world The world to run the trip in.
 * @param intent Whether to start a sign-in or a profile link.
 */
async function reachExchange(
  world: TripWorld,
  intent: 'login' | 'link' = 'login',
): Promise<void> {
  await reachProvider(world, intent)
  world.courier({
    type: OAUTH_RETURN_MESSAGE_TYPE,
    code: 'code-1',
    state: 'state-1',
    error: '',
  })
  await Promise.resolve()
  await Promise.resolve()
}

afterEach(() => {
  active?.oauth.cancelOAuthTrip()
  active?.unbind()
  active = null
  sessionStorage.clear()
  vi.restoreAllMocks()
  vi.useRealTimers()
})

describe('the OAuth trip machine', () => {
  it('refuses to start when the browser blocked the window', async () => {
    const world = tripWorld()
    world.opened = null

    await expect(world.oauth.startOAuthLogin(GITHUB)).rejects.toThrow(
      OAUTH_POPUP_BLOCKED_MESSAGE,
    )
    expect(world.oauth.trip.get()).toBeNull()
    expect(world.dispatched).toEqual([])
  })

  it('stashes the provider before opening the window, not after', async () => {
    // The window is handed a COPY of session storage made when it opens, so a
    // stash written afterwards would never reach it — and the cold path, which
    // finishes a return whose starting window is gone, has nothing else to read
    // the provider from. Asserted at the instant of the open for that reason:
    // "it is in storage by the end of the start" would pass either way.
    const world = tripWorld()

    await world.oauth.startOAuthLogin(GITHUB)

    expect(world.stashedAtOpen).toBe(GITHUB)
  })

  it('leaves no stash behind when the browser blocked the window', async () => {
    const world = tripWorld()
    world.opened = null

    await expect(world.oauth.startOAuthLogin(GITHUB)).rejects.toThrow(
      OAUTH_POPUP_BLOCKED_MESSAGE,
    )

    // A trip that never began must not name the provider of a later cold return.
    expect(sessionStorage.getItem(PROVIDER_STORAGE_KEY)).toBeNull()
  })

  it('publishes an authorizing trip and puts the authorize URL in the window', async () => {
    const world = tripWorld()

    await reachProvider(world)

    expect(world.oauth.trip.get()).toEqual({
      phase: 'authorizing',
      provider: GITHUB,
      providerName: 'GitHub',
      intent: 'login',
    })
    expect(world.opened?.visited).toEqual([AUTHORIZE_URL])
  })

  it('leaves the live trip alone when the frame names an abandoned one', async () => {
    // The race the trip id exists for: GitHub, cancel, Google. The answer to the
    // first start arrives late, and the window it would steer is the one the
    // person is now waiting at. Without the comparison the second window leaves
    // for the first provider's consent screen.
    const world = tripWorld()
    await world.oauth.startOAuthLogin(GITHUB)
    const abandoned = lastTripId(world)

    world.oauth.cancelOAuthTrip()
    world.opened = providerWindow()
    await world.oauth.startOAuthLogin(GITHUB)
    const live = world.opened

    world.emit(OAUTH_AUTHORIZE_SIGNAL, {
      acceptKey: 'accept-1',
      authorizeUrl: AUTHORIZE_URL,
      tripId: abandoned,
      provider: GITHUB,
    })

    expect(live?.visited).toEqual([])
    expect(world.oauth.trip.get()?.phase).toBe('authorizing')
  })

  it("names the dropped frame's provider in the log", async () => {
    // The window knows the trip it is running; whose tail just arrived is only
    // in the frame, so the frame is where the log has to read it from.
    const world = tripWorld()
    const warn = vi.spyOn(console, 'warn').mockImplementation(() => undefined)

    world.emit(OAUTH_AUTHORIZE_SIGNAL, {
      acceptKey: 'accept-1',
      authorizeUrl: AUTHORIZE_URL,
      tripId: 'trip-nobody-is-running',
      provider: GITHUB,
    })

    expect(warn).toHaveBeenCalledTimes(1)
    expect(String(warn.mock.calls[0][0])).toContain(GITHUB)
  })

  it('gives both halves a trip id, and a different one per trip', async () => {
    const world = tripWorld()

    await world.oauth.startOAuthLogin(GITHUB)
    const login = lastTripId(world)

    world.oauth.cancelOAuthTrip()
    world.opened = providerWindow()
    await world.oauth.startOAuthLink(GITHUB)
    const link = lastTripId(world)

    expect(world.dispatched).toEqual([
      {
        action: AUTH_ACTION_OAUTH_START,
        payload: { provider: GITHUB, tripId: login },
      },
      {
        action: AUTH_ACTION_LINK_OAUTH_START,
        payload: { provider: GITHUB, tripId: link },
      },
    ])
    // Not `not.toBe('')`: an absent field reads back as the string 'undefined',
    // which an emptiness check would let through.
    expect(login).toMatch(/^\d+$/)
    expect(link).not.toBe(login)
  })

  it('closes the window and drops the trip when the start is refused', async () => {
    const world = tripWorld()
    world.refuseStart = 'Unknown provider.'

    await expect(world.oauth.startOAuthLogin(GITHUB)).rejects.toThrow(
      'Unknown provider.',
    )
    expect(world.opened?.closed).toBe(true)
    expect(world.oauth.trip.get()).toBeNull()
    // A trip that never began reports nothing: the click is still there to answer.
    expect(world.outcomes).toEqual([])
  })

  it('leaves a newer trip alone when an older start is refused late', async () => {
    const world = tripWorld()
    world.holdStart = true
    const abandoned = world.oauth.startOAuthLogin(GITHUB)
    const first = world.opened

    // The person gives up waiting and starts again; the new start closed the
    // previous window and ended that trip, so the second is the one they are now
    // standing in front of.
    world.holdStart = false
    world.opened = providerWindow()
    await world.oauth.startOAuthLogin(GITHUB)
    const second = world.opened

    // Only now does the first start's refusal come back.
    world.refuseHeldStart('Unknown provider.')
    await expect(abandoned).rejects.toThrow('Unknown provider.')

    expect(second?.closed).toBe(false)
    expect(first?.closed).toBe(true)
    expect(world.outcomes).toEqual([{ kind: 'canceled', message: '' }])
    expect(world.oauth.trip.get()?.phase).toBe('authorizing')
  })

  it('ends the live trip instead of taking its window', async () => {
    const world = tripWorld()
    await reachProvider(world)
    const first = world.opened

    world.opened = providerWindow()
    await world.oauth.startOAuthLogin(GOOGLE)

    expect(world.outcomes).toEqual([{ kind: 'canceled', message: '' }])
    expect(first?.closed).toBe(true)
    expect(world.oauth.trip.get()).toEqual({
      phase: 'authorizing',
      provider: GOOGLE,
      providerName: 'Google',
      intent: 'login',
    })
    expect(world.stashedAtOpen).toBe(GOOGLE)
  })

  it('cancels the trip and closes the window when the person cancels', async () => {
    const world = tripWorld()
    await reachProvider(world)

    world.oauth.cancelOAuthTrip()

    expect(world.opened?.closed).toBe(true)
    expect(world.oauth.trip.get()).toBeNull()
    expect(world.outcomes).toEqual([{ kind: 'canceled', message: '' }])
  })

  it('cancels the trip when the person closes the window by hand', async () => {
    vi.useFakeTimers()
    const world = tripWorld()
    await reachProvider(world)

    const opened = world.opened
    if (opened !== null) {
      opened.closed = true
    }
    await vi.advanceTimersByTimeAsync(OAUTH_WINDOW_POLL_MS)
    // The poll saw the window closed; the verdict is the notice it posted itself,
    // which comes as the next task (HIL-926).
    await vi.advanceTimersToNextTimerAsync()

    expect(world.outcomes).toEqual([{ kind: 'canceled', message: '' }])
  })

  it('keeps a return the window posted just before it closed itself (HIL-926)', async () => {
    // The callback page posts the return and closes its window in one task, and a
    // poll that fell due meanwhile is served first: it finds the window closed while
    // the return still waits in the queue. Played here with timers — the poll is the
    // older timer, so it runs first, and the return is queued right behind it.
    vi.useFakeTimers()
    const world = tripWorld()
    await reachProvider(world)

    await vi.advanceTimersByTimeAsync(OAUTH_WINDOW_POLL_MS - 1)
    setTimeout(() => {
      world.courier({
        type: OAUTH_RETURN_MESSAGE_TYPE,
        code: 'code-1',
        state: 'state-1',
        error: '',
      })
    }, 1)
    const opened = world.opened
    if (opened !== null) {
      opened.closed = true
    }
    await vi.advanceTimersByTimeAsync(1)
    // And the poll's own notice, queued behind the return, finds the trip moved on.
    await vi.advanceTimersToNextTimerAsync()

    expect(world.outcomes).toEqual([])
    expect(world.oauth.trip.get()?.phase).toBe('exchanging')
  })

  it('cancels quietly when the provider returns an error', async () => {
    const world = tripWorld()
    await reachProvider(world)

    world.courier({
      type: OAUTH_RETURN_MESSAGE_TYPE,
      code: '',
      state: '',
      error: 'access_denied',
    })

    expect(world.outcomes).toEqual([{ kind: 'canceled', message: '' }])
    expect(world.dispatched).toHaveLength(1)
  })

  it('ignores a return from another origin', async () => {
    const world = tripWorld()
    await reachProvider(world)

    world.courier(
      {
        type: OAUTH_RETURN_MESSAGE_TYPE,
        code: 'stolen',
        state: 'stolen',
        error: '',
      },
      { origin: 'https://evil.test' },
    )
    await Promise.resolve()

    expect(world.dispatched).toHaveLength(1)
    expect(world.oauth.trip.get()?.phase).toBe('authorizing')
  })

  it('ignores a return from a window that is not the trip window', async () => {
    const world = tripWorld()
    await reachProvider(world)

    world.courier(
      {
        type: OAUTH_RETURN_MESSAGE_TYPE,
        code: 'stolen',
        state: 'stolen',
        error: '',
      },
      { source: providerWindow() },
    )
    await Promise.resolve()

    expect(world.dispatched).toHaveLength(1)
    expect(world.oauth.trip.get()?.phase).toBe('authorizing')
  })

  it('ignores a return from the window the previous trip stood in', async () => {
    const world = tripWorld()
    await reachProvider(world)
    const first = world.opened

    world.opened = providerWindow()
    await world.oauth.startOAuthLogin(GOOGLE)

    world.courier(
      {
        type: OAUTH_RETURN_MESSAGE_TYPE,
        code: 'code-1',
        state: 'state-1',
        error: '',
      },
      { source: first },
    )
    await Promise.resolve()

    expect(world.dispatched).toHaveLength(2)
    expect(world.oauth.trip.get()?.phase).toBe('authorizing')
    expect(world.outcomes).toEqual([{ kind: 'canceled', message: '' }])
  })

  it('exchanges the return over this window and moves to the exchanging phase', async () => {
    const world = tripWorld()

    await reachExchange(world)

    expect(world.oauth.trip.get()?.phase).toBe('exchanging')
    expect(world.dispatched[1]).toEqual({
      action: 'hilos_oauth_callback',
      payload: {
        provider: GITHUB,
        code: 'code-1',
        state: 'state-1',
        tripKey: expect.stringMatching(/^[0-9a-f]{32}$/),
      },
    })
  })

  it('sends every exchange under a key of its own', async () => {
    const world = tripWorld()
    await reachExchange(world)
    world.oauth.cancelOAuthTrip()

    sessionStorage.setItem(PROVIDER_STORAGE_KEY, GITHUB)
    world.oauth.resumeOAuthReturn('code-2', 'state-2', '')
    await Promise.resolve()
    await Promise.resolve()

    const keys = world.dispatched
      .filter((sent) => sent.action === 'hilos_oauth_callback')
      .map((sent) => sent.payload.tripKey)
    expect(keys).toHaveLength(2)
    expect(keys[0]).not.toBe(keys[1])
  })

  it('presents the key on a new connection once its page has answered (HIL-1044)', async () => {
    const world = tripWorld()
    await reachExchange(world)
    const tripKey = world.dispatched[1].payload.tripKey

    world.setState('reconnecting')
    world.setState('connected')
    expect(world.dispatched).toHaveLength(2)

    world.emit(SIGNAL_TYPE_PAGE_RESPONSE, { page: 'main', payload: {} })

    expect(world.dispatched[2]).toEqual({
      action: 'hilos_oauth_resume',
      payload: { tripKey },
    })
    // A known key changes nothing here: the outcome arrives the way it always does.
    await Promise.resolve()
    await Promise.resolve()
    expect(world.outcomes).toEqual([])
    world.signIn(7)
    expect(world.outcomes).toEqual([{ kind: 'signed_in', message: '' }])
  })

  it('ends the trip when the server does not know the presented key', async () => {
    const world = tripWorld()
    world.resumeKnown = false
    await reachExchange(world)

    world.setState('reconnecting')
    world.setState('connected')
    world.emit(SIGNAL_TYPE_PAGE_RESPONSE, { page: 'main', payload: {} })
    await Promise.resolve()
    await Promise.resolve()

    expect(world.outcomes).toEqual([
      { kind: 'error', message: 'OAuth login failed. Please try again.' },
    ])
    expect(world.oauth.trip.get()).toBeNull()
  })

  it('keeps the trip when the callback went down with its connection', async () => {
    const world = tripWorld()
    world.dropCallback = true

    await reachExchange(world)
    await Promise.resolve()

    // Whether the server took it before the drop is what the key finds out.
    expect(world.outcomes).toEqual([])
    expect(world.oauth.trip.get()?.phase).toBe('exchanging')
  })

  it('does not present the key on the first connect of a freshly loaded document', async () => {
    const world = tripWorld()
    sessionStorage.setItem(PROVIDER_STORAGE_KEY, GITHUB)
    world.oauth.resumeOAuthReturn('code-cold', 'state-cold', '')

    // The cold path: this connect is the one the callback is about to leave on,
    // not a replacement of it - a key presented now would be answered "unknown".
    world.setState('connected')
    world.emit(SIGNAL_TYPE_PAGE_RESPONSE, { page: 'main', payload: {} })

    expect(
      world.dispatched.filter((sent) => sent.action === 'hilos_oauth_resume'),
    ).toEqual([])
  })

  it('presents nothing once the trip is over', async () => {
    const world = tripWorld()
    await reachExchange(world)
    world.signIn(7)

    world.setState('reconnecting')
    world.setState('connected')
    world.emit(SIGNAL_TYPE_PAGE_RESPONSE, { page: 'main', payload: {} })

    expect(
      world.dispatched.filter((sent) => sent.action === 'hilos_oauth_resume'),
    ).toEqual([])
  })

  it('has no deadline while the person is at the provider', async () => {
    vi.useFakeTimers()
    const world = tripWorld()
    await reachProvider(world)

    await vi.advanceTimersByTimeAsync(60_000)

    expect(world.outcomes).toEqual([])
    expect(world.oauth.trip.get()?.phase).toBe('authorizing')
  })

  it('keeps waiting on an exchange however long the server takes (HIL-1044)', async () => {
    vi.useFakeTimers()
    const world = tripWorld()
    await reachExchange(world)

    // A queue behind the pool ceiling is drained by facts, not by a clock.
    await vi.advanceTimersByTimeAsync(60_000)

    expect(world.outcomes).toEqual([])
    expect(world.oauth.trip.get()?.phase).toBe('exchanging')
  })

  it('ends the exchange when the connection cannot be brought back', async () => {
    const world = tripWorld()
    await reachExchange(world)

    world.setDragging(true)

    expect(world.outcomes).toEqual([
      {
        kind: 'error',
        message: 'Could not reach the server. Please try again.',
      },
    ])
    expect(world.oauth.trip.get()).toBeNull()
  })

  it('ends the exchange when the connection gave up', async () => {
    const world = tripWorld()
    await reachExchange(world)

    world.setState('disconnected')

    expect(world.outcomes).toEqual([
      {
        kind: 'error',
        message: 'Could not reach the server. Please try again.',
      },
    ])
  })

  it('ends a sign-in when the session becomes somebody', async () => {
    const world = tripWorld()
    await reachExchange(world)

    world.signIn(7)

    expect(world.outcomes).toEqual([{ kind: 'signed_in', message: '' }])
    expect(world.oauth.trip.get()).toBeNull()
  })

  it('ends a link on the link_ok result', async () => {
    const world = tripWorld()
    await reachExchange(world, 'link')

    world.emit(OAUTH_RESULT_SIGNAL, {
      acceptKey: 'accept-1',
      provider: GITHUB,
      reason: OAUTH_REASON_LINK_OK,
      email: null,
      linkToken: null,
    })

    expect(world.outcomes).toEqual([{ kind: 'linked', message: '' }])
  })

  it('lets a sign-in into a blocked account go quietly (HIL-289)', async () => {
    const world = tripWorld()
    await reachExchange(world)

    world.emit(OAUTH_RESULT_SIGNAL, {
      acceptKey: 'accept-1',
      provider: GITHUB,
      reason: OAUTH_REASON_ACCOUNT_BLOCKED,
      email: null,
      linkToken: null,
    })

    expect(world.outcomes).toEqual([{ kind: 'account_blocked', message: '' }])
  })

  it('names the duplicate a link collides with', async () => {
    const world = tripWorld()
    await reachExchange(world, 'link')

    world.emit(OAUTH_RESULT_SIGNAL, {
      acceptKey: 'accept-1',
      provider: GITHUB,
      reason: OAUTH_REASON_LINK_DUPLICATE,
      email: null,
      linkToken: null,
    })

    expect(world.outcomes).toEqual([
      {
        kind: 'error',
        message: 'That account is already linked to another user.',
      },
    ])
  })

  it('arms the pending link when the provider email needs a re-auth', async () => {
    const world = tripWorld()
    await reachExchange(world)

    world.emit(OAUTH_RESULT_SIGNAL, {
      acceptKey: 'accept-1',
      provider: GITHUB,
      reason: OAUTH_REASON_REAUTH_REQUIRED,
      email: 'someone@example.test',
      linkToken: 'link-token-1',
    })

    expect(world.outcomes).toEqual([{ kind: 'reauth_pending', message: '' }])
    expect(world.oauth.peekOAuthLink()).toEqual({
      email: 'someone@example.test',
      linkToken: 'link-token-1',
    })
  })

  it('says nothing twice when a late arm fires after the trip ended', async () => {
    vi.useFakeTimers()
    const world = tripWorld()
    await reachExchange(world)

    world.signIn(7)
    world.emit(OAUTH_RESULT_SIGNAL, {
      acceptKey: 'accept-1',
      provider: GITHUB,
      reason: OAUTH_REASON_LINK_OK,
      email: null,
      linkToken: null,
    })
    world.setDragging(true)
    await vi.advanceTimersByTimeAsync(60_000)

    expect(world.outcomes).toEqual([{ kind: 'signed_in', message: '' }])
  })

  it('finishes a return in its own document when there is no opener', async () => {
    const world = tripWorld()
    // The cold path reads the provider back from the storage its window inherited.
    sessionStorage.setItem(PROVIDER_STORAGE_KEY, GITHUB)

    world.oauth.resumeOAuthReturn('code-cold', 'state-cold', '')
    await Promise.resolve()
    await Promise.resolve()

    expect(world.oauth.trip.get()).toBeNull()
    expect(world.dispatched[0]).toEqual({
      action: 'hilos_oauth_callback',
      payload: {
        provider: GITHUB,
        code: 'code-cold',
        state: 'state-cold',
        tripKey: expect.stringMatching(/^[0-9a-f]{32}$/),
      },
    })

    world.signIn(9)
    expect(world.outcomes).toEqual([{ kind: 'signed_in', message: '' }])
  })

  it('refuses a cold return that carries no provider', async () => {
    const world = tripWorld()

    world.oauth.resumeOAuthReturn('code-cold', 'state-cold', '')

    expect(world.dispatched).toEqual([])
    expect(world.outcomes).toEqual([
      { kind: 'error', message: 'This sign-in link is invalid or incomplete.' },
    ])
  })
})
