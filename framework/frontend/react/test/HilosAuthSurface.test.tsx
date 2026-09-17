// The React peer of vue/src/auth/HilosAuthSurface.test.ts, mounting the same two
// registries: the poorest one a deployment can declare (ONE method, the
// password, and no channels, providers, passkey or magic link) and the
// magic-link one, for the screen that has no equivalent anywhere else — a
// waiting screen that also takes a code (HIL-606).
//
// What is asserted is exactly that: the surface renders, it offers no way in
// that was not declared, and its submit path reaches the wire. The flow
// machine's own behavior is covered by core's authFlow spec and is not
// re-tested here.
import {
  ActionError,
  ActionLifecycle,
  AUTH_ACTION_CANCEL_REGISTRATION,
  AUTH_ACTION_CONFIRM_MAGIC_LINK_CODE,
  AUTH_ACTION_DETECT_IDENTIFIER,
  AUTH_ACTION_LOGIN,
  AUTH_ACTION_REQUEST_MAGIC_LINK,
  AUTH_SURFACE_HEADING_ID,
  bindCodeSendProgress,
  CODE_SEND_STATE_NOT_SENT,
  createHilosAuthContext,
  createSignal,
  DEFAULT_DETECT_DEBOUNCE_MS,
  MAGIC_LINK_FLOW_METHOD,
  MAGIC_LINK_METHOD_KEY,
  PASSWORD_FLOW_METHOD,
  PASSWORD_METHOD_KEY,
  ScopeManager,
  SESSION_ACK_REGISTERED,
  SIGNAL_CODE_SEND_PROGRESS,
  SIGNAL_HANDSHAKE_RESPONSE,
  type ActionHandle,
  type AuthGate,
  type HilosAuthContext,
  type HilosConnection,
} from '@hilos/core'
import { act, cleanup, fireEvent, render } from '@testing-library/react'
import { afterEach, describe, expect, it, vi } from 'vitest'

import { HilosAuthSurface } from '../src/auth/HilosAuthSurface.js'
import { HilosAuthGateContext } from '../src/auth/hilosAuthGateContext.js'

/** The dispatch calls one mounted surface made, in order. */
type Dispatched = Array<{ action: string; payload: Record<string, unknown> }>

// The session slot the surface reads its ack from — the default of
// `sessionPendingAck`, which is what the surface asks for (sessionScope.ts).
const PENDING_ACK_SLOT = 'pendingAck'

// The session slot the surface reads the delivery answer from — the default of
// `sessionCodeDelivery` (sessionScope.ts).
const CODE_DELIVERY_SLOT = 'codeDelivery'

// The session slot the unfinished auth step arrives in — the default of
// `sessionPendingAuthStep` (sessionScope.ts), read the same way.
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
 * A context whose lookup answers with an account that exists and carries
 * `methods`; every other dispatch is recorded and resolved with nothing.
 *
 * @param methods The methods the answered account signs in with.
 * @param refuseLogin Answer the sign-in with a refusal instead of a success.
 * @returns The context to mount with, and the dispatch log to assert on.
 */
