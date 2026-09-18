// The Angular peer of the HIL-955 handshake-lowering case. The file is opened
// for that one case: the rule lives in core, and this proves the surface wires
// it. The nearest neighbour is HilosMagicLinkPage.test.ts; the package's
// vitest.setup.ts already owns TestBed, so this file configures nothing extra.
// A second world rides a provider trip to the park and back (HIL-926), the peer
// of the same two cases in the Vue and React suites: where a trip that ended on
// the park lands is the surface's half of that answer.
import { TestBed, type ComponentFixture } from '@angular/core/testing'
import {
  bindPageReady,
  cancelOAuthTrip,
  createHilosAuthContext,
  createOAuthLogin,
  createSignal,
  OAUTH_RESULT_SIGNAL,
  OAUTH_RETURN_MESSAGE_TYPE,
  oauthFlowMethod,
  PASSWORD_FLOW_METHOD,
  ScopeManager,
  SESSION_ACK_REGISTERED,
  SIGNAL_HANDSHAKE_RESPONSE,
  SIGNAL_TYPE_PAGE_RESPONSE,
  type ActionHandle,
  type ActionLifecycle,
  type AuthGate,
  type HilosAuthContext,
  type HilosConnection,
  type ProjectSignal,
} from '@hilos/core'
import { afterEach, describe, expect, it, vi } from 'vitest'

import { HilosAuthSurface } from '../src/auth/HilosAuthSurface.js'
import { HILOS_AUTH_GATE } from '../src/auth/hilosAuthGateToken.js'

// The session slot the surface reads its ack from — the default of
// `sessionPendingAck`, which is what the surface asks for (sessionScope.ts).
const PENDING_ACK_SLOT = 'pendingAck'

/**
 * A surface's world: the context it draws, a gate that records dismiss, and a
 * connection that can replay a handshake_response into the listener it registered.
 *
 * @returns The mount context plus the handles each assertion reads.
 */
