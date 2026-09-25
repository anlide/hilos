// The Angular peer of the HIL-955 handshake-lowering case. The file is opened
// for that one case: the rule lives in core, and this proves the surface wires
// it. The nearest neighbour is HilosMagicLinkPage.test.ts; the package's
// vitest.setup.ts already owns TestBed, so this file configures nothing extra.
// A second world rides a provider trip to the park and back (HIL-926), the peer
// of the same two cases in the Vue and React suites: where a trip that ended on
// the park lands is the surface's half of that answer.
// A third world walks the letter to its code screen (HIL-977), for the send
// line's room: the same four cases, under the same names, as in Vue and React.
import { TestBed, type ComponentFixture } from '@angular/core/testing'
import {
  AUTH_ACTION_COMPLETE_REGISTRATION_PASSWORDLESS,
  AUTH_ACTION_DETECT_IDENTIFIER,
  AUTH_SURFACE_HEADING_ID,
  bindCodeSendProgress,
  bindPageReady,
  cancelOAuthTrip,
  CODE_SEND_STATE_FAILED,
  CODE_SEND_STATE_NOT_SENT,
  CODE_SEND_STATE_QUEUED,
  CODE_SEND_STATE_SENDING,
  CODE_SEND_STATE_SENT,
  COOKIES_REFUSED_COPY,
  createHilosAuthContext,
  createOAuthLogin,
  createSignal,
  DEFAULT_DETECT_DEBOUNCE_MS,
  MAGIC_LINK_METHOD_KEY,
  OAUTH_RESULT_SIGNAL,
  OAUTH_RETURN_MESSAGE_TYPE,
  PASSWORD_METHOD_KEY,
  ScopeManager,
  SESSION_ACK_REGISTERED,
  SIGNAL_CODE_SEND_PROGRESS,
  SIGNAL_HANDSHAKE_RESPONSE,
  SIGNAL_TYPE_PAGE_RESPONSE,
  SMS_CODE_CHANNEL,
  type ActionHandle,
  type ActionLifecycle,
  type AuthGate,
  type AuthMethodEntry,
  type HilosAuthContext,
  type HilosConnection,
  type ProjectSignal,
} from '@hilos/core'
import { afterEach, describe, expect, it, vi } from 'vitest'

import { HilosAuthSurface } from '../src/auth/HilosAuthSurface.js'
import { HILOS_AUTH_GATE } from '../src/auth/hilosAuthGateToken.js'

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

// The session slot the surface reads its ack from — the default of
// `sessionPendingAck`, which is what the surface asks for (sessionScope.ts).
const PENDING_ACK_SLOT = 'pendingAck'

// The session slot carrying the authentication step this session stands on (sessionScope.ts).
const PENDING_AUTH_STEP_SLOT = 'pendingAuthStep'

// The session slot the surface reads the delivery answer from (sessionScope.ts).
const CODE_DELIVERY_SLOT = 'codeDelivery'

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
      scopes: scopesWith([{ key: 'password', name: null }]),
      actions,
      channels: [],
      termsPath: '/terms',
      privacyPath: '/privacy',
    }),
  }
}

/**
 * A world offering the password and the magic link, the pair the letter screen
 * needs: the lookup answers with an account that has both, so the icon shows.
 *
 * @returns The context to mount with and a gate that does nothing.
 */
function magicLinkWorld(
  dispatched?: Array<{ action: string; payload: Record<string, unknown> }>,
): { context: HilosAuthContext; gate: AuthGate } {
  const connection = {
    on: (): (() => void) => () => undefined,
  } as unknown as HilosConnection
  const actions = {
    dispatch: (action: string, payload: Record<string, unknown>) => {
      dispatched?.push({ action, payload })
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
          : undefined

      return {
        requestId: 'req-letter',
        loading: createSignal(false),
        done: Promise.resolve({ reply }),
      } as unknown as ActionHandle
    },
  } as unknown as ActionLifecycle

  return {
    gate: {
      modalOpen: createSignal(false),
      requireAuth: vi.fn(),
      dismiss: vi.fn(),
    },
    context: createHilosAuthContext({
      connection,
      scopes: scopesWith([
        { key: 'password', name: null },
        { key: 'magic_link', name: null },
      ]),
      actions,
      channels: [],
      termsPath: '/terms',
      privacyPath: '/privacy',
    }),
  }
}

