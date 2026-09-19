// The poorest registry a deployment can declare: ONE method, the password, and no
// channels, providers, passkey or magic link (HIL-409). The chat demo enables
// everything, so nothing else covers the surface assembling for a project that
// wired only a password — and the first live one of those arrives in R3, when the
// React and Angular defaults are written (HIL-424/425).
//
// What is asserted is exactly that: the surface renders, it offers no way in that
// was not declared, and its submit path reaches the wire. The flow machine's own
// behavior is covered by core's authFlow spec and is not re-tested here.
//
// One more registry is mounted below, the magic-link one (HIL-606), for the screen
// that has no equivalent anywhere else: a waiting screen that also takes a code.
// And one with a provider (HIL-926), for where a trip that ended on the park
// lands: the surface's half of that answer lives only here.
import {
  ActionError,
  ActionLifecycle,
  AUTH_ACTION_COMPLETE_REGISTRATION_PASSWORDLESS,
  AUTH_ACTION_CONFIRM_MAGIC_LINK_CODE,
  AUTH_ACTION_DETECT_IDENTIFIER,
  AUTH_ACTION_LOGIN,
  AUTH_ACTION_REQUEST_MAGIC_LINK,
  AUTH_SURFACE_HEADING_ID,
  bindCodeSendProgress,
  bindPageReady,
  cancelOAuthTrip,
  CODE_SEND_STATE_FAILED,
  CODE_SEND_STATE_NOT_SENT,
  CODE_SEND_STATE_QUEUED,
  CODE_SEND_STATE_SENDING,
  CODE_SEND_STATE_SENT,
  createHilosAuthContext,
  createOAuthLogin,
  createSignal,
  DEFAULT_DETECT_DEBOUNCE_MS,
  MAGIC_LINK_FLOW_METHOD,
  MAGIC_LINK_METHOD_KEY,
  OAUTH_RESULT_SIGNAL,
  OAUTH_RETURN_MESSAGE_TYPE,
  oauthFlowMethod,
  PASSWORD_FLOW_METHOD,
  PASSWORD_METHOD_KEY,
  ScopeManager,
  SESSION_ACK_REGISTERED,
  SIGNAL_CODE_SEND_PROGRESS,
  SIGNAL_HANDSHAKE_RESPONSE,
  SIGNAL_TYPE_PAGE_RESPONSE,
  type ActionHandle,
  type AuthGate,
  type HilosAuthContext,
  type HilosConnection,
  type ProjectSignal,
} from '@hilos/core'
import { mount, type VueWrapper } from '@vue/test-utils'
import { afterEach, describe, expect, it, vi } from 'vitest'

import HilosAuthSurface from './HilosAuthSurface.vue'
import { hilosAuthGateKey } from './hilosAuthGateKey.js'

// The session slot the surface reads its ack from — the default of
// `sessionPendingAck`, which is what the surface asks for (sessionScope.ts).
const PENDING_ACK_SLOT = 'pendingAck'

// The session slot the surface reads the delivery answer from — the default of
// `sessionCodeDelivery` (sessionScope.ts), the same way the ack is read.
const CODE_DELIVERY_SLOT = 'codeDelivery'

// The session slot the unfinished auth step arrives in — the default of
// `sessionPendingAuthStep` (sessionScope.ts), read here the way the ack is.
const PENDING_AUTH_STEP_SLOT = 'pendingAuthStep'

/**
 * What a session that LOST the race for an address comes back to (HIL-833): the
 * step it was MOVED to, under the intent that is now the only way in, carrying
 * the reason. No moment, because there is no code of its own left in play.
 */
const LOST_RACE_STEP = {
  identifier: 'someone@example.com',
  kind: 'email',
  intent: 'login',
  step: 'identifier',
  channel: null,
  expiresAt: null,
  code: 'identifier_taken',
}

/**
 * What a session that is simply unfinished comes back to: the code screen it was
 * standing on all along, with no reason on it, because nobody moved it.
 */
const RESUMED_CODE_STEP = {
  identifier: 'someone@example.com',
  kind: 'email',
  intent: 'register',
  step: 'code',
  channel: null,
  expiresAt: Date.now() + 600000,
  code: null,
}

/**
 * What a session that proved its address and owes a password comes back to: the
 * password screen of a registration, which is the screen the way past it lives on
 * (HIL-1008).
 */
const PROVED_REGISTRATION_STEP = {
  identifier: 'newcomer@example.com',
  kind: 'email',
  intent: 'register',
  step: 'set_password',
  channel: null,
  // A deadline is not optional on this step: the hold behind the screen runs
  // out, and a node without one is dropped as half-written (sessionScope).
  expiresAt: Date.now() + 600000,
  code: null,
}

/** The dispatch calls one mounted surface made, in order. */
type Dispatched = Array<{ action: string; payload: Record<string, unknown> }>

/**
 * A context with one password method and nothing else: no channels, no providers.
 * The connection answers every subscription with an unsubscribe and never delivers
 * a signal; the action lifecycle records what was dispatched and resolves it.
 *
 * @param refuseLogin Answer the sign-in with a refusal instead of a success.
 * @returns The context to mount with, and the dispatch log to assert on.
 */
function passwordOnlyContext(refuseLogin = false): {
  context: HilosAuthContext
  dispatched: Dispatched
  emitProjectSignal: (signal: { type: string; data: unknown }) => void
} {
  const dispatched: Dispatched = []
  const projectListeners: Array<
    (signal: { type: string; data: unknown }) => void
  > = []
  const connection = {
    on: vi.fn((event: string, listener: (payload: never) => void) => {
      if (event === 'projectSignal') {
        projectListeners.push(
          listener as unknown as (signal: {
            type: string
            data: unknown
          }) => void,
        )
      }

      return () => {
        const index = projectListeners.indexOf(
          listener as unknown as (signal: {
            type: string
            data: unknown
          }) => void,
        )
        if (index >= 0) {
          projectListeners.splice(index, 1)
        }
      }
    }),
  } as unknown as HilosConnection
  const actions = {
    dispatch: (action: string, payload: Record<string, unknown>) => {
      dispatched.push({ action, payload })
      const identifier = String(payload['identifier'] ?? '')
      // Only the lookup answers with a domain reply, and it is what reveals the
      // password field: an account that exists and has a password on it.
      const reply =
        action === AUTH_ACTION_DETECT_IDENTIFIER
          ? {
              identifier,
              normalized: identifier,
              kind: 'email',
              status: 'active',
              methods: [PASSWORD_METHOD_KEY],
              registerable: [],
              registrationBlock: null,
              signInBlock: null,
            }
          : undefined

      return {
        requestId: `req-${dispatched.length}`,
        loading: createSignal(false),
        done:
          refuseLogin && action === AUTH_ACTION_LOGIN
            ? Promise.reject(
                new ActionError(action, 'fail', 'Incorrect password'),
              )
            : Promise.resolve({ reply }),
      } as unknown as ActionHandle
    },
  } as unknown as ActionLifecycle

  return {
    dispatched,
    emitProjectSignal: (signal: { type: string; data: unknown }): void => {
      for (const listener of projectListeners) {
        listener(signal)
      }
    },
    context: createHilosAuthContext({
      connection,
      scopes: new ScopeManager(),
      actions,
      methods: [PASSWORD_FLOW_METHOD],
      channels: [],
      oauthProviders: [],
      termsPath: '/terms',
      privacyPath: '/privacy',
    }),
  }
}

/**
 * A context offering the password and the magic link, the pair the letter screen
 * needs: the lookup answers with an account that has both, so the icon shows.
 *
 * @returns The context to mount with, and the dispatch log to assert on.
 */
