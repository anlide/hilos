// The poorest registry a deployment can declare: ONE method, the password, and no
// channels, providers, passkey or magic link (HIL-409). The chat demo enables
// everything, so nothing else covers the surface assembling for a project that
// wired only a password — and the first live one of those arrives in R3, when the
// React and Angular defaults are written (HIL-424/425).
//
// What is asserted is exactly that: the surface renders, it offers no way in that
// was not declared, and its submit path reaches the wire. The flow machine's own
// behavior is covered by core's authFlow spec and is not re-tested here.
import {
  ActionLifecycle,
  AUTH_ACTION_DETECT_IDENTIFIER,
  AUTH_ACTION_LOGIN,
  createHilosAuthContext,
  createSignal,
  DEFAULT_DETECT_DEBOUNCE_MS,
  PASSWORD_FLOW_METHOD,
  PASSWORD_METHOD_KEY,
  ScopeManager,
  type ActionHandle,
  type HilosAuthContext,
  type HilosConnection,
} from '@hilos/core'
import { mount } from '@vue/test-utils'
import { afterEach, describe, expect, it, vi } from 'vitest'

import HilosAuthSurface from './HilosAuthSurface.vue'

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
