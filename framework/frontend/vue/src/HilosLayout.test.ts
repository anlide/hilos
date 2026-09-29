import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, describe, expect, it, vi } from 'vitest'
import {
  ActionLifecycle,
  applyServerTime,
  bindAccountBlocked,
  bindAccountStanding,
  formatCalendarDate,
  bindImpersonation,
  bindSessionScope,
  bindSignOut,
  hilosToasts,
  IMPERSONATION_ACTION_STOP,
  PROTECTED_MODE_INACTIVE,
  RT_STALENESS_FRESH,
  ScopeManager,
} from '@hilos/core'
import type {
  ActionErrorSignal,
  ActionLifecycleSource,
  ActionSuccessSignal,
  HilosConnection,
  ProjectSignal,
  ProtectedModeStatus,
} from '@hilos/core'

import HilosLayout from './HilosLayout.vue'

/** A node frozen for a restore: the shell turns into the maintenance surface. */
const FROZEN: ProtectedModeStatus = {
  active: true,
  operation: 'restore',
  title: 'Restoring a backup',
  message: 'The application will be back in a few minutes.',
  bannerMessage: undefined,
  acceptsPass: false,
  passIssued: false,
  passRejected: false,
}

/** The verification window seen from inside it: the protected-mode strip is up. */
const ADMITTED: ProtectedModeStatus = {
  ...FROZEN,
  active: false,
  title: undefined,
  message: undefined,
  acceptsPass: true,
  bannerMessage: 'The restore is being verified.',
}

/** A handshake naming Bob as the user and Ada as the administrator behind him. */
const TAKEOVER = {
  entities: {
    currentUser: { id: 2, name: 'Bob' },
    impersonatedBy: { id: 1, name: 'Ada' },
  },
}

/** The shell's own connection: only the states it reads, fixed for the case. */
function shellConnection(
  protectedMode: ProtectedModeStatus = PROTECTED_MODE_INACTIVE,
): HilosConnection {
  return {
    state: 'connected',
    protectedMode,
    rtStaleness: RT_STALENESS_FRESH,
    reconnectDragging: false,
    on: () => () => {},
  } as unknown as HilosConnection
}

/** A lifecycle source that records what was sent and replies on demand. */
class ReplyingSource implements ActionLifecycleSource {
  readonly sent: { action: string; data: unknown; requestId?: string }[] = []
  private readonly success: ((signal: ActionSuccessSignal) => void)[] = []
  private readonly error: ((signal: ActionErrorSignal) => void)[] = []

  sendAction(action: string, data: unknown, requestId?: string): boolean {
    this.sent.push({ action, data, requestId })

    return true
  }

  on(event: string, listener: (payload: never) => void): () => void {
    if (event === 'actionSuccess') {
      this.success.push(listener as (signal: ActionSuccessSignal) => void)
    }
    if (event === 'actionError') {
      this.error.push(listener as (signal: ActionErrorSignal) => void)
    }

    return () => {}
  }

  succeed(
    requestId: string | undefined,
    action = IMPERSONATION_ACTION_STOP,
  ): void {
    for (const listener of this.success) {
      listener({
        kind: 'actionSuccess',
        action,
        requestId,
        envelope: { type: 'action_success', data: {} },
      } as ActionSuccessSignal)
    }
  }

  refuse(
    requestId: string | undefined,
    reason: string,
    action = IMPERSONATION_ACTION_STOP,
  ): void {
    for (const listener of this.error) {
      listener({
        kind: 'actionError',
        action,
        reason,
        requestId,
        envelope: { type: 'action_error', data: {} },
      } as ActionErrorSignal)
    }
  }
}

/**
 * Bind the shell's session controls the way bootHilos does, over a session
 * scope fed by handshakes this harness emits.
 */
