import { afterEach, describe, expect, it, vi } from 'vitest'
import type { ReactNode } from 'react'
import { act, cleanup, fireEvent, render } from '@testing-library/react'
import {
  ActionLifecycle,
  applyServerTime,
  bindAccountBlocked,
  bindAccountStanding,
  bindImpersonation,
  formatCalendarDate,
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

import { HilosLayout } from '../src/HilosLayout.js'

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