function magicLinkContext(): {
  context: HilosAuthContext
  dispatched: Dispatched
} {
  const dispatched: Dispatched = []
  const connection = {
    on: vi.fn().mockReturnValue(() => undefined),
  } as unknown as HilosConnection
  const actions = {
    dispatch: (action: string, payload: Record<string, unknown>) => {
      dispatched.push({ action, payload })
      const identifier = String(payload['identifier'] ?? '')
      const reply =
        action === AUTH_ACTION_DETECT_IDENTIFIER
          ? {
              identifier,
              normalized: identifier,
              kind: 'email',
              status: 'active',
              methods: [PASSWORD_METHOD_KEY, MAGIC_LINK_METHOD_KEY],
              registerable: [],
              registrationBlock: null,
              signInBlock: null,
            }
          : undefined

      return {
        requestId: `req-${dispatched.length}`,
        loading: createSignal(false),
        done: Promise.resolve({ reply }),
      } as unknown as ActionHandle
    },
  } as unknown as ActionLifecycle

  return {
    dispatched,
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
 * A context whose lookup answers a FREE address that cannot be registered, and
 * names why (HIL-830). Both reasons produce the same empty `registerable`, which
 * is exactly why the surface is not allowed to guess between them.
 *
 * @param registrationBlock Why registration is not offered on this deployment.
 * @returns The context to mount with.
 */
function freeIdentifierContext(
  registrationBlock: 'closed' | 'no_channel',
): HilosAuthContext {
  const connection = {
    on: vi.fn().mockReturnValue(() => undefined),
  } as unknown as HilosConnection
  const actions = {
    dispatch: (action: string, payload: Record<string, unknown>) => {
      const identifier = String(payload['identifier'] ?? '')
      const reply =
        action === AUTH_ACTION_DETECT_IDENTIFIER
          ? {
              identifier,
              normalized: identifier,
              kind: 'email',
              status: 'none',
              methods: [],
              registerable: [],
              registrationBlock,
              signInBlock: null,
            }
          : undefined

      return {
        requestId: 'req-free',
        loading: createSignal(false),
        done: Promise.resolve({ reply }),
      } as unknown as ActionHandle
    },
  } as unknown as ActionLifecycle

  return createHilosAuthContext({
    connection,
    scopes: new ScopeManager(),
    actions,
    methods: [PASSWORD_FLOW_METHOD],
    channels: [],
    oauthProviders: [],
    termsPath: '/terms',
    privacyPath: '/privacy',
  })
}

/**
 * A context whose lookup answers a free address this deployment WILL register,
 * by both roads it has (HIL-1008). This is the reply that used to put a second
 * way on beside the main button: the envelope stood on the strength of
 * `registerable` naming the magic link, and the password hint stood on the
 * intent alone.
 *
 * @returns The context to mount with.
 */
function registrableIdentifierContext(): HilosAuthContext {
  const connection = {
    on: vi.fn().mockReturnValue(() => undefined),
  } as unknown as HilosConnection
  const actions = {
    dispatch: (action: string, payload: Record<string, unknown>) => {
      const identifier = String(payload['identifier'] ?? '')
      const reply =
        action === AUTH_ACTION_DETECT_IDENTIFIER
          ? {
              identifier,
              normalized: identifier,
              kind: 'email',
              status: 'none',
              methods: [],
              registerable: [PASSWORD_METHOD_KEY, MAGIC_LINK_METHOD_KEY],
              registrationBlock: null,
              signInBlock: null,
            }
          : undefined

      return {
        requestId: 'req-registrable',
        loading: createSignal(false),
        done: Promise.resolve({ reply }),
      } as unknown as ActionHandle
    },
  } as unknown as ActionLifecycle

  return createHilosAuthContext({
    connection,
    scopes: new ScopeManager(),
    actions,
    methods: [PASSWORD_FLOW_METHOD, MAGIC_LINK_FLOW_METHOD],
    channels: [],
    oauthProviders: [],
    termsPath: '/terms',
    privacyPath: '/privacy',
  })
}

/**
 * A context whose lookup answers an existing email account on an installation
 * that may not be able to mail (HIL-973): the methods the backend left it and,
 * when none are left, why.
 *
 * @param methods What the account signs in with after the delivery filter.
 * @param signInBlock Why the account is offered no way in, or null.
 * @returns The context to mount with.
 */
function liveAccountContext(
  methods: readonly string[],
  signInBlock: 'no_channel' | null,
): HilosAuthContext {
  const connection = {
    on: vi.fn().mockReturnValue(() => undefined),
  } as unknown as HilosConnection
  const actions = {
    dispatch: (action: string, payload: Record<string, unknown>) => {
      const identifier = String(payload['identifier'] ?? '')
      const reply =
        action === AUTH_ACTION_DETECT_IDENTIFIER
          ? {
              identifier,
              normalized: identifier,
              kind: 'email',
              status: 'active',
              methods,
              registerable: [],
              registrationBlock: null,
              signInBlock,
            }
          : undefined

      return {
        requestId: 'req-live',
        loading: createSignal(false),
        done: Promise.resolve({ reply }),
      } as unknown as ActionHandle
    },
  } as unknown as ActionLifecycle

  return createHilosAuthContext({
    connection,
    scopes: new ScopeManager(),
    actions,
    methods: [PASSWORD_FLOW_METHOD, MAGIC_LINK_FLOW_METHOD],
    channels: [],
    oauthProviders: [],
    termsPath: '/terms',
    privacyPath: '/privacy',
  })
}

/**
 * A context whose lookup answers `pending` — an address this browser already
 * holds — which carries the surface straight to the registration code screen
 * (HIL-608). The shortest road to the one screen this leaf is about.
 *
 * @returns The context to mount with, and the dispatch log to assert on.
 */
function heldIdentifierContext(): {
  context: HilosAuthContext
  dispatched: Dispatched
} {
  const dispatched: Dispatched = []
  const connection = {
    on: vi.fn().mockReturnValue(() => undefined),
  } as unknown as HilosConnection
  const actions = {
    dispatch: (action: string, payload: Record<string, unknown>) => {
      dispatched.push({ action, payload })
      const identifier = String(payload['identifier'] ?? '')
      const reply =
        action === AUTH_ACTION_DETECT_IDENTIFIER
          ? {
              identifier,
              normalized: identifier,
              kind: 'email',
              status: 'pending',
              methods: [],
              registerable: [],
              registrationBlock: null,
              signInBlock: null,
            }
          : undefined

      return {
        requestId: `req-${dispatched.length}`,
        loading: createSignal(false),
        done: Promise.resolve({ reply }),
      } as unknown as ActionHandle
    },
  } as unknown as ActionLifecycle

  return {
    dispatched,
    context: createHilosAuthContext({
      connection,
      scopes: new ScopeManager(),
      actions,
      methods: [PASSWORD_FLOW_METHOD],
      channels: [],
      oauthProviders: [],
      termsPath: '/terms',
      privacyPath: '/privacy',
    }),
  }
}

/**
 * A context whose magic-link send answers with a code screen that has a LIFE on
 * it — the one thing the expired screen needs to exist (HIL-828). The cooldown is
 * armed in the past, so the button rather than the countdown is what it offers.
 *
 * @param lifetimeMs How long the code the backend answers with is good for.
 * @returns The context to mount with, and the dispatch log to assert on.
 */
function expiringLetterContext(lifetimeMs: number): {
  context: HilosAuthContext
  dispatched: Dispatched
} {
  const dispatched: Dispatched = []
  const connection = {
    on: vi.fn().mockReturnValue(() => undefined),
  } as unknown as HilosConnection
  const actions = {
    dispatch: (action: string, payload: Record<string, unknown>) => {
      dispatched.push({ action, payload })
      const identifier = String(payload['identifier'] ?? '')
      const reply =
        action === AUTH_ACTION_DETECT_IDENTIFIER
          ? {
              identifier,
              normalized: identifier,
              kind: 'email',
              status: 'active',
              methods: [PASSWORD_METHOD_KEY, MAGIC_LINK_METHOD_KEY],
              registerable: [],
              registrationBlock: null,
              signInBlock: null,
            }
          : {
              ok: true,
              resendAt: Date.now() - 1,
              expiresAt: Date.now() + lifetimeMs,
            }

      return {
        requestId: `req-${dispatched.length}`,
        loading: createSignal(false),
        done: Promise.resolve({ reply }),
      } as unknown as ActionHandle
    },
  } as unknown as ActionLifecycle

  return {
    dispatched,
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
 * A gate double: the surface only ever asks it to close, and the test asks the
 * double whether that happened.
 *
 * @returns The gate to provide, and the spy standing in for `dismiss`.
 */
function gateDouble(): { gate: AuthGate; dismissed: ReturnType<typeof vi.fn> } {
  const dismissed = vi.fn()

  return {
    dismissed,
    gate: {
      modalOpen: createSignal(false),
      requireAuth: vi.fn(),
      dismiss: dismissed,
    },
  }
}

/**
 * Let every pending microtask and the render that follows it settle.
 *
 * @param wrapper The mounted surface to flush the render of.
 */
async function flush(wrapper: {
  vm: { $nextTick: () => Promise<void> }
}): Promise<void> {
  await Promise.resolve()
  await Promise.resolve()
  await wrapper.vm.$nextTick()
}

/**
 * Put a send-progress frame on the SDK's session-wide line, the way the socket
 * would (HIL-826). The line is a module singleton, so the frame outlives the
 * surface and the next test has to be handed a clean one - which is what the
 * null frame below does, and what the server itself sends to take a line away.
 *
 * @param state The `CODE_SEND_STATE_*` the line should report, or null for none.
 * @param detail The provider's own sentence riding the frame, or null for none.
 */
function reportSendProgress(
  state: string | null,
  detail: string | null = null,
): void {
  const listeners: ((signal: { type: string; data: unknown }) => void)[] = []
  const connection = {
    on: (_event: string, listener: (signal: never) => void) => {
      listeners.push(
        listener as unknown as (signal: {
          type: string
          data: unknown
        }) => void,
      )

      return () => undefined
    },
  } as unknown as HilosConnection
  const unbind = bindCodeSendProgress(connection)
  for (const listener of listeners) {
    listener({
      type: SIGNAL_CODE_SEND_PROGRESS,
      data: { state, channel: 'email', detail },
    })
  }
  unbind()
}

/**
 * Mount the magic-link registry and walk it to the code screen of the letter,
 * where the send line lives.
 *
 * @returns The mounted surface, standing on the code screen.
 */
async function openLetterCodeScreen(): Promise<VueWrapper> {
  vi.useFakeTimers()
  const { context } = magicLinkContext()
  const wrapper = mount(HilosAuthSurface, { props: { context } })

  await wrapper
    .find('[data-id="auth-identifier"]')
    .setValue('someone@example.com')
  await vi.advanceTimersByTimeAsync(DEFAULT_DETECT_DEBOUNCE_MS + 1)
  await flush(wrapper)
  await wrapper.find('[data-id="auth-icon-magic-link"]').trigger('click')
  await flush(wrapper)

  return wrapper
}

/**
 * How many elements of the code screen's form stand before the code field — the
 * unit test's stand-in for its vertical position, which jsdom does not lay out.
 * Counted inside the form, because the live regions above it are visually
 * hidden and gain a node when they speak.
 *
 * @param root The mounted surface's root element.
 * @returns The number of form elements preceding the code field.
 */
function elementsBeforeCodeField(root: Element): number {
  const all = Array.from(root.querySelectorAll('form *'))

  return all.findIndex((node) => node.getAttribute('data-id') === 'auth-code')
}

/** The provider the trip cases ride to. */
const GITHUB_PROVIDER = 'oauth:github'

/**
 * The reason the daemon ends a sign-in exchange that failed with
 * (`OAuthResultSignalData::REASON_LOGIN_FAILED`): a provider that answered 500,
 * stayed silent, cut its answer or refused the code all arrive as this one.
 */
const OAUTH_REASON_LOGIN_FAILED = 'oauth_login_failed'

/** What the trip says when a sign-in exchange failed (oauthLogin.ts). */
const OAUTH_FAILED_MESSAGE = 'OAuth login failed. Please try again.'

/** One trip case's world: the context to mount with and the doors a trip comes home by. */
interface TripWorld {
  context: HilosAuthContext
  /**
   * Bring the provider's return home the way the courier in the provider window
   * does.
   *
   * @param error The provider's error code, or empty for a return with a code.
   */
  courier(error: string): void
  /**
   * Deliver a project signal the way the connection would.
   *
   * @param type The signal type.
   * @param data The signal payload.
   */
  emit(type: string, data: Record<string, unknown>): void
  /** End any live trip and drop every binding and stub this world made. */
  unbind(): void
}

/** The trip world the running case stood up, for the teardown to take down. */
let activeTrip: TripWorld | null = null

/**
 * A context with the password and one provider, and core's real trip machine
 * bound to it the way boot binds it: `window.open` hands back a window double,
 * the courier's message listener is caught rather than fed a real MessageEvent
 * (whose `source` has to be a real window), and the page-ready gate the exchange
 * waits on is latched. Every action is accepted, so what ends a trip is whatever
 * the case delivers next — the same harness as core's oauthTrip spec, cut down to
 * the two endings the surface tells apart.
 *
 * @returns The world, already bound.
 */
function oauthTripWorld(): TripWorld {
  const projectListeners: Array<(signal: ProjectSignal) => void> = []
  const messageListeners: Array<(event: MessageEvent) => void> = []
  const connection = {
    on: (event: string, listener: (payload: never) => void) => {
      if (event !== 'projectSignal') {
        return () => undefined
      }
      const typed = listener as unknown as (signal: ProjectSignal) => void
      projectListeners.push(typed)

      return () => {
        const index = projectListeners.indexOf(typed)
        if (index >= 0) {
          projectListeners.splice(index, 1)
        }
      }
    },
  } as unknown as HilosConnection
  let dispatches = 0
  const actions = {
    dispatch: () => {
      dispatches += 1

      return {
        requestId: `req-${dispatches}`,
        loading: createSignal(false),
        done: Promise.resolve({}),
      } as unknown as ActionHandle
    },
  } as unknown as ActionLifecycle
  const context = createHilosAuthContext({
    connection,
    scopes: new ScopeManager(),
    actions,
    methods: [
      PASSWORD_FLOW_METHOD,
      oauthFlowMethod(GITHUB_PROVIDER, 'Continue with GitHub'),
    ],
    channels: [],
    oauthProviders: [
      { key: GITHUB_PROVIDER, label: 'Continue with GitHub', name: 'GitHub' },
    ],
    termsPath: '/terms',
    privacyPath: '/privacy',
  })

  const providerWindow = {
    closed: false,
    close: (): void => {
      providerWindow.closed = true
    },
    location: { replace: (): void => undefined },
  }
  const opening = vi
    .spyOn(window, 'open')
    .mockReturnValue(providerWindow as unknown as Window)
  const realAdd = window.addEventListener.bind(window)
  const listening = vi
    .spyOn(window, 'addEventListener')
    .mockImplementation(
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
  const stopTrip = createOAuthLogin(context).bindOAuthTrip()
  const stopReady = bindPageReady(connection)

  const world: TripWorld = {
    context,
    courier(error) {
      const event = {
        data: {
          type: OAUTH_RETURN_MESSAGE_TYPE,
          code: error === '' ? 'code-1' : '',
          state: error === '' ? 'state-1' : '',
          error,
        },
        origin: window.location.origin,
        source: providerWindow,
      } as unknown as MessageEvent
      for (const listener of [...messageListeners]) {
        listener(event)
      }
    },
    emit(type, data) {
      const signal = {
        kind: 'project',
        type,
        data,
        envelope: {},
      } as unknown as ProjectSignal
      for (const listener of [...projectListeners]) {
        listener(signal)
      }
    },
    unbind() {
      cancelOAuthTrip()
      stopTrip()
      stopReady()
      opening.mockRestore()
      listening.mockRestore()
    },
  }
  // The main window answered its page long before anybody clicked a provider.
  world.emit(SIGNAL_TYPE_PAGE_RESPONSE, { page: 'main', payload: {} })
  activeTrip = world

  return world
}

describe('HilosAuthSurface', () => {
  afterEach(() => {
    vi.useRealTimers()
    reportSendProgress(null)
    activeTrip?.unbind()
    activeTrip = null
  })

  it('assembles from a one-password registry with no icon method offered', () => {
    const { context } = passwordOnlyContext()
    const wrapper = mount(HilosAuthSurface, { props: { context } })

    expect(wrapper.find('[data-id="auth-surface"]').exists()).toBe(true)
    // The frame showing this surface names itself with that heading, so the id
    // is part of the surface's contract, not decoration (HIL-832).
    expect(wrapper.find('[data-id="auth-heading"]').attributes('id')).toBe(
      AUTH_SURFACE_HEADING_ID,
    )
    expect(wrapper.find('[data-id="auth-identifier"]').exists()).toBe(true)
    // Identifier-first: the whole sign-in surface is that one field until the
    // lookup answers (HIL-423), so neither the password nor a submit shows yet.
    expect(wrapper.find('[data-id="auth-password"]').exists()).toBe(false)
    expect(wrapper.find('[data-id="auth-submit"]').exists()).toBe(false)
    // Nothing that was not declared: the icon methods and the code channels are
    // rendered from the registry, so an empty one renders none of them.
    expect(wrapper.find('[data-id="auth-icon-passkey"]').exists()).toBe(false)
    expect(wrapper.find('[data-id="auth-icon-magic-link"]').exists()).toBe(
      false,
    )
    expect(wrapper.find('[data-id="auth-channel-sms"]').exists()).toBe(false)
  })

  it('keeps the submit path alive: the lookup reveals the password and submit signs in', async () => {
    vi.useFakeTimers()
    const { context, dispatched } = passwordOnlyContext()
    const wrapper = mount(HilosAuthSurface, { props: { context } })

    await wrapper
      .find('[data-id="auth-identifier"]')
      .setValue('someone@example.com')
    // The lookup is debounced by the machine; let it fire and its reply land.
    await vi.advanceTimersByTimeAsync(DEFAULT_DETECT_DEBOUNCE_MS + 1)
    await flush(wrapper)

    expect(dispatched.map((call) => call.action)).toEqual([
      AUTH_ACTION_DETECT_IDENTIFIER,
    ])
    const password = wrapper.find('[data-id="auth-password"]')
    expect(password.exists()).toBe(true)

    await password.setValue('correct horse')
    // The submit control appears with the revealed password, and it submits the
    // step's form (`type="submit"`), which is what carries the dispatch.
    expect(wrapper.find('[data-id="auth-submit"]').exists()).toBe(true)
    await wrapper.find('form').trigger('submit')
    await flush(wrapper)

    expect(dispatched.map((call) => call.action)).toEqual([
      AUTH_ACTION_DETECT_IDENTIFIER,
      AUTH_ACTION_LOGIN,
    ])
    expect(dispatched[1]?.payload).toMatchObject({
      email: 'someone@example.com',
      password: 'correct horse',
    })
  })

  it('the letter screen keeps its heading and grows a code field', async () => {
    vi.useFakeTimers()
    const { context, dispatched } = magicLinkContext()
    const wrapper = mount(HilosAuthSurface, { props: { context } })

    await wrapper
      .find('[data-id="auth-identifier"]')
      .setValue('someone@example.com')
    await vi.advanceTimersByTimeAsync(DEFAULT_DETECT_DEBOUNCE_MS + 1)
    await flush(wrapper)

    await wrapper.find('[data-id="auth-icon-magic-link"]').trigger('click')
    await flush(wrapper)

    // Still the same screen the person asked for, now with a way to answer by
    // hand: the heading, the plaque about the link, the field, and the resend.
    expect(wrapper.text()).toContain('Check your inbox')
    expect(wrapper.find('[data-id="auth-link-sent"]').exists()).toBe(true)
    expect(wrapper.find('[data-id="auth-code"]').exists()).toBe(true)
    expect(wrapper.find('[data-id="auth-resend"]').exists()).toBe(true)

    await wrapper.find('[data-id="auth-code"]').setValue('135790')
    await wrapper.find('form').trigger('submit')
    await flush(wrapper)

    expect(dispatched.map((call) => call.action)).toEqual([
      AUTH_ACTION_DETECT_IDENTIFIER,
      AUTH_ACTION_REQUEST_MAGIC_LINK,
      AUTH_ACTION_CONFIRM_MAGIC_LINK_CODE,
    ])
    expect(dispatched[2]?.payload).toMatchObject({
      email: 'someone@example.com',
      code: '135790',
    })
  })

  it('says a letter that was only written down was not really sent', async () => {
    vi.useFakeTimers()
    const { context } = magicLinkContext()
    const wrapper = mount(HilosAuthSurface, { props: { context } })

    await wrapper
      .find('[data-id="auth-identifier"]')
      .setValue('someone@example.com')
    await vi.advanceTimersByTimeAsync(DEFAULT_DETECT_DEBOUNCE_MS + 1)
    await flush(wrapper)
    await wrapper.find('[data-id="auth-icon-magic-link"]').trigger('click')
    await flush(wrapper)

    reportSendProgress(CODE_SEND_STATE_NOT_SENT)
    await flush(wrapper)

    // The whole visible output of this leaf: the line says the letter went
    // nowhere, and it says it calmly - not green, because nothing was sent, and
    // not red, because nothing went wrong (HIL-827). No path is named.
    const line = wrapper.find('[data-id="auth-send-progress"]')
    expect(line.text()).toBe(
      'Not really sent to someone@example.com — letters are written here, not mailed',
    )
    expect(line.classes()).toContain('text-body-secondary')
    expect(line.find('i').classes()).toContain('bi-flask')
  })

  it('holds the send line room with an idle twin before the first frame', async () => {
    const wrapper = await openLetterCodeScreen()

    // The code screen stands and nothing has been said about the letter yet:
    // the slot is there anyway, holding one inert copy of the line.
    const slot = wrapper.find('[data-id="auth-send-progress-slot"]')
    expect(slot.element.children).toHaveLength(1)
    const twin = slot.find('[data-id="auth-send-progress-idle"]')
    expect(twin.exists()).toBe(true)
    expect(twin.attributes('aria-hidden')).toBe('true')
    expect(twin.classes()).toContain('invisible')
    expect(twin.find('button').exists()).toBe(false)
    expect(slot.find('[data-id="auth-send-progress"]').exists()).toBe(false)
  })

  it('swaps the twin for the line without moving the code field', async () => {
    const wrapper = await openLetterCodeScreen()
    const before = elementsBeforeCodeField(wrapper.element)

    reportSendProgress(CODE_SEND_STATE_SENT)
    await flush(wrapper)

    // One visible line, no twin, and the field below stands where it stood:
    // the line took the room the twin was holding instead of adding one.
    const slot = wrapper.find('[data-id="auth-send-progress-slot"]')
    expect(slot.element.children).toHaveLength(1)
    expect(slot.find('[data-id="auth-send-progress"]').exists()).toBe(true)
    expect(slot.find('[data-id="auth-send-progress-idle"]').exists()).toBe(
      false,
    )
    expect(elementsBeforeCodeField(wrapper.element)).toBe(before)
  })

  it('offers the full send line behind a button in every state', async () => {
    const wrapper = await openLetterCodeScreen()

    for (const state of [
      CODE_SEND_STATE_QUEUED,
      CODE_SEND_STATE_SENDING,
      CODE_SEND_STATE_SENT,
      CODE_SEND_STATE_FAILED,
      CODE_SEND_STATE_NOT_SENT,
    ]) {
      reportSendProgress(state)
      await flush(wrapper)

      // The button stands in every state, not only on a refusal, and the
      // panel it opens holds the very text the line is showing.
      const line = wrapper.find('[data-id="auth-send-progress"]')
      const details = line.find('[data-id="auth-send-progress-details"]')
      expect(details.exists()).toBe(true)
      expect(details.attributes('aria-label')).toBe('Show the full message')
      await details.trigger('click')
      await flush(wrapper)
      const full = document.querySelector('[data-id="auth-send-progress-full"]')
      expect(full?.textContent?.trim()).toBe(line.text())
      expect(
        document.querySelector('[data-id="auth-send-progress-close"]'),
      ).not.toBeNull()
    }

    // A line the server takes away closes the panel along with it.
    reportSendProgress(null)
    await flush(wrapper)
    expect(document.querySelector('[data-id="auth-send-progress-full"]')).toBe(
      null,
    )
  })

  it('keeps a long provider sentence to the one line and gives all of it to the panel', async () => {
    const wrapper = await openLetterCodeScreen()
    reportSendProgress(CODE_SEND_STATE_FAILED, 'short')
    await flush(wrapper)
    const line = wrapper.find('[data-id="auth-send-progress"]')
    const shortShape = line.element.querySelectorAll('*').length
    const shortClasses = line.classes()

    const sentence = 'x'.repeat(200)
    reportSendProgress(CODE_SEND_STATE_FAILED, sentence)
    await flush(wrapper)

    // Same nodes, same classes: the length is the text's business, and the
    // text is truncated to the room rather than growing it.
    const long = wrapper.find('[data-id="auth-send-progress"]')
    expect(long.element.querySelectorAll('*').length).toBe(shortShape)
    expect(long.classes()).toEqual(shortClasses)
    expect(long.find('span').classes()).toContain('text-truncate')

    await long.find('[data-id="auth-send-progress-details"]').trigger('click')
    await flush(wrapper)
    expect(
      document.querySelector('[data-id="auth-send-progress-full"]')
        ?.textContent,
    ).toContain(`Could not send: ${sentence}`)
  })

  it('takes the finished panel away when the ack is answered in another tab', async () => {
    const { context } = passwordOnlyContext()
    const { gate, dismissed } = gateDouble()
    const wrapper = mount(HilosAuthSurface, {
      props: { context },
      global: { provide: { [hilosAuthGateKey as symbol]: gate } },
    })

    // This tab signed nobody in: the ack is the whole reason it shows a panel.
    context.scopes.session.data.set(PENDING_ACK_SLOT, SESSION_ACK_REGISTERED)
    await flush(wrapper)
    expect(wrapper.find('[data-id="auth-continue"]').exists()).toBe(true)

    // The other tab pressed Continue; the server cleared the row and published
    // the cleared mark back here. That, and nothing local, is what closes it.
    context.scopes.session.data.set(PENDING_ACK_SLOT, null)
    await flush(wrapper)

    expect(wrapper.find('[data-id="auth-continue"]').exists()).toBe(false)
    expect(dismissed).toHaveBeenCalledTimes(1)
  })

  it('takes the finished panel away when a handshake says the session owes nothing', async () => {
    const { context, emitProjectSignal } = passwordOnlyContext()
    const { gate, dismissed } = gateDouble()
    const wrapper = mount(HilosAuthSurface, {
      props: { context },
      global: { provide: { [hilosAuthGateKey as symbol]: gate } },
    })

    context.scopes.session.data.set(PENDING_ACK_SLOT, SESSION_ACK_REGISTERED)
    await flush(wrapper)
    expect(wrapper.find('[data-id="auth-continue"]').exists()).toBe(true)

    // The clearing frame never arrived; a later handshake restates that the
    // session owes nothing. The panel comes down from that fact, not from the
    // session slot changing — that slot is still the standing mark.
    emitProjectSignal({
      type: SIGNAL_HANDSHAKE_RESPONSE,
      data: { data: { pendingAck: null } },
    })
    await flush(wrapper)

    expect(wrapper.find('[data-id="auth-continue"]').exists()).toBe(false)
    expect(dismissed).toHaveBeenCalledTimes(1)
  })

  it('leaves a screen that has moved on alone when the ack is answered', async () => {
    const { context } = passwordOnlyContext()
    const { gate, dismissed } = gateDouble()
    const wrapper = mount(HilosAuthSurface, {
      props: { context },
      global: { provide: { [hilosAuthGateKey as symbol]: gate } },
    })

    // A kind this build has no panel for: the mark stands on the session, and
    // the surface goes on showing the identifier field. It stands for every tab
    // that LEFT the panel — somebody else's dismissal may not take that screen.
    context.scopes.session.data.set(PENDING_ACK_SLOT, 'ack-from-a-newer-server')
    await flush(wrapper)
    expect(wrapper.find('[data-id="auth-identifier"]').exists()).toBe(true)

    context.scopes.session.data.set(PENDING_ACK_SLOT, null)
    await flush(wrapper)

    expect(wrapper.find('[data-id="auth-identifier"]').exists()).toBe(true)
    expect(dismissed).not.toHaveBeenCalled()
  })

  it('tells a tab that came back by reload the address was taken while away', async () => {
    const { context } = passwordOnlyContext()
    // The handshake was answered before this surface existed — the whole of the
    // reload case — so the node is already on the session at mount.
    context.scopes.session.data.set(PENDING_AUTH_STEP_SLOT, LOST_RACE_STEP)
    const wrapper = mount(HilosAuthSurface, { props: { context } })
    await flush(wrapper)

    // The step alone would leave the address field standing there filled in and
    // unexplained; what the leaf adds is the sentence beside it.
    const field = wrapper.find('[data-id="auth-identifier"]')
    expect((field.element as HTMLInputElement).value).toBe(
      'someone@example.com',
    )
    expect(wrapper.find('[data-id="auth-notice"]').text()).toBe(
      'That address already has an account — sign in instead.',
    )
  })

  it('tells a tab whose socket only blinked, with no reload under it', async () => {
    const { context } = passwordOnlyContext()
    const wrapper = mount(HilosAuthSurface, { props: { context } })
    await flush(wrapper)
    expect(wrapper.find('[data-id="auth-notice"]').exists()).toBe(false)

    // The socket came back and the handshake put the step on the session. Mount
    // is long over, so nothing but the watch can carry this.
    context.scopes.session.data.set(PENDING_AUTH_STEP_SLOT, LOST_RACE_STEP)
    await flush(wrapper)

    expect(wrapper.find('[data-id="auth-notice"]').text()).toBe(
      'That address already has an account — sign in instead.',
    )
    expect(wrapper.find('[data-id="auth-identifier"]').exists()).toBe(true)
  })

  it('leaves a screen alone when the step that arrives names no reason', async () => {
    const { context } = passwordOnlyContext()
    const wrapper = mount(HilosAuthSurface, { props: { context } })
    await flush(wrapper)

    // An ordinary unfinished step, arriving on a reconnect while somebody is on
    // the identifier field. It is restored on MOUNT and nowhere else: rebuilding
    // the screen under their hands is exactly what the narrowing is for.
    context.scopes.session.data.set(PENDING_AUTH_STEP_SLOT, RESUMED_CODE_STEP)
    await flush(wrapper)

    expect(wrapper.find('[data-id="auth-code"]').exists()).toBe(false)
    expect(wrapper.find('[data-id="auth-identifier"]').exists()).toBe(true)
    expect(wrapper.find('[data-id="auth-notice"]').exists()).toBe(false)
  })

  it('stands both live regions up empty before anything has been said', () => {
    const { context } = passwordOnlyContext()
    const wrapper = mount(HilosAuthSurface, { props: { context } })

    // The point of the leaf: the region is there BEFORE its text, so the reader
    // announces the change of content rather than the arrival of a node.
    const urgent = wrapper.find('[data-id="auth-live-assertive"]')
    const calm = wrapper.find('[data-id="auth-live-polite"]')
    expect(urgent.exists()).toBe(true)
    expect(calm.exists()).toBe(true)
    expect(urgent.text()).toBe('')
    expect(calm.text()).toBe('')
  })

  it('speaks a refusal from the urgent region while the visible block stays mute', async () => {
    vi.useFakeTimers()
    const { context } = passwordOnlyContext(true)
    const wrapper = mount(HilosAuthSurface, { props: { context } })

    await wrapper
      .find('[data-id="auth-identifier"]')
      .setValue('someone@example.com')
    await vi.advanceTimersByTimeAsync(DEFAULT_DETECT_DEBOUNCE_MS + 1)
    await flush(wrapper)

    await wrapper.find('[data-id="auth-password"]').setValue('wrong horse')
    await wrapper.find('form').trigger('submit')
    await flush(wrapper)

    expect(wrapper.find('[data-id="auth-live-assertive"]').text()).toBe(
      'Incorrect password',
    )
    // The same sentence is on the screen for the eye — and carries no role of
    // its own, or the reader would say it twice.
    const visible = wrapper.find('[data-id="auth-error"]')
    expect(visible.text()).toBe('Incorrect password')
    expect(visible.attributes('role')).toBeUndefined()
    expect(visible.attributes('aria-live')).toBeUndefined()
  })

  it('puts the letter, address and all, into the calm region', async () => {
    vi.useFakeTimers()
    const { context } = magicLinkContext()
    const wrapper = mount(HilosAuthSurface, { props: { context } })

    await wrapper
      .find('[data-id="auth-identifier"]')
      .setValue('someone@example.com')
    await vi.advanceTimersByTimeAsync(DEFAULT_DETECT_DEBOUNCE_MS + 1)
    await flush(wrapper)

    await wrapper.find('[data-id="auth-icon-magic-link"]').trigger('click')
    await flush(wrapper)

    expect(wrapper.find('[data-id="auth-live-polite"]').text()).toBe(
      "We've sent a sign-in link to someone@example.com. Open it to continue.",
    )
    // News belongs to the region now; the plaque that shows it is a colored
    // line and nothing more.
    expect(
      wrapper.find('[data-id="auth-link-sent"]').attributes('role'),
    ).toBeUndefined()
  })

  it('says nothing extra on an empty field while either kind can be reached', async () => {
    const { context } = passwordOnlyContext()
    const wrapper = mount(HilosAuthSurface, { props: { context } })

    // Never told: what an installation that predates the key looks like.
    expect(wrapper.find('[data-id="auth-identifier-hint"]').text()).toBe(
      'Your email address or phone number.',
    )

    // Told that half of it works. The partial case is deliberately silent here -
    // the kind that works is still worth typing, and it is named after it is.
    context.scopes.session.data.set(CODE_DELIVERY_SLOT, {
      email: true,
      phone: false,
    })
    await flush(wrapper)

    expect(wrapper.find('[data-id="auth-identifier-hint"]').text()).toBe(
      'Your email address or phone number.',
    )
  })

  it('declines registration on an empty field when neither kind can be reached', async () => {
    const { context } = passwordOnlyContext()
    const wrapper = mount(HilosAuthSurface, { props: { context } })

    context.scopes.session.data.set(CODE_DELIVERY_SLOT, {
      email: false,
      phone: false,
    })
    await flush(wrapper)

    expect(wrapper.find('[data-id="auth-identifier-hint"]').text()).toBe(
      'Your email address or phone number. New accounts cannot be created here — there is nothing to send a code with.',
    )
    // Sign-in and the providers need no channel, so the field itself stays.
    expect(wrapper.find('[data-id="auth-identifier"]').exists()).toBe(true)
  })

  it('blames the missing channel rather than a decision nobody took', async () => {
    vi.useFakeTimers()
    const context = freeIdentifierContext('no_channel')
    const wrapper = mount(HilosAuthSurface, { props: { context } })

    await wrapper
      .find('[data-id="auth-identifier"]')
      .setValue('nobody@example.com')
    await vi.advanceTimersByTimeAsync(DEFAULT_DETECT_DEBOUNCE_MS + 1)
    await flush(wrapper)

    expect(wrapper.find('[data-id="auth-identifier-hint"]').text()).toBe(
      'No account for this, and there is nothing to send a code with.',
    )
  })

  it('keeps its own sentence for a registration somebody closed', async () => {
    vi.useFakeTimers()
    const context = freeIdentifierContext('closed')
    const wrapper = mount(HilosAuthSurface, { props: { context } })

    await wrapper
      .find('[data-id="auth-identifier"]')
      .setValue('nobody@example.com')
    await vi.advanceTimersByTimeAsync(DEFAULT_DETECT_DEBOUNCE_MS + 1)
    await flush(wrapper)

    expect(wrapper.find('[data-id="auth-identifier-hint"]').text()).toBe(
      'No account for this, and registration is closed.',
    )
  })

  it('leaves a free address one way on, and no password hint beside it', async () => {
    vi.useFakeTimers()
    const context = registrableIdentifierContext()
    const wrapper = mount(HilosAuthSurface, { props: { context } })

    await wrapper
      .find('[data-id="auth-identifier"]')
      .setValue('newcomer@example.com')
    await vi.advanceTimersByTimeAsync(DEFAULT_DETECT_DEBOUNCE_MS + 1)
    await flush(wrapper)

    // The main button is the whole road from here: the envelope beside it sent
    // mail to the same address and meant the same thing (HIL-1008).
    expect(wrapper.find('[data-id="auth-submit"]').exists()).toBe(true)
    expect(wrapper.find('[data-id="auth-icon-magic-link"]').exists()).toBe(
      false,
    )
    // And no rule about a password on a screen that asks for none: this
    // registration is asked for one after the code (HIL-825).
    expect(wrapper.find('[data-id="auth-password"]').exists()).toBe(false)
    expect(wrapper.text()).not.toContain('At least')
    expect(wrapper.find('[data-id="auth-identifier-hint"]').text()).toBe(
      'No account yet — this creates one.',
    )
  })

  it('holds one room under the field for the hint and the reveal alike', async () => {
    vi.useFakeTimers()
    const context = registrableIdentifierContext()
    const wrapper = mount(HilosAuthSurface, { props: { context } })

    // Before the first character there is no room to hold: the icon row above
    // still stands, and the hint under the field is the tallest thing here.
    expect(wrapper.find('[data-id="auth-reveal-slot"]').exists()).toBe(true)
    expect(wrapper.find('[data-id="auth-reveal-idle"]').exists()).toBe(false)

    await wrapper
      .find('[data-id="auth-identifier"]')
      .setValue('newcomer@example.com')
    await flush(wrapper)

    // Typed, and the lookup has not answered yet: the room is already taken, so
    // the reply that follows changes what is inside it and not its height.
    expect(wrapper.find('[data-id="auth-reveal-idle"]').exists()).toBe(true)
    expect(wrapper.find('[data-id="auth-identifier-hint"]').exists()).toBe(
      false,
    )

    await vi.advanceTimersByTimeAsync(DEFAULT_DETECT_DEBOUNCE_MS + 1)
    await flush(wrapper)

    expect(wrapper.find('[data-id="auth-reveal-idle"]').exists()).toBe(true)
    expect(wrapper.find('[data-id="auth-identifier-hint"]').exists()).toBe(true)
  })

  it('gives the reveal the same room the twin was holding', async () => {
    vi.useFakeTimers()
    const context = liveAccountContext([PASSWORD_METHOD_KEY], null)
    context.scopes.session.data.set(CODE_DELIVERY_SLOT, {
      email: true,
      phone: false,
    })
    const wrapper = mount(HilosAuthSurface, { props: { context } })

    await wrapper
      .find('[data-id="auth-identifier"]')
      .setValue('someone@example.com')
    await vi.advanceTimersByTimeAsync(DEFAULT_DETECT_DEBOUNCE_MS + 1)
    await flush(wrapper)

    // The reveal is what the twin was a copy of, so it stands in the same slot
    // and the twin steps aside for it.
    expect(wrapper.find('[data-id="auth-reveal-slot"]').exists()).toBe(true)
    expect(wrapper.find('[data-id="auth-password"]').exists()).toBe(true)
    expect(wrapper.find('[data-id="auth-reveal-idle"]').exists()).toBe(false)
    expect(wrapper.find('[data-id="auth-identifier-hint"]').exists()).toBe(
      false,
    )
  })

  it('offers the way past the password where a link can be mailed, and says so', async () => {
    const { context, dispatched } = magicLinkContext()
    context.scopes.session.data.set(
      PENDING_AUTH_STEP_SLOT,
      PROVED_REGISTRATION_STEP,
    )
    const wrapper = mount(HilosAuthSurface, { props: { context } })
    await flush(wrapper)

    expect(wrapper.find('[data-id="auth-new-password"]').exists()).toBe(true)
    // The sentence is the only place the second road is explained, so it changes
    // with the control rather than standing on its own.
    expect(wrapper.text()).toContain(
      'Choose a password, or create the account without one and sign in by a mailed link instead.',
    )

    const exit = wrapper.find('[data-id="auth-complete-passwordless"]')
    expect(exit.exists()).toBe(true)
    await exit.trigger('click')
    await flush(wrapper)

    expect(dispatched.map((call) => call.action)).toEqual([
      AUTH_ACTION_COMPLETE_REGISTRATION_PASSWORDLESS,
    ])
    // No address on the wire: the one this creates an account for is read off
    // the proved hold of this session on the server.
    expect(dispatched[0]?.payload).toEqual({})
  })

  it('keeps the way past the password off a registry that mounted no link', async () => {
    const { context } = passwordOnlyContext()
    context.scopes.session.data.set(
      PENDING_AUTH_STEP_SLOT,
      PROVED_REGISTRATION_STEP,
    )
    const wrapper = mount(HilosAuthSurface, { props: { context } })
    await flush(wrapper)

    expect(wrapper.find('[data-id="auth-new-password"]').exists()).toBe(true)
    expect(
      wrapper.find('[data-id="auth-complete-passwordless"]').exists(),
    ).toBe(false)
    expect(wrapper.text()).toContain(
      'Choose a password — your account is created when you save it.',
    )
  })

  it('keeps it off an installation that cannot mail the link it would rely on', async () => {
    const { context } = magicLinkContext()
    context.scopes.session.data.set(
      PENDING_AUTH_STEP_SLOT,
      PROVED_REGISTRATION_STEP,
    )
    context.scopes.session.data.set(CODE_DELIVERY_SLOT, {
      email: false,
      phone: true,
    })
    const wrapper = mount(HilosAuthSurface, { props: { context } })
    await flush(wrapper)

    // The method is mounted and the screen is the right one; what is missing is
    // the half the handshake answers, and without it the account would be made
    // with no way back into it.
    expect(
      wrapper.find('[data-id="auth-complete-passwordless"]').exists(),
    ).toBe(false)
    expect(wrapper.text()).toContain(
      'Choose a password — your account is created when you save it.',
    )
  })

  it('refuses an account left with no way in, in the refusal row and not the hint', async () => {
    vi.useFakeTimers()
    const context = liveAccountContext([], 'no_channel')
    const wrapper = mount(HilosAuthSurface, { props: { context } })

    await wrapper
      .find('[data-id="auth-identifier"]')
      .setValue('linkonly@example.com')
    await vi.advanceTimersByTimeAsync(DEFAULT_DETECT_DEBOUNCE_MS + 1)
    await flush(wrapper)

    expect(wrapper.find('[data-id="auth-error"]').text()).toBe(
      'This account signs in by a mailed link, and this installation has nothing to send it with. Whoever runs it can set that up.',
    )
    // One fact, one voice: the grey line does not repeat it.
    expect(wrapper.find('[data-id="auth-identifier-hint"]').exists()).toBe(
      false,
    )
    expect(wrapper.find('[data-id="auth-password"]').exists()).toBe(false)
    expect(wrapper.find('[data-id="auth-icon-magic-link"]').exists()).toBe(
      false,
    )
    // The field stays: another address is the one thing left to try.
    expect(wrapper.find('[data-id="auth-identifier"]').exists()).toBe(true)
  })

  it('keeps the password but drops the recovery key where nothing can mail', async () => {
    vi.useFakeTimers()
    const context = liveAccountContext([PASSWORD_METHOD_KEY], null)
    context.scopes.session.data.set(CODE_DELIVERY_SLOT, {
      email: false,
      phone: false,
    })
    const wrapper = mount(HilosAuthSurface, { props: { context } })

    await wrapper
      .find('[data-id="auth-identifier"]')
      .setValue('someone@example.com')
    await vi.advanceTimersByTimeAsync(DEFAULT_DETECT_DEBOUNCE_MS + 1)
    await flush(wrapper)

    expect(wrapper.find('[data-id="auth-password"]').exists()).toBe(true)
    expect(wrapper.find('[data-id="auth-recovery"]').exists()).toBe(false)
    expect(wrapper.find('[data-id="auth-icon-magic-link"]').exists()).toBe(
      false,
    )
    expect(wrapper.find('[data-id="auth-error"]').exists()).toBe(false)
  })

  it('offers the recovery key where mail can go out', async () => {
    vi.useFakeTimers()
    const context = liveAccountContext([PASSWORD_METHOD_KEY], null)
    context.scopes.session.data.set(CODE_DELIVERY_SLOT, {
      email: true,
      phone: false,
    })
    const wrapper = mount(HilosAuthSurface, { props: { context } })

    await wrapper
      .find('[data-id="auth-identifier"]')
      .setValue('someone@example.com')
    await vi.advanceTimersByTimeAsync(DEFAULT_DETECT_DEBOUNCE_MS + 1)
    await flush(wrapper)

    expect(wrapper.find('[data-id="auth-recovery"]').exists()).toBe(true)
  })

  it('ends the registration code screen in a red cancel and clears the address row', async () => {
    vi.useFakeTimers()
    const { context } = heldIdentifierContext()
    const wrapper = mount(HilosAuthSurface, { props: { context } })

    await wrapper
      .find('[data-id="auth-identifier"]')
      .setValue('reserved@example.com')
    await vi.advanceTimersByTimeAsync(DEFAULT_DETECT_DEBOUNCE_MS + 1)
    await flush(wrapper)
    expect(wrapper.find('[data-id="auth-code"]').exists()).toBe(true)

    // The way out says what it does, in the color that says it is not the safe
    // choice, and it is the LAST thing on the card (HIL-829).
    const cancel = wrapper.find('[data-id="auth-cancel-registration"]')
    expect(cancel.exists()).toBe(true)
    expect(cancel.text()).toBe('Cancel registration')
    expect(cancel.classes()).toContain('text-danger')
    // And the row naming the address is a statement again: no control in it.
    expect(wrapper.find('[data-id="auth-restart"]').exists()).toBe(false)

    // What the press DOES is asserted in the React peer of this file and in the
    // chat e2e, not here. Until HIL-994 a SECOND swap of the step branch threw
    // inside Vue's own patch under this test project (P-293); the swap works now,
    // and the press itself is nobody's port yet.
    expect(cancel.attributes('type')).toBe('button')
  })

  it('ends the same screen in a plain Back when a sign-in opened it', async () => {
    vi.useFakeTimers()
    const { context } = magicLinkContext()
    const wrapper = mount(HilosAuthSurface, { props: { context } })

    await wrapper
      .find('[data-id="auth-identifier"]')
      .setValue('someone@example.com')
    await vi.advanceTimersByTimeAsync(DEFAULT_DETECT_DEBOUNCE_MS + 1)
    await flush(wrapper)
    await wrapper.find('[data-id="auth-icon-magic-link"]').trigger('click')
    await flush(wrapper)

    // Nothing is being given up here, so nothing is said in red: the word is the
    // one the consent step already uses, and so is the mark it carries.
    const back = wrapper.find('[data-id="auth-restart"]')
    expect(back.exists()).toBe(true)
    expect(back.text()).toBe('Back')
    expect(back.classes()).not.toContain('text-danger')
    expect(wrapper.find('[data-id="auth-cancel-registration"]').exists()).toBe(
      false,
    )
  })

  it('the code screen turns itself into the expired one when the countdown runs out', async () => {
    vi.useFakeTimers()
    const { context } = expiringLetterContext(10_000)
    const wrapper = mount(HilosAuthSurface, { props: { context } })

    await wrapper
      .find('[data-id="auth-identifier"]')
      .setValue('someone@example.com')
    await vi.advanceTimersByTimeAsync(DEFAULT_DETECT_DEBOUNCE_MS + 1)
    await flush(wrapper)
    await wrapper.find('[data-id="auth-icon-magic-link"]').trigger('click')
    await flush(wrapper)
    expect(wrapper.find('[data-id="auth-code"]').exists()).toBe(true)
    expect(wrapper.find('[data-id="auth-expires-in"]').exists()).toBe(true)

    await vi.advanceTimersByTimeAsync(10_000)
    await flush(wrapper)

    // Nobody is made to type a code that is known to be dead in order to hear
    // that it is dead: the field and its Confirm go, and one button is left.
    expect(wrapper.find('[data-id="auth-code"]').exists()).toBe(false)
    expect(wrapper.find('[data-id="auth-submit"]').exists()).toBe(false)
    expect(wrapper.find('[data-id="auth-code-expired"]').text()).toContain(
      'That code has expired.',
    )
    expect(wrapper.find('[data-id="auth-code-renew"]').exists()).toBe(true)
    // The errand has not changed, so neither has the heading (HIL-606).
    expect(wrapper.text()).toContain('Check your inbox')
    // The screen's own news, said calmly - it is not a refusal of anything the
    // person did.
    expect(wrapper.find('[data-id="auth-live-polite"]').text()).toContain(
      'That code has expired.',
    )
    expect(wrapper.find('[data-id="auth-live-assertive"]').text()).toBe('')
  })

  it('the button on the expired screen orders the first send of the flow again', async () => {
    vi.useFakeTimers()
    const { context, dispatched } = expiringLetterContext(10_000)
    const wrapper = mount(HilosAuthSurface, { props: { context } })

    await wrapper
      .find('[data-id="auth-identifier"]')
      .setValue('someone@example.com')
    await vi.advanceTimersByTimeAsync(DEFAULT_DETECT_DEBOUNCE_MS + 1)
    await flush(wrapper)
    await wrapper.find('[data-id="auth-icon-magic-link"]').trigger('click')
    await flush(wrapper)
    await vi.advanceTimersByTimeAsync(10_000)
    await flush(wrapper)

    await wrapper.find('[data-id="auth-code-renew"]').trigger('click')
    await flush(wrapper)

    // The send that STARTED this flow, dispatched again - and the code screen
    // comes back with a field and a countdown of its own. That is the fourth
    // screen of one mount and the third swap of the step branch: the move this
    // test project could not make until it compiled templates the way the
    // shipped build does (HIL-994, vitest.config.ts).
    expect(dispatched.map((call) => call.action)).toEqual([
      AUTH_ACTION_DETECT_IDENTIFIER,
      AUTH_ACTION_REQUEST_MAGIC_LINK,
      AUTH_ACTION_REQUEST_MAGIC_LINK,
    ])
    expect(dispatched[2]?.payload).toEqual({ email: 'someone@example.com' })
    expect(wrapper.find('[data-id="auth-code"]').exists()).toBe(true)
    expect(wrapper.find('[data-id="auth-expires-in"]').exists()).toBe(true)
    expect(wrapper.find('[data-id="auth-code-expired"]').exists()).toBe(false)
  })

  it('answers a provider trip that failed with a refusal on the form', async () => {
    const world = oauthTripWorld()
    const wrapper = mount(HilosAuthSurface, {
      props: { context: world.context },
    })
    await wrapper.find('[data-id="auth-icon-oauth-github"]').trigger('click')
    await flush(wrapper)
    // Parked on the trip: the waiting screen, not the field.
    expect(wrapper.find('[data-id="auth-cancel"]').exists()).toBe(true)

    world.courier('')
    world.emit(OAUTH_RESULT_SIGNAL, {
      acceptKey: 'accept-1',
      provider: GITHUB_PROVIDER,
      reason: OAUTH_REASON_LOGIN_FAILED,
      email: null,
      linkToken: null,
    })
    await flush(wrapper)

    // The refusal of this form, on its refusal line — not news in the notice.
    expect(wrapper.find('[data-id="auth-error"]').text()).toBe(
      OAUTH_FAILED_MESSAGE,
    )
    expect(wrapper.find('[data-id="auth-notice"]').exists()).toBe(false)
    expect(wrapper.find('[data-id="auth-identifier"]').exists()).toBe(true)
    expect(wrapper.find('[data-id="auth-live-assertive"]').text()).toBe(
      OAUTH_FAILED_MESSAGE,
    )
  })

  it('returns quietly when the person ends the trip', async () => {
    const world = oauthTripWorld()
    const wrapper = mount(HilosAuthSurface, {
      props: { context: world.context },
    })
    await wrapper.find('[data-id="auth-icon-oauth-github"]').trigger('click')
    await flush(wrapper)
    expect(wrapper.find('[data-id="auth-cancel"]').exists()).toBe(true)

    // Declined at the provider: the courier brings an error and no code.
    world.courier('access_denied')
    await flush(wrapper)

    expect(wrapper.find('[data-id="auth-error"]').exists()).toBe(false)
    expect(wrapper.find('[data-id="auth-notice"]').exists()).toBe(false)
    expect(wrapper.find('[data-id="auth-identifier"]').exists()).toBe(true)
  })

  it('refuses a registry with no method at all, at wiring time', () => {
    expect(() =>
      createHilosAuthContext({
        connection: { on: vi.fn() } as unknown as HilosConnection,
        scopes: new ScopeManager(),
        actions: { dispatch: vi.fn() } as unknown as ActionLifecycle,
        methods: [],
        channels: [],
        oauthProviders: [],
        termsPath: '/terms',
        privacyPath: '/privacy',
      }),
    ).toThrow(/at least one method/)
  })
})
