// Covers the "it was not me" relay of a second-factor removal (HIL-494): the
// token relayed once a page has answered, the outcome said in place, and a link
// with nothing in it refused without asking anybody.
import {
  bindPageReady,
  createHilosAuthContext,
  createSignal,
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
import { beforeEach, describe, expect, it } from 'vitest'

import { hilosRouterKey } from '../hilosRouterKey.js'
import HilosSecondFactorCancelPage from './HilosSecondFactorCancelPage.vue'

/** The dispatch calls one mounted relay made, in order. */
type Dispatched = Array<{ action: string; payload: Record<string, unknown> }>

/**
 * A relay world whose cancel succeeds or names the dead link, with a page
 * answered already so the relay can dispatch at once.
 *
 * @param cancelOk Whether the server cancels the removal.
 */
function relayWorld(cancelOk: boolean): {
  context: HilosAuthContext
  dispatched: Dispatched
  navigated: string[]
  router: HilosRouter
} {
  const dispatched: Dispatched = []
  const navigated: string[] = []
  const connection = {
    on(event: string, listener: (payload: never) => void): () => void {
      if (event === 'projectSignal') {
        ;(listener as (signal: ProjectSignal) => void)({
          kind: 'project',
          type: SIGNAL_TYPE_PAGE_RESPONSE,
          data: { page: 'main', payload: {} },
          envelope: {},
        } as unknown as ProjectSignal)
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
          reply: cancelOk
            ? { ok: true, next: { step: 'done', intent: 'login' } }
            : { ok: false, message: 'This link no longer works' },
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
    context: createHilosAuthContext({
      connection,
      scopes: new ScopeManager(),
      actions,
      channels: [],
      termsPath: '/terms',
      privacyPath: '/privacy',
    }),
  }
}

/**
 * Mount the relay with the router provided the way an app provides it.
 *
 * @param world The relay world the screen dispatches and navigates through.
 */
function mountRelay(world: ReturnType<typeof relayWorld>) {
  return mount(HilosSecondFactorCancelPage, {
    props: { context: world.context },
    global: { provide: { [hilosRouterKey as symbol]: world.router } },
  })
}

/**
 * Let the gate, the dispatch and the reply's parse settle, and the render after.
 *
 * @param wrapper The mounted relay.
 */
async function flush(wrapper: {
  vm: { $nextTick: () => Promise<void> }
}): Promise<void> {
  for (let tick = 0; tick < 10; tick += 1) {
    await Promise.resolve()
  }
  await wrapper.vm.$nextTick()
}

describe('HilosSecondFactorCancelPage', () => {
  beforeEach(() => {
    window.history.replaceState({}, '', '/auth/second-factor/cancel?token=t-1')
  })

  it('relays the token and says the removal is canceled, then goes home', async () => {
    const world = relayWorld(true)
    const wrapper = mountRelay(world)
    await flush(wrapper)

    expect(world.dispatched).toEqual([
      {
        action: 'hilos_second_factor_reset_cancel_link',
        payload: { token: 't-1' },
      },
    ])
    expect(
      wrapper.find('[data-id="auth-second-factor-cancel-done"]').text(),
    ).toBe('The request to remove two-step verification was canceled.')

    await wrapper
      .find('[data-id="auth-second-factor-cancel-continue"]')
      .trigger('click')
    expect(world.navigated).toEqual(['/'])
  })

  it("says the server's sentence when the link names no removal", async () => {
    const world = relayWorld(false)
    const wrapper = mountRelay(world)
    await flush(wrapper)

    expect(
      wrapper.find('[data-id="auth-second-factor-cancel-error"]').text(),
    ).toBe('This link no longer works')
    expect(
      wrapper.find('[data-id="auth-second-factor-cancel-retry"]').exists(),
    ).toBe(true)
  })

  it('refuses a link with no token without asking anybody', async () => {
    window.history.replaceState({}, '', '/auth/second-factor/cancel')
    const world = relayWorld(true)
    const wrapper = mountRelay(world)
    await flush(wrapper)

    expect(world.dispatched).toEqual([])
    expect(
      wrapper.find('[data-id="auth-second-factor-cancel-error"]').text(),
    ).toBe('This link no longer works.')
    expect(
      wrapper.find('[data-id="auth-second-factor-cancel-retry"]').exists(),
    ).toBe(false)
  })
})
