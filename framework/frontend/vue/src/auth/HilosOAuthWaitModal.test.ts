// Covers the shell-mounted OAuth waiting modal (HIL-633): who it shows for, what
// it says on each leg of a trip, and the one leg it can be taken back from. The
// trip is a real one started through @hilos/core with the browser window stubbed,
// because the modal reads the framework's module state and nothing else — which is
// exactly the property worth proving, the shell holding no auth context.
import { mount } from '@vue/test-utils'
import { afterEach, describe, expect, it, vi } from 'vitest'
import {
  cancelOAuthTrip,
  createHilosAuthContext,
  createOAuthLogin,
  PASSWORD_FLOW_METHOD,
  ScopeManager,
  createSignal,
  type ActionHandle,
  type ActionLifecycle,
  type HilosConnection,
  type HilosOAuthLogin,
  type ProjectSignal,
} from '@hilos/core'
import { nextTick } from 'vue'

import HilosOAuthWaitModal from './HilosOAuthWaitModal.vue'

/** The provider every trip here is for. */
const GITHUB = 'oauth:github'

/** A stand-in for the window a trip opens; nothing here navigates for real. */
function providerWindow(): Window {
  const win = {
    closed: false,
    close: (): void => {
      win.closed = true
    },
    location: { replace: (): void => undefined },
  }

  return win as unknown as Window
}

/** One test's wire: the bound client, and the way to answer its signals. */
interface TripWorld {
  oauth: HilosOAuthLogin
  window: Window
  emit(type: string, data: Record<string, unknown>): void
  unbind(): void
}

/**
 * Stand up a client whose starts are accepted and whose window is a double.
 *
 * @returns The world the tests start trips in.
 */
function tripWorld(): TripWorld {
  const listeners: Array<(signal: ProjectSignal) => void> = []
  const opened = providerWindow()

  const connection = {
    on(event: string, listener: (payload: never) => void): () => void {
      if (event === 'projectSignal') {
        listeners.push(listener as unknown as (signal: ProjectSignal) => void)
      }

      return () => undefined
    },
  } as unknown as HilosConnection

  const actions = {
    dispatch: () =>
      ({
        requestId: 'req-1',
        loading: createSignal(false),
        done: Promise.resolve({}),
      }) as unknown as ActionHandle,
  } as unknown as ActionLifecycle

  const context = createHilosAuthContext({
    connection,
    scopes: new ScopeManager(),
    actions,
    methods: [PASSWORD_FLOW_METHOD],
    channels: [],
    oauthProviders: [
      { key: GITHUB, label: 'Continue with GitHub', name: 'GitHub' },
    ],
    termsPath: '/terms',
    privacyPath: '/privacy',
  })

  vi.spyOn(window, 'open').mockImplementation(() => opened)

  const oauth = createOAuthLogin(context)
  const unbind = oauth.bindOAuthTrip()

  return {
    oauth,
    window: opened,
    unbind,
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
  }
}

/**
 * Drive a trip to its exchange leg, the way a returning courier window does.
 *
 * @param world The world the trip runs in.
 */
function courierReturns(world: TripWorld): void {
  const event = {
    data: {
      type: 'hilos.oauth.return',
      code: 'code-1',
      state: 'state-1',
      error: '',
    },
    origin: window.location.origin,
    source: world.window,
  } as unknown as MessageEvent

  window.dispatchEvent(
    Object.assign(new Event('message'), event) as unknown as Event,
  )
}

let active: TripWorld | null = null

afterEach(() => {
  cancelOAuthTrip()
  active?.unbind()
  active = null
  vi.restoreAllMocks()
})

describe('HilosOAuthWaitModal', () => {
  it('shows nothing when no trip is running', () => {
    const wrapper = mount(HilosOAuthWaitModal)

    expect(wrapper.find('[data-id="auth-oauth-wait"]').exists()).toBe(false)
  })

  it('stays out of the way of a sign-in, which has a screen of its own', async () => {
    const world = tripWorld()
    active = world
    const wrapper = mount(HilosOAuthWaitModal)

    await world.oauth.startOAuthLogin(GITHUB)
    await nextTick()

    expect(
      document.body.querySelector('[data-id="auth-oauth-wait"]'),
    ).toBeNull()
    wrapper.unmount()
  })

  it('names the provider and says where to look while a link waits', async () => {
    const world = tripWorld()
    active = world
    const wrapper = mount(HilosOAuthWaitModal)

    await world.oauth.startOAuthLink(GITHUB)
    await nextTick()

    const modal = document.body.querySelector('[data-id="modal"]')
    expect(modal?.textContent).toContain('Waiting for GitHub')
    expect(modal?.textContent).toContain(
      'Confirm in the window that just opened',
    )
    expect(
      document.body.querySelector('[data-id="auth-oauth-wait-cancel"]'),
    ).not.toBeNull()
    wrapper.unmount()
  })

  it('ends the trip and closes the provider window on Cancel', async () => {
    const world = tripWorld()
    active = world
    const wrapper = mount(HilosOAuthWaitModal)
    await world.oauth.startOAuthLink(GITHUB)
    await nextTick()

    const cancel = document.body.querySelector<HTMLButtonElement>(
      '[data-id="auth-oauth-wait-cancel"]',
    )
    cancel?.click()
    await nextTick()

    expect(world.window.closed).toBe(true)
    expect(world.oauth.trip.get()).toBeNull()
    expect(document.body.querySelector('[data-id="modal"]')).toBeNull()
    wrapper.unmount()
  })

  it('drops Cancel once the exchange is running', async () => {
    const world = tripWorld()
    active = world
    const wrapper = mount(HilosOAuthWaitModal)
    await world.oauth.startOAuthLink(GITHUB)
    courierReturns(world)
    await nextTick()

    expect(world.oauth.trip.get()?.phase).toBe('exchanging')
    const modal = document.body.querySelector('[data-id="modal"]')
    expect(modal?.textContent).toContain('Linking your account…')
    expect(
      document.body.querySelector('[data-id="auth-oauth-wait-cancel"]'),
    ).toBeNull()
    wrapper.unmount()
  })
})
