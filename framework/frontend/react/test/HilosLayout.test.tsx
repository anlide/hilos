import { afterEach, describe, expect, it, vi } from 'vitest'
import { StrictMode, type ReactNode } from 'react'
import { createPortal } from 'react-dom'
import { act, cleanup, fireEvent, render } from '@testing-library/react'
import {
  ActionLifecycle,
  applyServerTime,
  bindAccountBlocked,
  bindAccountStanding,
  bindAdminAccess,
  bindImpersonation,
  bindLegalReconsent,
  createSignal,
  DEFAULT_IMPERSONATION_POLICY,
  formatCalendarDate,
  HILOS_IMPERSONATION_STRIP_TEXT_ID,
  HILOS_CONNECTION_STATUS_TEXT_ID,
  HILOS_INTERACTION_BLOCKED_ATTR,
  HILOS_INTERACTION_REGION_ATTR,
  HILOS_VIEW_MODE_STRIP_TEXT_ID,
  HilosPages,
  bindSessionScope,
  bindSignOut,
  bindThemeState,
  hilosToasts,
  IMPERSONATION_ACTION_STOP,
  PROTECTED_MODE_INACTIVE,
  RT_STALENESS_FRESH,
  rtStalenessCompactLabel,
  rtStalenessLabel,
  ScopeManager,
} from '@hilos/core'
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
} from '@hilos/core'

