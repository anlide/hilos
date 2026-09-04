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
  type ActionHandle,
  type HilosAuthContext,
  type HilosConnection,
} from '@hilos/core'
import { act, cleanup, fireEvent, render } from '@testing-library/react'
import { afterEach, describe, expect, it, vi } from 'vitest'

import { HilosAuthSurface } from '../src/auth/HilosAuthSurface.js'

/** The dispatch calls one mounted surface made, in order. */
type Dispatched = Array<{ action: string; payload: Record<string, unknown> }>

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
              methods,
              registerable: [],
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

function byId(id: string): HTMLElement | null {
  return document.querySelector(`[data-id="${id}"]`)
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

describe('HilosAuthSurface', () => {
  afterEach(() => {
    cleanup()
    vi.useRealTimers()
  })

  it('assembles from a one-password registry with no icon method offered', () => {
    const { context } = contextAnswering([PASSWORD_METHOD_KEY])
    render(<HilosAuthSurface context={context} />)

    expect(byId('auth-surface')).not.toBeNull()
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
