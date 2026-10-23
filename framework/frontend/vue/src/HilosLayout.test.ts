import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { h, nextTick, ref, shallowRef } from 'vue'
import {
  ActionLifecycle,
  applyServerTime,
  bindAccountBlocked,
  bindAccountStanding,
  bindAdminAccess,
  bindLegalReconsent,
  createSignal,
  DEFAULT_IMPERSONATION_POLICY,
  formatCalendarDate,
  HILOS_IMPERSONATION_STRIP_TEXT_ID,
  HilosPages,
  bindImpersonation,
  bindSessionScope,
  bindSignOut,
  hilosToasts,
  IMPERSONATION_ACTION_STOP,
  PROTECTED_MODE_INACTIVE,
  RT_STALENESS_FRESH,
  rtStalenessCompactLabel,
  rtStalenessLabel,
  ScopeManager,
} from '@hilos/core/base'
import type {
  ActionErrorSignal,
  ActionLifecycleSource,
  ActionSuccessSignal,
  ConnectionState,
  HilosConnection,
  HilosRouter,
  PageRouteMatch,
  ProjectSignal,
  ProtectedModeStatus,
} from '@hilos/core/base'

import { THEME_SETTINGS_STORAGE_KEY } from '../../core/src/theme/themeBrowser.js'
import { bindThemeState } from '../../core/src/theme/themeState.js'
import { reconsentContent } from '../../core/test/legal/reconsentFixture.js'
import HilosLayout from './HilosLayout.vue'
import LoadingButton from './LoadingButton.vue'
import { hilosRouterKey } from './hilosRouterKey.js'

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
    reply?: unknown,
  ): void {
    for (const listener of this.success) {
      listener({
        kind: 'actionSuccess',
        action,
        requestId,
        reply,
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
  const unbindAdminAccess = bindAdminAccess(scopes)
  const unbindReconsent = bindLegalReconsent(scopes, actions)

  return {
    source,
    unbind(): void {
      unbindStrip()
      unbindCard()
      unbindStanding()
      unbindSignOut()
      unbindAdminAccess()
      unbindReconsent()
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
    expect(stop.attributes('aria-disabled')).toBe('true')

    session.source.succeed(session.source.sent[0]?.requestId)
    await flushPromises()

    expect(
      wrapper
        .find('[data-id="impersonation-stop"]')
        .attributes('aria-disabled'),
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

  /**
   * The takeover handshake carrying the installation's impersonation policy.
   *
   * @param viewOnly Whether the administrator may only look.
   */
  function takeoverWith(viewOnly: boolean): Record<string, unknown> {
    return {
      ...TAKEOVER,
      data: {
        impersonationPolicy: {
          ...DEFAULT_IMPERSONATION_POLICY,
          viewOnly,
        },
      },
    }
  }

  /** Mount the shell over a page whose one control is a tracked button. */
  function mountOverPageAction() {
    return mount(HilosLayout, {
      props: { connection: shellConnection() },
      slots: {
        default: () =>
          h(LoadingButton, { 'data-id': 'page-action' }, () => 'Send'),
      },
    })
  }

  it('says "view only" and switches off the page, never Stop, in a takeover that only looks (HIL-1170)', async () => {
    const session = bindSession()
    unbind = session.unbind
    session.handshake(takeoverWith(true))

    const wrapper = mountOverPageAction()
    const strip = wrapper.find('[data-id="impersonation-banner"]')
    const text = strip.find(`#${HILOS_IMPERSONATION_STRIP_TEXT_ID}`)

    expect(text.exists()).toBe(true)
    expect(text.text()).toContain('You are impersonating')
    expect(text.find('.badge').text()).toBe('view only')
    expect(strip.find('strong').text()).toBe('Bob')
    const stop = wrapper.find('[data-id="impersonation-stop"]')
    expect(stop.attributes('disabled')).toBeUndefined()
    expect(stop.attributes('aria-describedby')).toBeUndefined()
    const action = wrapper.find('[data-id="page-action"]')
    expect(action.attributes('disabled')).toBeDefined()
    expect(action.attributes('aria-describedby')).toBe(
      HILOS_IMPERSONATION_STRIP_TEXT_ID,
    )

    await stop.trigger('click')
    expect(session.source.sent[0]?.action).toBe('hilos_impersonate_stop')
  })

  it('leaves the page live and the strip unmarked while the takeover may act, and follows the policy', async () => {
    const session = bindSession()
    unbind = session.unbind
    session.handshake(takeoverWith(false))

    const wrapper = mountOverPageAction()

    expect(
      wrapper.find('[data-id="impersonation-banner"] .badge').exists(),
    ).toBe(false)
    expect(
      wrapper.find('[data-id="page-action"]').attributes('disabled'),
    ).toBeUndefined()

    session.handshake(takeoverWith(true))
    await flushPromises()

    expect(wrapper.find('[data-id="impersonation-banner"] .badge').text()).toBe(
      'view only',
    )
    expect(
      wrapper.find('[data-id="page-action"]').attributes('disabled'),
    ).toBeDefined()
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
        window: [],
        mergedInto: null,
        mergedIntoName: null,
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
    // This session has no takeover, so the protected-mode banner comes first.
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

  it('draws the deletion strip under a takeover without a personal Keep action', () => {
    vi.useFakeTimers()
    vi.setSystemTime(NOW)
    const session = bindSession()
    unbind = session.unbind
    session.handshake(
      standingHandshake('deletion_scheduled', true, NOW + 3 * DAY_MS),
    )

    const taken = mountShell(shellConnection())
    expect(taken.find('[data-id="impersonation-banner"]').exists()).toBe(true)
    expect(taken.find('[data-id="account-deletion-strip"]').exists()).toBe(true)
    expect(taken.find('[data-id="account-deletion-strip-keep"]').exists()).toBe(
      false,
    )
    expect(taken.get('[data-id="account-deletion-strip-text"]').text()).toBe(
      `Your account will be deleted on ${formatCalendarDate(NOW + 3 * DAY_MS)} — 3 days left`,
    )
    taken.unmount()
  })

  it('draws no deletion strip under maintenance', () => {
    const session = bindSession()
    unbind = session.unbind
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
    expect(keep.attributes('aria-disabled')).toBe('true')
    await keep.trigger('click')
    expect(session.source.sent).toHaveLength(1)

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

describe('HilosLayout admin gear', () => {
  let unbind: (() => void) | undefined
  let mounted: ReturnType<typeof mountShell> | undefined

  /**
   * One handshake: who is behind the session, if anybody, and the node's admin
   * view mode, both as the backend stamps them (HIL-1253).
   *
   * @param user The person behind the session, or null for a guest.
   * @param viewMode The node's admin view mode.
   */
  function greeting(
    user: { id: number; admin: boolean } | null,
    viewMode: boolean,
  ): Record<string, unknown> {
    return {
      entities: {
        currentUser: user === null ? null : { ...user, name: 'Olena' },
      },
      data: { adminViewMode: viewMode },
    }
  }

  function gear(connection: HilosConnection = shellConnection()) {
    mounted = mountShell(connection)

    return mounted.find('[data-id="nav-admin"]')
  }

  afterEach(() => {
    mounted?.unmount()
    mounted = undefined
    unbind?.()
    unbind = undefined
  })

  it('draws no gear for a guest on a node without the view mode', () => {
    const session = bindSession()
    unbind = session.unbind
    session.handshake(greeting(null, false))

    expect(gear().exists()).toBe(false)
  })

  it('draws no gear for a signed-in non-admin on a node without the view mode', () => {
    const session = bindSession()
    unbind = session.unbind
    session.handshake(greeting({ id: 7, admin: false }, false))

    expect(gear().exists()).toBe(false)
  })

  it('draws the full gear for an admin, leading to the dashboard', () => {
    const session = bindSession()
    unbind = session.unbind
    session.handshake(greeting({ id: 7, admin: true }, false))
    const link = gear()

    expect(link.exists()).toBe(true)
    expect(link.attributes('data-access')).toBe('full')
    expect(link.attributes('href')).toBe('/hilos')
    expect(link.attributes('aria-label')).toBe('Hilos dashboard')
    expect(link.attributes('title')).toBeUndefined()
    expect(link.find('.visually-hidden').text()).toBe('Hilos dashboard')
    expect(link.find('.bi-gear-fill').classes()).not.toContain(
      'text-info-emphasis',
    )
    expect(link.find('.bi-gear-fill').attributes('aria-hidden')).toBe('true')
    expect(link.findAll('.bi-eye')).toHaveLength(0)
  })

  it.each([
    ['a guest', null],
    ['a signed-in non-admin', { id: 7, admin: false }],
  ])('draws the view gear for %s on a node in the view mode', (_who, user) => {
    const session = bindSession()
    unbind = session.unbind
    session.handshake(greeting(user, true))
    const link = gear()

    expect(link.exists()).toBe(true)
    expect(link.attributes('data-access')).toBe('view')
    expect(link.attributes('href')).toBe('/hilos')
    expect(link.attributes('aria-label')).toBe('Hilos dashboard — View mode')
    expect(link.attributes('title')).toBe('Hilos dashboard — View mode')
    expect(link.find('.visually-hidden').text()).toBe(
      'Hilos dashboard — View mode',
    )
    expect(link.find('.bi-gear-fill').classes()).toContain('text-info-emphasis')
    expect(link.find('.bi-gear-fill').attributes('aria-hidden')).toBe('true')
    expect(link.findAll('.bi-eye')).toHaveLength(1)
    expect(link.find('.bi-eye').attributes('aria-hidden')).toBe('true')
  })

  it('turns the gear full on a grant and back to view on a revoke, live', async () => {
    const session = bindSession()
    unbind = session.unbind
    session.handshake(greeting({ id: 7, admin: false }, true))
    gear()

    session.handshake(greeting({ id: 7, admin: true }, true))
    await flushPromises()
    expect(mounted?.findAll('[data-id="nav-admin"]')).toHaveLength(1)
    const fullLink = mounted!.find('[data-id="nav-admin"]')
    expect(fullLink.attributes('data-access')).toBe('full')
    expect(fullLink.attributes('aria-label')).toBe('Hilos dashboard')
    expect(fullLink.attributes('title')).toBeUndefined()
    expect(fullLink.find('.visually-hidden').text()).toBe('Hilos dashboard')
    expect(fullLink.find('.bi-gear-fill').classes()).not.toContain(
      'text-info-emphasis',
    )
    expect(fullLink.findAll('.bi-eye')).toHaveLength(0)

    session.handshake(greeting({ id: 7, admin: false }, true))
    await flushPromises()
    expect(mounted?.findAll('[data-id="nav-admin"]')).toHaveLength(1)
    const viewLink = mounted!.find('[data-id="nav-admin"]')
    expect(viewLink.attributes('data-access')).toBe('view')
    expect(viewLink.attributes('aria-label')).toBe(
      'Hilos dashboard — View mode',
    )
    expect(viewLink.attributes('title')).toBe('Hilos dashboard — View mode')
    expect(viewLink.find('.visually-hidden').text()).toBe(
      'Hilos dashboard — View mode',
    )
    expect(viewLink.find('.bi-gear-fill').classes()).toContain(
      'text-info-emphasis',
    )
    expect(viewLink.findAll('.bi-eye')).toHaveLength(1)
    expect(viewLink.find('.bi-eye').attributes('aria-hidden')).toBe('true')
  })

  it('draws no gear under the maintenance surface', () => {
    const session = bindSession()
    unbind = session.unbind
    session.handshake(greeting(null, true))

    expect(gear(shellConnection(FROZEN)).exists()).toBe(false)
  })
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
    'draws the named control before the final connection indicator, also while impersonated: %j',
    (payload) => {
      const session = bindSession()
      unbind = session.unbind
      session.handshake(payload)
      const container = renderSignOutShell(shellConnection())
      const button = surface(container, 'nav-logout') as HTMLButtonElement

      expect(button.tagName).toBe('BUTTON')
      expect(button.getAttribute('aria-label')).toBe('Sign out')
      expect(button.title).toBe('Sign out')
      expect(button.parentElement?.lastElementChild).toBe(
        button.nextElementSibling,
      )
      expect(button.nextElementSibling).toBe(surface(container, 'conn-state'))
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
    expect(button.getAttribute('aria-disabled')).toBe('true')
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

    expect(button.getAttribute('aria-disabled')).toBeNull()
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
    expect(signOut.attributes('aria-disabled')).toBe('true')

    session.source.succeed(session.source.sent[0]?.requestId)
    await flushPromises()

    expect(
      wrapper
        .find('[data-id="account-blocked-sign-out"]')
        .attributes('aria-disabled'),
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

/**
 * A handshake of Bob's session with documents waiting for his decision, or of
 * an anonymous session.
 *
 * @param facts The standing's facts beside a plain account, or null for nobody.
 */
function reconsentHandshake(
  facts: Record<string, unknown> | null,
): Record<string, unknown> {
  return {
    data: {
      accountStanding:
        facts === null
          ? null
          : {
              shown: facts['frozen'] === true ? 'frozen' : 'none',
              blocked: false,
              frozen: false,
              deletionEffectiveAt: null,
              lapsed: [],
              window: [],
              mergedInto: null,
              mergedIntoName: null,
              ...facts,
            },
    },
    entities: {
      currentUser: facts === null ? null : { id: 2, name: 'Bob' },
      impersonatedBy: null,
    },
  }
}

/**
 * A router standing on one page.
 *
 * @param page The page key of the current route.
 * @param admin Whether the route is an admin one.
 */
function routerOn(page: string, admin = false): HilosRouter {
  return {
    currentRoute: createSignal<PageRouteMatch>({
      page,
      params: {},
      admin,
    }),
    currentPath: createSignal(''),
    currentTitle: createSignal(''),
    pageError: createSignal(null),
    pageLoading: createSignal(false),
    pageIdentity: createSignal(undefined),
    dashboardSections: createSignal(undefined),
    resolvePath: () => undefined,
    clearPageError: () => {},
    denyCurrentPage: () => {},
    awaitPageAnswer: () => {},
    onLeave: () => () => {},
    navigate: () => {},
    replacePath: () => {},
    start: () => {},
    stop: () => {},
  }
}

describe('HilosLayout "the terms have changed" (HIL-500)', () => {
  let unbind: (() => void) | undefined
  const inWindow = { window: [{ document: 'terms', deadline: '2026-11-10' }] }
  const lapsed = { lapsed: [{ document: 'terms', deadline: '2026-09-01' }] }

  afterEach(() => {
    unbind?.()
    unbind = undefined
    vi.useRealTimers()
  })

  /** Whether the window stands: the modal is teleported to the body. */
  const windowShown = (): boolean =>
    document.body.querySelector('[data-id="legal-reconsent-modal"]') !== null

  /**
   * An element of the window, which is teleported to the body.
   *
   * @param id The element's data-id.
   */
  function windowPart<T extends HTMLElement = HTMLElement>(id: string): T {
    const element = document.body.querySelector<T>(`[data-id="${id}"]`)
    if (element === null) throw new Error(`Missing ${id}`)

    return element
  }

  /** The window's dialog, where its Escape and backdrop are heard. */
  function dialog(): HTMLElement {
    return windowPart('legal-reconsent-modal').closest<HTMLElement>(
      '[role="dialog"]',
    )!
  }

  /** The window's ×. */
  function cross(): HTMLButtonElement {
    return dialog().querySelector<HTMLButtonElement>('[data-id="modal-close"]')!
  }

  /** Raise the window on a sign-in and answer its reading, so Accept is live. */
  async function openWithContent() {
    const session = bindSession()
    unbind = session.unbind
    session.handshake(reconsentHandshake(null))
    const wrapper = mountShell(shellConnection())
    session.handshake(reconsentHandshake(inWindow))
    await flushPromises()
    const read = session.source.sent.at(-1)!
    session.source.succeed(read.requestId, read.action, reconsentContent())
    await flushPromises()

    return { session, wrapper }
  }

  /** Every way a person leaves the window: ×, Escape, the backdrop, Later and the refusal step. */
  function tryClosing(): void {
    cross().click()
    dialog().dispatchEvent(
      new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }),
    )
    dialog().click()
    windowPart('legal-reconsent-later').click()
    windowPart('legal-reconsent-refuse').click()
  }

  it('draws the yellow document icon after the user slot with the days left', () => {
    vi.useFakeTimers()
    vi.setSystemTime(Date.UTC(2026, 10, 1))
    const session = bindSession()
    unbind = session.unbind
    session.handshake(reconsentHandshake(inWindow))

    const wrapper = mountShell(shellConnection())

    const icon = wrapper.get('[data-id="legal-reconsent-icon"]')
    expect(icon.classes()).toContain('text-warning')
    expect(icon.find('.bi-file-earmark-text').exists()).toBe(true)
    expect(icon.attributes('title')).toBe(
      'The terms have changed — 9 days left to decide',
    )
    expect(windowShown()).toBe(false)
    wrapper.unmount()
  })

  it('says "please review them" for a lapsed document under the remind setting', () => {
    const session = bindSession()
    unbind = session.unbind
    session.handshake(reconsentHandshake(lapsed))

    const wrapper = mountShell(shellConnection())

    expect(
      wrapper.get('[data-id="legal-reconsent-icon"]').attributes('title'),
    ).toBe('The terms have changed — please review them')
    expect(wrapper.find('[data-id="legal-frozen-screen"]').exists()).toBe(false)
  })

  it('raises the window on a sign-in in this tab and opens it from the icon after Later', async () => {
    const session = bindSession()
    unbind = session.unbind
    session.handshake(reconsentHandshake(null))
    const wrapper = mountShell(shellConnection())
    expect(wrapper.find('[data-id="legal-reconsent-icon"]').exists()).toBe(
      false,
    )

    session.handshake(reconsentHandshake(inWindow))
    await flushPromises()

    expect(windowShown()).toBe(true)
    expect(session.source.sent.at(-1)?.action).toBe('hilos_legal_reconsent')
    ;(
      document.body.querySelector(
        '[data-id="legal-reconsent-later"]',
      ) as HTMLButtonElement
    ).click()
    await flushPromises()
    expect(windowShown()).toBe(false)
    await wrapper.get('[data-id="legal-reconsent-icon"]').trigger('click')
    expect(windowShown()).toBe(true)
    wrapper.unmount()
  })

  it('holds the window while the acceptance is out, Accept keeping its look and focus until 300 ms (HIL-1763)', async () => {
    vi.useFakeTimers()
    const { session, wrapper } = await openWithContent()
    const accept = windowPart<HTMLButtonElement>('legal-reconsent-accept')
    const spinner = () =>
      accept.querySelector('[data-id="loading-button-spinner"]')
    const accepts = () =>
      session.source.sent.filter((sent) => sent.action === 'hilos_legal_accept')

    accept.focus()
    accept.click()
    await nextTick()
    expect(accepts()).toHaveLength(1)
    expect(accept.getAttribute('aria-busy')).toBe('true')
    expect(accept.getAttribute('aria-disabled')).toBe('true')
    expect(accept.matches(':disabled')).toBe(false)
    expect(accept.closest('fieldset')).toBeNull()
    expect(spinner()).toBeNull()
    expect(document.activeElement).toBe(accept)
    expect(
      windowPart('legal-reconsent-full-text').closest('fieldset')?.disabled,
    ).toBe(true)
    expect(
      windowPart<HTMLButtonElement>('legal-reconsent-later').disabled,
    ).toBe(true)
    expect(
      windowPart<HTMLButtonElement>('legal-reconsent-refuse').disabled,
    ).toBe(true)
    expect(cross().disabled).toBe(true)
    tryClosing()
    await nextTick()
    expect(windowShown()).toBe(true)
    expect(document.querySelector('[data-id="modal-confirm"]')).toBeNull()
    expect(
      document.querySelector('[data-id="legal-reconsent-refuse-step"]'),
    ).toBeNull()

    await vi.advanceTimersByTimeAsync(299)
    expect(spinner()).toBeNull()
    expect(accept.classList.contains('disabled')).toBe(false)
    await vi.advanceTimersByTimeAsync(1)
    expect(spinner()).not.toBeNull()
    expect(accept.classList.contains('disabled')).toBe(true)
    expect(accept.matches(':disabled')).toBe(false)
    accept.click()
    await nextTick()
    expect(accepts()).toHaveLength(1)
    expect(document.activeElement).toBe(accept)

    session.source.succeed(accepts()[0]!.requestId, 'hilos_legal_accept')
    await flushPromises()
    expect(windowShown()).toBe(false)
    wrapper.unmount()
  })

  it('frees the window after a refused acceptance, with the refusal shown, as before (HIL-1763)', async () => {
    const { session, wrapper } = await openWithContent()
    windowPart('legal-reconsent-accept').click()
    await nextTick()
    const accepted = session.source.sent.at(-1)!
    expect(accepted.action).toBe('hilos_legal_accept')

    session.source.refuse(
      accepted.requestId,
      'The terms changed again.',
      'hilos_legal_accept',
    )
    await flushPromises()
    // The text is read again before the acceptance lets go of the window.
    const reread = session.source.sent.at(-1)!
    expect(reread.action).toBe('hilos_legal_reconsent')
    expect(cross().disabled).toBe(true)
    session.source.succeed(reread.requestId, reread.action, reconsentContent())
    await flushPromises()

    expect(windowPart('legal-reconsent-error').textContent).toContain(
      'The terms changed again.',
    )
    expect(cross().disabled).toBe(false)
    expect(
      windowPart('legal-reconsent-full-text').closest('fieldset')?.disabled,
    ).toBe(false)
    const later = windowPart<HTMLButtonElement>('legal-reconsent-later')
    expect(later.disabled).toBe(false)
    later.click()
    await flushPromises()
    expect(windowShown()).toBe(false)
    wrapper.unmount()
  })

  it('closes the window as before while only its text is read (HIL-1763)', async () => {
    const session = bindSession()
    unbind = session.unbind
    session.handshake(reconsentHandshake(null))
    const wrapper = mountShell(shellConnection())
    session.handshake(reconsentHandshake(inWindow))
    await flushPromises()

    windowPart('legal-reconsent-loading')
    expect(cross().disabled).toBe(false)
    expect(
      windowPart('legal-reconsent').querySelector('fieldset')?.disabled,
    ).toBe(false)
    cross().click()
    await flushPromises()
    expect(windowShown()).toBe(false)

    await wrapper.get('[data-id="legal-reconsent-icon"]').trigger('click')
    windowPart('legal-reconsent-loading')
    dialog().dispatchEvent(
      new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }),
    )
    await flushPromises()
    expect(windowShown()).toBe(false)
    expect(document.querySelector('[data-id="modal-confirm"]')).toBeNull()
    wrapper.unmount()
  })

  it('puts the freeze screen in place of the content with no icon', async () => {
    const session = bindSession()
    unbind = session.unbind
    session.handshake(reconsentHandshake({ ...lapsed, frozen: true }))

    const wrapper = mountShell(shellConnection())
    await flushPromises()

    const screen = wrapper.get('[data-id="legal-frozen-screen"]')
    expect(screen.find('.bi-snow').exists()).toBe(true)
    expect(
      screen.get('[data-id="legal-reconsent"]').attributes('data-variant'),
    ).toBe('frozen')
    expect(wrapper.find('[data-id="page-body"]').exists()).toBe(false)
    expect(wrapper.find('[data-id="app-footer"]').exists()).toBe(true)
    expect(wrapper.find('[data-id="legal-reconsent-icon"]').exists()).toBe(
      false,
    )
    expect(session.source.sent.at(-1)?.action).toBe('hilos_legal_reconsent')
  })

  it('leaves the pages the freeze keeps open to their content', () => {
    const session = bindSession()
    unbind = session.unbind
    session.handshake(reconsentHandshake({ ...lapsed, frozen: true }))

    const wrapper = mount(HilosLayout, {
      props: { connection: shellConnection() },
      slots: { default: '<p data-id="page-body">Page</p>' },
      global: {
        provide: {
          [hilosRouterKey as symbol]: routerOn(HilosPages.PROFILE_DATA),
        },
      },
    })

    expect(wrapper.find('[data-id="legal-frozen-screen"]').exists()).toBe(false)
    expect(wrapper.find('[data-id="page-body"]').exists()).toBe(true)
  })

  it('draws no icon under the maintenance surface', () => {
    const session = bindSession()
    unbind = session.unbind
    session.handshake(reconsentHandshake(inWindow))

    const wrapper = mountShell(shellConnection(FROZEN))

    expect(wrapper.find('[data-id="legal-reconsent-icon"]').exists()).toBe(
      false,
    )
  })
})

describe('HilosLayout view-mode strip (HIL-1260)', () => {
  let unbind: (() => void) | undefined
  let mounted: ReturnType<typeof mount> | undefined

  afterEach(() => {
    mounted?.unmount()
    mounted = undefined
    unbind?.()
    unbind = undefined
  })

  /**
   * One handshake: Bob, an admin or not, on a node with the admin view mode on
   * or off, with the facts of his standing.
   *
   * @param admin Whether Bob is an admin.
   * @param viewMode The node's admin view mode.
   * @param deletionEffectiveAt When Bob's own account is deleted, or null.
   */
  function viewerHandshake(
    admin: boolean,
    viewMode: boolean,
    deletionEffectiveAt: number | null = null,
  ): Record<string, unknown> {
    const standing = standingHandshake(
      deletionEffectiveAt === null ? 'none' : 'deletion_scheduled',
      false,
      deletionEffectiveAt,
    )

    return {
      data: {
        ...(standing['data'] as Record<string, unknown>),
        adminViewMode: viewMode,
      },
      entities: { currentUser: { id: 2, name: 'Bob', admin } },
    }
  }

  /**
   * Mount the shell on one route.
   *
   * @param admin Whether the route is an admin one.
   * @param connection The shell's connection.
   * @param banner The project's own strip, if any.
   */
  function mountOn(
    admin: boolean,
    connection: HilosConnection = shellConnection(),
    banner?: string,
  ) {
    mounted = mount(HilosLayout, {
      props: { connection },
      slots: {
        default: '<p data-id="page-body">Page</p>',
        ...(banner === undefined ? {} : { banner }),
      },
      global: {
        provide: {
          [hilosRouterKey as symbol]: routerOn(
            admin ? HilosPages.DASHBOARD : HilosPages.PROFILE_DATA,
            admin,
          ),
        },
      },
    })

    return mounted
  }

  it('tells a viewer on an admin route that the screen may be looked at and not changed', () => {
    const session = bindSession()
    unbind = session.unbind
    session.handshake(viewerHandshake(false, true))

    const strip = mountOn(true).get('[data-id="view-mode-banner"]')

    expect(strip.classes()).toEqual(
      expect.arrayContaining(['alert', 'alert-secondary', 'border-0']),
    )
    expect(strip.find('.bi-eye').attributes('aria-hidden')).toBe('true')
    const text = strip.get('#hilos-view-mode-strip-text')
    expect(text.get('strong').text()).toBe('View mode')
    expect(text.text()).toBe(
      'View mode · You can look around, but not change anything.',
    )
  })

  it('stands after the session strips and before the project strip', () => {
    const session = bindSession()
    unbind = session.unbind
    session.handshake(viewerHandshake(false, true, NOW + 3 * DAY_MS))

    const wrapper = mountOn(
      true,
      shellConnection(ADMITTED),
      '<p data-id="test-banner">A trial notice</p>',
    )

    const order = Array.from(
      wrapper.find('[data-id="app-banner"]').element.children,
    ).map((child) => child.getAttribute('data-id'))
    expect(order).toEqual([
      'protected-mode-banner',
      'account-deletion-strip',
      'view-mode-banner',
      'test-banner',
    ])
  })

  it.each([
    ['on a route outside the admin section', false, true, false],
    ['for an admin', true, true, true],
    ['on a node without the view mode', false, false, true],
  ])('draws no strip %s', (_case, admin, viewMode, adminRoute) => {
    const session = bindSession()
    unbind = session.unbind
    session.handshake(viewerHandshake(admin, viewMode))

    expect(
      mountOn(adminRoute).find('[data-id="view-mode-banner"]').exists(),
    ).toBe(false)
  })

  it('draws no strip under the maintenance surface', () => {
    const session = bindSession()
    unbind = session.unbind
    session.handshake(viewerHandshake(false, true))

    expect(
      mountOn(true, shellConnection(FROZEN))
        .find('[data-id="view-mode-banner"]')
        .exists(),
    ).toBe(false)
  })

  it('leaves on a grant and comes back on a revoke, live', async () => {
    const session = bindSession()
    unbind = session.unbind
    session.handshake(viewerHandshake(false, true))
    const wrapper = mountOn(true)
    const shown = () => wrapper.find('[data-id="view-mode-banner"]').exists()
    expect(shown()).toBe(true)

    session.handshake(viewerHandshake(true, true))
    await flushPromises()
    expect(shown()).toBe(false)

    session.handshake(viewerHandshake(false, true))
    await flushPromises()
    expect(shown()).toBe(true)
  })
})

describe('HilosLayout theme icon', () => {
  let releaseTheme: (() => void) | undefined

  const themeActions = new ActionLifecycle(new ReplyingSource())

  function dummyConnection(): HilosConnection {
    return { on: () => () => {} } as unknown as HilosConnection
  }

  // Filled by construction, not by a storage write in this file: a setItem
  // call here is a browser value the file does not declare.
  function memoryStorage(raw: string): Storage {
    const store = new Map<string, string>([[THEME_SETTINGS_STORAGE_KEY, raw]])

    return {
      get length() {
        return store.size
      },
      clear() {
        store.clear()
      },
      getItem(key: string) {
        return store.get(key) ?? null
      },
      key(index: number) {
        return [...store.keys()][index] ?? null
      },
      removeItem(key: string) {
        store.delete(key)
      },
      setItem(key: string, value: string) {
        store.set(key, value)
      },
    }
  }

  function bindSnapshot(switchingEnabled: boolean): void {
    releaseTheme?.()
    vi.stubGlobal(
      'localStorage',
      memoryStorage(
        JSON.stringify({ switchingEnabled, defaultTheme: 'light' }),
      ),
    )
    releaseTheme = bindThemeState(
      dummyConnection(),
      new ScopeManager(),
      themeActions,
    )
  }

  function mountThemed(connection: HilosConnection) {
    return mount(HilosLayout, {
      props: { connection },
      slots: {
        default: '<p data-id="page-body">Page</p>',
        user: '<span data-id="user-slot">Ada</span>',
      },
    })
  }

  beforeEach(() => {
    localStorage.clear()
    releaseTheme = bindThemeState(
      dummyConnection(),
      new ScopeManager(),
      themeActions,
    )
  })

  afterEach(() => {
    releaseTheme?.()
    releaseTheme = undefined
    vi.unstubAllGlobals()
    localStorage.clear()
    const restore = bindThemeState(
      dummyConnection(),
      new ScopeManager(),
      themeActions,
    )
    restore()
  })

  it.each([
    ['guest', null],
    ['signed-in person', { id: 7, name: 'Ada' }],
  ] as const)(
    'draws the theme icon first in the right-hand group for a %s',
    (_label, user) => {
      const session = bindSession()
      session.handshake({ entities: { currentUser: user } })
      const wrapper = mountThemed(shellConnection())
      const group = wrapper.get('nav .d-flex.align-items-center.gap-2')
      const theme = group.get('[data-id="nav-theme"]')

      expect(group.element.firstElementChild?.contains(theme.element)).toBe(
        true,
      )
      expect(
        theme.element.compareDocumentPosition(
          group.get('[data-id="user-slot"]').element,
        ) & Node.DOCUMENT_POSITION_FOLLOWING,
      ).not.toBe(0)

      wrapper.unmount()
      session.unbind()
    },
  )

  it('draws no theme icon under maintenance', () => {
    const wrapper = mountThemed(shellConnection(FROZEN))

    expect(wrapper.find('[data-id="maintenance"]').exists()).toBe(true)
    expect(wrapper.find('[data-id="nav-theme"]').exists()).toBe(false)
    expect(wrapper.find('[data-id="conn-state"]').exists()).toBe(true)
    wrapper.unmount()
  })

  it('draws no theme icon when switching is off', () => {
    bindSnapshot(false)
    const wrapper = mountThemed(shellConnection())

    expect(wrapper.find('[data-id="nav-brand"]').exists()).toBe(true)
    expect(wrapper.find('[data-id="nav-theme"]').exists()).toBe(false)
    wrapper.unmount()
  })
})

/** A shell connection with snapshots and individually removable event listeners. */
function shellFeed(initialBuild?: string) {
  const listeners = new Map<string, Set<(payload: never) => void>>()
  const state = {
    build: initialBuild,
    state: 'connected' as ConnectionState,
    protectedMode: PROTECTED_MODE_INACTIVE,
    rtStaleness: RT_STALENESS_FRESH,
    reconnectDragging: false,
    firstFrameHeld: false,
    on(event: string, listener: (payload: never) => void): () => void {
      const set = listeners.get(event) ?? new Set()
      listeners.set(event, set)
      set.add(listener)
      return () => {
        set.delete(listener)
      }
    },
  }
  return {
    connection: state as unknown as HilosConnection,
    listeners,
    push(
      patch: Partial<Omit<typeof state, 'on'>>,
      event:
        | 'state'
        | 'protectedMode'
        | 'rtStaleness'
        | 'handshake'
        | 'firstFrameHold',
    ): void {
      Object.assign(state, patch)
      const payload =
        event === 'handshake'
          ? {}
          : event === 'firstFrameHold'
            ? state.firstFrameHeld
            : state[event]
      for (const listener of listeners.get(event) ?? [])
        listener(payload as never)
    },
  }
}

/** Mount the real slot seam with changing nav content and a replaceable connection. */
function renderResponsive(
  feed: ReturnType<typeof shellFeed>,
  initiallyVisible = true,
) {
  const bound = shallowRef(feed.connection)
  const visible = ref(initiallyVisible)
  const route = createSignal<PageRouteMatch>({
    page: null,
    params: {},
    admin: false,
  })
  const router = { ...routerOn(''), currentRoute: route } as HilosRouter
  const host = document.createElement('div')
  document.body.append(host)
  const wrapper = mount(
    {
      render: () =>
        h(
          HilosLayout,
          { connection: bound.value },
          {
            nav: () =>
              visible.value
                ? [
                    h('a', { href: '/hidden', hidden: true }, 'Hidden'),
                    h(
                      'a',
                      { href: '/first', 'data-id': 'project-nav-first' },
                      'First',
                    ),
                    h(
                      'a',
                      { href: '/second', 'data-id': 'project-nav-second' },
                      'Second',
                    ),
                  ]
                : null,
            user: () => [
              h('span', { 'data-id': 'user-bell' }, 'Bell'),
              h('span', { 'data-id': 'user-avatar' }, 'Avatar'),
              h('a', { href: '/signin', 'data-id': 'user-signin' }, 'Sign in'),
            ],
            default: () => h('p', 'Page'),
          },
        ),
    },
    {
      attachTo: host,
      global: { provide: { [hilosRouterKey as symbol]: router } },
    },
  )
  const root = wrapper.element as HTMLElement
  root.addEventListener('click', (event) => event.preventDefault())
  const settle = async (): Promise<void> => {
    await nextTick()
    await flushPromises()
    await nextTick()
  }
  const update = async (run: () => void): Promise<void> => {
    run()
    await settle()
  }
  return {
    root,
    route,
    settle,
    update,
    click: (element: HTMLElement) => update(() => element.click()),
    key: (element: HTMLElement, key: string) =>
      update(() =>
        element.dispatchEvent(
          new KeyboardEvent('keydown', {
            key,
            bubbles: true,
            cancelable: true,
          }),
        ),
      ),
    nav: (value: boolean) =>
      update(() => {
        visible.value = value
      }),
    connection: (value: HilosConnection) =>
      update(() => {
        bound.value = value
      }),
    dispose(): void {
      wrapper.unmount()
      host.remove()
    },
  }
}

describe('HilosLayout responsive shell (HIL-1647)', () => {
  let shell: ReturnType<typeof renderResponsive> | undefined
  let releaseSession: (() => void) | undefined
  let wide = false

  const surface = (id: string): HTMLElement | null =>
    shell?.root.querySelector(`[data-id="${id}"]`) ?? null
  const get = (id: string): HTMLElement => {
    const element = surface(id)
    expect(element, id).not.toBeNull()
    return element!
  }
  // Model CSS visibility, not geometry: the production code asks isDisplayed.
  function cssVisibility(): void {
    const computedStyle = window.getComputedStyle.bind(window)
    vi.spyOn(window, 'getComputedStyle').mockImplementation(
      (element, pseudo) => {
        if (element.getAttribute('data-id') === 'nav-toggle') {
          return { display: wide ? 'none' : 'block' } as CSSStyleDeclaration
        }
        return computedStyle(element, pseudo)
      },
    )
  }

  afterEach(() => {
    shell?.dispose()
    shell = undefined
    releaseSession?.()
    releaseSession = undefined
    vi.useRealTimers()
    applyServerTime(Date.now())
    wide = false
    vi.restoreAllMocks()
  })

  it('uses one named native disclosure with an existing unique controlled panel', async () => {
    shell = renderResponsive(shellFeed())
    await shell.settle()
    const toggle = get('nav-toggle')
    const panel = get('nav-panel')
    expect(toggle.tagName).toBe('BUTTON')
    expect(toggle.getAttribute('type')).toBe('button')
    expect(toggle.getAttribute('aria-label')).toBe('Navigation')
    expect(toggle.getAttribute('aria-expanded')).toBe('false')
    expect(toggle.getAttribute('aria-controls')).toBe(panel.id)
    expect(document.querySelectorAll(`[id="${panel.id}"]`)).toHaveLength(1)
    expect(
      shell.root.querySelectorAll('[data-id="project-nav-first"]'),
    ).toHaveLength(1)
    expect(panel.classList.contains('show')).toBe(false)
    expect(panel.getAttribute('role')).toBeNull()
    expect(panel.getAttribute('aria-modal')).toBeNull()
    expect(toggle.hasAttribute('data-bs-toggle')).toBe(false)
  })

  it('opens by native button activation, closes by Escape, link and route without stealing router focus', async () => {
    cssVisibility()
    shell = renderResponsive(shellFeed())
    await shell.settle()
    const toggle = get('nav-toggle')
    // Enter and Space activate a native button as a click in a real browser.
    for (const key of ['Enter', ' ']) {
      toggle.dispatchEvent(new KeyboardEvent('keydown', { key, bubbles: true }))
      await shell.click(toggle)
      expect(toggle.getAttribute('aria-expanded')).toBe('true')
      expect(get('nav-panel').classList.contains('show')).toBe(true)
      expect(document.activeElement).toBe(get('project-nav-first'))
      await shell.key(get('project-nav-first'), 'Escape')
      expect(toggle.getAttribute('aria-expanded')).toBe('false')
      expect(document.activeElement).toBe(toggle)
    }
    await shell.click(toggle)
    const link = get('project-nav-second')
    link.focus()
    await shell.click(link)
    expect(toggle.getAttribute('aria-expanded')).toBe('false')
    expect(document.activeElement).toBe(link)
    await shell.click(toggle)
    const main = shell.root.querySelector('main')!
    main.focus()
    await shell.update(() => {
      shell!.route.set({ page: 'next', params: {}, admin: false })
    })
    expect(toggle.getAttribute('aria-expanded')).toBe('false')
    expect(document.activeElement).toBe(main)
  })

  it('removes empty or vanished content and resets when maintenance or the first-frame hold rises', async () => {
    const feed = shellFeed()
    shell = renderResponsive(feed)
    await shell.settle()
    await shell.click(get('nav-toggle'))
    await shell.nav(false)
    expect(surface('nav-toggle')).toBeNull()
    expect(surface('nav-panel')).toBeNull()
    await shell.nav(true)
    expect(get('nav-toggle').getAttribute('aria-expanded')).toBe('false')
    await shell.click(get('nav-toggle'))
    await shell.update(() =>
      feed.push({ protectedMode: FROZEN }, 'protectedMode'),
    )
    expect(surface('nav-toggle')).toBeNull()
    expect(surface('nav-panel')).toBeNull()
    expect(surface('nav-brand')).toBeNull()
    expect(surface('nav-admin')).toBeNull()
    expect(surface('nav-logout')).toBeNull()
    expect(surface('conn-state')).not.toBeNull()
    await shell.update(() =>
      feed.push({ protectedMode: PROTECTED_MODE_INACTIVE }, 'protectedMode'),
    )
    expect(get('nav-toggle').getAttribute('aria-expanded')).toBe('false')
    await shell.update(() =>
      feed.push({ firstFrameHeld: true }, 'firstFrameHold'),
    )
    expect(surface('app-root')).toBeNull()
    expect(surface('hilos-boot-state')?.getAttribute('data-state')).toBe('held')
  })

  it('has no disclosure when the host starts with an empty nav', async () => {
    shell = renderResponsive(shellFeed(), false)
    await shell.settle()
    expect(surface('nav-toggle')).toBeNull()
    expect(surface('nav-panel')).toBeNull()
  })

  it('resets at CSS width changes and moves focus out of the hidden button or panel', async () => {
    cssVisibility()
    shell = renderResponsive(shellFeed())
    await shell.settle()
    const toggle = get('nav-toggle')
    await shell.click(toggle)
    wide = true
    await shell.update(() => window.dispatchEvent(new Event('resize')))
    expect(toggle.getAttribute('aria-expanded')).toBe('false')
    expect(document.activeElement).toBe(get('project-nav-first'))
    wide = false
    await shell.update(() => window.dispatchEvent(new Event('resize')))
    expect(document.activeElement).toBe(toggle)
    expect(get('nav-panel').classList.contains('show')).toBe(false)
    toggle.focus()
    wide = true
    await shell.update(() => window.dispatchEvent(new Event('resize')))
    expect(document.activeElement).toBe(get('project-nav-first'))
  })

  it('reads the latest build snapshot, updates on welcome, keeps it on drop and releases replaced subscriptions', async () => {
    const feed = shellFeed('A')
    shell = renderResponsive(feed)
    await shell.settle()
    expect(get('footer-build').textContent?.trim()).toBe('build A')
    await shell.update(() => feed.push({ build: 'B' }, 'handshake'))
    expect(get('footer-build').textContent?.trim()).toBe('build B')
    await shell.update(() => feed.push({ state: 'disconnected' }, 'state'))
    expect(get('footer-build').textContent?.trim()).toBe('build B')
    const replacement = shellFeed('C')
    await shell.connection(replacement.connection)
    expect(get('footer-build').textContent?.trim()).toBe('build C')
    expect(feed.listeners.get('handshake')?.size).toBe(0)
    await shell.update(() => feed.push({ build: 'old' }, 'handshake'))
    expect(get('footer-build').textContent?.trim()).toBe('build C')
    await shell.update(() => replacement.push({ build: 'D' }, 'handshake'))
    expect(get('footer-build').textContent?.trim()).toBe('build D')
    const remove = vi.spyOn(window, 'removeEventListener')
    const disconnect = vi.spyOn(MutationObserver.prototype, 'disconnect')
    shell.dispose()
    shell = undefined
    expect(replacement.listeners.get('handshake')?.size).toBe(0)
    expect(remove.mock.calls.some(([name]) => name === 'resize')).toBe(true)
    expect(disconnect).toHaveBeenCalled()
  })

  it('reserves a hidden build line before welcome and keeps the four SPA links in a responsive grid', async () => {
    shell = renderResponsive(shellFeed())
    await shell.settle()
    expect(get('footer-build').classList.contains('invisible')).toBe(true)
    expect(get('footer-build').textContent?.trim()).toBe('build')
    const footer = get('app-footer')
    const grid = footer.querySelector('.row-cols-2')!
    expect(grid.children).toHaveLength(4)
    expect(grid.classList.contains('text-center')).toBe(true)
    expect(grid.classList.contains('d-md-flex')).toBe(true)
    expect(
      Array.from(footer.querySelectorAll('a')).map((link) =>
        link.getAttribute('href'),
      ),
    ).toEqual(['/about', '/terms', '/privacy', '/license'])
  })

  it('follows the current person live and preserves blocked/frozen priority over deletion', async () => {
    vi.useFakeTimers({ toFake: ['Date'] })
    vi.setSystemTime(NOW)
    const session = bindSession()
    releaseSession = session.unbind
    session.handshake(
      standingHandshake('deletion_scheduled', false, NOW + 3 * DAY_MS),
    )
    shell = renderResponsive(shellFeed())
    await shell.settle()
    expect(surface('account-deletion-strip-keep')).not.toBeNull()
    await shell.update(() =>
      session.handshake({
        ...standingHandshake('deletion_scheduled', true, NOW + 5 * DAY_MS),
        entities: {
          currentUser: { id: 3, name: 'Eve' },
          impersonatedBy: { id: 1, name: 'Ada' },
        },
      }),
    )
    expect(
      get('impersonation-banner').querySelector('strong')?.textContent,
    ).toBe('Eve')
    expect(get('account-deletion-strip-text').textContent).toContain(
      `Your account will be deleted on ${formatCalendarDate(NOW + 5 * DAY_MS)} — 5 days left`,
    )
    expect(surface('account-deletion-strip-keep')).toBeNull()
    for (const [standing, tone] of [
      ['blocked', 'alert-danger'],
      ['frozen', 'alert-info'],
    ] as const) {
      await shell.update(() =>
        session.handshake(standingHandshake(standing, true, NOW + 5 * DAY_MS)),
      )
      expect(surface('account-deletion-strip')).toBeNull()
      expect(get('impersonation-banner').classList.contains(tone)).toBe(true)
    }
    await shell.update(() =>
      session.handshake({
        ...standingHandshake('deletion_scheduled', false, NOW + 7 * DAY_MS),
        entities: {
          currentUser: { id: 4, name: 'Carol' },
          impersonatedBy: null,
        },
      }),
    )
    expect(surface('impersonation-banner')).toBeNull()
    expect(surface('account-deletion-strip-keep')).not.toBeNull()
    expect(get('account-deletion-strip-text').textContent).toContain(
      `Your account will be deleted on ${formatCalendarDate(NOW + 7 * DAY_MS)} — 7 days left`,
    )
  })

  it('shows one visual compact stale sentence including maintenance and updates without navigation', async () => {
    const feed = shellFeed()
    shell = renderResponsive(feed)
    await shell.settle()
    expect(surface('conn-stale-line')).toBeNull()
    const stale = { stale: true, since: undefined }
    await shell.update(() => feed.push({ rtStaleness: stale }, 'rtStaleness'))
    const line = get('conn-stale-line')
    expect(line.textContent?.trim()).toBe(
      'Some data may be out of date since an unknown time',
    )
    expect(line.getAttribute('aria-hidden')).toBe('true')
    expect(line.hasAttribute('aria-live')).toBe(false)
    expect(line.hasAttribute('role')).toBe(false)
    expect(get('app-banner').firstElementChild).toBe(line)
    expect(get('conn-state').getAttribute('title')).toBe(
      `connected - ${rtStalenessLabel(stale)}`,
    )
    expect(get('conn-state').querySelector('.bi-snow')).not.toBeNull()
    expect(get('app-banner').querySelectorAll('[aria-live]')).toHaveLength(0)
    const dated = { stale: true, since: Date.UTC(2026, 9, 21, 10) }
    await shell.update(() => feed.push({ rtStaleness: dated }, 'rtStaleness'))
    expect(line.textContent?.trim()).toBe(rtStalenessCompactLabel(dated))
    await shell.update(() =>
      feed.push({ protectedMode: FROZEN }, 'protectedMode'),
    )
    expect(surface('conn-stale-line')).toBe(line)
    expect(surface('app-footer')).toBeNull()
    await shell.update(() => feed.push({ state: 'reconnecting' }, 'state'))
    expect(surface('conn-stale-line')).toBeNull()
    await shell.update(() => feed.push({ state: 'connected' }, 'state'))
    expect(surface('conn-stale-line')).not.toBeNull()
    await shell.update(() =>
      feed.push({ rtStaleness: RT_STALENESS_FRESH }, 'rtStaleness'),
    )
    expect(surface('conn-stale-line')).toBeNull()
  })

  it.each([
    ['guest', null, false, false],
    ['signed', { id: 2, name: 'Bob' }, false, false],
    ['admin', { id: 2, name: 'Bob', admin: true }, false, false],
    ['view', null, true, false],
    ['takeover', { id: 2, name: 'Bob' }, false, true],
  ] as const)(
    'keeps actual control DOM order for %s',
    async (_label, user, viewMode, takeover) => {
      const session = bindSession()
      releaseSession = session.unbind
      session.handshake({
        data: {
          adminViewMode: viewMode,
          accountStanding:
            user === null
              ? null
              : {
                  shown: 'none',
                  blocked: false,
                  frozen: false,
                  deletionEffectiveAt: null,
                  lapsed: [],
                  window: [{ document: 'terms', deadline: '2099-11-10' }],
                  mergedInto: null,
                  mergedIntoName: null,
                },
        },
        entities: {
          currentUser: user,
          impersonatedBy: takeover ? { id: 1, name: 'Ada' } : null,
        },
      })
      shell = renderResponsive(shellFeed())
      await shell.settle()
      const ids = [
        'nav-theme',
        'nav-admin',
        'user-bell',
        'user-avatar',
        'user-signin',
        'legal-reconsent-icon',
        'nav-logout',
        'nav-toggle',
        'conn-state',
      ]
      const present = ids
        .map(surface)
        .filter((element): element is HTMLElement => element !== null)
      for (let index = 1; index < present.length; index++) {
        expect(
          present[index - 1]!.compareDocumentPosition(present[index]!) &
            Node.DOCUMENT_POSITION_FOLLOWING,
        ).not.toBe(0)
      }
      expect(get('conn-state').parentElement?.lastElementChild).toBe(
        get('conn-state'),
      )
      expect(!!surface('nav-admin')).toBe(
        viewMode || (user !== null && 'admin' in user),
      )
      expect(!!surface('nav-logout')).toBe(user !== null)
      expect(!!surface('legal-reconsent-icon')).toBe(user !== null && !takeover)
    },
  )
})