function bindSession() {
  const projectListeners: ((signal: ProjectSignal) => void)[] = []
  const handshakes = {
    on(event: string, listener: (payload: never) => void): () => void {
      if (event === 'projectSignal') {
        projectListeners.push(listener as (signal: ProjectSignal) => void)
      }

      return () => {}
    },
  } as unknown as HilosConnection
  const scopes = new ScopeManager()
  bindSessionScope(handshakes, scopes)
  const source = new ReplyingSource()
  // One lifecycle for all, as bootHilos binds them: two would mint the same ids.
  const actions = new ActionLifecycle(source)
  const unbindStrip = bindImpersonation(scopes, actions)
  const unbindCard = bindAccountBlocked(scopes, actions, handshakes)
  const unbindStanding = bindAccountStanding(scopes, actions)
  const unbindSignOut = bindSignOut(scopes, actions)

  return {
    source,
    unbind(): void {
      unbindStrip()
      unbindCard()
      unbindStanding()
      unbindSignOut()
    },
    handshake(payload: Record<string, unknown>): void {
      const signal = {
        kind: 'project',
        type: 'handshake_response',
        data: payload,
        envelope: {},
      } as unknown as ProjectSignal
      for (const listener of projectListeners) {
        listener(signal)
      }
    },
  }
}

function mountShell(connection: HilosConnection, banner?: string) {
  return mount(HilosLayout, {
    props: { connection },
    slots: {
      default: '<p data-id="page-body">Page</p>',
      ...(banner === undefined ? {} : { banner }),
    },
  })
}

describe('HilosLayout impersonation strip', () => {
  let unbind: (() => void) | undefined

  afterEach(() => {
    unbind?.()
    unbind = undefined
    hilosToasts.clear()
  })

  it('draws no strip for a plain session', () => {
    const session = bindSession()
    unbind = session.unbind
    session.handshake({ entities: { currentUser: { id: 1, name: 'Ada' } } })

    const wrapper = mountShell(shellConnection())

    expect(wrapper.find('[data-id="impersonation-banner"]').exists()).toBe(
      false,
    )
  })

  it('names the user the session acts as while impersonated', () => {
    const session = bindSession()
    unbind = session.unbind
    session.handshake(TAKEOVER)

    const wrapper = mountShell(shellConnection())

    const strip = wrapper.find('[data-id="impersonation-banner"]')
    expect(strip.exists()).toBe(true)
    expect(strip.text()).toContain('You are impersonating')
    expect(strip.find('strong').text()).toBe('Bob')
    expect(strip.find('[data-id="impersonation-stop"]').text()).toBe('Stop')
  })

  it('stands between the protected-mode strip and the project strip', () => {
    const session = bindSession()
    unbind = session.unbind
    session.handshake(TAKEOVER)

    const wrapper = mountShell(
      shellConnection(ADMITTED),
      '<p data-id="test-banner">A trial notice</p>',
    )

    const region = wrapper.find('[data-id="app-banner"]')
    const order = Array.from(region.element.children).map((child) =>
      child.getAttribute('data-id'),
    )
    expect(order).toEqual([
      'protected-mode-banner',
      'impersonation-banner',
      'test-banner',
    ])
  })

  it('draws no strip under the maintenance surface', () => {
    const session = bindSession()
    unbind = session.unbind
    session.handshake(TAKEOVER)

    const wrapper = mountShell(shellConnection(FROZEN))

    expect(wrapper.find('[data-id="impersonation-banner"]').exists()).toBe(
      false,
    )
  })

  it('sends Stop tracked and keeps it disabled until the reply settles', async () => {
    const session = bindSession()
    unbind = session.unbind
    session.handshake(TAKEOVER)
    const wrapper = mountShell(shellConnection())
    const stop = wrapper.find('[data-id="impersonation-stop"]')

    await stop.trigger('click')

    expect(session.source.sent).toHaveLength(1)
    expect(session.source.sent[0]?.action).toBe('hilos_impersonate_stop')
    expect(session.source.sent[0]?.data).toEqual({})
    expect(session.source.sent[0]?.requestId).toBeTruthy()
    expect(stop.attributes('disabled')).toBeDefined()

    session.source.succeed(session.source.sent[0]?.requestId)
    await flushPromises()

    expect(
      wrapper.find('[data-id="impersonation-stop"]').attributes('disabled'),
    ).toBeUndefined()
    expect(hilosToasts.toasts.get()).toEqual([])
  })

  it('leaves the strip standing on a refusal and says why in a toast', async () => {
    const session = bindSession()
    unbind = session.unbind
    session.handshake(TAKEOVER)
    const wrapper = mountShell(shellConnection())

    await wrapper.find('[data-id="impersonation-stop"]').trigger('click')
    session.source.refuse(
      session.source.sent[0]?.requestId,
      'Session is not impersonating',
    )
    await flushPromises()

    expect(wrapper.find('[data-id="impersonation-banner"]').exists()).toBe(true)
    expect(
      wrapper.find('[data-id="impersonation-stop"]').attributes('disabled'),
    ).toBeUndefined()
    expect(
      hilosToasts.toasts.get().map((toast) => [toast.severity, toast.message]),
    ).toEqual([['error', 'Session is not impersonating']])
  })

  it('adds no live region of its own when the strip goes up', () => {
    // Counted before the strip goes up: both shells read the one store, so the
    // plain one would grow the strip too once the takeover lands.
    const live = '[role="status"][aria-live="polite"]'
    const plain = bindSession()
    plain.handshake({ entities: { currentUser: { id: 1, name: 'Ada' } } })
    const down = mountShell(shellConnection())
    const liveWithout = down.element.querySelectorAll(live).length
    down.unmount()
    plain.unbind()

    const session = bindSession()
    unbind = session.unbind
    session.handshake(TAKEOVER)
    const up = mountShell(shellConnection())

    expect(up.find('[data-id="impersonation-banner"]').exists()).toBe(true)
    expect(up.element.querySelectorAll(live).length).toBe(liveWithout)
  })
})

