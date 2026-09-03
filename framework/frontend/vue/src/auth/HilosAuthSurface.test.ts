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
import {
  ActionLifecycle,
  AUTH_ACTION_CONFIRM_MAGIC_LINK_CODE,
  AUTH_ACTION_DETECT_IDENTIFIER,
  AUTH_ACTION_LOGIN,
  AUTH_ACTION_REQUEST_MAGIC_LINK,
  createHilosAuthContext,
  createSignal,
  DEFAULT_DETECT_DEBOUNCE_MS,
  MAGIC_LINK_FLOW_METHOD,
  MAGIC_LINK_METHOD_KEY,
  PASSWORD_FLOW_METHOD,
  PASSWORD_METHOD_KEY,
  ScopeManager,
  SESSION_ACK_REGISTERED,
  type ActionHandle,
  type AuthGate,
  type HilosAuthContext,
  type HilosConnection,
} from '@hilos/core'
import { mount } from '@vue/test-utils'
import { afterEach, describe, expect, it, vi } from 'vitest'

import HilosAuthSurface from './HilosAuthSurface.vue'
import { hilosAuthGateKey } from './hilosAuthGateKey.js'

// The session slot the surface reads its ack from — the default of
// `sessionPendingAck`, which is what the surface asks for (sessionScope.ts).
const PENDING_ACK_SLOT = 'pendingAck'

/** The dispatch calls one mounted surface made, in order. */
type Dispatched = Array<{ action: string; payload: Record<string, unknown> }>

/**
 * A context with one password method and nothing else: no channels, no providers.
 * The connection answers every subscription with an unsubscribe and never delivers
 * a signal; the action lifecycle records what was dispatched and resolves it.
 *
 * @returns The context to mount with, and the dispatch log to assert on.
 */
function passwordOnlyContext(): {
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

describe('HilosAuthSurface', () => {
  afterEach(() => {
    vi.useRealTimers()
  })

  it('assembles from a one-password registry with no icon method offered', () => {
    const { context } = passwordOnlyContext()
    const wrapper = mount(HilosAuthSurface, { props: { context } })

    expect(wrapper.find('[data-id="auth-surface"]').exists()).toBe(true)
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