/**
 * Put a send-progress frame on the SDK's session-wide line, the way the socket
 * would (HIL-826). The line is a module singleton, so the frame outlives the
 * surface and the next test has to be handed a clean one - which is what the
 * null frame in the teardown does, and what the server itself sends to take a
 * line away.
 *
 * @param state The `CODE_SEND_STATE_*` the line should report, or null for none.
 * @param detail The provider's own sentence riding the frame, or null for none.
 */
function reportSendProgress(
  state: string | null,
  detail: string | null = null,
): void {
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
      data: { state, channel: 'email', detail },
    })
  }
  unbind()
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
    scopes: scopesWith([
      { key: 'password', name: null },
      { key: GITHUB_PROVIDER, name: 'GitHub' },
    ]),
    actions,
    channels: [],
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

/**
 * Mount the magic-link world and walk it to the code screen of the letter,
 * where the send line lives.
 *
 * @returns The mounted fixture, standing on the code screen.
 */
async function openLetterCodeScreen(): Promise<
  ComponentFixture<HilosAuthSurface>
> {
  vi.useFakeTimers()
  const fixture = mountSurface(magicLinkWorld())

  const field = byId(fixture, 'auth-identifier') as HTMLInputElement
  field.value = 'someone@example.com'
  field.dispatchEvent(new Event('input'))
  await vi.advanceTimersByTimeAsync(DEFAULT_DETECT_DEBOUNCE_MS + 1)
  await flush(fixture)
  byId(fixture, 'auth-icon-magic-link')?.click()
  await flush(fixture)

  return fixture
}

/**
 * Put a frame on the send line and let the surface redraw under it.
 *
 * @param fixture The mounted surface.
 * @param state The `CODE_SEND_STATE_*` the line should report, or null for none.
 * @param detail The provider's own sentence riding the frame, or null for none.
 */
async function sendFrame(
  fixture: ComponentFixture<HilosAuthSurface>,
  state: string | null,
  detail: string | null = null,
): Promise<void> {
  reportSendProgress(state, detail)
  await flush(fixture)
}

/**
 * How many elements of the code screen's form stand before the code field — the
 * unit test's stand-in for its vertical position, which jsdom does not lay out.
 * Counted inside the form, because the live regions above it are visually
 * hidden and gain a node when they speak.
 *
 * @param fixture The mounted surface.
 * @returns The number of form elements preceding the code field.
 */
function elementsBeforeCodeField(
  fixture: ComponentFixture<HilosAuthSurface>,
): number {
  const root = fixture.nativeElement as HTMLElement
  const all = Array.from(root.querySelectorAll('form *'))

  return all.findIndex((node) => node.getAttribute('data-id') === 'auth-code')
}