/** One day, in ms. */
const DAY_MS = 86_400_000

/** The browser clock the standing cases are parked at. */
const NOW = 1_800_000_000_000

/**
 * A handshake of Bob's session carrying a standing.
 *
 * @param shown The standing shown.
 * @param impersonated Whether Ada stands behind the session.
 * @param deletionEffectiveAt The server moment of a scheduled erasure, or null.
 */
function standingHandshake(
  shown: string,
  impersonated = false,
  deletionEffectiveAt: number | null = null,
): Record<string, unknown> {
  return {
    data: {
      serverTimeMs: NOW,
      accountStanding: {
        shown,
        blocked: shown === 'blocked',
        frozen: shown === 'frozen',
        deletionEffectiveAt,
        lapsed: [],
      },
    },
    entities: {
      currentUser: { id: 2, name: 'Bob' },
      impersonatedBy: impersonated ? { id: 1, name: 'Ada' } : null,
    },
  }
}

describe('HilosLayout account standing (HIL-945)', () => {
  let unbind: (() => void) | undefined

  afterEach(() => {
    unbind?.()
    unbind = undefined
    hilosToasts.clear()
    applyServerTime(Date.now())
    vi.useRealTimers()
  })

  it('says when the own account is deleted and how many days are left', () => {
    vi.useFakeTimers()
    vi.setSystemTime(NOW)
    const session = bindSession()
    unbind = session.unbind
    const effectiveAt = NOW + 12 * DAY_MS
    session.handshake(
      standingHandshake('deletion_scheduled', false, effectiveAt),
    )

    const wrapper = mountShell(shellConnection())

    const strip = wrapper.find('[data-id="account-deletion-strip"]')
    expect(strip.exists()).toBe(true)
    expect(strip.classes()).toContain('alert-warning')
    expect(strip.find('.bi-trash').exists()).toBe(true)
    expect(strip.find('[data-id="account-deletion-strip-text"]').text()).toBe(
      `Your account will be deleted on ${formatCalendarDate(effectiveAt)} — 12 days left`,
    )
    expect(strip.find('[data-id="account-deletion-strip-keep"]').text()).toBe(
      'Keep my account',
    )
  })

  it('counts the days left again once a minute', async () => {
    vi.useFakeTimers()
    vi.setSystemTime(NOW)
    const session = bindSession()
    unbind = session.unbind
    session.handshake(
      standingHandshake('deletion_scheduled', false, NOW + 2 * DAY_MS),
    )
    const wrapper = mountShell(shellConnection())
    const text = () =>
      wrapper.find('[data-id="account-deletion-strip-text"]').text()
    expect(text()).toContain('2 days left')

    vi.setSystemTime(NOW + 1.5 * DAY_MS)
    await vi.advanceTimersByTimeAsync(60_000)

    expect(text()).toContain('1 day left')
  })

  it('stands after the framework strips and before the project strip', () => {
    // It never meets the impersonation strip: a takeover takes it down.
    const session = bindSession()
    unbind = session.unbind
    session.handshake(
      standingHandshake('deletion_scheduled', false, NOW + 3 * DAY_MS),
    )

    const wrapper = mountShell(
      shellConnection(ADMITTED),
      '<p data-id="test-banner">A trial notice</p>',
    )

    const order = Array.from(
      wrapper.find('[data-id="app-banner"]').element.children,
    ).map((child) => child.getAttribute('data-id'))
    expect(order).toEqual([
      'protected-mode-banner',
      'account-deletion-strip',
      'test-banner',
    ])
  })

  it('draws no deletion strip under a takeover, nor under maintenance', () => {
    const session = bindSession()
    unbind = session.unbind
    session.handshake(
      standingHandshake('deletion_scheduled', true, NOW + 3 * DAY_MS),
    )

    const taken = mountShell(shellConnection())
    expect(taken.find('[data-id="impersonation-banner"]').exists()).toBe(true)
    expect(taken.find('[data-id="account-deletion-strip"]').exists()).toBe(
      false,
    )
    taken.unmount()

    session.handshake(
      standingHandshake('deletion_scheduled', false, NOW + 3 * DAY_MS),
    )
    const frozenNode = mountShell(shellConnection(FROZEN))
    expect(frozenNode.find('[data-id="account-deletion-strip"]').exists()).toBe(
      false,
    )
  })

  it('sends Keep my account tracked, busy until the reply, and toasts nothing on success', async () => {
    const session = bindSession()
    unbind = session.unbind
    session.handshake(
      standingHandshake('deletion_scheduled', false, NOW + 3 * DAY_MS),
    )
    const wrapper = mountShell(shellConnection())
    const keep = wrapper.find('[data-id="account-deletion-strip-keep"]')

    await keep.trigger('click')

    expect(session.source.sent).toHaveLength(1)
    expect(session.source.sent[0]?.action).toBe('hilos_account_deletion_cancel')
    expect(session.source.sent[0]?.data).toEqual({})
    expect(session.source.sent[0]?.requestId).toBeTruthy()
    expect(keep.attributes('disabled')).toBeDefined()

    session.source.succeed(
      session.source.sent[0]?.requestId,
      'hilos_account_deletion_cancel',
    )
    session.handshake(standingHandshake('none'))
    await flushPromises()

    expect(wrapper.find('[data-id="account-deletion-strip"]').exists()).toBe(
      false,
    )
    expect(hilosToasts.toasts.get()).toEqual([])
  })

  it('leaves the strip standing on a refusal and says why in a toast', async () => {
    const session = bindSession()
    unbind = session.unbind
    session.handshake(
      standingHandshake('deletion_scheduled', false, NOW + 3 * DAY_MS),
    )
    const wrapper = mountShell(shellConnection())

    await wrapper
      .find('[data-id="account-deletion-strip-keep"]')
      .trigger('click')
    session.source.refuse(
      session.source.sent[0]?.requestId,
      'No deletion is scheduled',
      'hilos_account_deletion_cancel',
    )
    await flushPromises()

    expect(wrapper.find('[data-id="account-deletion-strip"]').exists()).toBe(
      true,
    )
    expect(
      wrapper
        .find('[data-id="account-deletion-strip-keep"]')
        .attributes('disabled'),
    ).toBeUndefined()
    expect(
      hilosToasts.toasts.get().map((toast) => [toast.severity, toast.message]),
    ).toEqual([['error', 'No deletion is scheduled']])
  })

  it.each([
    ['none', 'alert-warning'],
    ['blocked', 'alert-danger'],
    ['frozen', 'alert-info'],
  ])(
    'colors the impersonation strip of a %s person %s',
    (shown, alertClass) => {
      const session = bindSession()
      unbind = session.unbind
      session.handshake(standingHandshake(shown, true))

      const wrapper = mountShell(shellConnection())

      const strip = wrapper.find('[data-id="impersonation-banner"]')
      expect(strip.classes()).toContain(alertClass)
      expect(strip.find('strong').text()).toBe('Bob')
    },
  )
})

