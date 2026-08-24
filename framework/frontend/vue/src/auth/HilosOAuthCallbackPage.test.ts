// Covers the callback relay's two jobs (HIL-633). The one that matters most is the
// negative: opened as a trip's window, this route dispatches NOTHING — it hands the
// return to the page that started the trip and closes. Dispatching from here is the
// old behavior the ticket removed, and it is invisible from the screen, so a test
// is the only place it can be held down.
import { mount } from '@vue/test-utils'
import { afterEach, describe, expect, it, vi } from 'vitest'
import {
  createHilosAuthContext,
  createSignal,
  PASSWORD_FLOW_METHOD,
  ScopeManager,
  type ActionHandle,
  type ActionLifecycle,
  type HilosAuthContext,
  type HilosConnection,
  type HilosRouter,
} from '@hilos/core'

import HilosOAuthCallbackPage from './HilosOAuthCallbackPage.vue'
import { hilosRouterKey } from '../hilosRouterKey.js'

/** One mount's world: what the relay dispatched, and where it navigated. */
interface RelayWorld {
  context: HilosAuthContext
  dispatched: string[]
  navigated: string[]
}

/**
 * Build the context and router doubles the relay mounts against.
 *
 * @returns The world, ready to mount into.
 */
function relayWorld(): RelayWorld {
  const dispatched: string[] = []

  const connection = {
    on: () => () => undefined,
  } as unknown as HilosConnection

  const actions = {
    dispatch: (action: string) => {
      dispatched.push(action)

      return {
        requestId: 'req-1',
        loading: createSignal(false),
        done: Promise.resolve({}),
      } as unknown as ActionHandle
    },
  } as unknown as ActionLifecycle

  return {
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
    dispatched,
    navigated: [],
  }
}

/**
 * Mount the relay with the router provided the way an app provides it.
 *
 * @param world The world the relay dispatches and navigates through.
 * @returns The mounted wrapper.
 */
function mountRelay(world: RelayWorld) {
  return mount(HilosOAuthCallbackPage, {
    props: { context: world.context },
    global: {
      provide: {
        [hilosRouterKey as symbol]: {
          navigate: (pathname: string) => world.navigated.push(pathname),
        } as unknown as HilosRouter,
      },
    },
  })
}

afterEach(() => {
  vi.restoreAllMocks()
  Reflect.deleteProperty(window, 'opener')
  window.history.replaceState({}, '', '/')
})

describe('HilosOAuthCallbackPage', () => {
  it('couriers the return to its opener and closes, dispatching nothing', async () => {
    window.history.replaceState({}, '', '/auth/callback?code=c-1&state=s-1')
    const posted: unknown[] = []
    Object.defineProperty(window, 'opener', {
      configurable: true,
      value: {
        postMessage: (message: unknown) => posted.push(message),
      },
    })
    const close = vi.spyOn(window, 'close').mockImplementation(() => undefined)
    const world = relayWorld()

    const wrapper = mountRelay(world)
    await Promise.resolve()

    expect(posted).toEqual([
      {
        type: 'hilos.oauth.return',
        code: 'c-1',
        state: 's-1',
        error: '',
      },
    ])
    expect(close).toHaveBeenCalled()
    expect(world.dispatched).toEqual([])
    expect(world.navigated).toEqual([])
    wrapper.unmount()
  })

  it('carries a provider refusal home rather than deciding it here', async () => {
    window.history.replaceState({}, '', '/auth/callback?error=access_denied')
    const posted: Array<{ error: string }> = []
    Object.defineProperty(window, 'opener', {
      configurable: true,
      value: {
        postMessage: (message: { error: string }) => posted.push(message),
      },
    })
    vi.spyOn(window, 'close').mockImplementation(() => undefined)
    const world = relayWorld()

    const wrapper = mountRelay(world)
    await Promise.resolve()

    expect(posted[0]?.error).toBe('access_denied')
    expect(world.dispatched).toEqual([])
    wrapper.unmount()
  })

  it('refuses a cold return that carries nothing to exchange', async () => {
    window.history.replaceState({}, '', '/auth/callback')
    const world = relayWorld()

    const wrapper = mountRelay(world)
    await Promise.resolve()

    expect(world.dispatched).toEqual([])
    expect(
      wrapper.find('[data-id="auth-oauth-callback-error"]').text(),
    ).toContain('invalid or incomplete')
    wrapper.unmount()
  })
})
