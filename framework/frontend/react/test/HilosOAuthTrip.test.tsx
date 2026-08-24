// The React peer of vue/src/auth/HilosOAuthWaitModal.test.ts and
// HilosOAuthCallbackPage.test.ts (HIL-633): the shell-mounted waiting modal, and
// the callback route's two jobs.
//
// The one worth the most is the negative: opened as a trip's window, the callback
// route dispatches NOTHING — it hands the return to the page that started the trip
// and closes. Dispatching from there is the old behavior the ticket removed, and it
// is invisible from the screen, so a test is the only place it can be held down.
import {
  cancelOAuthTrip,
  createHilosAuthContext,
  createOAuthLogin,
  createSignal,
  PASSWORD_FLOW_METHOD,
  ScopeManager,
  type ActionHandle,
  type ActionLifecycle,
  type HilosAuthContext,
  type HilosConnection,
  type HilosOAuthLogin,
  type HilosRouter,
} from '@hilos/core'
import { act, cleanup, fireEvent, render } from '@testing-library/react'
import { afterEach, describe, expect, it, vi } from 'vitest'

import { HilosOAuthCallbackPage } from '../src/auth/HilosOAuthCallbackPage.js'
import { HilosOAuthWaitModal } from '../src/auth/HilosOAuthWaitModal.js'
import { HilosRouterContext } from '../src/hilosRouterContext.js'

/** The provider every trip here is for. */
const GITHUB = 'oauth:github'

/**
 * Find a rendered element by its `data-id`. The dialog teleports to the document
 * body, so queries are scoped to the document rather than to a render container.
 *
 * @param id The `data-id` to look for.
 * @returns The element, or null when it is not on screen.
 */
function byId(id: string): HTMLElement | null {
  return document.querySelector<HTMLElement>(`[data-id="${id}"]`)
}

/** One test's world: the bound client, its window double, and what it dispatched. */
interface TripWorld {
  oauth: HilosOAuthLogin
  context: HilosAuthContext
  window: { closed: boolean }
  dispatched: string[]
  navigated: string[]
  router: HilosRouter
  unbind(): void
}

/**
 * Stand up a client whose starts are accepted and whose provider window is a
 * double that records being closed rather than navigating anywhere.
 *
 * @returns The world the tests run trips in.
 */
function tripWorld(): TripWorld {
  const dispatched: string[] = []
  const navigated: string[] = []
  const opened = {
    closed: false,
    close: (): void => {
      opened.closed = true
    },
    location: { replace: (): void => undefined },
  }

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

  vi.spyOn(window, 'open').mockImplementation(() => opened as unknown as Window)

  const oauth = createOAuthLogin(context)

  return {
    oauth,
    context,
    window: opened,
    dispatched,
    navigated,
    router: {
      navigate: (pathname: string) => navigated.push(pathname),
    } as unknown as HilosRouter,
    unbind: oauth.bindOAuthTrip(),
  }
}

/**
 * Render the callback route with the router provided the way an app provides it.
 *
 * @param world The world the route dispatches and navigates through.
 * @returns The render result.
 */
function renderCallback(world: TripWorld) {
  return render(
    <HilosRouterContext.Provider value={world.router}>
      <HilosOAuthCallbackPage context={world.context} />
    </HilosRouterContext.Provider>,
  )
}

let active: TripWorld | null = null

afterEach(() => {
  cleanup()
  cancelOAuthTrip()
  active?.unbind()
  active = null
  vi.restoreAllMocks()
  Reflect.deleteProperty(window, 'opener')
  window.history.replaceState({}, '', '/')
})

describe('HilosOAuthWaitModal', () => {
  it('shows nothing when no trip is running', () => {
    render(<HilosOAuthWaitModal />)

    expect(byId('auth-oauth-wait')).toBeNull()
  })

  it('stays out of the way of a sign-in, which has a screen of its own', async () => {
    const world = tripWorld()
    active = world
    render(<HilosOAuthWaitModal />)

    await act(async () => {
      await world.oauth.startOAuthLogin(GITHUB)
    })

    expect(byId('auth-oauth-wait')).toBeNull()
  })

  it('names the provider and ends the trip on Cancel while a link waits', async () => {
    const world = tripWorld()
    active = world
    render(<HilosOAuthWaitModal />)

    await act(async () => {
      await world.oauth.startOAuthLink(GITHUB)
    })

    expect(byId('auth-oauth-wait')?.textContent).toContain(
      'Confirm in the window that just opened',
    )
    expect(byId('modal')?.textContent).toContain('Waiting for GitHub')

    await act(async () => {
      fireEvent.click(byId('auth-oauth-wait-cancel') as HTMLElement)
    })

    expect(world.window.closed).toBe(true)
    expect(world.oauth.trip.get()).toBeNull()
  })
})

describe('HilosOAuthCallbackPage', () => {
  it('couriers the return to its opener and closes, dispatching nothing', async () => {
    window.history.replaceState({}, '', '/auth/callback?code=c-1&state=s-1')
    const posted: unknown[] = []
    Object.defineProperty(window, 'opener', {
      configurable: true,
      value: { postMessage: (message: unknown) => posted.push(message) },
    })
    const close = vi.spyOn(window, 'close').mockImplementation(() => undefined)
    const world = tripWorld()
    active = world

    await act(async () => {
      renderCallback(world)
    })

    expect(posted).toEqual([
      { type: 'hilos.oauth.return', code: 'c-1', state: 's-1', error: '' },
    ])
    expect(close).toHaveBeenCalled()
    expect(world.dispatched).toEqual([])
    expect(world.navigated).toEqual([])
  })

  it('refuses a cold return that carries nothing to exchange', async () => {
    window.history.replaceState({}, '', '/auth/callback')
    const world = tripWorld()
    active = world

    await act(async () => {
      renderCallback(world)
    })

    expect(world.dispatched).toEqual([])
    expect(byId('auth-oauth-callback-error')?.textContent).toContain(
      'invalid or incomplete',
    )
  })
})
