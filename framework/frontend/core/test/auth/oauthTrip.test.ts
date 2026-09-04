// @vitest-environment happy-dom
// Covers the OAuth trip machine (HIL-633): the window opened in the click, the
// authorize URL substituted into it, the courier message that brings the provider's
// return home, and the five ways a trip ends. The environment is a DOM one because
// the machine IS browser code — a window, a message listener, a poll — even though
// it lives in the framework-agnostic core; `window.open` is stubbed rather than
// exercised, so what is tested is the machine's reaction to a window, not the
// emulator's idea of one. The trip id deciding whose authorize URL the window
// follows came later (HIL-707).
import { afterEach, describe, expect, it, vi } from 'vitest'
import { type HilosConnection } from '../../src/connection/HilosConnection.js'
import {
  type ActionHandle,
  type ActionLifecycle,
} from '../../src/connection/actionLifecycle.js'
import {
  AUTH_ACTION_LINK_OAUTH_START,
  AUTH_ACTION_OAUTH_START,
} from '../../src/auth/authProtocol.js'
import { PASSWORD_FLOW_METHOD } from '../../src/auth/authFlow.js'
import { createHilosAuthContext } from '../../src/auth/authContext.js'
import {
  createOAuthLogin,
  OAUTH_EXCHANGE_TIMEOUT_MS,
  OAUTH_POPUP_BLOCKED_MESSAGE,
  OAUTH_RETURN_MESSAGE_TYPE,
  OAUTH_WINDOW_POLL_MS,
  type HilosOAuthLogin,
  type OAuthTripOutcome,
} from '../../src/auth/oauthLogin.js'
import {
  OAUTH_AUTHORIZE_SIGNAL,
  OAUTH_REASON_LINK_DUPLICATE,
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
  /** Hold the next start's answer back, for the test to refuse when it chooses. */
  holdStart: boolean
  /** Refuse a held start, the way a slow backend refusal lands. */
  refuseHeldStart(message: string): void
  /** Deliver a project signal the way the connection would. */
  emit(type: string, data: Record<string, unknown>): void
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
  const messageListeners: Array<(event: MessageEvent) => void> = []
  const held: Array<(reason: unknown) => void> = []
  const scopes = new ScopeManager()

  const connection = {
    on(event: string, listener: (payload: never) => void): () => void {
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
    methods: [PASSWORD_FLOW_METHOD],
    channels: [],
    oauthProviders: [
      { key: GITHUB, label: 'Continue with GitHub', name: 'GitHub' },
    ],
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

    // The person gives up waiting and starts again; the window is reused, so the
    // second trip is the one they are now standing in front of.
    world.holdStart = false
    world.opened = providerWindow()
    await world.oauth.startOAuthLogin(GITHUB)
    const second = world.opened

    // Only now does the first start's refusal come back.
    world.refuseHeldStart('Unknown provider.')
    await expect(abandoned).rejects.toThrow('Unknown provider.')

    expect(second?.closed).toBe(false)
    expect(first?.closed).toBe(false)
    expect(world.oauth.trip.get()?.phase).toBe('authorizing')
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

    expect(world.outcomes).toEqual([{ kind: 'canceled', message: '' }])
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

  it('exchanges the return over this window and moves to the exchanging phase', async () => {
    const world = tripWorld()

    await reachExchange(world)

    expect(world.oauth.trip.get()?.phase).toBe('exchanging')
    expect(world.dispatched[1]).toEqual({
      action: 'hilos_oauth_callback',
      payload: { provider: GITHUB, code: 'code-1', state: 'state-1' },
    })
  })

  it('has no deadline while the person is at the provider', async () => {
    vi.useFakeTimers()
    const world = tripWorld()
    await reachProvider(world)

    await vi.advanceTimersByTimeAsync(OAUTH_EXCHANGE_TIMEOUT_MS * 2)

    expect(world.outcomes).toEqual([])
    expect(world.oauth.trip.get()?.phase).toBe('authorizing')
  })

  it('gives up on an exchange that never answers', async () => {
    vi.useFakeTimers()
    const world = tripWorld()
    await reachExchange(world)

    await vi.advanceTimersByTimeAsync(OAUTH_EXCHANGE_TIMEOUT_MS)

    expect(world.outcomes).toEqual([
      { kind: 'error', message: 'OAuth login timed out. Please try again.' },
    ])
    expect(world.oauth.trip.get()).toBeNull()
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
    await vi.advanceTimersByTimeAsync(OAUTH_EXCHANGE_TIMEOUT_MS)

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
      payload: { provider: GITHUB, code: 'code-cold', state: 'state-cold' },
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