function contextAnswering(
  methods: readonly string[],
  refuseLogin = false,
): {
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
              methods,
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
      methods:
        methods.length > 1
          ? [PASSWORD_FLOW_METHOD, MAGIC_LINK_FLOW_METHOD]
          : [PASSWORD_FLOW_METHOD],
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
 * A context whose lookup answers a free registrable address the FIRST time and an
 * address this browser already PROVED afterwards. The pair is what draws the
 * resume control: a proof is reached by registering, and the control itself is
 * only ever drawn by a RETURN to the field, which asks the lookup again without
 * moving the step (HIL-825).
 *
 * @returns The context to mount with.
 */
function provenOnReturnContext(): HilosAuthContext {
  let answered = false
  const connection = {
    on: vi.fn().mockReturnValue(() => undefined),
  } as unknown as HilosConnection
  const actions = {
    dispatch: (action: string, payload: Record<string, unknown>) => {
      const identifier = String(payload['identifier'] ?? '')
      let reply
      if (action === AUTH_ACTION_DETECT_IDENTIFIER) {
        reply = {
          identifier,
          normalized: identifier,
          kind: 'email',
          status: answered ? 'proven' : 'none',
          methods: [],
          registerable: answered ? [] : [PASSWORD_METHOD_KEY],
          registrationBlock: null,
          signInBlock: null,
        }
        answered = true
      }

      return {
        requestId: 'req-proven',
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

function byId(id: string): HTMLElement | null {
  return document.querySelector(`[data-id="${id}"]`)
}

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

/** Let every pending microtask and the render that follows it settle. */
async function flush(): Promise<void> {
  await act(async () => {
    await Promise.resolve()
    await Promise.resolve()
  })
}

/**
 * Type into a machine-controlled field: the value is the machine's, so the
 * change event is what carries it there.
 *
 * @param id The field's `data-id`.
 * @param value What is typed into it.
 */
function type(id: string, value: string): void {
  fireEvent.change(byId(id) as Element, { target: { value } })
}

/**
 * Put a send-progress frame on the SDK's session-wide line, the way the socket
 * would (HIL-826). The line is a module singleton, so the frame outlives the
 * surface and the next test has to be handed a clean one - which is what the
 * null frame below does, and what the server itself sends to take a line away.
 *
 * @param state The `CODE_SEND_STATE_*` the line should report, or null for none.
 */
function reportSendProgress(state: string | null): void {
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
      data: { state, channel: 'email', detail: null },
    })
  }
  unbind()
}

describe('HilosAuthSurface', () => {
  afterEach(() => {
    cleanup()
    vi.useRealTimers()
    reportSendProgress(null)
  })

  it('assembles from a one-password registry with no icon method offered', () => {
    const { context } = contextAnswering([PASSWORD_METHOD_KEY])
    render(<HilosAuthSurface context={context} />)

    expect(byId('auth-surface')).not.toBeNull()
    // The frame showing this surface names itself with that heading, so the id
    // is part of the surface's contract, not decoration (HIL-832).
    expect(byId('auth-heading')?.getAttribute('id')).toBe(
      AUTH_SURFACE_HEADING_ID,
    )
    expect(byId('auth-identifier')).not.toBeNull()
    // Identifier-first: the whole sign-in surface is that one field until the
    // lookup answers (HIL-423), so neither the password nor a submit shows yet.
    expect(byId('auth-password')).toBeNull()
    expect(byId('auth-submit')).toBeNull()
    // Nothing that was not declared: the icon methods and the code channels are
    // rendered from the registry, so an empty one renders none of them.
    expect(byId('auth-icon-passkey')).toBeNull()
    expect(byId('auth-icon-magic-link')).toBeNull()
    expect(byId('auth-channel-sms')).toBeNull()
  })

  it('takes the finished panel away when a handshake says the session owes nothing', async () => {
    const { context, emitProjectSignal } = contextAnswering([
      PASSWORD_METHOD_KEY,
    ])
    const { gate, dismissed } = gateDouble()
    render(
      <HilosAuthGateContext.Provider value={gate}>
        <HilosAuthSurface context={context} />
      </HilosAuthGateContext.Provider>,
    )

    context.scopes.session.data.set(PENDING_ACK_SLOT, SESSION_ACK_REGISTERED)
    await flush()
    expect(byId('auth-continue')).not.toBeNull()

    // The clearing frame never arrived; a later handshake restates that the
    // session owes nothing. The panel comes down from that fact, not from the
    // session slot changing — that slot is still the standing mark.
    emitProjectSignal({
      type: SIGNAL_HANDSHAKE_RESPONSE,
      data: { data: { pendingAck: null } },
    })
    await flush()

    expect(byId('auth-continue')).toBeNull()
    expect(dismissed).toHaveBeenCalledTimes(1)
  })

  it('keeps the submit path alive: the lookup reveals the password and submit signs in', async () => {
    vi.useFakeTimers()
    const { context, dispatched } = contextAnswering([PASSWORD_METHOD_KEY])
    render(<HilosAuthSurface context={context} />)

    type('auth-identifier', 'someone@example.com')
    // The lookup is debounced by the machine; let it fire and its reply land.
    await act(async () => {
      await vi.advanceTimersByTimeAsync(DEFAULT_DETECT_DEBOUNCE_MS + 1)
    })
    await flush()

    expect(dispatched.map((call) => call.action)).toEqual([
      AUTH_ACTION_DETECT_IDENTIFIER,
    ])
    expect(byId('auth-password')).not.toBeNull()

    type('auth-password', 'correct horse')
    // The submit control appears with the revealed password, and it submits the
    // step's form (`type="submit"`), which is what carries the dispatch.
    expect(byId('auth-submit')).not.toBeNull()
    fireEvent.submit(document.querySelector('form') as Element)
    await flush()

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
    const { context, dispatched } = contextAnswering([
      PASSWORD_METHOD_KEY,
      MAGIC_LINK_METHOD_KEY,
    ])
    const { container } = render(<HilosAuthSurface context={context} />)

    type('auth-identifier', 'someone@example.com')
    await act(async () => {
      await vi.advanceTimersByTimeAsync(DEFAULT_DETECT_DEBOUNCE_MS + 1)
    })
    await flush()

    fireEvent.click(byId('auth-icon-magic-link') as Element)
    await flush()

    // Still the same screen the person asked for, now with a way to answer by
    // hand: the heading, the plaque about the link, the field, and the resend.
    expect(container.textContent).toContain('Check your inbox')
    expect(byId('auth-link-sent')).not.toBeNull()
    expect(byId('auth-code')).not.toBeNull()
    expect(byId('auth-resend')).not.toBeNull()

    type('auth-code', '135790')
    fireEvent.submit(document.querySelector('form') as Element)
    await flush()

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
    const { context } = contextAnswering([
      PASSWORD_METHOD_KEY,
      MAGIC_LINK_METHOD_KEY,
    ])
    render(<HilosAuthSurface context={context} />)

    type('auth-identifier', 'someone@example.com')
    await act(async () => {
      await vi.advanceTimersByTimeAsync(DEFAULT_DETECT_DEBOUNCE_MS + 1)
    })
    await flush()
    fireEvent.click(byId('auth-icon-magic-link') as Element)
    await flush()

    await act(async () => {
      reportSendProgress(CODE_SEND_STATE_NOT_SENT)
    })
    await flush()

    // The whole visible output of this leaf: the line says the letter went
    // nowhere, and it says it calmly - not green, because nothing was sent, and
    // not red, because nothing went wrong (HIL-827). No path is named.
    const line = byId('auth-send-progress')
    expect(line?.textContent).toBe(
      'Not really sent to someone@example.com — letters are written here, not mailed',
    )
    expect(line?.className).toContain('text-body-secondary')
    expect(line?.querySelector('i')?.className).toContain('bi-flask')
  })

  it('tells a tab that came back by reload the address was taken while away', async () => {
    const { context } = contextAnswering([PASSWORD_METHOD_KEY])
    // The handshake was answered before this surface existed — the whole of the
    // reload case — so the node is already on the session at mount.
    context.scopes.session.data.set(PENDING_AUTH_STEP_SLOT, LOST_RACE_STEP)
    render(<HilosAuthSurface context={context} />)
    await flush()

    // The step alone would leave the address field standing there filled in and
    // unexplained; what the leaf adds is the sentence beside it.
    expect((byId('auth-identifier') as HTMLInputElement).value).toBe(
      'someone@example.com',
    )
    expect(byId('auth-notice')?.textContent).toBe(
      'That address already has an account — sign in instead.',
    )
  })

  it('tells a tab whose socket only blinked, with no reload under it', async () => {
    const { context } = contextAnswering([PASSWORD_METHOD_KEY])
    render(<HilosAuthSurface context={context} />)
    await flush()
    expect(byId('auth-notice')).toBeNull()

    // The socket came back and the handshake put the step on the session. Mount
    // is long over, so nothing but the effect watching it can carry this.
    await act(async () => {
      context.scopes.session.data.set(PENDING_AUTH_STEP_SLOT, LOST_RACE_STEP)
    })
    await flush()

    expect(byId('auth-notice')?.textContent).toBe(
      'That address already has an account — sign in instead.',
    )
    expect(byId('auth-identifier')).not.toBeNull()
  })

  it('leaves a screen alone when the step that arrives names no reason', async () => {
    const { context } = contextAnswering([PASSWORD_METHOD_KEY])
    render(<HilosAuthSurface context={context} />)
    await flush()

    // An ordinary unfinished step, arriving on a reconnect while somebody is on
    // the identifier field. It is restored on MOUNT and nowhere else: rebuilding
    // the screen under their hands is exactly what the narrowing is for.
    await act(async () => {
      context.scopes.session.data.set(PENDING_AUTH_STEP_SLOT, RESUMED_CODE_STEP)
    })
    await flush()

    expect(byId('auth-code')).toBeNull()
    expect(byId('auth-identifier')).not.toBeNull()
    expect(byId('auth-notice')).toBeNull()
  })

  it('stands both live regions up empty before anything has been said', () => {
    const { context } = contextAnswering([PASSWORD_METHOD_KEY])
    render(<HilosAuthSurface context={context} />)

    // The point of the leaf: the region is there BEFORE its text, so the reader
    // announces the change of content rather than the arrival of a node.
    expect(byId('auth-live-assertive')?.textContent).toBe('')
    expect(byId('auth-live-polite')?.textContent).toBe('')
  })

  it('speaks a refusal from the urgent region while the visible block stays mute', async () => {
    vi.useFakeTimers()
    const { context } = contextAnswering([PASSWORD_METHOD_KEY], true)
    render(<HilosAuthSurface context={context} />)

    type('auth-identifier', 'someone@example.com')
    await act(async () => {
      await vi.advanceTimersByTimeAsync(DEFAULT_DETECT_DEBOUNCE_MS + 1)
    })
    await flush()

    type('auth-password', 'wrong horse')
    fireEvent.submit(document.querySelector('form') as Element)
    await flush()

    expect(byId('auth-live-assertive')?.textContent).toBe('Incorrect password')
    // The same sentence is on the screen for the eye — and carries no role of
    // its own, or the reader would say it twice.
    const visible = byId('auth-error')
    expect(visible?.textContent).toBe('Incorrect password')
    expect(visible?.getAttribute('role')).toBeNull()
    expect(visible?.getAttribute('aria-live')).toBeNull()
  })

  it('puts the letter, address and all, into the calm region', async () => {
    vi.useFakeTimers()
    const { context } = contextAnswering([
      PASSWORD_METHOD_KEY,
      MAGIC_LINK_METHOD_KEY,
    ])
    render(<HilosAuthSurface context={context} />)

    type('auth-identifier', 'someone@example.com')
    await act(async () => {
      await vi.advanceTimersByTimeAsync(DEFAULT_DETECT_DEBOUNCE_MS + 1)
    })
    await flush()

    fireEvent.click(byId('auth-icon-magic-link') as Element)
    await flush()

    expect(byId('auth-live-polite')?.textContent).toBe(
      "We've sent a sign-in link to someone@example.com. Open it to continue.",
    )
    // News belongs to the region now; the plaque that shows it is a colored
    // line and nothing more.
    expect(byId('auth-link-sent')?.getAttribute('role')).toBeNull()
  })

  it('declines registration on an empty field when neither kind can be reached', async () => {
    const { context } = contextAnswering([PASSWORD_METHOD_KEY])
    render(<HilosAuthSurface context={context} />)

    // Never told: what an installation that predates the key looks like. The
    // partial case reads the same on purpose - the kind that works is still
    // worth typing, and it is named after the kind is known.
    expect(byId('auth-identifier-hint')?.textContent).toBe(
      'Your email address or phone number.',
    )

    await act(async () => {
      context.scopes.session.data.set(CODE_DELIVERY_SLOT, {
        email: true,
        phone: false,
      })
    })
    await flush()

    expect(byId('auth-identifier-hint')?.textContent).toBe(
      'Your email address or phone number.',
    )

    await act(async () => {
      context.scopes.session.data.set(CODE_DELIVERY_SLOT, {
        email: false,
        phone: false,
      })
    })
    await flush()

    expect(byId('auth-identifier-hint')?.textContent).toBe(
      'Your email address or phone number. New accounts cannot be created here — there is nothing to send a code with.',
    )
    // Sign-in and the providers need no channel, so the field itself stays.
    expect(byId('auth-identifier')).not.toBeNull()
  })

  it('blames the missing channel rather than a decision nobody took', async () => {
    vi.useFakeTimers()
    render(<HilosAuthSurface context={freeIdentifierContext('no_channel')} />)

    type('auth-identifier', 'nobody@example.com')
    await act(async () => {
      await vi.advanceTimersByTimeAsync(DEFAULT_DETECT_DEBOUNCE_MS + 1)
    })
    await flush()

    expect(byId('auth-identifier-hint')?.textContent).toBe(
      'No account for this, and there is nothing to send a code with.',
    )
  })

  it('keeps its own sentence for a registration somebody closed', async () => {
    vi.useFakeTimers()
    render(<HilosAuthSurface context={freeIdentifierContext('closed')} />)

    type('auth-identifier', 'nobody@example.com')
    await act(async () => {
      await vi.advanceTimersByTimeAsync(DEFAULT_DETECT_DEBOUNCE_MS + 1)
    })
    await flush()

    expect(byId('auth-identifier-hint')?.textContent).toBe(
      'No account for this, and registration is closed.',
    )
  })

  it('refuses an account left with no way in, in the refusal row and not the hint', async () => {
    vi.useFakeTimers()
    render(<HilosAuthSurface context={liveAccountContext([], 'no_channel')} />)

    type('auth-identifier', 'linkonly@example.com')
    await act(async () => {
      await vi.advanceTimersByTimeAsync(DEFAULT_DETECT_DEBOUNCE_MS + 1)
    })
    await flush()

    expect(byId('auth-error')?.textContent).toBe(
      'This account signs in by a mailed link, and this installation has nothing to send it with. Whoever runs it can set that up.',
    )
    // One fact, one voice: the grey line does not repeat it.
    expect(byId('auth-identifier-hint')).toBeNull()
    expect(byId('auth-password')).toBeNull()
    expect(byId('auth-icon-magic-link')).toBeNull()
    // The field stays: another address is the one thing left to try.
    expect(byId('auth-identifier')).not.toBeNull()
  })

  it('keeps the password but drops the recovery key where nothing can mail', async () => {
    vi.useFakeTimers()
    const context = liveAccountContext([PASSWORD_METHOD_KEY], null)
    context.scopes.session.data.set(CODE_DELIVERY_SLOT, {
      email: false,
      phone: false,
    })
    render(<HilosAuthSurface context={context} />)

    type('auth-identifier', 'someone@example.com')
    await act(async () => {
      await vi.advanceTimersByTimeAsync(DEFAULT_DETECT_DEBOUNCE_MS + 1)
    })
    await flush()

    expect(byId('auth-password')).not.toBeNull()
    expect(byId('auth-recovery')).toBeNull()
    expect(byId('auth-icon-magic-link')).toBeNull()
    expect(byId('auth-error')).toBeNull()
  })

  it('offers the recovery key where mail can go out', async () => {
    vi.useFakeTimers()
    const context = liveAccountContext([PASSWORD_METHOD_KEY], null)
    context.scopes.session.data.set(CODE_DELIVERY_SLOT, {
      email: true,
      phone: false,
    })
    render(<HilosAuthSurface context={context} />)

    type('auth-identifier', 'someone@example.com')
    await act(async () => {
      await vi.advanceTimersByTimeAsync(DEFAULT_DETECT_DEBOUNCE_MS + 1)
    })
    await flush()

    expect(byId('auth-recovery')).not.toBeNull()
  })

  it('the code screen turns itself into the expired one when the countdown runs out', async () => {
    vi.useFakeTimers()
    const { context } = expiringLetterContext(10_000)
    const { container } = render(<HilosAuthSurface context={context} />)

    type('auth-identifier', 'someone@example.com')
    await act(async () => {
      await vi.advanceTimersByTimeAsync(DEFAULT_DETECT_DEBOUNCE_MS + 1)
    })
    await flush()
    fireEvent.click(byId('auth-icon-magic-link') as Element)
    await flush()
    expect(byId('auth-code')).not.toBeNull()

    await act(async () => {
      await vi.advanceTimersByTimeAsync(10_000)
    })
    await flush()

    // Nobody is made to type a code that is known to be dead in order to hear
    // that it is dead: the field and its Confirm go, and one button is left.
    expect(byId('auth-code')).toBeNull()
    expect(byId('auth-submit')).toBeNull()
    expect(byId('auth-code-expired')?.textContent).toContain(
      'That code has expired.',
    )
    expect(byId('auth-code-renew')).not.toBeNull()
    // The errand has not changed, so neither has the heading (HIL-606).
    expect(container.textContent).toContain('Check your inbox')
    // The screen's own news, said calmly - it is not a refusal of anything the
    // person did.
    expect(byId('auth-live-polite')?.textContent).toContain(
      'That code has expired.',
    )
    expect(byId('auth-live-assertive')?.textContent).toBe('')
  })

  it('the button on the expired screen orders the first send of the flow again', async () => {
    vi.useFakeTimers()
    const { context, dispatched } = expiringLetterContext(10_000)
    render(<HilosAuthSurface context={context} />)

    type('auth-identifier', 'someone@example.com')
    await act(async () => {
      await vi.advanceTimersByTimeAsync(DEFAULT_DETECT_DEBOUNCE_MS + 1)
    })
    await flush()
    fireEvent.click(byId('auth-icon-magic-link') as Element)
    await flush()
    await act(async () => {
      await vi.advanceTimersByTimeAsync(10_000)
    })
    await flush()

    fireEvent.click(byId('auth-code-renew') as Element)
    await flush()

    // The send that STARTED this flow, dispatched again - and the code screen
    // comes back with a field and a countdown of its own.
    expect(dispatched.map((call) => call.action)).toEqual([
      AUTH_ACTION_DETECT_IDENTIFIER,
      AUTH_ACTION_REQUEST_MAGIC_LINK,
      AUTH_ACTION_REQUEST_MAGIC_LINK,
    ])
    expect(dispatched[2]?.payload).toEqual({ email: 'someone@example.com' })
    expect(byId('auth-code')).not.toBeNull()
    expect(byId('auth-code-expired')).toBeNull()
  })

  it('takes the resume control out of reach while its reply is re-asked (HIL-646)', async () => {
    vi.useFakeTimers()
    render(<HilosAuthSurface context={provenOnReturnContext()} />)

    // A free registrable address goes to the terms screen, and the way back from
    // it is the plain return to the field — which asks the lookup again, this
    // time hearing a proof, and stays where it is (HIL-825).
    type('auth-identifier', 'newcomer@example.com')
    await act(async () => {
      await vi.advanceTimersByTimeAsync(DEFAULT_DETECT_DEBOUNCE_MS + 1)
    })
    await flush()
    fireEvent.submit(document.querySelector('form') as Element)
    await flush()
    fireEvent.click(byId('auth-restart') as Element)
    await flush()

    const resume = byId('auth-resume-password') as HTMLButtonElement | null
    expect(resume).not.toBeNull()
    expect(resume?.disabled).toBe(false)

    // Editing the address holds the reply the control is drawn from (HIL-646),
    // so the control itself must not act on a verdict already being re-asked
    // about — it stays on screen and goes out of reach until the reply lands.
    type('auth-identifier', 'newcomer@example.co')
    await flush()

    expect((byId('auth-resume-password') as HTMLButtonElement).disabled).toBe(
      true,
    )

    // And the composition changes ONCE, when the reply lands: a typed proof
    // carries the person on to the password rather than leaving the control
    // behind (HIL-825), so the held one is never acted on at all.
    await act(async () => {
      await vi.advanceTimersByTimeAsync(DEFAULT_DETECT_DEBOUNCE_MS + 1)
    })
    await flush()

    expect(byId('auth-resume-password')).toBeNull()
    expect(byId('auth-new-password')).not.toBeNull()
  })

  it('ends the registration code screen in a red cancel and clears the address row', async () => {
    vi.useFakeTimers()
    const { context, dispatched } = heldIdentifierContext()
    render(<HilosAuthSurface context={context} />)

    type('auth-identifier', 'reserved@example.com')
    await act(async () => {
      await vi.advanceTimersByTimeAsync(DEFAULT_DETECT_DEBOUNCE_MS + 1)
    })
    await flush()
    expect(byId('auth-code')).not.toBeNull()

    // The way out says what it does, in the color that says it is not the safe
    // choice, and it is the LAST thing on the card (HIL-829).
    const cancel = byId('auth-cancel-registration')
    expect(cancel).not.toBeNull()
    expect(cancel?.textContent).toBe('Cancel registration')
    expect(cancel?.classList.contains('text-danger')).toBe(true)
    // And the row naming the address is a statement again: no control in it.
    expect(byId('auth-restart')).toBeNull()

    fireEvent.click(cancel as Element)
    await flush()

    expect(dispatched.map((call) => call.action)).toContain(
      AUTH_ACTION_CANCEL_REGISTRATION,
    )
    expect(byId('auth-identifier')).not.toBeNull()
  })

  it('ends the same screen in a plain Back when a sign-in opened it', async () => {
    vi.useFakeTimers()
    const { context } = contextAnswering([
      PASSWORD_METHOD_KEY,
      MAGIC_LINK_METHOD_KEY,
    ])
    render(<HilosAuthSurface context={context} />)

    type('auth-identifier', 'someone@example.com')
    await act(async () => {
      await vi.advanceTimersByTimeAsync(DEFAULT_DETECT_DEBOUNCE_MS + 1)
    })
    await flush()
    fireEvent.click(byId('auth-icon-magic-link') as Element)
    await flush()

    // Nothing is being given up here, so nothing is said in red: the word is the
    // one the consent step already uses, and so is the mark it carries.
    const back = byId('auth-restart')
    expect(back).not.toBeNull()
    expect(back?.textContent).toBe('Back')
    expect(back?.classList.contains('text-danger')).toBe(false)
    expect(byId('auth-cancel-registration')).toBeNull()
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