describe('HilosAuthSurface', () => {
  afterEach(() => {
    vi.useRealTimers()
    reportSendProgress(null)
    activeTrip?.unbind()
    activeTrip = null
  })

  it('reshapes itself when the installation switches a method off (HIL-427)', async () => {
    const world = magicLinkWorld()
    const context = createHilosAuthContext({
      ...world.context,
      scopes: scopesWith([
        { key: 'password', name: null },
        { key: 'passkey', name: null },
      ]),
    })
    const fixture = mountSurface({ context, gate: world.gate })
    expect(byId(fixture, 'auth-icon-passkey')).not.toBeNull()

    // The frame the settings library sends lands in the same session slot.
    context.scopes.session.data.set('authMethods', [
      { key: 'password', name: null },
    ])
    await flush(fixture)

    expect(byId(fixture, 'auth-icon-passkey')).toBeNull()
  })

  it('holds the send line room with an idle twin before the first frame', async () => {
    const fixture = await openLetterCodeScreen()

    // The code screen stands and nothing has been said about the letter yet:
    // the slot is there anyway, holding one inert copy of the line.
    const slot = byId(fixture, 'auth-send-progress-slot')
    expect(slot?.children).toHaveLength(1)
    const twin = slot?.querySelector('[data-id="auth-send-progress-idle"]')
    expect(twin).not.toBeNull()
    expect(twin?.getAttribute('aria-hidden')).toBe('true')
    expect(twin?.classList.contains('invisible')).toBe(true)
    expect(twin?.querySelector('button')).toBeNull()
    expect(slot?.querySelector('[data-id="auth-send-progress"]')).toBeNull()
  })

  it('swaps the twin for the line without moving the code field', async () => {
    const fixture = await openLetterCodeScreen()
    const before = elementsBeforeCodeField(fixture)

    await sendFrame(fixture, CODE_SEND_STATE_SENT)

    // One visible line, no twin, and the field below stands where it stood:
    // the line took the room the twin was holding instead of adding one.
    const slot = byId(fixture, 'auth-send-progress-slot')
    expect(slot?.children).toHaveLength(1)
    expect(slot?.querySelector('[data-id="auth-send-progress"]')).not.toBeNull()
    expect(
      slot?.querySelector('[data-id="auth-send-progress-idle"]'),
    ).toBeNull()
    expect(elementsBeforeCodeField(fixture)).toBe(before)
  })

  it('offers the full send line behind a button in every state', async () => {
    const fixture = await openLetterCodeScreen()

    for (const state of [
      CODE_SEND_STATE_QUEUED,
      CODE_SEND_STATE_SENDING,
      CODE_SEND_STATE_SENT,
      CODE_SEND_STATE_FAILED,
      CODE_SEND_STATE_NOT_SENT,
    ]) {
      await sendFrame(fixture, state)

      // The button stands in every state, not only on a refusal, and the
      // panel it opens holds the very text the line is showing.
      const line = byId(fixture, 'auth-send-progress')
      const details = byId(fixture, 'auth-send-progress-details')
      expect(details).not.toBeNull()
      expect(details?.getAttribute('aria-label')).toBe('Show the full message')
      details?.click()
      await flush(fixture)
      expect(
        byId(fixture, 'auth-send-progress-full')?.textContent?.trim(),
      ).toBe(line?.textContent?.trim())
      expect(byId(fixture, 'auth-send-progress-close')).not.toBeNull()
    }

    // A line the server takes away closes the panel along with it.
    await sendFrame(fixture, null)
    expect(byId(fixture, 'auth-send-progress-full')).toBeNull()
  })

  it('keeps a long provider sentence to the one line and gives all of it to the panel', async () => {
    const fixture = await openLetterCodeScreen()
    await sendFrame(fixture, CODE_SEND_STATE_FAILED, 'short')
    const short = byId(fixture, 'auth-send-progress')
    const shortShape = short?.querySelectorAll('*').length
    const shortClasses = short?.className

    const sentence = 'x'.repeat(200)
    await sendFrame(fixture, CODE_SEND_STATE_FAILED, sentence)

    // Same nodes, same classes: the length is the text's business, and the
    // text is truncated to the room rather than growing it.
    const long = byId(fixture, 'auth-send-progress')
    expect(long?.querySelectorAll('*').length).toBe(shortShape)
    expect(long?.className).toBe(shortClasses)
    expect(
      long?.querySelector('span')?.classList.contains('text-truncate'),
    ).toBe(true)

    byId(fixture, 'auth-send-progress-details')?.click()
    await flush(fixture)
    expect(byId(fixture, 'auth-send-progress-full')?.textContent).toContain(
      `Could not send: ${sentence}`,
    )
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

  it('a tab that came back by reload after losing the race can sign in', async () => {
    const world = magicLinkWorld()
    world.context.scopes.session.data.set(PENDING_AUTH_STEP_SLOT, {
      identifier: 'someone@example.com',
      kind: 'email',
      intent: 'login',
      step: 'identifier',
      channel: null,
      expiresAt: null,
      code: 'identifier_taken',
    })
    const fixture = mountSurface(world)
    await flush(fixture)

    expect(byId(fixture, 'auth-password')).not.toBeNull()
    expect(byId(fixture, 'auth-submit')).not.toBeNull()
  })
})

describe('HilosAuthSurface on a sign-in held on its second factor (HIL-494)', () => {
  it('draws the code step restored by the handshake, and follows its release', async () => {
    const world = surfaceWorld()
    world.context.scopes.session.data.set(PENDING_AUTH_STEP_SLOT, {
      identifier: null,
      kind: null,
      intent: 'login',
      step: 'second_factor',
      channel: null,
      expiresAt: Date.now() + 600000,
      code: null,
      secondFactor: { trustDeviceDays: 30, resetEffectiveAt: null },
    })
    const fixture = mountSurface(world)
    await flush(fixture)

    expect(byId(fixture, 'auth-heading')?.textContent?.trim()).toBe(
      'Two-step verification',
    )
    expect(
      (fixture.nativeElement as HTMLElement)
        .querySelector('label[for="auth-trust-device"]')
        ?.textContent?.trim(),
    ).toBe("Don't ask again on this device for 30 days")
    ;(byId(fixture, 'auth-backup-toggle') as HTMLButtonElement).click()
    await flush(fixture)
    expect(
      (fixture.nativeElement as HTMLElement)
        .querySelector('label[for="auth-code"]')
        ?.textContent?.trim(),
    ).toBe('Backup code')

    world.context.scopes.session.data.set(PENDING_AUTH_STEP_SLOT, {
      identifier: null,
      kind: null,
      intent: 'login',
      step: 'identifier',
      channel: null,
      expiresAt: null,
      code: null,
    })
    await flush(fixture)

    expect(byId(fixture, 'auth-identifier')).not.toBeNull()
  })
})

describe('HilosAuthSurface in a browser that refuses cookies', () => {
  afterEach(() => {
    vi.restoreAllMocks()
  })

  it('draws the card that says why in place of every form, and sends nothing', async () => {
    vi.spyOn(navigator, 'cookieEnabled', 'get').mockReturnValue(false)
    const world = surfaceWorld()
    const dispatch = vi.fn()
    const context = createHilosAuthContext({
      ...world.context,
      actions: { dispatch } as unknown as ActionLifecycle,
    })
    const fixture = mountSurface({ context, gate: world.gate })
    await flush(fixture)

    expect(byId(fixture, 'cookies-refused')).not.toBeNull()
    // The card's heading takes the surface's id, so a modal around it is named
    // by the refusal rather than by a sign-in heading that is not drawn.
    const heading = byId(fixture, 'cookies-refused-heading')
    expect(heading?.tagName).toBe('H2')
    expect(heading?.getAttribute('id')).toBe(AUTH_SURFACE_HEADING_ID)
    expect(heading?.textContent?.trim()).toBe(COOKIES_REFUSED_COPY.title)
    expect(byId(fixture, 'auth-heading')).toBeNull()
    expect(byId(fixture, 'auth-identifier')).toBeNull()
    expect(byId(fixture, 'cookies-refused-link-kept')).toBeNull()
    expect(dispatch).not.toHaveBeenCalled()
  })
})

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
 * What a session that proved its address and owes a password comes back to: the
 * password screen of a registration (HIL-1008).
 */
const PROVED_REGISTRATION_STEP = {
  identifier: 'newcomer@example.com',
  kind: 'email',
  intent: 'register',
  step: 'set_password',
  channel: null,
  expiresAt: Date.now() + 600000,
  code: null,
}

/**
 * The pending step of a sign-in by phone whose code went out by SMS: the code
 * screen, with the channel on it — the one place the surface names a channel.
 */
const PHONE_CODE_STEP = {
  identifier: '+15550100',
  kind: 'phone',
  intent: 'login',
  step: 'code',
  channel: 'sms',
  expiresAt: Date.now() + 600000,
  code: null,
}

/**
 * A world whose lookup answers a free address this deployment will register: the
 * reply that turns the one screen into a registration, whose submit is the local
 * move to the terms.
 *
 * @returns The context to mount with and a gate that does nothing.
 */
function registrableWorld(): { context: HilosAuthContext; gate: AuthGate } {
  const connection = {
    on: (): (() => void) => () => undefined,
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
              registerable: [PASSWORD_METHOD_KEY],
              registrationBlock: null,
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

  return {
    gate: {
      modalOpen: createSignal(false),
      requireAuth: vi.fn(),
      dismiss: vi.fn(),
    },
    context: createHilosAuthContext({
      connection,
      scopes: scopesWith([{ key: 'password', name: null }]),
      actions,
      channels: [],
      termsPath: '/terms',
      privacyPath: '/privacy',
    }),
  }
}

/**
 * A world that can reach a number by SMS, with a dispatch that accepts everything
 * and answers nothing: the screen under test is restored from the session slot,
 * and nothing on it is sent.
 *
 * @returns The context to mount with and a gate that does nothing.
 */
function smsWorld(): { context: HilosAuthContext; gate: AuthGate } {
  const world = surfaceWorld()

  return {
    gate: world.gate,
    context: createHilosAuthContext({
      ...world.context,
      channels: [SMS_CODE_CHANNEL],
    }),
  }
}

describe('HilosAuthSurface holds the room a step takes (HIL-1107)', () => {
  afterEach(() => {
    vi.useRealTimers()
  })

  /**
   * What the actions of every step look like: the block at the bottom of the
   * room holds the main button, and under it the room of the tail — the live
   * tail first, its invisible twin second, and the twin names nothing.
   *
   * @param fixture The mounted surface, standing on the step.
   * @param main The data-id of the step's main button.
   */
  function expectActionsAtTheBottom(
    fixture: ComponentFixture<HilosAuthSurface>,
    main: string,
  ): void {
    const actions = byId(fixture, 'auth-step-actions')
    expect(actions?.classList.contains('mt-auto')).toBe(true)
    expect(actions?.querySelector(`[data-id="${main}"]`)).not.toBeNull()

    const tail = actions?.querySelector('[data-id="auth-step-tail"]')
    expect(tail?.classList.contains('hilos-stack')).toBe(true)
    // Angular leaves a comment node for the outlet; the elements are what count.
    expect(tail?.children).toHaveLength(2)
    const twin = tail?.children.item(1)
    expect(twin?.getAttribute('aria-hidden')).toBe('true')
    expect(twin?.classList.contains('invisible')).toBe(true)
    expect(twin?.querySelector('[data-id], button, input')).toBeNull()
  }

  /**
   * Type into the identifier field the way a person does, and let the lookup
   * answer.
   *
   * @param fixture The mounted surface.
   * @param value The address to type.
   */
  async function typeIdentifier(
    fixture: ComponentFixture<HilosAuthSurface>,
    value: string,
  ): Promise<void> {
    const field = byId(fixture, 'auth-identifier') as HTMLInputElement
    field.value = value
    field.dispatchEvent(new Event('input'))
    await vi.advanceTimersByTimeAsync(DEFAULT_DETECT_DEBOUNCE_MS + 1)
    await flush(fixture)
  }

  it('stacks the live step over twins that no locator, focus trap or reader can find', () => {
    const fixture = mountSurface(surfaceWorld())

    const room = byId(fixture, 'auth-step-room')
    expect(room?.classList.contains('hilos-stack')).toBe(true)
    const idle = byId(fixture, 'auth-step-room-idle')
    expect(idle?.classList.contains('hilos-stack')).toBe(true)
    expect(idle?.classList.contains('invisible')).toBe(true)
    expect(idle?.getAttribute('aria-hidden')).toBe('true')
    expect(idle?.hasAttribute('inert')).toBe(true)
    // Four steps can turn out tallest: the identifier, the code, the password
    // and the second factor. The rest of the ordinary path is shorter than one
    // of them on every width, and the steps that grow the card have no twin.
    expect(idle?.children).toHaveLength(4)
    // The rule of a twin: nothing inside that a strict locator, a count() > 0
    // wait, the focus trap or a form would find.
    expect(idle?.querySelector('[data-id]')).toBeNull()
    expect(
      idle?.querySelector('form, input, select, textarea, button'),
    ).toBeNull()
    expect(idle?.querySelector('[data-autofocus]')).toBeNull()
    // The live step stands beside the twins in the same cell, not inside them.
    expect(room?.children).toHaveLength(2)
    expect(
      (fixture.nativeElement as HTMLElement).querySelector('form')
        ?.parentElement,
    ).toBe(room)
  })

  it('stands the actions of the identifier step at the bottom once there is a main button', async () => {
    vi.useFakeTimers()
    const fixture = mountSurface(magicLinkWorld())

    // An empty field has no main button and therefore no block of actions.
    expect(byId(fixture, 'auth-step-actions')).toBeNull()

    await typeIdentifier(fixture, 'someone@example.com')

    expectActionsAtTheBottom(fixture, 'auth-submit')
  })

  it('stands the actions of the terms step at the bottom', async () => {
    vi.useFakeTimers()
    const fixture = mountSurface(registrableWorld())

    await typeIdentifier(fixture, 'newcomer@example.com')
    // A local move to the terms screen — this dispatches nothing.
    ;(fixture.nativeElement as HTMLElement)
      .querySelector('form')
      ?.dispatchEvent(new Event('submit'))
    await flush(fixture)

    expect(byId(fixture, 'auth-consent-accept')).not.toBeNull()
    expectActionsAtTheBottom(fixture, 'auth-submit')
  })

  it('stands the actions of the code step at the bottom', async () => {
    const fixture = await openLetterCodeScreen()

    expect(byId(fixture, 'auth-code')).not.toBeNull()
    expectActionsAtTheBottom(fixture, 'auth-submit')
  })

  it('stands the actions of the password step at the bottom', async () => {
    const world = magicLinkWorld()
    world.context.scopes.session.data.set(
      PENDING_AUTH_STEP_SLOT,
      PROVED_REGISTRATION_STEP,
    )
    const fixture = mountSurface(world)
    await flush(fixture)

    expect(byId(fixture, 'auth-new-password')).not.toBeNull()
    expectActionsAtTheBottom(fixture, 'auth-submit')
  })

  it('stands the one button of the finished panel where a main button stands', async () => {
    const world = surfaceWorld()
    const fixture = mountSurface(world)

    world.context.scopes.session.data.set(
      PENDING_ACK_SLOT,
      SESSION_ACK_REGISTERED,
    )
    await flush(fixture)

    expectActionsAtTheBottom(fixture, 'auth-continue')
  })

  it('names the channel of a delivered code by the plaque glyph and a line for the reader only', async () => {
    const world = smsWorld()
    world.context.scopes.session.data.set(
      PENDING_AUTH_STEP_SLOT,
      PHONE_CODE_STEP,
    )
    const fixture = mountSurface(world)
    await flush(fixture)

    expect(byId(fixture, 'auth-code')).not.toBeNull()
    // The same words the screen used to print under the plaque, now inside it
    // and for the ear only: the e2e specs read them by this data-id still.
    const channel = byId(fixture, 'auth-delivered-channel')
    expect(channel?.classList.contains('visually-hidden')).toBe(true)
    expect(channel?.textContent?.trim()).toBe('Sent via SMS.')
    // The plaque draws the channel and not an envelope: this number was not
    // mailed, and the glyph is now where the channel is named for the eye.
    const glyph = channel?.parentElement?.querySelector('i')
    expect(glyph?.className).toContain('bi-chat-dots')
    expect(glyph?.className).not.toContain('bi-envelope')
  })

  it('keeps the envelope on a mailbox and says nothing about its channel', async () => {
    const world = surfaceWorld()
    world.context.scopes.session.data.set(
      PENDING_AUTH_STEP_SLOT,
      RESUMED_CODE_STEP,
    )
    const fixture = mountSurface(world)
    await flush(fixture)

    expect(byId(fixture, 'auth-code')).not.toBeNull()
    expect(byId(fixture, 'auth-delivered-channel')).toBeNull()
    const plaque = (fixture.nativeElement as HTMLElement).querySelector(
      'form .bg-body-tertiary',
    )
    expect(plaque?.querySelector('i')?.className).toContain('bi-envelope')
  })

  it('offers the way past the password where a link can be mailed, and says so', async () => {
    const dispatched: Array<{
      action: string
      payload: Record<string, unknown>
    }> = []
    const world = magicLinkWorld(dispatched)
    world.context.scopes.session.data.set(
      PENDING_AUTH_STEP_SLOT,
      PROVED_REGISTRATION_STEP,
    )
    const fixture = mountSurface(world)
    await flush(fixture)

    expect(byId(fixture, 'auth-new-password')).not.toBeNull()
    expect(
      byId(fixture, 'auth-set-password-lead')?.textContent?.trim(),
    ).toContain(
      'Choose a password, or create the account without one and sign in by a mailed link instead.',
    )
    expect(
      byId(fixture, 'auth-set-password-lead-idle')?.textContent?.trim(),
    ).toContain(
      'Choose a password, or create the account without one and sign in by a mailed link instead.',
    )

    const exit = byId(fixture, 'auth-complete-passwordless')
    expect(exit).not.toBeNull()
    expect(byId(fixture, 'auth-complete-passwordless-idle')).toBeNull()
    exit?.click()
    await flush(fixture)

    expect(dispatched.map((call) => call.action)).toEqual([
      AUTH_ACTION_COMPLETE_REGISTRATION_PASSWORDLESS,
    ])
    expect(dispatched[0]?.payload).toEqual({})
  })

  it('keeps the way past the password off a registry that mounted no link', async () => {
    const world = surfaceWorld()
    world.context.scopes.session.data.set(
      PENDING_AUTH_STEP_SLOT,
      PROVED_REGISTRATION_STEP,
    )
    const fixture = mountSurface(world)
    await flush(fixture)

    expect(byId(fixture, 'auth-new-password')).not.toBeNull()
    expect(byId(fixture, 'auth-complete-passwordless')).toBeNull()
    const idleExit = byId(fixture, 'auth-complete-passwordless-idle')
    expect(idleExit).not.toBeNull()
    expect(idleExit?.tagName.toLowerCase()).toBe('span')
    expect(idleExit?.getAttribute('aria-hidden')).toBe('true')
    expect(idleExit?.classList.contains('invisible')).toBe(true)

    expect(
      byId(fixture, 'auth-set-password-lead')?.textContent?.trim(),
    ).toContain('Choose a password — your account is created when you save it.')
    expect(
      byId(fixture, 'auth-set-password-lead-idle')?.textContent?.trim(),
    ).toContain(
      'Choose a password, or create the account without one and sign in by a mailed link instead.',
    )
  })

  it('keeps it off an installation that cannot mail the link it would rely on', async () => {
    const world = magicLinkWorld()
    world.context.scopes.session.data.set(
      PENDING_AUTH_STEP_SLOT,
      PROVED_REGISTRATION_STEP,
    )
    world.context.scopes.session.data.set(CODE_DELIVERY_SLOT, {
      email: false,
      phone: true,
    })
    const fixture = mountSurface(world)
    await flush(fixture)

    expect(byId(fixture, 'auth-complete-passwordless')).toBeNull()
    const idleExit = byId(fixture, 'auth-complete-passwordless-idle')
    expect(idleExit).not.toBeNull()
    expect(idleExit?.tagName.toLowerCase()).toBe('span')
    expect(idleExit?.getAttribute('aria-hidden')).toBe('true')
    expect(idleExit?.classList.contains('invisible')).toBe(true)

    expect(
      byId(fixture, 'auth-set-password-lead')?.textContent?.trim(),
    ).toContain('Choose a password — your account is created when you save it.')
    expect(
      byId(fixture, 'auth-set-password-lead-idle')?.textContent?.trim(),
    ).toContain(
      'Choose a password, or create the account without one and sign in by a mailed link instead.',
    )
  })

  it('swaps the exit button with its idle twin as sign-in methods change live', async () => {
    const world = magicLinkWorld()
    world.context.scopes.session.data.set(
      PENDING_AUTH_STEP_SLOT,
      PROVED_REGISTRATION_STEP,
    )
    const fixture = mountSurface(world)
    await flush(fixture)

    expect(byId(fixture, 'auth-complete-passwordless')).not.toBeNull()
    expect(byId(fixture, 'auth-complete-passwordless-idle')).toBeNull()
    expect(
      byId(fixture, 'auth-set-password-lead')?.textContent?.trim(),
    ).toContain(
      'Choose a password, or create the account without one and sign in by a mailed link instead.',
    )
    expect(
      byId(fixture, 'auth-set-password-lead-idle')?.textContent?.trim(),
    ).toContain(
      'Choose a password, or create the account without one and sign in by a mailed link instead.',
    )

    world.context.scopes.session.data.set('authMethods', [
      { key: 'password', name: null },
    ])
    await flush(fixture)

    expect(byId(fixture, 'auth-complete-passwordless')).toBeNull()
    const idleExit = byId(fixture, 'auth-complete-passwordless-idle')
    expect(idleExit).not.toBeNull()
    expect(idleExit?.tagName.toLowerCase()).toBe('span')
    expect(idleExit?.getAttribute('aria-hidden')).toBe('true')
    expect(idleExit?.classList.contains('invisible')).toBe(true)

    expect(
      byId(fixture, 'auth-set-password-lead')?.textContent?.trim(),
    ).toContain('Choose a password — your account is created when you save it.')
    expect(
      byId(fixture, 'auth-set-password-lead-idle')?.textContent?.trim(),
    ).toContain(
      'Choose a password, or create the account without one and sign in by a mailed link instead.',
    )

    world.context.scopes.session.data.set('authMethods', [
      { key: 'password', name: null },
      { key: 'magic_link', name: null },
    ])
    await flush(fixture)

    expect(byId(fixture, 'auth-complete-passwordless')).not.toBeNull()
    expect(byId(fixture, 'auth-complete-passwordless-idle')).toBeNull()
    expect(
      byId(fixture, 'auth-set-password-lead')?.textContent?.trim(),
    ).toContain(
      'Choose a password, or create the account without one and sign in by a mailed link instead.',
    )
    expect(
      byId(fixture, 'auth-set-password-lead-idle')?.textContent?.trim(),
    ).toContain(
      'Choose a password, or create the account without one and sign in by a mailed link instead.',
    )
  })

  it('omits the exit and twin during recovery while keeping the recovery lead in both lines', async () => {
    const world = magicLinkWorld()
    world.context.scopes.session.data.set(PENDING_AUTH_STEP_SLOT, {
      ...PROVED_REGISTRATION_STEP,
      intent: 'recovery',
    })
    const fixture = mountSurface(world)
    await flush(fixture)

    expect(byId(fixture, 'auth-complete-passwordless')).toBeNull()
    expect(byId(fixture, 'auth-complete-passwordless-idle')).toBeNull()
    expect(byId(fixture, 'auth-set-password-lead')?.textContent?.trim()).toBe(
      'The code was accepted. Choose a new password.',
    )
    expect(
      byId(fixture, 'auth-set-password-lead-idle')?.textContent?.trim(),
    ).toBe('The code was accepted. Choose a new password.')
  })
})