describe('HilosLayout sign-out', () => {
  let unbind: (() => void) | undefined
  let mounted: ReturnType<typeof mountShell> | undefined

  function surface(container: Element, id: string): Element | null {
    return container.querySelector(`[data-id="${id}"]`)
  }

  function renderSignOutShell(connection: HilosConnection): HTMLElement {
    mounted = mountShell(connection)
    return mounted.element as HTMLElement
  }

  afterEach(() => {
    mounted?.unmount()
    mounted = undefined
    unbind?.()
    unbind = undefined
    hilosToasts.clear()
  })

  it('draws no control for an anonymous session', () => {
    const session = bindSession()
    unbind = session.unbind
    session.handshake({ entities: { currentUser: null } })

    expect(
      surface(renderSignOutShell(shellConnection()), 'nav-logout'),
    ).toBeNull()
  })

  it.each([{ entities: { currentUser: { id: 1, name: '' } } }, TAKEOVER])(
    'draws the named control last, also while impersonated: %j',
    (payload) => {
      const session = bindSession()
      unbind = session.unbind
      session.handshake(payload)
      const container = renderSignOutShell(shellConnection())
      const button = surface(container, 'nav-logout') as HTMLButtonElement

      expect(button.tagName).toBe('BUTTON')
      expect(button.getAttribute('aria-label')).toBe('Sign out')
      expect(button.title).toBe('Sign out')
      expect(button.parentElement?.lastElementChild).toBe(button)
      expect(button.previousElementSibling).toBe(
        surface(container, 'conn-state'),
      )
      expect(
        button
          .querySelector('.bi-box-arrow-right')
          ?.getAttribute('aria-hidden'),
      ).toBe('true')
    },
  )

  it('draws no control under the maintenance surface', () => {
    const session = bindSession()
    unbind = session.unbind
    session.handshake(TAKEOVER)

    expect(
      surface(renderSignOutShell(shellConnection(FROZEN)), 'nav-logout'),
    ).toBeNull()
  })

  it('draws no control during the held first frame', () => {
    const session = bindSession()
    unbind = session.unbind
    session.handshake(TAKEOVER)
    const connection = shellConnection()
    Object.defineProperty(connection, 'firstFrameHeld', { value: true })

    expect(surface(renderSignOutShell(connection), 'nav-logout')).toBeNull()
  })

  it('sends sign-out tracked once and stays disabled until a silent success', async () => {
    const session = bindSession()
    unbind = session.unbind
    session.handshake(TAKEOVER)
    const container = renderSignOutShell(shellConnection())
    const button = surface(container, 'nav-logout') as HTMLButtonElement

    button.click()
    await flushPromises()
    expect(button.disabled).toBe(true)
    expect(button.getAttribute('aria-busy')).toBe('true')
    expect(surface(container, 'loading-button-spinner')).toBeNull()
    button.click()
    await flushPromises()
    expect(session.source.sent).toEqual([
      { action: 'hilos_logout', data: {}, requestId: expect.any(String) },
    ])
    expect(session.source.sent[0]?.requestId).toBeTruthy()

    session.source.succeed(session.source.sent[0]?.requestId, 'hilos_logout')
    await flushPromises()

    expect(button.disabled).toBe(false)
    expect(hilosToasts.toasts.get()).toEqual([])
  })

  it('leaves the control ready on a refusal and toasts the server reason', async () => {
    const session = bindSession()
    unbind = session.unbind
    session.handshake(TAKEOVER)
    const container = renderSignOutShell(shellConnection())
    const button = surface(container, 'nav-logout') as HTMLButtonElement

    button.click()
    await flushPromises()
    session.source.refuse(
      session.source.sent[0]?.requestId,
      'Session not on connection',
      'hilos_logout',
    )
    await flushPromises()

    expect(surface(container, 'nav-logout')).toBe(button)
    expect(button.disabled).toBe(false)
    expect(surface(container, 'impersonation-banner')?.textContent).toContain(
      'Bob',
    )
    expect(
      hilosToasts.toasts.get().map((toast) => [toast.severity, toast.message]),
    ).toEqual([['error', 'Session not on connection']])
  })

  it('removes the control with the anonymous identity before its ack arrives', async () => {
    const session = bindSession()
    unbind = session.unbind
    session.handshake(TAKEOVER)
    const container = renderSignOutShell(shellConnection())
    const button = surface(container, 'nav-logout') as HTMLButtonElement

    button.click()
    await flushPromises()
    session.handshake({ entities: { currentUser: null, impersonatedBy: null } })
    await flushPromises()
    expect(surface(container, 'nav-logout')).toBeNull()

    session.source.succeed(session.source.sent[0]?.requestId, 'hilos_logout')
    await flushPromises()
    expect(hilosToasts.toasts.get()).toEqual([])
  })
})