import { THEME_SETTINGS_STORAGE_KEY } from '../../core/src/theme/themeBrowser.js'
import { HilosLayout } from '../src/HilosLayout.js'
import { LoadingButton } from '../src/LoadingButton.js'
import {
  useConnectionInteraction,
  type ConnectionInteraction,
} from '../src/hilosConnectionInteraction.js'
import { HilosAdminViewModeContext } from '../src/hilosLookOnly.js'
import { HilosRouterContext } from '../src/hilosRouterContext.js'

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
  private readonly state: ((state: ConnectionState) => void)[] = []

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
    if (event === 'state') {
      this.state.push(listener as (state: ConnectionState) => void)
    }

    return () => {}
  }

  disconnect(): void {
    for (const listener of this.state) {
      listener('disconnected')
    }
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
  const unbindAdminAccess = bindAdminAccess(scopes)
  const unbindReconsent = bindLegalReconsent(scopes, actions)
  let released = false

  return {
    source,
    async unbind(): Promise<void> {
      if (released) return
      released = true
      // A dropped source settles every pending action and clears both timers.
      // Flush its promise callbacks while React and the DOM still belong to the test.
      await act(async () => {
        source.disconnect()
        unbindStrip()
        unbindCard()
        unbindStanding()
        unbindSignOut()
        unbindAdminAccess()
        unbindReconsent()
      })
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

function renderShell(
  connection: HilosConnection,
  banner?: ReactNode,
): HTMLElement {
  return render(
    <HilosLayout connection={connection} banner={banner}>
      <p data-id="page-body">Page</p>
    </HilosLayout>,
  ).container
}

function surface(container: HTMLElement, id: string): Element | null {
  return container.querySelector(`[data-id="${id}"]`)
}

describe('HilosLayout session fixture lifecycle (HIL-1651)', () => {
  let unbind: ReturnType<typeof bindSession>['unbind'] | undefined

  afterEach(async () => {
    await unbind?.()
    unbind = undefined
    cleanup()
    hilosToasts.clear()
    vi.useRealTimers()
  })

  it.each([0, 300])(
    'settles pending actions at fixture teardown after %d ms',
    async (elapsed) => {
      vi.useFakeTimers()
      const session = bindSession()
      unbind = session.unbind
      session.handshake(TAKEOVER)
      const container = renderShell(shellConnection())
      act(() => {
        fireEvent.click(surface(container, 'impersonation-stop')!)
        fireEvent.click(surface(container, 'nav-logout')!)
        vi.advanceTimersByTime(elapsed)
      })
      expect(session.source.sent.map(({ action }) => action)).toEqual([
        'hilos_impersonate_stop',
        'hilos_logout',
      ])

      await session.unbind()
      cleanup()

      expect(vi.getTimerCount()).toBe(0)
      const settledToasts = hilosToasts.toasts.get()
      expect(settledToasts).toEqual([
        expect.objectContaining({
          severity: 'error',
          message:
            'The connection dropped before the action completed. Please try again.',
          repeats: 2,
        }),
      ])
      await session.unbind()
      await act(async () => {
        vi.advanceTimersByTime(60_000)
      })
      expect(vi.getTimerCount()).toBe(0)
      expect(hilosToasts.toasts.get()).toEqual(settledToasts)
    },
  )
})

describe('HilosLayout impersonation strip', () => {
  let unbind: ReturnType<typeof bindSession>['unbind'] | undefined

  afterEach(async () => {
    await unbind?.()
    unbind = undefined
    cleanup()
    hilosToasts.clear()
  })

  it('draws no strip for a plain session', () => {
    const session = bindSession()
    unbind = session.unbind
    session.handshake({ entities: { currentUser: { id: 1, name: 'Ada' } } })

    const container = renderShell(shellConnection())

    expect(surface(container, 'impersonation-banner')).toBeNull()
  })

  it('names the user the session acts as while impersonated', () => {
    const session = bindSession()
    unbind = session.unbind
    session.handshake(TAKEOVER)

    const container = renderShell(shellConnection())

    const strip = surface(container, 'impersonation-banner')
    expect(strip?.textContent).toContain('You are impersonating')
    expect(strip?.querySelector('strong')?.textContent).toBe('Bob')
    expect(surface(container, 'impersonation-stop')?.textContent).toBe('Stop')
  })

  it('stands between the protected-mode strip and the project strip', () => {
    const session = bindSession()
    unbind = session.unbind
    session.handshake(TAKEOVER)

    const container = renderShell(
      shellConnection(ADMITTED),
      <p data-id="test-banner">A trial notice</p>,
    )

    const order = Array.from(
      surface(container, 'app-banner')?.children ?? [],
    ).map((child) => child.getAttribute('data-id'))
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

    const container = renderShell(shellConnection(FROZEN))

    expect(surface(container, 'impersonation-banner')).toBeNull()
  })

  it('sends Stop tracked and keeps it disabled until the reply settles', async () => {
    const session = bindSession()
    unbind = session.unbind
    session.handshake(TAKEOVER)
    const container = renderShell(shellConnection())
    const stop = surface(container, 'impersonation-stop') as HTMLButtonElement

    act(() => {
      fireEvent.click(stop)
    })

    expect(session.source.sent).toHaveLength(1)
    expect(session.source.sent[0]?.action).toBe('hilos_impersonate_stop')
    expect(session.source.sent[0]?.data).toEqual({})
    expect(session.source.sent[0]?.requestId).toBeTruthy()
    expect(stop.disabled).toBe(false)
    expect(stop.getAttribute('aria-disabled')).toBe('true')
    expect(stop.getAttribute('aria-busy')).toBe('true')
    act(() => {
      fireEvent.click(stop)
    })
    expect(session.source.sent).toHaveLength(1)

    await act(async () => {
      session.source.succeed(session.source.sent[0]?.requestId)
    })

    expect(stop.disabled).toBe(false)
    expect(stop.getAttribute('aria-disabled')).toBeNull()
    expect(stop.getAttribute('aria-busy')).toBeNull()
    expect(hilosToasts.toasts.get()).toEqual([])
  })

  it('leaves the strip standing on a refusal and says why in a toast', async () => {
    const session = bindSession()
    unbind = session.unbind
    session.handshake(TAKEOVER)
    const container = renderShell(shellConnection())
    const stop = surface(container, 'impersonation-stop') as HTMLButtonElement

    act(() => {
      fireEvent.click(stop)
    })
    await act(async () => {
      session.source.refuse(
        session.source.sent[0]?.requestId,
        'Session is not impersonating',
      )
    })

    expect(surface(container, 'impersonation-banner')).not.toBeNull()
    expect(stop.disabled).toBe(false)
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

  /** Render the shell over a page whose one control is a tracked button. */
  function renderOverPageAction(): HTMLElement {
    return render(
      <HilosLayout connection={shellConnection()}>
        <LoadingButton data-id="page-action">Send</LoadingButton>
      </HilosLayout>,
    ).container
  }

  it('says "view only" and switches off the page, never Stop, in a takeover that only looks (HIL-1170)', async () => {
    const session = bindSession()
    unbind = session.unbind
    session.handshake(takeoverWith(true))

    const container = renderOverPageAction()
    const strip = surface(container, 'impersonation-banner')
    const text = strip?.querySelector(`#${HILOS_IMPERSONATION_STRIP_TEXT_ID}`)

    expect(text).not.toBeNull()
    expect(text?.textContent).toContain('You are impersonating')
    expect(text?.querySelector('.badge')?.textContent).toBe('view only')
    expect(strip?.querySelector('strong')?.textContent).toBe('Bob')
    const stop = surface(container, 'impersonation-stop') as HTMLButtonElement
    expect(stop.disabled).toBe(false)
    expect(stop.getAttribute('aria-describedby')).toBeNull()
    const action = surface(container, 'page-action') as HTMLButtonElement
    expect(action.disabled).toBe(true)
    expect(action.getAttribute('aria-describedby')).toBe(
      HILOS_IMPERSONATION_STRIP_TEXT_ID,
    )

    act(() => {
      fireEvent.click(stop)
    })
    expect(session.source.sent[0]?.action).toBe('hilos_impersonate_stop')
    await act(async () => {
      session.source.succeed(session.source.sent[0]?.requestId)
    })
  })

  it('leaves the page live and the strip unmarked while the takeover may act, and follows the policy', () => {
    const session = bindSession()
    unbind = session.unbind
    session.handshake(takeoverWith(false))

    const container = renderOverPageAction()

    expect(
      container.querySelector('[data-id="impersonation-banner"] .badge'),
    ).toBeNull()
    expect(
      (surface(container, 'page-action') as HTMLButtonElement).disabled,
    ).toBe(false)

    act(() => {
      session.handshake(takeoverWith(true))
    })

    expect(
      container.querySelector('[data-id="impersonation-banner"] .badge')
        ?.textContent,
    ).toBe('view only')
    expect(
      (surface(container, 'page-action') as HTMLButtonElement).disabled,
    ).toBe(true)
  })

  it('adds no live region of its own when the strip goes up', async () => {
    // Counted before the strip goes up: both shells read the one store, so the
    // plain one would grow the strip too once the takeover lands.
    const live = '[role="status"][aria-live="polite"]'
    const plain = bindSession()
    plain.handshake({ entities: { currentUser: { id: 1, name: 'Ada' } } })
    const liveWithout =
      renderShell(shellConnection()).querySelectorAll(live).length
    await plain.unbind()
    cleanup()

    const session = bindSession()
    unbind = session.unbind
    session.handshake(TAKEOVER)
    const up = renderShell(shellConnection())

    expect(surface(up, 'impersonation-banner')).not.toBeNull()
    expect(up.querySelectorAll(live).length).toBe(liveWithout)
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
  let unbind: ReturnType<typeof bindSession>['unbind'] | undefined

  afterEach(async () => {
    await unbind?.()
    unbind = undefined
    cleanup()
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

    const container = renderShell(shellConnection())

    const strip = surface(container, 'account-deletion-strip')
    expect(strip).not.toBeNull()
    expect(strip?.classList.contains('alert-warning')).toBe(true)
    expect(strip?.querySelector('.bi-trash')).not.toBeNull()
    expect(
      strip
        ?.querySelector('[data-id="account-deletion-strip-text"]')
        ?.textContent?.trim(),
    ).toBe(
      `Your account will be deleted on ${formatCalendarDate(effectiveAt)} — 12 days left`,
    )
    expect(
      strip?.querySelector('[data-id="account-deletion-strip-keep"]')
        ?.textContent,
    ).toBe('Keep my account')
  })

  it('counts the days left again once a minute', () => {
    vi.useFakeTimers()
    vi.setSystemTime(NOW)
    const session = bindSession()
    unbind = session.unbind
    session.handshake(
      standingHandshake('deletion_scheduled', false, NOW + 2 * DAY_MS),
    )
    const container = renderShell(shellConnection())
    const text = () =>
      surface(container, 'account-deletion-strip-text')?.textContent
    expect(text()).toContain('2 days left')

    act(() => {
      vi.setSystemTime(NOW + 1.5 * DAY_MS)
      vi.advanceTimersByTime(60_000)
    })

    expect(text()).toContain('1 day left')
  })

  it('stands after the framework strips and before the project strip', () => {
    // This session has no takeover, so the protected-mode banner comes first.
    const session = bindSession()
    unbind = session.unbind
    session.handshake(
      standingHandshake('deletion_scheduled', false, NOW + 3 * DAY_MS),
    )

    const container = renderShell(
      shellConnection(ADMITTED),
      <p data-id="test-banner">A trial notice</p>,
    )

    const order = Array.from(
      surface(container, 'app-banner')?.children ?? [],
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

    const taken = renderShell(shellConnection())
    expect(surface(taken, 'impersonation-banner')).not.toBeNull()
    expect(surface(taken, 'account-deletion-strip')).not.toBeNull()
    expect(surface(taken, 'account-deletion-strip-keep')).toBeNull()
    expect(
      surface(taken, 'account-deletion-strip-text')?.textContent,
    ).toContain(
      `Your account will be deleted on ${formatCalendarDate(NOW + 3 * DAY_MS)} — 3 days left`,
    )
  })

  it('draws no deletion strip under maintenance', () => {
    const session = bindSession()
    unbind = session.unbind
    session.handshake(
      standingHandshake('deletion_scheduled', false, NOW + 3 * DAY_MS),
    )
    const frozenNode = renderShell(shellConnection(FROZEN))
    expect(surface(frozenNode, 'account-deletion-strip')).toBeNull()
  })

  it('sends Keep my account tracked, busy until the reply, and toasts nothing on success', async () => {
    const session = bindSession()
    unbind = session.unbind
    session.handshake(
      standingHandshake('deletion_scheduled', false, NOW + 3 * DAY_MS),
    )
    const container = renderShell(shellConnection())
    const keep = surface(
      container,
      'account-deletion-strip-keep',
    ) as HTMLButtonElement

    act(() => {
      fireEvent.click(keep)
    })

    expect(session.source.sent).toHaveLength(1)
    expect(session.source.sent[0]?.action).toBe('hilos_account_deletion_cancel')
    expect(session.source.sent[0]?.data).toEqual({})
    expect(session.source.sent[0]?.requestId).toBeTruthy()
    expect(keep.disabled).toBe(false)
    expect(keep.getAttribute('aria-disabled')).toBe('true')
    expect(keep.getAttribute('aria-busy')).toBe('true')
    act(() => {
      fireEvent.click(keep)
    })
    expect(session.source.sent).toHaveLength(1)

    await act(async () => {
      session.source.succeed(
        session.source.sent[0]?.requestId,
        'hilos_account_deletion_cancel',
      )
      session.handshake(standingHandshake('none'))
    })

    expect(surface(container, 'account-deletion-strip')).toBeNull()
    expect(hilosToasts.toasts.get()).toEqual([])
  })

  it('leaves the strip standing on a refusal and says why in a toast', async () => {
    const session = bindSession()
    unbind = session.unbind
    session.handshake(
      standingHandshake('deletion_scheduled', false, NOW + 3 * DAY_MS),
    )
    const container = renderShell(shellConnection())
    const keep = surface(
      container,
      'account-deletion-strip-keep',
    ) as HTMLButtonElement

    act(() => {
      fireEvent.click(keep)
    })
    await act(async () => {
      session.source.refuse(
        session.source.sent[0]?.requestId,
        'No deletion is scheduled',
        'hilos_account_deletion_cancel',
      )
    })

    expect(surface(container, 'account-deletion-strip')).not.toBeNull()
    expect(keep.disabled).toBe(false)
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

      const container = renderShell(shellConnection())

      const strip = surface(container, 'impersonation-banner')
      expect(strip?.classList.contains(alertClass)).toBe(true)
      expect(strip?.querySelector('strong')?.textContent).toBe('Bob')
    },
  )
})

describe('HilosLayout admin gear', () => {
  let unbind: ReturnType<typeof bindSession>['unbind'] | undefined

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

  function gear(connection: HilosConnection = shellConnection()): {
    container: HTMLElement
    link: Element | null
  } {
    const container = renderShell(connection)

    return { container, link: surface(container, 'nav-admin') }
  }

  afterEach(async () => {
    await unbind?.()
    unbind = undefined
    cleanup()
  })

  it('draws no gear for a guest on a node without the view mode', () => {
    const session = bindSession()
    unbind = session.unbind
    session.handshake(greeting(null, false))

    expect(gear().link).toBeNull()
  })

  it('draws no gear for a signed-in non-admin on a node without the view mode', () => {
    const session = bindSession()
    unbind = session.unbind
    session.handshake(greeting({ id: 7, admin: false }, false))

    expect(gear().link).toBeNull()
  })

  it('draws the full gear for an admin, leading to the dashboard', () => {
    const session = bindSession()
    unbind = session.unbind
    session.handshake(greeting({ id: 7, admin: true }, false))
    const { link } = gear()

    expect(link).not.toBeNull()
    expect(link?.getAttribute('data-access')).toBe('full')
    expect(link?.getAttribute('href')).toBe('/hilos')
    expect(link?.getAttribute('aria-label')).toBe('Hilos dashboard')
    expect(link?.getAttribute('title')).toBeNull()
    expect(link?.querySelector('.visually-hidden')?.textContent).toBe(
      'Hilos dashboard',
    )
    expect(
      link
        ?.querySelector('.bi-gear-fill')
        ?.classList.contains('text-info-emphasis'),
    ).toBe(false)
    expect(
      link?.querySelector('.bi-gear-fill')?.getAttribute('aria-hidden'),
    ).toBe('true')
    expect(link?.querySelectorAll('.bi-eye')).toHaveLength(0)
  })

  it.each([
    ['a guest', null],
    ['a signed-in non-admin', { id: 7, admin: false }],
  ] as const)(
    'draws the view gear for %s on a node in the view mode',
    (_who, user) => {
      const session = bindSession()
      unbind = session.unbind
      session.handshake(greeting(user, true))
      const { link } = gear()

      expect(link).not.toBeNull()
      expect(link?.getAttribute('data-access')).toBe('view')
      expect(link?.getAttribute('href')).toBe('/hilos')
      expect(link?.getAttribute('aria-label')).toBe(
        'Hilos dashboard — View mode',
      )
      expect(link?.getAttribute('title')).toBe('Hilos dashboard — View mode')
      expect(link?.querySelector('.visually-hidden')?.textContent).toBe(
        'Hilos dashboard — View mode',
      )
      expect(
        link
          ?.querySelector('.bi-gear-fill')
          ?.classList.contains('text-info-emphasis'),
      ).toBe(true)
      expect(
        link?.querySelector('.bi-gear-fill')?.getAttribute('aria-hidden'),
      ).toBe('true')
      expect(link?.querySelectorAll('.bi-eye')).toHaveLength(1)
      expect(link?.querySelector('.bi-eye')?.getAttribute('aria-hidden')).toBe(
        'true',
      )
    },
  )

  it('turns the gear full on a grant and back to view on a revoke, live', () => {
    const session = bindSession()
    unbind = session.unbind
    session.handshake(greeting({ id: 7, admin: false }, true))
    const { container } = gear()

    act(() => {
      session.handshake(greeting({ id: 7, admin: true }, true))
    })
    const fullLinks = container.querySelectorAll('[data-id="nav-admin"]')
    expect(fullLinks).toHaveLength(1)
    const fullLink = fullLinks[0]
    expect(fullLink.getAttribute('data-access')).toBe('full')
    expect(fullLink.getAttribute('aria-label')).toBe('Hilos dashboard')
    expect(fullLink.getAttribute('title')).toBeNull()
    expect(fullLink.querySelector('.visually-hidden')?.textContent).toBe(
      'Hilos dashboard',
    )
    expect(
      fullLink
        .querySelector('.bi-gear-fill')
        ?.classList.contains('text-info-emphasis'),
    ).toBe(false)
    expect(fullLink.querySelectorAll('.bi-eye')).toHaveLength(0)

    act(() => {
      session.handshake(greeting({ id: 7, admin: false }, true))
    })
    const viewLinks = container.querySelectorAll('[data-id="nav-admin"]')
    expect(viewLinks).toHaveLength(1)
    const viewLink = viewLinks[0]
    expect(viewLink.getAttribute('data-access')).toBe('view')
    expect(viewLink.getAttribute('aria-label')).toBe(
      'Hilos dashboard — View mode',
    )
    expect(viewLink.getAttribute('title')).toBe('Hilos dashboard — View mode')
    expect(viewLink.querySelector('.visually-hidden')?.textContent).toBe(
      'Hilos dashboard — View mode',
    )
    expect(
      viewLink
        .querySelector('.bi-gear-fill')
        ?.classList.contains('text-info-emphasis'),
    ).toBe(true)
    expect(viewLink.querySelectorAll('.bi-eye')).toHaveLength(1)
    expect(viewLink.querySelector('.bi-eye')?.getAttribute('aria-hidden')).toBe(
      'true',
    )
  })

  it('draws no gear under the maintenance surface', () => {
    const session = bindSession()
    unbind = session.unbind
    session.handshake(greeting(null, true))

    expect(gear(shellConnection(FROZEN)).link).toBeNull()
  })
})

describe('HilosLayout sign-out', () => {
  let unbind: ReturnType<typeof bindSession>['unbind'] | undefined

  afterEach(async () => {
    await unbind?.()
    unbind = undefined
    cleanup()
    hilosToasts.clear()
  })

  it('draws no control for an anonymous session', () => {
    const session = bindSession()
    unbind = session.unbind
    session.handshake({ entities: { currentUser: null } })

    expect(surface(renderShell(shellConnection()), 'nav-logout')).toBeNull()
  })

  it.each([{ entities: { currentUser: { id: 1, name: '' } } }, TAKEOVER])(
    'draws the named control before the final connection indicator, also while impersonated: %j',
    (payload) => {
      const session = bindSession()
      unbind = session.unbind
      session.handshake(payload)
      const container = renderShell(shellConnection())
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
      surface(renderShell(shellConnection(FROZEN)), 'nav-logout'),
    ).toBeNull()
  })

  it('draws no control during the held first frame', () => {
    const session = bindSession()
    unbind = session.unbind
    session.handshake(TAKEOVER)
    const connection = shellConnection()
    Object.defineProperty(connection, 'firstFrameHeld', { value: true })

    expect(surface(renderShell(connection), 'nav-logout')).toBeNull()
  })

  it('sends sign-out tracked once and stays disabled until a silent success', async () => {
    const session = bindSession()
    unbind = session.unbind
    session.handshake(TAKEOVER)
    const container = renderShell(shellConnection())
    const button = surface(container, 'nav-logout') as HTMLButtonElement

    act(() => {
      fireEvent.click(button)
    })
    expect(button.disabled).toBe(false)
    expect(button.getAttribute('aria-disabled')).toBe('true')
    expect(button.getAttribute('aria-busy')).toBe('true')
    expect(surface(container, 'loading-button-spinner')).toBeNull()
    act(() => {
      fireEvent.click(button)
    })
    expect(session.source.sent).toEqual([
      { action: 'hilos_logout', data: {}, requestId: expect.any(String) },
    ])
    expect(session.source.sent[0]?.requestId).toBeTruthy()

    await act(async () => {
      session.source.succeed(session.source.sent[0]?.requestId, 'hilos_logout')
    })

    expect(button.disabled).toBe(false)
    expect(button.getAttribute('aria-disabled')).toBeNull()
    expect(button.getAttribute('aria-busy')).toBeNull()
    expect(hilosToasts.toasts.get()).toEqual([])
  })

  it('leaves the control ready on a refusal and toasts the server reason', async () => {
    const session = bindSession()
    unbind = session.unbind
    session.handshake(TAKEOVER)
    const container = renderShell(shellConnection())
    const button = surface(container, 'nav-logout') as HTMLButtonElement

    act(() => {
      fireEvent.click(button)
    })
    await act(async () => {
      session.source.refuse(
        session.source.sent[0]?.requestId,
        'Session not on connection',
        'hilos_logout',
      )
    })

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
    const container = renderShell(shellConnection())

    act(() => {
      fireEvent.click(surface(container, 'nav-logout') as HTMLButtonElement)
    })
    act(() => {
      session.handshake({
        entities: { currentUser: null, impersonatedBy: null },
      })
    })
    expect(surface(container, 'nav-logout')).toBeNull()

    await act(async () => {
      session.source.succeed(session.source.sent[0]?.requestId, 'hilos_logout')
    })
    expect(hilosToasts.toasts.get()).toEqual([])
  })
})

/** The anonymous handshake a browser gets after its account was blocked. */
const BLOCKED = {
  entities: { currentUser: null },
  data: { accountBlocked: { identifier: 'maria@example.com' } },
}

describe('HilosLayout account blocked card', () => {
  let unbind: ReturnType<typeof bindSession>['unbind'] | undefined

  afterEach(async () => {
    await unbind?.()
    unbind = undefined
    cleanup()
    hilosToasts.clear()
  })

  it('stands in place of the content and keeps the header and footer', () => {
    const session = bindSession()
    unbind = session.unbind
    session.handshake(BLOCKED)

    const container = renderShell(shellConnection())

    expect(surface(container, 'account-blocked')).not.toBeNull()
    expect(surface(container, 'data-export-prepare')).not.toBeNull()
    expect(surface(container, 'page-body')).toBeNull()
    expect(surface(container, 'app-footer')).not.toBeNull()
    expect(container.querySelector('nav')).not.toBeNull()
    expect(surface(container, 'account-blocked-heading')?.textContent).toBe(
      'Access closed',
    )
    expect(surface(container, 'account-blocked-account')?.textContent).toBe(
      'The account maria@example.com has been blocked by the project administration.',
    )
    expect(surface(container, 'account-blocked-reason')?.textContent).toContain(
      'No reason given',
    )
  })

  it('says "This account" when the server could name no address', () => {
    const session = bindSession()
    unbind = session.unbind
    session.handshake({ data: { accountBlocked: { identifier: null } } })

    const container = renderShell(shellConnection())

    expect(surface(container, 'account-blocked-account')?.textContent).toBe(
      'This account has been blocked by the project administration.',
    )
  })

  it('sends Sign out tracked and keeps it disabled until the reply settles', async () => {
    const session = bindSession()
    unbind = session.unbind
    session.handshake(BLOCKED)
    const container = renderShell(shellConnection())
    const signOut = surface(
      container,
      'account-blocked-sign-out',
    ) as HTMLButtonElement

    act(() => {
      fireEvent.click(signOut)
    })

    expect(session.source.sent).toHaveLength(1)
    expect(session.source.sent[0]?.action).toBe('hilos_dismiss_account_blocked')
    expect(session.source.sent[0]?.data).toEqual({})
    expect(session.source.sent[0]?.requestId).toBeTruthy()
    expect(signOut.disabled).toBe(false)
    expect(signOut.getAttribute('aria-disabled')).toBe('true')
    expect(signOut.getAttribute('aria-busy')).toBe('true')
    act(() => {
      fireEvent.click(signOut)
    })
    expect(session.source.sent).toHaveLength(1)

    await act(async () => {
      session.source.succeed(session.source.sent[0]?.requestId)
    })

    expect(signOut.disabled).toBe(false)
    expect(signOut.getAttribute('aria-disabled')).toBeNull()
    expect(signOut.getAttribute('aria-busy')).toBeNull()
  })

  it('gives the content back when a handshake carries no card', () => {
    const session = bindSession()
    unbind = session.unbind
    session.handshake(BLOCKED)
    const container = renderShell(shellConnection())

    act(() => {
      session.handshake({ data: { accountBlocked: null } })
    })

    expect(surface(container, 'account-blocked')).toBeNull()
    expect(surface(container, 'page-body')).not.toBeNull()
  })

  it('gives way to the maintenance surface', () => {
    const session = bindSession()
    unbind = session.unbind
    session.handshake(BLOCKED)

    const container = renderShell(shellConnection(FROZEN))

    expect(surface(container, 'account-blocked')).toBeNull()
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
function routerOn(page: string | null, admin = false): HilosRouter {
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
  let unbind: ReturnType<typeof bindSession>['unbind'] | undefined
  const inWindow = { window: [{ document: 'terms', deadline: '2026-11-10' }] }
  const lapsed = { lapsed: [{ document: 'terms', deadline: '2026-09-01' }] }
  const windowShown = (): boolean =>
    document.body.querySelector('[data-id="legal-reconsent-modal"]') !== null

  afterEach(async () => {
    await unbind?.()
    unbind = undefined
    cleanup()
    vi.useRealTimers()
  })

  it('draws the yellow document icon after the user region with the days left', () => {
    vi.useFakeTimers()
    vi.setSystemTime(Date.UTC(2026, 10, 1))
    const session = bindSession()
    unbind = session.unbind
    session.handshake(reconsentHandshake(inWindow))

    const container = renderShell(shellConnection())

    const icon = surface(container, 'legal-reconsent-icon')
    expect(icon?.classList.contains('text-warning')).toBe(true)
    expect(icon?.querySelector('.bi-file-earmark-text')).not.toBeNull()
    expect(icon?.getAttribute('title')).toBe(
      'The terms have changed — 9 days left to decide',
    )
    expect(windowShown()).toBe(false)
  })

  it('says "please review them" for a lapsed document under the remind setting', () => {
    const session = bindSession()
    unbind = session.unbind
    session.handshake(reconsentHandshake(lapsed))

    const container = renderShell(shellConnection())

    expect(
      surface(container, 'legal-reconsent-icon')?.getAttribute('title'),
    ).toBe('The terms have changed — please review them')
    expect(surface(container, 'legal-frozen-screen')).toBeNull()
  })

  it('raises the window on a sign-in in this tab and opens it from the icon after Later', async () => {
    const session = bindSession()
    unbind = session.unbind
    session.handshake(reconsentHandshake(null))
    const container = renderShell(shellConnection())
    expect(surface(container, 'legal-reconsent-icon')).toBeNull()

    await act(async () => {
      session.handshake(reconsentHandshake(inWindow))
    })

    expect(windowShown()).toBe(true)
    expect(session.source.sent.at(-1)?.action).toBe('hilos_legal_reconsent')
    await act(async () => {
      fireEvent.click(
        document.body.querySelector('[data-id="legal-reconsent-later"]')!,
      )
    })
    expect(windowShown()).toBe(false)
    await act(async () => {
      fireEvent.click(surface(container, 'legal-reconsent-icon')!)
    })
    expect(windowShown()).toBe(true)
  })

  it('puts the freeze screen in place of the content with no icon', async () => {
    const session = bindSession()
    unbind = session.unbind
    session.handshake(reconsentHandshake({ ...lapsed, frozen: true }))

    let container!: HTMLElement
    await act(async () => {
      container = renderShell(shellConnection())
    })

    const screen = surface(container, 'legal-frozen-screen')
    expect(screen?.querySelector('.bi-snow')).not.toBeNull()
    expect(
      screen
        ?.querySelector('[data-id="legal-reconsent"]')
        ?.getAttribute('data-variant'),
    ).toBe('frozen')
    expect(surface(container, 'page-body')).toBeNull()
    expect(surface(container, 'app-footer')).not.toBeNull()
    expect(surface(container, 'legal-reconsent-icon')).toBeNull()
    expect(session.source.sent.at(-1)?.action).toBe('hilos_legal_reconsent')
  })

  it('leaves the pages the freeze keeps open to their content', () => {
    const session = bindSession()
    unbind = session.unbind
    session.handshake(reconsentHandshake({ ...lapsed, frozen: true }))

    const { container } = render(
      <HilosRouterContext.Provider value={routerOn(HilosPages.PROFILE_DATA)}>
        <HilosLayout connection={shellConnection()}>
          <p data-id="page-body">Page</p>
        </HilosLayout>
      </HilosRouterContext.Provider>,
    )

    expect(surface(container, 'legal-frozen-screen')).toBeNull()
    expect(surface(container, 'page-body')).not.toBeNull()
  })

  it('covers an address with no page with the freeze screen', () => {
    const session = bindSession()
    unbind = session.unbind
    session.handshake(reconsentHandshake({ ...lapsed, frozen: true }))

    const { container } = render(
      <HilosRouterContext.Provider value={routerOn(null)}>
        <HilosLayout connection={shellConnection()}>
          <p data-id="page-body">Page</p>
        </HilosLayout>
      </HilosRouterContext.Provider>,
    )

    expect(surface(container, 'legal-frozen-screen')).not.toBeNull()
    expect(surface(container, 'page-body')).toBeNull()
  })

  it('draws no icon under the maintenance surface', () => {
    const session = bindSession()
    unbind = session.unbind
    session.handshake(reconsentHandshake(inWindow))

    const container = renderShell(shellConnection(FROZEN))

    expect(surface(container, 'legal-reconsent-icon')).toBeNull()
  })
})

describe('HilosLayout view-mode strip (HIL-1260)', () => {
  let unbind: ReturnType<typeof bindSession>['unbind'] | undefined

  afterEach(async () => {
    await unbind?.()
    unbind = undefined
    cleanup()
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
   * Render the shell on one route.
   *
   * @param admin Whether the route is an admin one.
   * @param connection The shell's connection.
   * @param banner The project's own strip, if any.
   */
  function renderOn(
    admin: boolean,
    connection: HilosConnection = shellConnection(),
    banner?: ReactNode,
  ): HTMLElement {
    return render(
      <HilosRouterContext.Provider
        value={routerOn(
          admin ? HilosPages.DASHBOARD : HilosPages.PROFILE_DATA,
          admin,
        )}
      >
        <HilosLayout connection={connection} banner={banner}>
          <p data-id="page-body">Page</p>
        </HilosLayout>
      </HilosRouterContext.Provider>,
    ).container
  }

  it('tells a viewer on an admin route that the screen may be looked at and not changed', () => {
    const session = bindSession()
    unbind = session.unbind
    session.handshake(viewerHandshake(false, true))

    const strip = surface(renderOn(true), 'view-mode-banner')!

    expect(strip.classList.contains('alert')).toBe(true)
    expect(strip.classList.contains('alert-secondary')).toBe(true)
    expect(strip.classList.contains('border-0')).toBe(true)
    expect(strip.querySelector('.bi-eye')?.getAttribute('aria-hidden')).toBe(
      'true',
    )
    const text = strip.querySelector(`#${HILOS_VIEW_MODE_STRIP_TEXT_ID}`)!
    expect(text.querySelector('strong')?.textContent).toBe('View mode')
    expect(text.textContent?.trim()).toBe(
      'View mode · You can look around, but not change anything.',
    )
  })

  it('stands after the session strips and before the project strip', () => {
    const session = bindSession()
    unbind = session.unbind
    session.handshake(viewerHandshake(false, true, NOW + 3 * DAY_MS))

    const container = renderOn(
      true,
      shellConnection(ADMITTED),
      <p data-id="test-banner">A trial notice</p>,
    )

    const order = Array.from(
      surface(container, 'app-banner')?.children ?? [],
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
  ] as const)('draws no strip %s', (_case, admin, viewMode, adminRoute) => {
    const session = bindSession()
    unbind = session.unbind
    session.handshake(viewerHandshake(admin, viewMode))

    expect(surface(renderOn(adminRoute), 'view-mode-banner')).toBeNull()
  })

  it('draws no strip under the maintenance surface', () => {
    const session = bindSession()
    unbind = session.unbind
    session.handshake(viewerHandshake(false, true))

    expect(
      surface(renderOn(true, shellConnection(FROZEN)), 'view-mode-banner'),
    ).toBeNull()
  })

  it('leaves on a grant and comes back on a revoke, live', () => {
    const session = bindSession()
    unbind = session.unbind
    session.handshake(viewerHandshake(false, true))
    const container = renderOn(true)
    const shown = () => surface(container, 'view-mode-banner') !== null
    expect(shown()).toBe(true)

    act(() => {
      session.handshake(viewerHandshake(true, true))
    })
    expect(shown()).toBe(false)

    act(() => {
      session.handshake(viewerHandshake(false, true))
    })
    expect(shown()).toBe(true)
  })
})

describe('HilosLayout theme icon (HIL-1440)', () => {
  let unbind: ReturnType<typeof bindSession>['unbind'] | undefined
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

  function renderThemed(connection: HilosConnection): HTMLElement {
    return render(
      <HilosLayout
        connection={connection}
        user={<span data-id="user-slot">Ada</span>}
      >
        <p data-id="page-body">Page</p>
      </HilosLayout>,
    ).container
  }

  afterEach(async () => {
    await unbind?.()
    unbind = undefined
    cleanup()
    releaseTheme?.()
    releaseTheme = undefined
    vi.unstubAllGlobals()
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
      unbind = session.unbind
      session.handshake({ entities: { currentUser: user } })
      const container = renderThemed(shellConnection())
      const group = container.querySelector(
        'nav .d-flex.align-items-center.gap-2',
      ) as HTMLElement
      const theme = surface(group, 'nav-theme') as HTMLElement

      expect(group.firstElementChild?.contains(theme)).toBe(true)
      expect(
        theme.compareDocumentPosition(
          surface(group, 'user-slot') as HTMLElement,
        ) & Node.DOCUMENT_POSITION_FOLLOWING,
      ).not.toBe(0)
      expect(theme.getAttribute('aria-label')).toBe('Theme: System')
    },
  )

  it('draws no theme icon under maintenance', () => {
    const container = renderThemed(shellConnection(FROZEN))

    expect(surface(container, 'maintenance')).not.toBeNull()
    expect(surface(container, 'nav-theme')).toBeNull()
    expect(surface(container, 'conn-state')).not.toBeNull()
  })

  it('draws no theme icon during the held first frame', () => {
    const connection = shellConnection()
    Object.defineProperty(connection, 'firstFrameHeld', { value: true })
    const container = renderThemed(connection)

    expect(surface(container, 'nav-theme')).toBeNull()
    expect(
      surface(container, 'hilos-boot-state')?.getAttribute('data-state'),
    ).toBe('held')
  })

  it('draws no theme icon when switching is off', () => {
    bindSnapshot(false)
    const container = renderThemed(shellConnection())

    expect(surface(container, 'nav-brand')).not.toBeNull()
    expect(surface(container, 'nav-theme')).toBeNull()
  })

  it('keeps the icon in a takeover but closes it to writes', () => {
    const session = bindSession()
    unbind = session.unbind
    session.handshake(TAKEOVER)
    const container = renderThemed(shellConnection())
    const theme = surface(container, 'nav-theme') as HTMLButtonElement

    expect(theme.disabled).toBe(true)
    expect(theme.getAttribute('aria-describedby')).toBe(
      HILOS_IMPERSONATION_STRIP_TEXT_ID,
    )
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

/** Mount the real node prop seam, retaining the shell while the host changes. */
function renderResponsive(
  feed: ReturnType<typeof shellFeed>,
  initiallyVisible = true,
) {
  let bound = feed.connection
  let visible = initiallyVisible
  const route = createSignal<PageRouteMatch>({
    page: null,
    params: {},
    admin: false,
  })
  const router = { ...routerOn(null), currentRoute: route } as HilosRouter
  const draw = () => (
    <HilosRouterContext.Provider value={router}>
      <HilosLayout
        connection={bound}
        nav={
          visible ? (
            <>
              <a href="/hidden" hidden>
                Hidden
              </a>
              <a href="/first" data-id="project-nav-first">
                First
              </a>
              <a href="/second" data-id="project-nav-second">
                Second
              </a>
            </>
          ) : null
        }
        user={
          <>
            <span data-id="user-bell">Bell</span>
            <span data-id="user-avatar">Avatar</span>
            <a href="/signin" data-id="user-signin">
              Sign in
            </a>
          </>
        }
      >
        <p>Page</p>
      </HilosLayout>
    </HilosRouterContext.Provider>
  )
  const view = render(draw())
  const root = view.container
  root.addEventListener('click', (event) => event.preventDefault())
  const settle = async (): Promise<void> => {
    await act(async () => {
      await Promise.resolve()
    })
  }
  const update = async (run: () => void): Promise<void> => {
    await act(async () => {
      run()
      await Promise.resolve()
    })
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
        visible = value
        view.rerender(draw())
      }),
    connection: (value: HilosConnection) =>
      update(() => {
        bound = value
        view.rerender(draw())
      }),
    dispose(): void {
      view.unmount()
    },
  }
}

describe('HilosLayout responsive shell (HIL-1647)', () => {
  let shell: ReturnType<typeof renderResponsive> | undefined
  let releaseSession: ReturnType<typeof bindSession>['unbind'] | undefined
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

  afterEach(async () => {
    await releaseSession?.()
    releaseSession = undefined
    shell?.dispose()
    shell = undefined
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

/** A context consumer placed in the page, shell user region and a real portal. */
function InteractionProbe({
  capture,
}: {
  capture: (value: ConnectionInteraction) => void
}) {
  capture(useConnectionInteraction())
  return null
}

/** The actual shell retained across input changes, including StrictMode replay. */
function renderInteraction(feed: ReturnType<typeof shellFeed>, allow = false) {
  let bound = feed.connection
  let allowed = allow
  let ownDisabled = false
  let ownDescription = 'validation'
  const interactions = new Map<string, ConnectionInteraction>()
  const clicks = vi.fn()
  const capture = (place: string) => (value: ConnectionInteraction) => {
    interactions.set(place, value)
  }
  const router = routerOn(null, true)
  const draw = () => (
    <StrictMode>
      <HilosRouterContext.Provider value={router}>
        <HilosLayout
          connection={bound}
          allowDisconnectedInteraction={allowed}
          nav={<a href="/next">Next</a>}
          user={
            <>
              <InteractionProbe capture={capture('shell')} />
              {createPortal(
                <InteractionProbe capture={capture('portal')} />,
                document.body,
              )}
            </>
          }
        >
          <InteractionProbe capture={capture('page')} />
          <HilosAdminViewModeContext.Provider value={true}>
            <LoadingButton data-id="page-view" aria-describedby="validation">
              View
            </LoadingButton>
          </HilosAdminViewModeContext.Provider>
          <input
            data-id="draft"
            aria-describedby={ownDescription}
            disabled={ownDisabled}
          />
          <input type="file" data-id="file" />
          <select data-id="choice" defaultValue="a">
            <option value="a">A</option>
            <option value="b">B</option>
          </select>
          <LoadingButton opensWindow onClick={clicks} data-id="page-open">
            Open
          </LoadingButton>
          <LoadingButton onClick={clicks} data-id="page-mutate">
            Save
          </LoadingButton>
          <a href="/action" onClick={clicks} data-id="page-link">
            Action
          </a>
        </HilosLayout>
      </HilosRouterContext.Provider>
    </StrictMode>
  )
  const view = render(draw())
  const settle = async () => {
    await act(async () => {
      await Promise.resolve()
    })
  }
  const update = async (run: () => void) => {
    await act(async () => {
      run()
      await Promise.resolve()
    })
  }
  return {
    root: view.container,
    interactions,
    clicks,
    settle,
    update,
    configure: (options: {
      connection?: HilosConnection
      allow?: boolean
      disabled?: boolean
      description?: string
    }) =>
      update(() => {
        bound = options.connection ?? bound
        allowed = options.allow ?? allowed
        ownDisabled = options.disabled ?? ownDisabled
        ownDescription = options.description ?? ownDescription
        view.rerender(draw())
      }),
    dispose() {
      view.unmount()
    },
  }
}

describe('HilosLayout connection interaction (HIL-1651)', () => {
  let shell: ReturnType<typeof renderInteraction> | undefined
  let unbind: ReturnType<typeof bindSession>['unbind'] | undefined
  afterEach(async () => {
    await unbind?.()
    unbind = undefined
    shell?.dispose()
    shell = undefined
    cleanup()
    hilosToasts.clear()
  })
  const get = <T extends HTMLElement = HTMLElement>(id: string): T =>
    shell!.root.querySelector<T>(`[data-id="${id}"]`)!

  it('keeps the main, fieldset, draft, file, choice and scroll through every unavailable state', async () => {
    const feed = shellFeed()
    shell = renderInteraction(feed)
    const main = shell.root.querySelector('main')!
    const fieldset = get<HTMLFieldSetElement>('page-interaction-scope')
    const draft = get<HTMLInputElement>('draft')
    const file = get<HTMLInputElement>('file')
    const files = Object.freeze([new File(['draft'], 'draft.txt')])
    Object.defineProperty(file, 'files', { value: files })
    draft.value = 'Keep this draft'
    draft.setSelectionRange(2, 6)
    get<HTMLSelectElement>('choice').value = 'b'
    main.scrollTop = 137
    for (const state of [
      'connecting',
      'reconnecting',
      'disconnected',
    ] as const) {
      await shell.update(() => {
        feed.push({ state }, 'state')
        expect(fieldset.disabled).toBe(true)
      })
      expect(get('draft')).toBe(draft)
      expect(draft.matches(':disabled')).toBe(true)
      expect(get('page-open').matches(':disabled')).toBe(true)
      await shell.update(() => {
        get('page-open').click()
        get('page-link').click()
      })
      expect(shell.clicks).not.toHaveBeenCalled()
      expect(get('page-view').getAttribute('aria-describedby')).toBe(
        `validation ${HILOS_VIEW_MODE_STRIP_TEXT_ID} ${HILOS_CONNECTION_STATUS_TEXT_ID}`,
      )
      expect(draft.getAttribute('aria-describedby')).toBe(
        `validation ${HILOS_CONNECTION_STATUS_TEXT_ID}`,
      )
      expect(
        shell.root.querySelectorAll(`#${HILOS_CONNECTION_STATUS_TEXT_ID}`),
      ).toHaveLength(1)
    }
    await shell.configure({ disabled: true, description: 'new-validation' })
    await shell.update(() => {
      feed.push({ state: 'connected' }, 'state')
      feed.push(
        { rtStaleness: { stale: true, since: undefined } },
        'rtStaleness',
      )
    })
    expect(shell.root.querySelector('main')).toBe(main)
    expect(get('page-interaction-scope')).toBe(fieldset)
    expect(fieldset.disabled).toBe(false)
    expect(draft.disabled).toBe(true)
    expect(get<HTMLButtonElement>('page-view').disabled).toBe(true)
    expect(get('page-view').getAttribute('aria-describedby')).toBe(
      `validation ${HILOS_VIEW_MODE_STRIP_TEXT_ID}`,
    )
    expect(draft.getAttribute('aria-describedby')).toBe('new-validation')
    expect(draft.value).toBe('Keep this draft')
    expect([draft.selectionStart, draft.selectionEnd]).toEqual([2, 6])
    expect(file.files).toBe(files)
    expect(get<HTMLSelectElement>('choice').value).toBe('b')
    expect(main.scrollTop).toBe(137)
    expect(shell.clicks).not.toHaveBeenCalled()
    await shell.update(() => get('page-open').click())
    expect(shell.clicks).toHaveBeenCalledTimes(1)
  })

  it('shares one core state through page, shell and portal, with a readonly standalone fallback', async () => {
    let outside: ConnectionInteraction | undefined
    const probe = render(
      <InteractionProbe
        capture={(value) => {
          outside = value
        }}
      />,
    )
    expect(outside!.unavailable.get()).toBe(false)
    expect(outside!.blocked.get()).toBe(false)
    expect('set' in outside!.blocked).toBe(false)
    probe.unmount()
    const feed = shellFeed()
    shell = renderInteraction(feed)
    expect(new Set(shell.interactions.values()).size).toBe(1)
    expect([...shell.interactions.keys()].sort()).toEqual([
      'page',
      'portal',
      'shell',
    ])
    await shell.update(() => feed.push({ state: 'disconnected' }, 'state'))
    expect(shell.interactions.get('page')!.unavailable.get()).toBe(true)
    const independent = renderInteraction(shellFeed())
    expect(independent.interactions.get('page')!.blocked.get()).toBe(false)
    independent.dispose()
  })

  it('updates opt-out and the connection live, releases replaced sources and remounts cleanly', async () => {
    const old = shellFeed()
    old.push({ state: 'disconnected' }, 'state')
    shell = renderInteraction(old)
    const fieldset = get<HTMLFieldSetElement>('page-interaction-scope')
    await shell.configure({ allow: true })
    expect(fieldset.disabled).toBe(false)
    expect(shell.interactions.get('page')!.unavailable.get()).toBe(true)
    const next = shellFeed()
    await shell.configure({ allow: false, connection: next.connection })
    expect(fieldset.disabled).toBe(false)
    await shell.update(() => old.push({ state: 'connecting' }, 'state'))
    expect(fieldset.disabled).toBe(false)
    expect(old.listeners.get('state')?.size).toBe(0)
    await shell.update(() => next.push({ state: 'reconnecting' }, 'state'))
    expect(fieldset.disabled).toBe(true)
    shell.dispose()
    shell = undefined
    expect([...next.listeners.values()].every((set) => set.size === 0)).toBe(
      true,
    )
    shell = renderInteraction(next)
    expect(get<HTMLFieldSetElement>('page-interaction-scope').disabled).toBe(
      true,
    )
  })

  it.each(['nav-logout', 'impersonation-stop', 'account-deletion-strip-keep'])(
    'guards %s before render, independently of page opt-out and busy',
    async (id) => {
      const session = bindSession()
      unbind = session.unbind
      session.handshake(
        id === 'impersonation-stop'
          ? TAKEOVER
          : standingHandshake('deletion_scheduled', false, Date.now() + DAY_MS),
      )
      const feed = shellFeed()
      shell = renderInteraction(feed, true)
      const button = get<HTMLButtonElement>(id)
      // The old React click closure is still mounted within this batch. The core
      // state has already changed, so only the handler's synchronous guard can stop it.
      await shell.update(() => {
        feed.push({ state: 'reconnecting' }, 'state')
        button.click()
        expect(session.source.sent).toHaveLength(0)
      })
      expect(button.disabled).toBe(true)
      expect(button.getAttribute('aria-busy')).toBeNull()
      expect(button.getAttribute('aria-describedby')).toBe(
        HILOS_CONNECTION_STATUS_TEXT_ID,
      )
      expect(get<HTMLFieldSetElement>('page-interaction-scope').disabled).toBe(
        false,
      )
      expect(get('nav-brand').hasAttribute('aria-disabled')).toBe(false)
      expect(
        get('app-footer').querySelector('a')!.hasAttribute('aria-disabled'),
      ).toBe(false)
      await shell.update(() => button.click())
      expect(session.source.sent).toHaveLength(0)
      await shell.update(() => feed.push({ state: 'connected' }, 'state'))
      await shell.update(() => button.click())
      expect(session.source.sent).toHaveLength(1)
      await shell.update(() => feed.push({ state: 'disconnected' }, 'state'))
      await shell.update(() => feed.push({ state: 'connected' }, 'state'))
      expect(button.getAttribute('aria-busy')).toBe('true')
      await shell.update(() => button.click())
      expect(session.source.sent).toHaveLength(1)
      await shell.update(() => {
        const sent = session.source.sent[0]!
        session.source.succeed(sent.requestId, sent.action)
      })
      expect(button.getAttribute('aria-busy')).toBeNull()
      expect(button.getAttribute('aria-disabled')).toBeNull()
      expect(hilosToasts.toasts.get()).toEqual([])
    },
  )

  it('releases only the connection cause while takeover restrictions still apply', async () => {
    const session = bindSession()
    unbind = session.unbind
    session.handshake({
      ...TAKEOVER,
      data: {
        impersonationPolicy: {
          ...DEFAULT_IMPERSONATION_POLICY,
          viewOnly: true,
        },
      },
    })
    const feed = shellFeed()
    shell = renderInteraction(feed)
    const action = get<HTMLButtonElement>('page-mutate')
    expect(action.disabled).toBe(true)
    await shell.update(() => feed.push({ state: 'reconnecting' }, 'state'))
    expect(action.getAttribute('aria-describedby')).toBe(
      `${HILOS_IMPERSONATION_STRIP_TEXT_ID} ${HILOS_CONNECTION_STATUS_TEXT_ID}`,
    )
    await shell.update(() => feed.push({ state: 'connected' }, 'state'))
    expect(action.disabled).toBe(true)
    expect(action.getAttribute('aria-describedby')).toBe(
      HILOS_IMPERSONATION_STRIP_TEXT_ID,
    )
    expect(get<HTMLButtonElement>('page-open').disabled).toBe(false)
    expect(get<HTMLButtonElement>('impersonation-stop').disabled).toBe(false)
  })

  it('leaves shell navigation, dashboard links and mobile disclosure available during a drop', async () => {
    const session = bindSession()
    unbind = session.unbind
    session.handshake({
      entities: { currentUser: { id: 2, name: 'Bob', admin: true } },
    })
    const feed = shellFeed()
    shell = renderInteraction(feed)
    await shell.settle()
    await shell.update(() => feed.push({ state: 'disconnected' }, 'state'))
    for (const id of ['nav-brand', 'nav-admin']) {
      expect(get(id).hasAttribute('aria-disabled')).toBe(false)
      expect(get(id).closest('fieldset')).toBeNull()
    }
    const toggle = get<HTMLButtonElement>('nav-toggle')
    expect(toggle.disabled).toBe(false)
    await shell.update(() => toggle.click())
    expect(toggle.getAttribute('aria-expanded')).toBe('true')
    expect(get('app-footer').closest('fieldset')).toBeNull()
    expect(get<HTMLFieldSetElement>('page-interaction-scope').disabled).toBe(
      true,
    )
    const main = shell.root.querySelector('main')!
    await shell.update(() =>
      feed.push({ firstFrameHeld: true }, 'firstFrameHold'),
    )
    expect(shell.root.querySelector('main')).toBeNull()
    expect(main.hasAttribute(HILOS_INTERACTION_REGION_ATTR)).toBe(false)
    await shell.update(() =>
      feed.push({ firstFrameHeld: false }, 'firstFrameHold'),
    )
    expect(get<HTMLFieldSetElement>('page-interaction-scope').disabled).toBe(
      true,
    )
  })

  it.each(['blocked', 'frozen'])(
    'gates the %s account branch without replacing it on reconnect',
    async (branch) => {
      const session = bindSession()
      unbind = session.unbind
      session.handshake(
        branch === 'blocked'
          ? BLOCKED
          : reconsentHandshake({ frozen: true, shown: 'frozen' }),
      )
      const feed = shellFeed()
      shell = renderInteraction(feed)
      const content = get(
        branch === 'blocked' ? 'account-blocked' : 'legal-frozen-screen',
      )
      const fieldset = get<HTMLFieldSetElement>('page-interaction-scope')
      expect(fieldset.contains(content)).toBe(true)
      await shell.update(() => feed.push({ state: 'disconnected' }, 'state'))
      expect(fieldset.disabled).toBe(true)
      await shell.update(() => feed.push({ state: 'connected' }, 'state'))
      expect(content.isConnected).toBe(true)
      expect(fieldset.disabled).toBe(false)
    },
  )

  it('removes the main capture gate for maintenance and rebinds the ordinary branch', async () => {
    const feed = shellFeed()
    shell = renderInteraction(feed)
    await shell.update(() => feed.push({ state: 'disconnected' }, 'state'))
    const main = shell.root.querySelector('main')!
    expect(main.hasAttribute(HILOS_INTERACTION_BLOCKED_ATTR)).toBe(true)
    await shell.update(() =>
      feed.push(
        { protectedMode: { ...FROZEN, acceptsPass: true, passIssued: true } },
        'protectedMode',
      ),
    )
    expect(main.hasAttribute(HILOS_INTERACTION_REGION_ATTR)).toBe(false)
    expect(
      shell.root.querySelector('[data-id="page-interaction-scope"]'),
    ).toBeNull()
    const input = get<HTMLInputElement>('maintenance-pass')
    expect(input.matches(':disabled')).toBe(false)
    const inputEvent = new Event('beforeinput', {
      bubbles: true,
      cancelable: true,
    })
    input.dispatchEvent(inputEvent)
    expect(inputEvent.defaultPrevented).toBe(false)
    await shell.update(() =>
      feed.push({ protectedMode: PROTECTED_MODE_INACTIVE }, 'protectedMode'),
    )
    expect(get<HTMLFieldSetElement>('page-interaction-scope').disabled).toBe(
      true,
    )
  })
})
