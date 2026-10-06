import { afterEach, describe, expect, it, vi } from 'vitest'
import type { ReactNode } from 'react'
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
  formatCalendarDate,
  HILOS_IMPERSONATION_STRIP_TEXT_ID,
  HILOS_VIEW_MODE_STRIP_TEXT_ID,
  HilosPages,
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
  HilosRouter,
  PageRouteMatch,
  ProjectSignal,
  ProtectedModeStatus,
} from '@hilos/core'

import { HilosLayout } from '../src/HilosLayout.js'
import { LoadingButton } from '../src/LoadingButton.js'
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

describe('HilosLayout impersonation strip', () => {
  let unbind: (() => void) | undefined

  afterEach(() => {
    cleanup()
    unbind?.()
    unbind = undefined
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
    expect(stop.disabled).toBe(true)

    await act(async () => {
      session.source.succeed(session.source.sent[0]?.requestId)
    })

    expect(stop.disabled).toBe(false)
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
      data: { impersonationPolicy: { viewOnly, carryAdmin: false } },
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

  it('adds no live region of its own when the strip goes up', () => {
    // Counted before the strip goes up: both shells read the one store, so the
    // plain one would grow the strip too once the takeover lands.
    const live = '[role="status"][aria-live="polite"]'
    const plain = bindSession()
    plain.handshake({ entities: { currentUser: { id: 1, name: 'Ada' } } })
    const liveWithout =
      renderShell(shellConnection()).querySelectorAll(live).length
    cleanup()
    plain.unbind()

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
    cleanup()
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
    // It never meets the impersonation strip: a takeover takes it down.
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

  it('draws no deletion strip under a takeover, nor under maintenance', () => {
    const session = bindSession()
    unbind = session.unbind
    session.handshake(
      standingHandshake('deletion_scheduled', true, NOW + 3 * DAY_MS),
    )

    const taken = renderShell(shellConnection())
    expect(surface(taken, 'impersonation-banner')).not.toBeNull()
    expect(surface(taken, 'account-deletion-strip')).toBeNull()
    cleanup()

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
    expect(keep.disabled).toBe(true)

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
  let unbind: (() => void) | undefined

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

  afterEach(() => {
    cleanup()
    unbind?.()
    unbind = undefined
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
  let unbind: (() => void) | undefined

  afterEach(() => {
    cleanup()
    unbind?.()
    unbind = undefined
    hilosToasts.clear()
  })

  it('draws no control for an anonymous session', () => {
    const session = bindSession()
    unbind = session.unbind
    session.handshake({ entities: { currentUser: null } })

    expect(surface(renderShell(shellConnection()), 'nav-logout')).toBeNull()
  })

  it.each([{ entities: { currentUser: { id: 1, name: '' } } }, TAKEOVER])(
    'draws the named control last, also while impersonated: %j',
    (payload) => {
      const session = bindSession()
      unbind = session.unbind
      session.handshake(payload)
      const container = renderShell(shellConnection())
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
    expect(button.disabled).toBe(true)
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
  let unbind: (() => void) | undefined

  afterEach(() => {
    cleanup()
    unbind?.()
    unbind = undefined
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
    expect(signOut.disabled).toBe(true)

    await act(async () => {
      session.source.succeed(session.source.sent[0]?.requestId)
    })

    expect(signOut.disabled).toBe(false)
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
  const windowShown = (): boolean =>
    document.body.querySelector('[data-id="legal-reconsent-modal"]') !== null

  afterEach(() => {
    cleanup()
    unbind?.()
    unbind = undefined
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

  it('draws no icon under the maintenance surface', () => {
    const session = bindSession()
    unbind = session.unbind
    session.handshake(reconsentHandshake(inWindow))

    const container = renderShell(shellConnection(FROZEN))

    expect(surface(container, 'legal-reconsent-icon')).toBeNull()
  })
})

describe('HilosLayout view-mode strip (HIL-1260)', () => {
  let unbind: (() => void) | undefined

  afterEach(() => {
    cleanup()
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