/** The anonymous handshake a browser gets after its account was blocked. */
const BLOCKED = {
  entities: { currentUser: null },
  data: { accountBlocked: { identifier: 'maria@example.com' } },
}

describe('HilosLayout account blocked card', () => {
  let unbind: (() => void) | undefined

  afterEach(() => {
    unbind?.()
    unbind = undefined
    hilosToasts.clear()
  })

  it('stands in place of the content and keeps the header and footer', () => {
    const session = bindSession()
    unbind = session.unbind
    session.handshake(BLOCKED)

    const wrapper = mountShell(shellConnection())

    const card = wrapper.find('[data-id="account-blocked"]')
    expect(card.exists()).toBe(true)
    expect(card.find('[data-id="data-export-prepare"]').exists()).toBe(true)
    expect(wrapper.find('[data-id="page-body"]').exists()).toBe(false)
    expect(wrapper.find('[data-id="app-footer"]').exists()).toBe(true)
    expect(wrapper.find('nav').exists()).toBe(true)
    expect(card.find('[data-id="account-blocked-heading"]').text()).toBe(
      'Access closed',
    )
    expect(card.find('[data-id="account-blocked-account"]').text()).toBe(
      'The account maria@example.com has been blocked by the project administration.',
    )
    expect(card.find('[data-id="account-blocked-reason"]').text()).toContain(
      'No reason given',
    )
  })

  it('says "This account" when the server could name no address', () => {
    const session = bindSession()
    unbind = session.unbind
    session.handshake({ data: { accountBlocked: { identifier: null } } })

    const wrapper = mountShell(shellConnection())

    expect(wrapper.find('[data-id="account-blocked-account"]').text()).toBe(
      'This account has been blocked by the project administration.',
    )
  })

  it('sends Sign out tracked and keeps it disabled until the reply settles', async () => {
    const session = bindSession()
    unbind = session.unbind
    session.handshake(BLOCKED)
    const wrapper = mountShell(shellConnection())
    const signOut = wrapper.find('[data-id="account-blocked-sign-out"]')

    await signOut.trigger('click')

    expect(session.source.sent).toHaveLength(1)
    expect(session.source.sent[0]?.action).toBe('hilos_dismiss_account_blocked')
    expect(session.source.sent[0]?.data).toEqual({})
    expect(session.source.sent[0]?.requestId).toBeTruthy()
    expect(signOut.attributes('disabled')).toBeDefined()

    session.source.succeed(session.source.sent[0]?.requestId)
    await flushPromises()

    expect(
      wrapper
        .find('[data-id="account-blocked-sign-out"]')
        .attributes('disabled'),
    ).toBeUndefined()
  })

  it('gives the content back when a handshake carries no card', async () => {
    const session = bindSession()
    unbind = session.unbind
    session.handshake(BLOCKED)
    const wrapper = mountShell(shellConnection())

    session.handshake({ data: { accountBlocked: null } })
    await flushPromises()

    expect(wrapper.find('[data-id="account-blocked"]').exists()).toBe(false)
    expect(wrapper.find('[data-id="page-body"]').exists()).toBe(true)
  })

  it('gives way to the maintenance surface', () => {
    const session = bindSession()
    unbind = session.unbind
    session.handshake(BLOCKED)

    const wrapper = mountShell(shellConnection(FROZEN))

    expect(wrapper.find('[data-id="account-blocked"]').exists()).toBe(false)
  })
})