function surfaceWorld(): {
  context: HilosAuthContext
  dismissed: ReturnType<typeof vi.fn>
  emitProjectSignal: (signal: { type: string; data: unknown }) => void
  gate: AuthGate
} {
  const projectListeners: Array<(signal: ProjectSignal) => void> = []
  const dismissed = vi.fn()
  const connection = {
    on(event: string, listener: (payload: never) => void): () => void {
      if (event === 'projectSignal') {
        projectListeners.push(listener as (signal: ProjectSignal) => void)
      }

      return () => undefined
    },
  } as unknown as HilosConnection
  const actions = {
    dispatch: () =>
      ({
        requestId: 'req-1',
        loading: createSignal(false),
        done: Promise.resolve({ reply: undefined }),
      }) as unknown as ActionHandle,
  } as unknown as ActionLifecycle

  return {
    dismissed,
    emitProjectSignal: (signal: { type: string; data: unknown }): void => {
      const frame = {
        kind: 'project',
        type: signal.type,
        data: signal.data,
        envelope: {},
      } as unknown as ProjectSignal
      for (const listener of projectListeners) {
        listener(frame)
      }
    },
    gate: {
      modalOpen: createSignal(false),
      requireAuth: vi.fn(),
      dismiss: dismissed,
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

/** One trip case's world: what to mount with and the doors a trip comes home by. */
interface TripWorld {
  context: HilosAuthContext
  gate: AuthGate
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
    on(event: string, listener: (payload: never) => void): () => void {
      if (event !== 'projectSignal') {
        return () => undefined
      }
      const typed = listener as (signal: ProjectSignal) => void
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
    gate: {
      modalOpen: createSignal(false),
      requireAuth: vi.fn(),
      dismiss: vi.fn(),
    },
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

/**
 * Mount the surface with the gate provided the way an app provides it.
 *
 * @param world The world the screen draws from and dismisses through.
 * @returns The mounted fixture.
 */
function mountSurface(world: {
  context: HilosAuthContext
  gate: AuthGate
}): ComponentFixture<HilosAuthSurface> {
  TestBed.configureTestingModule({
    providers: [{ provide: HILOS_AUTH_GATE, useValue: world.gate }],
  })

  const fixture = TestBed.createComponent(HilosAuthSurface)
  fixture.componentRef.setInput('context', world.context)
  fixture.detectChanges()

  return fixture
}

/**
 * Let every pending microtask and the render that follows it settle.
 *
 * @param fixture The mounted surface to flush the render of.
 */
async function flush(
  fixture: ComponentFixture<HilosAuthSurface>,
): Promise<void> {
  await Promise.resolve()
  await Promise.resolve()
  fixture.detectChanges()
}

/**
 * Find a node of the mounted screen by its stable test id.
 *
 * @param fixture The mounted surface to look inside.
 * @param id The `data-id` the screen renders on the node.
 * @returns The node, or null when this state does not render it.
 */
function byId(
  fixture: ComponentFixture<HilosAuthSurface>,
  id: string,
): HTMLElement | null {
  return (fixture.nativeElement as HTMLElement).querySelector(
    `[data-id="${id}"]`,
  )
}

describe('HilosAuthSurface', () => {
  afterEach(() => {
    activeTrip?.unbind()
    activeTrip = null
  })

  it('takes the finished panel away when a handshake says the session owes nothing', async () => {
    const world = surfaceWorld()
    const fixture = mountSurface(world)

    world.context.scopes.session.data.set(
      PENDING_ACK_SLOT,
      SESSION_ACK_REGISTERED,
    )
    await flush(fixture)
    expect(byId(fixture, 'auth-continue')).not.toBeNull()

    // The clearing frame never arrived; a later handshake restates that the
    // session owes nothing. The panel comes down from that fact, not from the
    // session slot changing — that slot is still the standing mark.
    world.emitProjectSignal({
      type: SIGNAL_HANDSHAKE_RESPONSE,
      data: { data: { pendingAck: null } },
    })
    await flush(fixture)

    expect(byId(fixture, 'auth-continue')).toBeNull()
    expect(world.dismissed).toHaveBeenCalledTimes(1)
  })

  it('answers a provider trip that failed with a refusal on the form', async () => {
    const world = oauthTripWorld()
    const fixture = mountSurface(world)
    byId(fixture, 'auth-icon-oauth-github')?.click()
    await flush(fixture)
    // Parked on the trip: the waiting screen, not the field.
    expect(byId(fixture, 'auth-cancel')).not.toBeNull()

    world.courier('')
    world.emit(OAUTH_RESULT_SIGNAL, {
      acceptKey: 'accept-1',
      provider: GITHUB_PROVIDER,
      reason: OAUTH_REASON_LOGIN_FAILED,
      email: null,
      linkToken: null,
    })
    await flush(fixture)

    // The refusal of this form, on its refusal line — not news in the notice.
    expect(byId(fixture, 'auth-error')?.textContent?.trim()).toBe(
      OAUTH_FAILED_MESSAGE,
    )
    expect(byId(fixture, 'auth-notice')).toBeNull()
    expect(byId(fixture, 'auth-identifier')).not.toBeNull()
    expect(byId(fixture, 'auth-live-assertive')?.textContent?.trim()).toBe(
      OAUTH_FAILED_MESSAGE,
    )
  })

  it('returns quietly when the person ends the trip', async () => {
    const world = oauthTripWorld()
    const fixture = mountSurface(world)
    byId(fixture, 'auth-icon-oauth-github')?.click()
    await flush(fixture)
    expect(byId(fixture, 'auth-cancel')).not.toBeNull()

    // Declined at the provider: the courier brings an error and no code.
    world.courier('access_denied')
    await flush(fixture)

    expect(byId(fixture, 'auth-error')).toBeNull()
    expect(byId(fixture, 'auth-notice')).toBeNull()
    expect(byId(fixture, 'auth-identifier')).not.toBeNull()
  })
})
