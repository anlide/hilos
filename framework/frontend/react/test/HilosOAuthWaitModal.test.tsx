// The React peer of vue/src/auth/HilosOAuthWaitModal.test.ts (HIL-633, HIL-1543):
// the shell-mounted OAuth waiting modal on each leg of a link, and the one leg it
// can be taken back from. The trip is a real one started through @hilos/core with
// the provider window and the connection stubbed, because the modal reads the
// framework's module state and nothing else.
//
// The leg change is driven under one mounted modal: the exchange starts while the
// window is already open, so what is proven is the open window going busy, not a
// fresh one drawn busy.
import {
  cancelOAuthTrip,
  createHilosAuthContext,
  createOAuthLogin,
  createSignal,
  OAUTH_REASON_LINK_OK,
  OAUTH_RESULT_SIGNAL,
  ScopeManager,
  type ActionHandle,
  type ActionLifecycle,
  type AuthMethodEntry,
  type HilosConnection,
  type HilosOAuthLogin,
  type ProjectSignal,
} from '@hilos/core'
import { act, cleanup, fireEvent, render } from '@testing-library/react'
import { afterEach, describe, expect, it, vi } from 'vitest'

import { HilosOAuthWaitModal } from '../src/auth/HilosOAuthWaitModal.js'

/**
 * A scope manager holding the enabled sign-in methods, the way a handshake puts
 * them in the session scope (HIL-427): the surface reads its set there.
 *
 * @param entries The enabled methods, in button order.
 */
function scopesWith(entries: readonly AuthMethodEntry[]): ScopeManager {
  const scopes = new ScopeManager()
  scopes.session.data.set('authMethods', entries)

  return scopes
}

/** The provider every trip here is for. */
const GITHUB = 'oauth:github'

/**
 * Find a rendered element by its `data-id`. The dialog portals to the document
 * body, so queries are scoped to the document rather than to a render container.
 *
 * @param id The `data-id` to look for.
 * @returns The element, or null when it is not on screen.
 */
function byId(id: string): HTMLElement | null {
  return document.querySelector<HTMLElement>(`[data-id="${id}"]`)
}

/** The header close button of the open dialog. */
function closeButton(): HTMLButtonElement | null {
  return document.querySelector<HTMLButtonElement>('[data-id="modal-close"]')
}

/** One test's wire: the bound client, its window double, and its signal feed. */
interface TripWorld {
  oauth: HilosOAuthLogin
  window: { closed: boolean }
  emit(type: string, data: Record<string, unknown>): void
  unbind(): void
}

/**
 * Stand up a client whose starts are accepted, whose provider window is a double
 * that records being closed rather than navigating anywhere, and whose project
 * signals the test answers by hand.
 *
 * @returns The world the tests run trips in.
 */
function tripWorld(): TripWorld {
  const listeners: Array<(signal: ProjectSignal) => void> = []
  const opened = {
    closed: false,
    close: (): void => {
      opened.closed = true
    },
    location: { replace: (): void => undefined },
  }

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
    scopes: scopesWith([
      { key: 'password', name: null },
      { key: GITHUB, name: 'GitHub' },
    ]),
    actions,
    channels: [],
  })

  vi.spyOn(window, 'open').mockImplementation(() => opened as unknown as Window)

  const oauth = createOAuthLogin(context)

  return {
    oauth,
    window: opened,
    unbind: oauth.bindOAuthTrip(),
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
  }

  act(() => {
    window.dispatchEvent(Object.assign(new Event('message'), event))
  })
}

/**
 * Mount the modal and start a link under it, leaving the trip at the provider.
 *
 * @returns The world the link runs in.
 */
async function linkWaiting(): Promise<TripWorld> {
  const world = tripWorld()
  active = world
  render(<HilosOAuthWaitModal />)

  await act(async () => {
    await world.oauth.startOAuthLink(GITHUB)
  })

  return world
}

let active: TripWorld | null = null

afterEach(() => {
  cleanup()
  cancelOAuthTrip()
  active?.unbind()
  active = null
  vi.restoreAllMocks()
})

const closeGestures: Array<[string, () => void]> = [
  ['the × button', () => fireEvent.click(closeButton() as Element)],
  [
    'Escape',
    () => fireEvent.keyDown(byId('modal') as Element, { key: 'Escape' }),
  ],
  ['a click on the backdrop', () => fireEvent.click(byId('modal') as Element)],
]

describe('HilosOAuthWaitModal', () => {
  it('shows nothing when no trip is running', () => {
    render(<HilosOAuthWaitModal />)

    expect(byId('modal')).toBeNull()
  })

  it('stays out of the way of a sign-in, which has a screen of its own', async () => {
    const world = tripWorld()
    active = world
    render(<HilosOAuthWaitModal />)

    await act(async () => {
      await world.oauth.startOAuthLogin(GITHUB)
    })

    expect(world.oauth.trip.get()?.phase).toBe('authorizing')
    expect(byId('modal')).toBeNull()
  })

  it.each(closeGestures)(
    'takes the trip back on %s while the provider leg waits',
    async (_name, gesture) => {
      const world = await linkWaiting()

      expect(closeButton()?.disabled).toBe(false)
      expect(byId('auth-oauth-wait-cancel')).not.toBeNull()
      gesture()

      expect(world.window.closed).toBe(true)
      expect(world.oauth.trip.get()).toBeNull()
      expect(byId('modal')).toBeNull()
    },
  )

  it('turns the open window busy when the exchange starts, without remounting it', async () => {
    const world = await linkWaiting()
    const dialog = byId('modal')
    const close = closeButton()
    expect(close?.disabled).toBe(false)

    courierReturns(world)

    expect(world.oauth.trip.get()?.phase).toBe('exchanging')
    expect(byId('modal')).toBe(dialog)
    expect(closeButton()).toBe(close)
    expect(close?.disabled).toBe(true)
    expect(close?.getAttribute('aria-label')).toBe('Close')
    expect(byId('auth-oauth-wait-cancel')).toBeNull()
    expect(dialog?.textContent).toContain('Linking your account…')
  })

  it.each(closeGestures)(
    'keeps the trip and the window on %s while the exchange runs',
    async (_name, gesture) => {
      const world = await linkWaiting()
      courierReturns(world)

      gesture()

      expect(world.oauth.trip.get()?.phase).toBe('exchanging')
      expect(byId('modal')).not.toBeNull()
      expect(closeButton()?.disabled).toBe(true)
    },
  )

  it('closes the window when the exchange outcome lands', async () => {
    const world = await linkWaiting()
    courierReturns(world)

    act(() => {
      world.emit(OAUTH_RESULT_SIGNAL, {
        acceptKey: '',
        provider: GITHUB,
        reason: OAUTH_REASON_LINK_OK,
        message: null,
        email: null,
        linkToken: null,
        accountToken: null,
      })
    })

    expect(world.oauth.trip.get()).toBeNull()
    expect(byId('modal')).toBeNull()
  })
})
