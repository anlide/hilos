// The banner region of the Angular shell: the full-width strip a project fills
// below the nav, which until HIL-933 existed only in the Vue shell. Six
// assertions, the same six the React peer makes
// (react/test/HilosMaintenance.test.tsx, describe "HilosLayout banner region"):
// the region is there and empty when nothing is projected, it carries what a
// project projects, it stands between the navigation and the content, it holds
// the framework's own protected-mode strip, that strip brings no live region of
// its own, and it stays above the strip a project passes.
//
// The second describe is the impersonation strip (HIL-1064), the framework's
// second strip, with the same cases the Vue and React shells run
// (vue/src/HilosLayout.test.ts, react/test/HilosLayout.test.tsx): no strip for
// a plain session, the name while impersonated, the region's child order, none
// under the maintenance surface, Stop tracked and disabled until its reply, a
// refusal that leaves the strip and toasts, and no live region of its own.
//
// The third describe is the account standing (HIL-945), under the Vue shell's
// case names: the deletion strip's words and their minute tick, its place in the
// region, present under a takeover but absent under maintenance, Keep tracked, a
// refusal that toasts, and the impersonation strip colored by the standing.
//
// Two more mirror the Vue shell's describes of the admin view mode: the admin
// gear drawn from the core access (HIL-1253) — none for a guest or a non-admin
// without the mode, full for an admin, view for a viewer, live across a grant
// and a revoke, none under maintenance — and the fourth framework strip, the
// view-mode strip (HIL-1260) — its words, its place after the session strips
// and before the project's, none off an admin route, for an admin, without the
// mode or under maintenance, and live across a grant and a revoke.
//
// The last is the shell's theme icon (HIL-1441), under the React shell's case
// names: first in the right-hand group before the project's user region, none
// under maintenance, in the held first frame or with switching off, and closed
// to writes in a takeover.
//
// Order is read off the shell's child order rather than off heights: jsdom does
// not lay out, so every height there is zero and a measurement would pass on a
// broken region too.
//
// The wiring these cases run on — the DOM environment, the TestBed platform, and
// the module reset between cases — lives in the package's vitest.setup.ts and was
// laid down by HIL-848; this file configures none of it.
import { Component, inject, signal } from '@angular/core'
import { By } from '@angular/platform-browser'
import { TestBed } from '@angular/core/testing'
import type { ComponentFixture } from '@angular/core/testing'
import {
  ActionLifecycle,
  applyServerTime,
  bindAccountBlocked,
  bindAccessLoss,
  bindAdminAccess,
  bindAccountStanding,
  bindImpersonation,
  bindLegalReconsent,
  createSignal,
  DEFAULT_IMPERSONATION_POLICY,
  formatCalendarDate,
  HILOS_IMPERSONATION_STRIP_TEXT_ID,
  HILOS_CONNECTION_STATUS_TEXT_ID,
  HILOS_VIEW_MODE_STRIP_TEXT_ID,
  HILOS_INTERACTION_BLOCKED_ATTR,
  HILOS_INTERACTION_REGION_ATTR,
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
  type ActionErrorSignal,
  type ActionLifecycleSource,
  type ActionSuccessSignal,
  type ConnectionState,
  type HilosConnection,
  type HilosRouter,
  type PageRouteMatch,
  type ProjectSignal,
  type ProtectedModeStatus,
  type RtStalenessStatus,
} from '@hilos/core'
import { afterEach, describe, expect, it, vi } from 'vitest'

import { THEME_SETTINGS_STORAGE_KEY } from '../../core/src/theme/themeBrowser.js'
import { HilosLayout } from '../src/HilosLayout.js'
import { LoadingButton } from '../src/LoadingButton.js'
import { HilosModal } from '../src/HilosModal.js'
import {
  HILOS_CONNECTION_INTERACTION,
  injectConnectionInteraction,
} from '../src/hilosConnectionInteraction.js'
import { HILOS_ADMIN_VIEW_MODE } from '../src/hilosLookOnly.js'
import { HILOS_ROUTER } from '../src/hilosRouterToken.js'
import { reconsentContent } from '../../core/test/legal/reconsentFixture.js'

/**
 * The five values the shell reads off a connection, and nothing else: it takes
 * each one in an effect of its own constructor (src/HilosLayout.ts, the five
 * effects) and never calls anything back.
 *
 * `firstFrameHeld` has to be false — held, the shell renders the hidden
 * boot-state marker alone and the region is not in the tree at all.
 *
 * @param protectedMode Protected-mode state the shell reads off the connection.
 * @returns A connection double the shell can mount on.
 */
function fakeConnection(
  protectedMode: ProtectedModeStatus = PROTECTED_MODE_INACTIVE,
): HilosConnection {
  return {
    get state(): ConnectionState {
      return 'connected'
    },
    get protectedMode(): ProtectedModeStatus {
      return protectedMode
    },
    get rtStaleness(): RtStalenessStatus {
      return RT_STALENESS_FRESH
    },
    get reconnectDragging(): boolean {
      return false
    },
    get firstFrameHeld(): boolean {
      return false
    },
    on(): () => void {
      return () => {}
    },
  } as unknown as HilosConnection
}

/** The window seen from inside it: the mode holds the node, not this client. */
const ADMITTED: ProtectedModeStatus = {
  ...PROTECTED_MODE_INACTIVE,
  acceptsPass: true,
  bannerMessage: 'The restore is being verified.',
}

/**
 * A host for the filled case: projected content cannot be handed to a component
 * created directly, so the strip is written into a template around the shell.
 *
 * The connection is writable so a case can swap in a mode that raises the
 * framework strip before the first change detection.
 */
@Component({
  selector: 'test-banner-host',
  imports: [HilosLayout],
  template: `
    <hilos-layout [connection]="connection">
      <span banner data-id="test-banner">Acting for someone else</span>
    </hilos-layout>
  `,
})
class BannerHost {
  connection = fakeConnection()
}

@Component({
  selector: 'test-live-blocked-page',
  imports: [HilosModal],
  template: `
    <div data-id="private-content">
      <button type="button" data-id="open-draft" (click)="open.set(true)">
        Edit
      </button>
      <hilos-modal
        title="Edit phone"
        [open]="open()"
        [confirmOnClose]="true"
        (openChange)="open.set($event)"
      >
        <input data-id="draft" data-autofocus="" />
      </hilos-modal>
    </div>
  `,
})
class LiveBlockedPage {
  readonly open = signal(false)
}

@Component({
  selector: 'test-live-blocked-shell',
  imports: [HilosLayout, LiveBlockedPage],
  template: `
    <hilos-layout [connection]="connection">
      <test-live-blocked-page />
    </hilos-layout>
  `,
})
class LiveBlockedShellHost {
  readonly connection = fakeConnection()
}

describe('HilosLayout banner region', () => {
  it('keeps an empty banner region in the shell for a project to fill', () => {
    const fixture = TestBed.createComponent(HilosLayout)
    fixture.componentRef.setInput('connection', fakeConnection())
    fixture.detectChanges()

    const region = fixture.nativeElement.querySelector(
      '[data-id="app-banner"]',
    ) as HTMLElement | null

    expect(region).not.toBeNull()
    expect(region?.childElementCount).toBe(0)
    expect(region?.textContent?.trim()).toBe('')
    expect(region?.className).toBe('flex-shrink-0')
  })

  it('puts the strip a project passes into the banner region', () => {
    const fixture = TestBed.createComponent(BannerHost)
    fixture.detectChanges()

    const region = fixture.nativeElement.querySelector(
      '[data-id="app-banner"]',
    ) as HTMLElement | null

    expect(region?.querySelector('[data-id="test-banner"]')).not.toBeNull()
  })

  it('stands the banner region between the navigation and the content', () => {
    const fixture = TestBed.createComponent(HilosLayout)
    fixture.componentRef.setInput('connection', fakeConnection())
    fixture.detectChanges()

    const root = fixture.nativeElement.querySelector(
      '[data-id="app-root"]',
    ) as HTMLElement | null
    const children = Array.from(root?.children ?? [])
    const nav = children.findIndex((child) => child.tagName === 'NAV')
    const region = children.findIndex(
      (child) => child.getAttribute('data-id') === 'app-banner',
    )
    const main = children.findIndex(
      (child) => child.id === 'hilos-main-content',
    )

    expect(nav).toBeGreaterThanOrEqual(0)
    expect(region).toBeGreaterThan(nav)
    expect(region).toBeLessThan(main)
  })

  it('puts the framework strip inside the banner region', () => {
    const fixture = TestBed.createComponent(HilosLayout)
    fixture.componentRef.setInput('connection', fakeConnection(ADMITTED))
    fixture.detectChanges()

    const region = fixture.nativeElement.querySelector(
      '[data-id="app-banner"]',
    ) as HTMLElement | null

    expect(
      region?.querySelector('[data-id="protected-mode-banner"]'),
    ).not.toBeNull()
  })

  it('adds no live region of its own when the strip goes up', () => {
    // The shell always carries live regions of its own - the page title, the
    // connection indicator, this region - so what the strip owes is a delta of
    // zero, not a document with exactly one of them.
    const live = '[role="status"][aria-live="polite"]'
    const down = TestBed.createComponent(HilosLayout)
    down.componentRef.setInput('connection', fakeConnection())
    down.detectChanges()
    const up = TestBed.createComponent(HilosLayout)
    up.componentRef.setInput('connection', fakeConnection(ADMITTED))
    up.detectChanges()

    expect(
      (up.nativeElement as HTMLElement).querySelectorAll(live).length,
    ).toBe((down.nativeElement as HTMLElement).querySelectorAll(live).length)
  })

  it('keeps the framework strip above the one a project passes', () => {
    const fixture = TestBed.createComponent(BannerHost)
    fixture.componentInstance.connection = fakeConnection(ADMITTED)
    fixture.detectChanges()

    // The order is the region's own child order now, not the order two
    // independent blocks happen to be written in.
    const region = fixture.nativeElement.querySelector(
      '[data-id="app-banner"]',
    ) as HTMLElement | null
    const children = Array.from(region?.children ?? [])
    const strip = children.findIndex(
      (child) => child.getAttribute('data-id') === 'protected-mode-banner',
    )
    const passed = children.findIndex(
      (child) => child.getAttribute('data-id') === 'test-banner',
    )

    expect(strip).toBeGreaterThanOrEqual(0)
    expect(passed).toBeGreaterThan(strip)
  })
})

/** A node frozen for a restore: the shell turns into the maintenance surface. */
const FROZEN: ProtectedModeStatus = {
  ...PROTECTED_MODE_INACTIVE,
  active: true,
  operation: 'restore',
  title: 'Restoring a backup',
  message: 'The application will be back in a few minutes.',
}

/** A handshake naming Bob as the user and Ada as the administrator behind him. */
const TAKEOVER = {
  entities: {
    currentUser: { id: 2, name: 'Bob' },
    impersonatedBy: { id: 1, name: 'Ada' },
  },
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
 *
 * @returns The lifecycle source, the unbind, and the handshake emitter.
 */
function bindSession(router?: HilosRouter) {
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
  const unbindReconsent = bindLegalReconsent(scopes, actions)
  const unbindAdminAccess = bindAdminAccess(scopes)
  const unbindAccessLoss = router ? bindAccessLoss(scopes, router) : () => {}

  return {
    source,
    unbind(): void {
      unbindStrip()
      unbindCard()
      unbindStanding()
      unbindSignOut()
      unbindReconsent()
      unbindAdminAccess()
      unbindAccessLoss()
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

/**
 * Mount the bare shell on a connection double.
 *
 * @param protectedMode Protected-mode state the shell reads off the connection.
 * @returns The fixture, already checked once.
 */
function mountShell(
  protectedMode: ProtectedModeStatus = PROTECTED_MODE_INACTIVE,
) {
  const fixture = TestBed.createComponent(HilosLayout)
  fixture.componentRef.setInput('connection', fakeConnection(protectedMode))
  fixture.detectChanges()

  return fixture
}

describe('HilosLayout skip link', () => {
  it('focuses the existing main and cancels navigation against a root document base', () => {
    const originalUrl = window.location.href
    const base = document.createElement('base')
    base.href = '/'
    document.head.prepend(base)
    window.history.replaceState(null, '', '/hilos/communications/email')
    const currentUrl = window.location.href
    const fixture = mountShell()

    try {
      const root = fixture.nativeElement as HTMLElement
      const skip = root.querySelector<HTMLAnchorElement>(
        '[data-id="skip-to-content"]',
      )!
      const main = root.querySelector<HTMLElement>('main#hilos-main-content')!
      expect(skip.href).toBe(new URL('/#hilos-main-content', currentUrl).href)
      expect(main.tabIndex).toBe(-1)
      let activation: MouseEvent | undefined
      skip.addEventListener(
        'click',
        (event) => {
          activation = event
        },
        { once: true },
      )

      skip.focus()
      expect(document.activeElement).toBe(skip)
      skip.click()

      expect(activation?.defaultPrevented).toBe(true)
      expect(document.activeElement).toBe(main)
      expect(root.querySelector('main')).toBe(main)
      expect(window.location.href).toBe(currentUrl)
    } finally {
      fixture.destroy()
      base.remove()
      window.history.replaceState(null, '', originalUrl)
    }
  })
})

/**
 * Let the tracked driver settle on the reply it was just given.
 *
 * @returns Resolves after the driver's awaited handle has run its finally.
 */
async function settle(): Promise<void> {
  await new Promise((resolve) => setTimeout(resolve, 0))
}

/**
 * A host for the takeover that only looks (HIL-1170): the shell over a page
 * whose one control is a tracked button, projected the way a project's page is.
 */
@Component({
  selector: 'test-page-action-host',
  imports: [HilosLayout, LoadingButton],
  template: `
    <hilos-layout [connection]="connection">
      <button hilosLoadingButton data-id="page-action">Send</button>
    </hilos-layout>
  `,
})
class PageActionHost {
  connection = fakeConnection()
}

/**
 * The takeover handshake carrying the installation's impersonation policy.
 *
 * @param viewOnly Whether the administrator may only look.
 * @returns The handshake payload.
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

    const root = mountShell().nativeElement as HTMLElement

    expect(root.querySelector('[data-id="impersonation-banner"]')).toBeNull()
  })

  it('names the user the session acts as while impersonated', () => {
    const session = bindSession()
    unbind = session.unbind
    session.handshake(TAKEOVER)

    const root = mountShell().nativeElement as HTMLElement

    const strip = root.querySelector('[data-id="impersonation-banner"]')
    expect(strip?.textContent).toContain('You are impersonating')
    expect(strip?.querySelector('strong')?.textContent).toBe('Bob')
    expect(
      root.querySelector('[data-id="impersonation-stop"]')?.textContent?.trim(),
    ).toBe('Stop')
  })

  it('stands between the protected-mode strip and the project strip', () => {
    const session = bindSession()
    unbind = session.unbind
    session.handshake(TAKEOVER)

    const fixture = TestBed.createComponent(BannerHost)
    fixture.componentInstance.connection = fakeConnection(ADMITTED)
    fixture.detectChanges()

    const region = (fixture.nativeElement as HTMLElement).querySelector(
      '[data-id="app-banner"]',
    )
    const order = Array.from(region?.children ?? []).map((child) =>
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

    const root = mountShell(FROZEN).nativeElement as HTMLElement

    expect(root.querySelector('[data-id="impersonation-banner"]')).toBeNull()
  })

  it('sends Stop tracked and keeps it disabled until the reply settles', async () => {
    const session = bindSession()
    unbind = session.unbind
    session.handshake(TAKEOVER)
    const fixture = mountShell()
    const stop = (fixture.nativeElement as HTMLElement).querySelector(
      '[data-id="impersonation-stop"]',
    ) as HTMLButtonElement

    stop.click()
    fixture.detectChanges()

    expect(session.source.sent).toHaveLength(1)
    expect(session.source.sent[0]?.action).toBe('hilos_impersonate_stop')
    expect(session.source.sent[0]?.data).toEqual({})
    expect(session.source.sent[0]?.requestId).toBeTruthy()
    expect(stop.disabled).toBe(false)
    expect(stop.getAttribute('aria-disabled')).toBe('true')
    expect(stop.getAttribute('aria-busy')).toBe('true')
    stop.click()
    fixture.detectChanges()
    expect(session.source.sent).toHaveLength(1)

    session.source.succeed(session.source.sent[0]?.requestId)
    await settle()
    fixture.detectChanges()

    expect(stop.disabled).toBe(false)
    expect(stop.getAttribute('aria-disabled')).toBeNull()
    expect(stop.getAttribute('aria-busy')).toBeNull()
    expect(hilosToasts.toasts.get()).toEqual([])
  })

  it('leaves the strip standing on a refusal and says why in a toast', async () => {
    const session = bindSession()
    unbind = session.unbind
    session.handshake(TAKEOVER)
    const fixture = mountShell()
    const root = fixture.nativeElement as HTMLElement
    const stop = root.querySelector(
      '[data-id="impersonation-stop"]',
    ) as HTMLButtonElement

    stop.click()
    fixture.detectChanges()
    session.source.refuse(
      session.source.sent[0]?.requestId,
      'Session is not impersonating',
    )
    await settle()
    fixture.detectChanges()

    expect(
      root.querySelector('[data-id="impersonation-banner"]'),
    ).not.toBeNull()
    expect(stop.disabled).toBe(false)
    expect(
      hilosToasts.toasts.get().map((toast) => [toast.severity, toast.message]),
    ).toEqual([['error', 'Session is not impersonating']])
  })

  it('says "view only" and switches off the page, never Stop, in a takeover that only looks (HIL-1170)', async () => {
    const session = bindSession()
    unbind = session.unbind
    session.handshake(takeoverWith(true))

    const fixture = TestBed.createComponent(PageActionHost)
    fixture.detectChanges()
    const root = fixture.nativeElement as HTMLElement
    const strip = root.querySelector('[data-id="impersonation-banner"]')
    const text = strip?.querySelector(`#${HILOS_IMPERSONATION_STRIP_TEXT_ID}`)

    expect(text).not.toBeNull()
    expect(text?.textContent).toContain('You are impersonating')
    expect(text?.querySelector('.badge')?.textContent?.trim()).toBe('view only')
    expect(strip?.querySelector('strong')?.textContent).toBe('Bob')
    const stop = root.querySelector(
      '[data-id="impersonation-stop"]',
    ) as HTMLButtonElement
    expect(stop.disabled).toBe(false)
    expect(stop.getAttribute('aria-describedby')).toBeNull()
    const action = root.querySelector(
      '[data-id="page-action"]',
    ) as HTMLButtonElement
    expect(action.disabled).toBe(true)
    expect(action.getAttribute('aria-describedby')).toBe(
      HILOS_IMPERSONATION_STRIP_TEXT_ID,
    )

    stop.click()
    fixture.detectChanges()
    expect(session.source.sent[0]?.action).toBe('hilos_impersonate_stop')
    session.source.succeed(session.source.sent[0]?.requestId)
    await settle()
  })

  it('leaves the page live and the strip unmarked while the takeover may act, and follows the policy', () => {
    const session = bindSession()
    unbind = session.unbind
    session.handshake(takeoverWith(false))

    const fixture = TestBed.createComponent(PageActionHost)
    fixture.detectChanges()
    const root = fixture.nativeElement as HTMLElement
    const action = () =>
      root.querySelector('[data-id="page-action"]') as HTMLButtonElement

    expect(
      root.querySelector('[data-id="impersonation-banner"] .badge'),
    ).toBeNull()
    expect(action().disabled).toBe(false)

    session.handshake(takeoverWith(true))
    fixture.detectChanges()

    expect(
      root
        .querySelector('[data-id="impersonation-banner"] .badge')
        ?.textContent?.trim(),
    ).toBe('view only')
    expect(action().disabled).toBe(true)
  })

  it('adds no live region of its own when the strip goes up', () => {
    // Counted before the strip goes up: both shells read the one store, so the
    // plain one would grow the strip too once the takeover lands.
    const live = '[role="status"][aria-live="polite"]'
    const plain = bindSession()
    plain.handshake({ entities: { currentUser: { id: 1, name: 'Ada' } } })
    const down = mountShell()
    const liveWithout = (down.nativeElement as HTMLElement).querySelectorAll(
      live,
    ).length
    down.destroy()
    plain.unbind()

    const session = bindSession()
    unbind = session.unbind
    session.handshake(TAKEOVER)
    const up = mountShell().nativeElement as HTMLElement

    expect(up.querySelector('[data-id="impersonation-banner"]')).not.toBeNull()
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
 * @param deletionGraceDays The original grace period, or null for a legacy standing.
 * @returns The handshake payload.
 */
function standingHandshake(
  shown: string,
  impersonated = false,
  deletionEffectiveAt: number | null = null,
  deletionGraceDays: number | null = null,
): Record<string, unknown> {
  return {
    data: {
      serverTimeMs: NOW,
      accountStanding: {
        shown,
        blocked: shown === 'blocked',
        frozen: shown === 'frozen',
        deletionEffectiveAt,
        deletionGraceDays,
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

    const root = mountShell().nativeElement as HTMLElement

    const strip = root.querySelector('[data-id="account-deletion-strip"]')
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
      strip
        ?.querySelector('[data-id="account-deletion-strip-keep"]')
        ?.textContent?.trim(),
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
    const fixture = mountShell()
    const text = () =>
      (fixture.nativeElement as HTMLElement).querySelector(
        '[data-id="account-deletion-strip-text"]',
      )?.textContent
    expect(text()).toContain('2 days left')

    vi.setSystemTime(NOW + 1.5 * DAY_MS)
    vi.advanceTimersByTime(60_000)
    fixture.detectChanges()

    expect(text()).toContain('1 day left')
  })

  it('bounds the strip by the standing grace period with a negative clock error and keeps ticking', () => {
    vi.useFakeTimers({ toFake: ['Date', 'setInterval', 'clearInterval'] })
    vi.setSystemTime(NOW)
    const session = bindSession()
    unbind = session.unbind
    const effectiveAt = NOW + 30 * DAY_MS
    session.handshake(
      standingHandshake('deletion_scheduled', false, effectiveAt, 30),
    )
    applyServerTime(NOW - 300)
    const fixture = mountShell()
    const text = () =>
      (fixture.nativeElement as HTMLElement).querySelector(
        '[data-id="account-deletion-strip-text"]',
      )?.textContent

    expect(text()).toContain(
      `Your account will be deleted on ${formatCalendarDate(effectiveAt)} — 30 days left`,
    )

    vi.setSystemTime(NOW + DAY_MS)
    vi.advanceTimersByTime(60_000)
    fixture.detectChanges()

    expect(text()).toContain(
      `Your account will be deleted on ${formatCalendarDate(effectiveAt)} — 29 days left`,
    )
  })

  it('stands after the framework strips and before the project strip', () => {
    // This session has no takeover, so the protected-mode banner comes first.
    const session = bindSession()
    unbind = session.unbind
    session.handshake(
      standingHandshake('deletion_scheduled', false, NOW + 3 * DAY_MS),
    )

    const fixture = TestBed.createComponent(BannerHost)
    fixture.componentInstance.connection = fakeConnection(ADMITTED)
    fixture.detectChanges()

    const region = (fixture.nativeElement as HTMLElement).querySelector(
      '[data-id="app-banner"]',
    )
    const order = Array.from(region?.children ?? []).map((child) =>
      child.getAttribute('data-id'),
    )
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

    const taken = mountShell()
    const takenRoot = taken.nativeElement as HTMLElement
    expect(
      takenRoot.querySelector('[data-id="impersonation-banner"]'),
    ).not.toBeNull()
    expect(
      takenRoot.querySelector('[data-id="account-deletion-strip"]'),
    ).not.toBeNull()
    expect(
      takenRoot.querySelector('[data-id="account-deletion-strip-keep"]'),
    ).toBeNull()
    expect(
      takenRoot.querySelector('[data-id="account-deletion-strip-text"]')
        ?.textContent,
    ).toContain(
      `Your account will be deleted on ${formatCalendarDate(NOW + 3 * DAY_MS)} — 3 days left`,
    )
    taken.destroy()
  })

  it('draws no deletion strip under maintenance', () => {
    const session = bindSession()
    unbind = session.unbind
    session.handshake(
      standingHandshake('deletion_scheduled', false, NOW + 3 * DAY_MS),
    )
    const frozenNode = mountShell(FROZEN).nativeElement as HTMLElement
    expect(
      frozenNode.querySelector('[data-id="account-deletion-strip"]'),
    ).toBeNull()
  })

  it('sends Keep my account tracked, busy until the reply, and toasts nothing on success', async () => {
    const session = bindSession()
    unbind = session.unbind
    session.handshake(
      standingHandshake('deletion_scheduled', false, NOW + 3 * DAY_MS),
    )
    const fixture = mountShell()
    const root = fixture.nativeElement as HTMLElement
    const keep = root.querySelector(
      '[data-id="account-deletion-strip-keep"]',
    ) as HTMLButtonElement

    keep.click()
    fixture.detectChanges()

    expect(session.source.sent).toHaveLength(1)
    expect(session.source.sent[0]?.action).toBe('hilos_account_deletion_cancel')
    expect(session.source.sent[0]?.data).toEqual({})
    expect(session.source.sent[0]?.requestId).toBeTruthy()
    expect(keep.disabled).toBe(false)
    expect(keep.getAttribute('aria-disabled')).toBe('true')
    expect(keep.getAttribute('aria-busy')).toBe('true')
    keep.click()
    fixture.detectChanges()
    expect(session.source.sent).toHaveLength(1)

    session.source.succeed(
      session.source.sent[0]?.requestId,
      'hilos_account_deletion_cancel',
    )
    session.handshake(standingHandshake('none'))
    await settle()
    fixture.detectChanges()

    expect(root.querySelector('[data-id="account-deletion-strip"]')).toBeNull()
    expect(hilosToasts.toasts.get()).toEqual([])
  })

  it('leaves the strip standing on a refusal and says why in a toast', async () => {
    const session = bindSession()
    unbind = session.unbind
    session.handshake(
      standingHandshake('deletion_scheduled', false, NOW + 3 * DAY_MS),
    )
    const fixture = mountShell()
    const root = fixture.nativeElement as HTMLElement
    const keep = root.querySelector(
      '[data-id="account-deletion-strip-keep"]',
    ) as HTMLButtonElement

    keep.click()
    fixture.detectChanges()
    session.source.refuse(
      session.source.sent[0]?.requestId,
      'No deletion is scheduled',
      'hilos_account_deletion_cancel',
    )
    await settle()
    fixture.detectChanges()

    expect(
      root.querySelector('[data-id="account-deletion-strip"]'),
    ).not.toBeNull()
    expect(keep.disabled).toBe(false)
    expect(
      hilosToasts.toasts.get().map((toast) => [toast.severity, toast.message]),
    ).toEqual([['error', 'No deletion is scheduled']])
  })

  it.each([
    ['none', 'alert-warning', 'btn-warning'],
    ['blocked', 'alert-danger', 'btn-danger'],
    ['frozen', 'alert-info', 'btn-info'],
  ])(
    'colors the impersonation strip of a %s person %s',
    (shown, alertClass, stopClass) => {
      const session = bindSession()
      unbind = session.unbind
      session.handshake(standingHandshake(shown, true))

      const root = mountShell().nativeElement as HTMLElement

      const strip = root.querySelector('[data-id="impersonation-banner"]')
      expect(strip?.classList.contains(alertClass)).toBe(true)
      expect(strip?.classList.contains('alert')).toBe(true)
      expect(strip?.querySelector('strong')?.textContent).toBe('Bob')
      // Stop takes the strip's tone on top of the loading button's own classes.
      const stop = strip?.querySelector('[data-id="impersonation-stop"]')
      expect([...(stop?.classList ?? [])]).toEqual(
        expect.arrayContaining(['btn', 'btn-sm', stopClass]),
      )
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
    mounted = TestBed.createComponent(HilosLayout)
    mounted.componentRef.setInput('connection', connection)
    mounted.detectChanges()
    return mounted.nativeElement as HTMLElement
  }

  afterEach(() => {
    mounted?.destroy()
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
      surface(renderSignOutShell(fakeConnection()), 'nav-logout'),
    ).toBeNull()
  })

  it.each([{ entities: { currentUser: { id: 1, name: '' } } }, TAKEOVER])(
    'draws the named control before the final connection indicator, also while impersonated: %j',
    (payload) => {
      const session = bindSession()
      unbind = session.unbind
      session.handshake(payload)
      const container = renderSignOutShell(fakeConnection())
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
      surface(renderSignOutShell(fakeConnection(FROZEN)), 'nav-logout'),
    ).toBeNull()
  })

  it('draws no control during the held first frame', () => {
    const session = bindSession()
    unbind = session.unbind
    session.handshake(TAKEOVER)
    const connection = fakeConnection()
    Object.defineProperty(connection, 'firstFrameHeld', { value: true })

    expect(surface(renderSignOutShell(connection), 'nav-logout')).toBeNull()
  })

  it('sends sign-out tracked once and stays disabled until a silent success', async () => {
    const session = bindSession()
    unbind = session.unbind
    session.handshake(TAKEOVER)
    const container = renderSignOutShell(fakeConnection())
    const button = surface(container, 'nav-logout') as HTMLButtonElement

    button.click()
    mounted?.detectChanges()
    expect(button.disabled).toBe(false)
    expect(button.getAttribute('aria-disabled')).toBe('true')
    expect(button.getAttribute('aria-busy')).toBe('true')
    expect(surface(container, 'loading-button-spinner')).toBeNull()
    button.click()
    mounted?.detectChanges()
    expect(session.source.sent).toEqual([
      { action: 'hilos_logout', data: {}, requestId: expect.any(String) },
    ])
    expect(session.source.sent[0]?.requestId).toBeTruthy()

    session.source.succeed(session.source.sent[0]?.requestId, 'hilos_logout')
    await settle()
    mounted?.detectChanges()

    expect(button.disabled).toBe(false)
    expect(button.getAttribute('aria-disabled')).toBeNull()
    expect(button.getAttribute('aria-busy')).toBeNull()
    expect(hilosToasts.toasts.get()).toEqual([])
  })

  it('leaves the control ready on a refusal and toasts the server reason', async () => {
    const session = bindSession()
    unbind = session.unbind
    session.handshake(TAKEOVER)
    const container = renderSignOutShell(fakeConnection())
    const button = surface(container, 'nav-logout') as HTMLButtonElement

    button.click()
    mounted?.detectChanges()
    session.source.refuse(
      session.source.sent[0]?.requestId,
      'Session not on connection',
      'hilos_logout',
    )
    await settle()
    mounted?.detectChanges()

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
    const container = renderSignOutShell(fakeConnection())
    const button = surface(container, 'nav-logout') as HTMLButtonElement

    button.click()
    mounted?.detectChanges()
    session.handshake({ entities: { currentUser: null, impersonatedBy: null } })
    mounted?.detectChanges()
    expect(surface(container, 'nav-logout')).toBeNull()

    session.source.succeed(session.source.sent[0]?.requestId, 'hilos_logout')
    await settle()
    mounted?.detectChanges()
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

    const root = mountShell().nativeElement as HTMLElement

    expect(root.querySelector('[data-id="account-blocked"]')).not.toBeNull()
    expect(root.querySelector('[data-id="data-export-prepare"]')).not.toBeNull()
    expect(root.querySelector('[data-id="app-footer"]')).not.toBeNull()
    expect(root.querySelector('nav')).not.toBeNull()
    expect(
      root
        .querySelector('[data-id="account-blocked-heading"]')
        ?.textContent?.trim(),
    ).toBe('Access closed')
    expect(
      root
        .querySelector('[data-id="account-blocked-account"]')
        ?.textContent?.replace(/\s+/g, ' ')
        .trim(),
    ).toBe(
      'The account maria@example.com has been blocked by the project administration.',
    )
    expect(
      root.querySelector('[data-id="account-blocked-reason"]')?.textContent,
    ).toContain('No reason given')
  })

  it('says "This account" when the server could name no address', () => {
    const session = bindSession()
    unbind = session.unbind
    session.handshake({ data: { accountBlocked: { identifier: null } } })

    const root = mountShell().nativeElement as HTMLElement

    expect(
      root
        .querySelector('[data-id="account-blocked-account"]')
        ?.textContent?.trim(),
    ).toBe('This account has been blocked by the project administration.')
  })

  it('sends Sign out tracked and keeps it disabled until the reply settles', async () => {
    const session = bindSession()
    unbind = session.unbind
    session.handshake(BLOCKED)
    const fixture = mountShell()
    const signOut = (fixture.nativeElement as HTMLElement).querySelector(
      '[data-id="account-blocked-sign-out"]',
    ) as HTMLButtonElement

    signOut.click()
    fixture.detectChanges()

    expect(session.source.sent).toHaveLength(1)
    expect(session.source.sent[0]?.action).toBe('hilos_dismiss_account_blocked')
    expect(session.source.sent[0]?.data).toEqual({})
    expect(session.source.sent[0]?.requestId).toBeTruthy()
    expect(signOut.disabled).toBe(false)
    expect(signOut.getAttribute('aria-disabled')).toBe('true')
    expect(signOut.getAttribute('aria-busy')).toBe('true')
    signOut.click()
    fixture.detectChanges()
    expect(session.source.sent).toHaveLength(1)

    session.source.succeed(session.source.sent[0]?.requestId)
    await settle()
    fixture.detectChanges()

    expect(signOut.disabled).toBe(false)
    expect(signOut.getAttribute('aria-disabled')).toBeNull()
    expect(signOut.getAttribute('aria-busy')).toBeNull()
  })

  it('takes the card down when a handshake carries none', () => {
    const session = bindSession()
    unbind = session.unbind
    session.handshake(BLOCKED)
    const fixture = mountShell()

    session.handshake({ data: { accountBlocked: null } })
    fixture.detectChanges()

    expect(
      (fixture.nativeElement as HTMLElement).querySelector(
        '[data-id="account-blocked"]',
      ),
    ).toBeNull()
  })

  it('gives way to the maintenance surface', () => {
    const session = bindSession()
    unbind = session.unbind
    session.handshake(BLOCKED)

    const root = mountShell(FROZEN).nativeElement as HTMLElement

    expect(root.querySelector('[data-id="account-blocked"]')).toBeNull()
  })
})

describe('HilosLayout live access loss', () => {
  let unbind: (() => void) | undefined
  let fixture: ComponentFixture<LiveBlockedShellHost> | undefined

  async function settleShell(): Promise<void> {
    fixture?.detectChanges()
    await fixture?.whenStable()
    fixture?.detectChanges()
  }

  function mountLiveShell() {
    fixture = TestBed.createComponent(LiveBlockedShellHost)
    fixture.detectChanges()

    return fixture
  }

  afterEach(() => {
    fixture?.destroy()
    fixture = undefined
    unbind?.()
    unbind = undefined
    document.body.innerHTML = ''
    document.body.classList.remove('modal-open')
    TestBed.resetTestingModule()
    hilosToasts.clear()
  })

  it('replaces the dirty slot with the live blocked warning and focuses the card', async () => {
    const router = routerOn('main')
    const session = bindSession(router)
    unbind = session.unbind
    session.handshake({
      entities: { currentUser: { id: 7 } },
      data: { accountBlocked: null },
    })
    const mounted = mountLiveShell()
    const root = mounted.nativeElement as HTMLElement
    const opener = root.querySelector<HTMLElement>('[data-id="open-draft"]')!
    opener.focus()
    opener.click()
    mounted.detectChanges()
    const draft = root.querySelector<HTMLInputElement>('[data-id="draft"]')!
    draft.value = 'Unsaved phone number'
    expect(document.activeElement).toBe(draft)

    session.handshake({
      entities: { currentUser: null },
      data: {
        accountBlocked: { identifier: 'maria@example.com' },
        accessLossReason: 'account_blocked',
      },
    })
    await settleShell()

    const card = root.querySelector<HTMLElement>('[data-id="account-blocked"]')!
    const notice = root.querySelector<HTMLElement>(
      '[data-id="access-loss-notice"]',
    )!
    const account = root.querySelector<HTMLElement>(
      '[data-id="account-blocked-account"]',
    )!
    const reason = root.querySelector<HTMLElement>(
      '[data-id="account-blocked-reason"]',
    )!
    expect(notice.textContent?.trim()).toBe('Any unsaved changes were lost.')
    expect(notice.classList.contains('alert-warning')).toBe(true)
    expect(notice.parentElement?.getAttribute('role')).toBe('status')
    expect(notice.parentElement?.getAttribute('aria-live')).toBe('polite')
    expect(account.nextElementSibling).toBe(notice.parentElement)
    expect(notice.parentElement?.nextElementSibling).toBe(reason)
    expect(root.querySelector('[data-id="private-content"]')).toBeNull()
    expect(root.querySelector('[data-id="hilos-page-departure"]')).toBeNull()
    expect(draft.isConnected).toBe(false)
    expect(root.querySelector('[data-id="modal"]')).toBeNull()
    expect(root.querySelector('[data-id="modal-confirm"]')).toBeNull()
    expect(document.activeElement).toBe(card.querySelector('section'))
    expect(root.querySelector('nav')).not.toBeNull()
    expect(root.querySelector('[data-id="app-footer"]')).not.toBeNull()

    session.handshake({
      entities: { currentUser: null },
      data: { accountBlocked: { identifier: 'maria@example.com' } },
    })
    await settleShell()
    expect(
      root.querySelector('[data-id="access-loss-notice"]')?.textContent?.trim(),
    ).toBe('Any unsaved changes were lost.')
  })

  it('keeps cold blocked sign-ins free of a live-loss warning', async () => {
    const router = routerOn('main')
    const session = bindSession(router)
    unbind = session.unbind
    session.handshake(BLOCKED)
    const mounted = mountLiveShell()
    await settleShell()

    const root = mounted.nativeElement as HTMLElement
    expect(root.querySelector('[data-id="account-blocked"]')).not.toBeNull()
    expect(root.querySelector('[data-id="access-loss-notice"]')).toBeNull()
  })

  it.each(['sign out', 'unblock'] as const)(
    'removes the live warning with the card after %s',
    async (exit) => {
      const router = routerOn('main')
      const session = bindSession(router)
      unbind = session.unbind
      session.handshake({
        entities: { currentUser: { id: 7 } },
        data: { accountBlocked: null },
      })
      const mounted = mountLiveShell()
      const root = mounted.nativeElement as HTMLElement
      session.handshake({
        entities: { currentUser: null },
        data: {
          accountBlocked: { identifier: 'maria@example.com' },
          accessLossReason: 'account_blocked',
        },
      })
      await settleShell()
      expect(
        root.querySelector('[data-id="access-loss-notice"]'),
      ).not.toBeNull()

      if (exit === 'sign out') {
        root
          .querySelector<HTMLElement>('[data-id="account-blocked-sign-out"]')
          ?.click()
        mounted.detectChanges()
        expect(session.source.sent[0]?.action).toBe(
          'hilos_dismiss_account_blocked',
        )
        session.source.succeed(session.source.sent[0]?.requestId)
        await settle()
        session.handshake({
          entities: { currentUser: null },
          data: { accountBlocked: null },
        })
      } else {
        session.handshake({
          entities: { currentUser: { id: 7 } },
          data: { accountBlocked: null },
        })
      }
      await settleShell()
      expect(root.querySelector('[data-id="account-blocked"]')).toBeNull()
      expect(root.querySelector('[data-id="access-loss-notice"]')).toBeNull()
      expect(root.querySelector('[data-id="private-content"]')).not.toBeNull()

      session.handshake(BLOCKED)
      await settleShell()
      expect(root.querySelector('[data-id="account-blocked"]')).not.toBeNull()
      expect(root.querySelector('[data-id="access-loss-notice"]')).toBeNull()
    },
  )
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
  let unbind: (() => void) | undefined
  const inWindow = { window: [{ document: 'terms', deadline: '2026-11-10' }] }
  const lapsed = { lapsed: [{ document: 'terms', deadline: '2026-09-01' }] }
  const windowShown = (): boolean =>
    document.body.querySelector('[data-id="legal-reconsent-modal"]') !== null
  const find = (id: string): HTMLElement | null =>
    document.body.querySelector(`[data-id="${id}"]`)

  afterEach(() => {
    unbind?.()
    unbind = undefined
    vi.useRealTimers()
  })

  /** Open the real shell window with its content response held by the test. */
  async function openWindow() {
    const session = bindSession()
    unbind = session.unbind
    session.handshake(reconsentHandshake(null))
    const fixture = mountShell()
    const feed = shellFeed()
    fixture.componentRef.setInput('connection', feed.connection)
    session.handshake(reconsentHandshake(inWindow))
    const settle = async (): Promise<void> => {
      fixture.detectChanges()
      await fixture.whenStable()
      fixture.detectChanges()
    }
    await settle()
    return { session, fixture, feed, settle }
  }

  it('allows Later while reading the terms, without treating loading as modal busy', async () => {
    const { session, fixture, settle } = await openWindow()
    expect(session.source.sent.at(-1)?.action).toBe('hilos_legal_reconsent')
    expect(find('legal-reconsent-loading')).not.toBeNull()
    expect(find('legal-reconsent-accept')!.matches(':disabled')).toBe(true)
    expect(find('modal-close')!.matches(':disabled')).toBe(false)
    expect(find('legal-reconsent-later')!.matches(':disabled')).toBe(false)
    find('legal-reconsent-later')!.click()
    await settle()
    expect(windowShown()).toBe(false)
    fixture.destroy()
  })

  it('holds the window through acceptance, unlocks a refusal without replaying a gesture, then closes on success', async () => {
    const { session, fixture, settle } = await openWindow()
    const read = session.source.sent.at(-1)!
    session.source.succeed(read.requestId, read.action, reconsentContent())
    await settle()
    vi.useFakeTimers()
    const modal = find('modal')!
    const content = find('legal-reconsent-document')!
    const error = find('legal-reconsent')!.querySelector('hilos-form-error')!
    const fullText = find('legal-reconsent-full-text')!
    const compare = find('legal-reconsent-compare')!
    const later = find('legal-reconsent-later')!
    const refuse = find('legal-reconsent-refuse')!
    const accept = find('legal-reconsent-accept')!
    const close = find('modal-close')!
    const calls = session.source.sent.length
    accept.focus()
    accept.click()
    fixture.detectChanges()
    const request = session.source.sent.at(-1)!
    expect(request.action).toBe('hilos_legal_accept')
    expect(session.source.sent).toHaveLength(calls + 1)
    const probe = (): void => {
      expect(find('modal')).toBe(modal)
      expect(find('legal-reconsent-document')).toBe(content)
      expect(find('legal-reconsent')!.querySelector('hilos-form-error')).toBe(
        error,
      )
      expect(accept.matches(':disabled')).toBe(false)
      expect(accept.getAttribute('aria-busy')).toBe('true')
      expect(document.activeElement).toBe(accept)
      for (const control of [fullText, compare, later, refuse, close]) {
        expect(control.matches(':disabled')).toBe(true)
        control.click()
      }
      accept.click()
      modal.dispatchEvent(
        new KeyboardEvent('keydown', {
          key: 'Escape',
          bubbles: true,
          cancelable: true,
        }),
      )
      modal.click()
      fixture.detectChanges()
      expect(find('modal-confirm')).toBeNull()
      expect(find('legal-reconsent-document')).toBe(content)
      expect(find('legal-reconsent-refuse-step')).toBeNull()
      expect(session.source.sent).toHaveLength(calls + 1)
    }
    probe()
    expect(
      accept.querySelector('[data-id="loading-button-spinner"]'),
    ).toBeNull()
    vi.advanceTimersByTime(299)
    fixture.detectChanges()
    probe()
    expect(
      accept.querySelector('[data-id="loading-button-spinner"]'),
    ).toBeNull()
    vi.advanceTimersByTime(1)
    fixture.detectChanges()
    probe()
    expect(
      accept.querySelectorAll('[data-id="loading-button-spinner"]'),
    ).toHaveLength(1)
    vi.useRealTimers()

    session.source.refuse(
      request.requestId,
      'The terms changed. Review them again.',
      request.action,
    )
    await settle()
    const reread = session.source.sent.at(-1)!
    expect(reread.action).toBe('hilos_legal_reconsent')
    expect(find('modal')).toBe(modal)
    expect(find('legal-reconsent')!.querySelector('hilos-form-error')).toBe(
      error,
    )
    expect(error.textContent).toContain('The terms changed. Review them again.')
    session.source.succeed(reread.requestId, reread.action, reconsentContent())
    await settle()
    expect(find('modal')).toBe(modal)
    await expect
      .poll(() => {
        fixture.detectChanges()
        return find('legal-reconsent-later')!.matches(':disabled')
      })
      .toBe(false)
    expect(find('legal-reconsent-refuse')!.matches(':disabled')).toBe(false)
    expect(find('modal-close')!.matches(':disabled')).toBe(false)
    expect(find('legal-reconsent-accept')!.matches(':disabled')).toBe(false)
    expect(session.source.sent).toHaveLength(calls + 2)
    expect(find('legal-reconsent-document')).not.toBeNull()
    find('legal-reconsent-accept')!.click()
    fixture.detectChanges()
    const next = session.source.sent.at(-1)!
    expect(session.source.sent).toHaveLength(calls + 3)
    session.source.succeed(next.requestId, next.action)
    await settle()
    expect(windowShown()).toBe(false)
    expect(find('modal-confirm')).toBeNull()
    fixture.destroy()
  })

  it('keeps the selected text mounted and subjects the custom Accept to the independent connection lock', async () => {
    const { session, fixture, feed, settle } = await openWindow()
    const read = session.source.sent.at(-1)!
    session.source.succeed(read.requestId, read.action, reconsentContent())
    await settle()
    find('legal-reconsent-full-text')!.click()
    await settle()
    const text = find('legal-revision-text')!
    expect(text).not.toBeNull()
    const back = find('legal-reconsent-back')!
    const accept = find('legal-reconsent-accept')!
    accept.click()
    fixture.detectChanges()
    const request = session.source.sent.at(-1)!
    expect(back.matches(':disabled')).toBe(true)
    back.click()
    expect(find('legal-revision-text')).toBe(text)
    feed.push({ state: 'disconnected' }, 'state')
    await settle()
    expect(find('legal-revision-text')).toBe(text)
    expect(find('legal-reconsent-accept')).toBe(accept)
    expect(accept.matches(':disabled')).toBe(true)
    const sent = session.source.sent.length
    accept.click()
    expect(session.source.sent).toHaveLength(sent)
    // The owner can still close a blocked modal when its successful reply lands.
    session.source.succeed(request.requestId, request.action)
    await settle()
    expect(windowShown()).toBe(false)
    fixture.destroy()
  })

  it('draws the yellow document icon after the user region with the days left', () => {
    vi.useFakeTimers()
    vi.setSystemTime(Date.UTC(2026, 10, 1))
    const session = bindSession()
    unbind = session.unbind
    session.handshake(reconsentHandshake(inWindow))

    const fixture = mountShell()
    const root = fixture.nativeElement as HTMLElement

    const icon = root.querySelector('[data-id="legal-reconsent-icon"]')
    expect(icon?.classList.contains('text-warning')).toBe(true)
    expect(icon?.querySelector('.bi-file-earmark-text')).not.toBeNull()
    expect(icon?.getAttribute('title')).toBe(
      'The terms have changed — 9 days left to decide',
    )
    expect(windowShown()).toBe(false)
    fixture.destroy()
  })

  it('says "please review them" for a lapsed document under the remind setting', () => {
    const session = bindSession()
    unbind = session.unbind
    session.handshake(reconsentHandshake(lapsed))

    const fixture = mountShell()
    const root = fixture.nativeElement as HTMLElement

    expect(
      root
        .querySelector('[data-id="legal-reconsent-icon"]')
        ?.getAttribute('title'),
    ).toBe('The terms have changed — please review them')
    expect(root.querySelector('[data-id="legal-frozen-screen"]')).toBeNull()
    fixture.destroy()
  })

  it('raises the window on a sign-in in this tab and opens it from the icon after Later', async () => {
    const session = bindSession()
    unbind = session.unbind
    session.handshake(reconsentHandshake(null))
    const fixture = mountShell()
    const root = fixture.nativeElement as HTMLElement
    expect(root.querySelector('[data-id="legal-reconsent-icon"]')).toBeNull()

    session.handshake(reconsentHandshake(inWindow))
    fixture.detectChanges()
    await fixture.whenStable()

    expect(windowShown()).toBe(true)
    expect(session.source.sent.at(-1)?.action).toBe('hilos_legal_reconsent')
    find('legal-reconsent-later')!.click()
    fixture.detectChanges()
    expect(windowShown()).toBe(false)
    ;(
      root.querySelector('[data-id="legal-reconsent-icon"]') as HTMLElement
    ).click()
    fixture.detectChanges()
    expect(windowShown()).toBe(true)
    fixture.destroy()
  })

  it('puts the freeze screen in place of the content with no icon', async () => {
    const session = bindSession()
    unbind = session.unbind
    session.handshake(reconsentHandshake({ ...lapsed, frozen: true }))

    const fixture = mountShell()
    await fixture.whenStable()
    fixture.detectChanges()
    const root = fixture.nativeElement as HTMLElement

    const screen = root.querySelector('[data-id="legal-frozen-screen"]')
    expect(screen?.querySelector('.bi-snow')).not.toBeNull()
    expect(
      screen
        ?.querySelector('[data-id="legal-reconsent"]')
        ?.getAttribute('data-variant'),
    ).toBe('frozen')
    expect(root.querySelector('[data-id="app-footer"]')).not.toBeNull()
    expect(root.querySelector('[data-id="legal-reconsent-icon"]')).toBeNull()
    expect(session.source.sent.at(-1)?.action).toBe('hilos_legal_reconsent')
    fixture.destroy()
  })

  it('leaves the pages the freeze keeps open to their content', () => {
    const session = bindSession()
    unbind = session.unbind
    session.handshake(reconsentHandshake({ ...lapsed, frozen: true }))
    TestBed.configureTestingModule({
      providers: [
        { provide: HILOS_ROUTER, useValue: routerOn(HilosPages.PROFILE_DATA) },
      ],
    })

    const fixture = mountShell()
    const root = fixture.nativeElement as HTMLElement

    expect(root.querySelector('[data-id="legal-frozen-screen"]')).toBeNull()
    fixture.destroy()
  })

  it('covers an address with no page with the freeze screen', () => {
    const session = bindSession()
    unbind = session.unbind
    session.handshake(reconsentHandshake({ ...lapsed, frozen: true }))
    TestBed.configureTestingModule({
      providers: [{ provide: HILOS_ROUTER, useValue: routerOn(null) }],
    })

    const fixture = mountShell()
    const root = fixture.nativeElement as HTMLElement

    expect(root.querySelector('[data-id="legal-frozen-screen"]')).not.toBeNull()
    fixture.destroy()
  })

  it('draws no icon under the maintenance surface', () => {
    const session = bindSession()
    unbind = session.unbind
    session.handshake(reconsentHandshake(inWindow))

    const fixture = mountShell(FROZEN)
    const root = fixture.nativeElement as HTMLElement

    expect(root.querySelector('[data-id="legal-reconsent-icon"]')).toBeNull()
    fixture.destroy()
  })
})

describe('HilosLayout admin gear', () => {
  let unbind: (() => void) | undefined

  afterEach(() => {
    unbind?.()
    unbind = undefined
  })

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

  /**
   * The gear of a freshly mounted shell, or null when it draws none.
   *
   * @param fixture The mounted shell.
   */
  function gear(fixture: ReturnType<typeof mountShell>): Element | null {
    return (fixture.nativeElement as HTMLElement).querySelector(
      '[data-id="nav-admin"]',
    )
  }

  it('draws no gear for a guest on a node without the view mode', () => {
    const session = bindSession()
    unbind = session.unbind
    session.handshake(greeting(null, false))

    expect(gear(mountShell())).toBeNull()
  })

  it('draws no gear for a signed-in non-admin on a node without the view mode', () => {
    const session = bindSession()
    unbind = session.unbind
    session.handshake(greeting({ id: 7, admin: false }, false))

    expect(gear(mountShell())).toBeNull()
  })

  it('draws the full gear for an admin, leading to the dashboard', () => {
    const session = bindSession()
    unbind = session.unbind
    session.handshake(greeting({ id: 7, admin: true }, false))
    const link = gear(mountShell())

    expect(link).not.toBeNull()
    expect(link?.getAttribute('data-access')).toBe('full')
    expect(link?.getAttribute('href')).toBe('/hilos')
    expect(link?.getAttribute('aria-label')).toBe('Hilos dashboard')
    expect(link?.getAttribute('title')).toBeNull()
    expect(link?.querySelector('.visually-hidden')?.textContent).toBe(
      'Hilos dashboard',
    )
    expect(
      link?.querySelector('.bi-gear')?.classList.contains('text-info-emphasis'),
    ).toBe(false)
    expect(link?.querySelector('.bi-gear')?.getAttribute('aria-hidden')).toBe(
      'true',
    )
    expect(link?.querySelectorAll('.bi-eye')).toHaveLength(0)
  })

  it.each([
    ['a guest', null],
    ['a signed-in non-admin', { id: 7, admin: false }],
  ])('draws the view gear for %s on a node in the view mode', (_who, user) => {
    const session = bindSession()
    unbind = session.unbind
    session.handshake(greeting(user, true))
    const link = gear(mountShell())

    expect(link).not.toBeNull()
    expect(link?.getAttribute('data-access')).toBe('view')
    expect(link?.getAttribute('href')).toBe('/hilos')
    expect(link?.getAttribute('aria-label')).toBe('Hilos dashboard — View mode')
    expect(link?.getAttribute('title')).toBe('Hilos dashboard — View mode')
    expect(link?.querySelector('.visually-hidden')?.textContent).toBe(
      'Hilos dashboard — View mode',
    )
    expect(
      link?.querySelector('.bi-gear')?.classList.contains('text-info-emphasis'),
    ).toBe(true)
    expect(link?.querySelector('.bi-gear')?.getAttribute('aria-hidden')).toBe(
      'true',
    )
    expect(link?.querySelectorAll('.bi-eye')).toHaveLength(1)
    expect(link?.querySelector('.bi-eye')?.getAttribute('aria-hidden')).toBe(
      'true',
    )
  })

  it('turns the gear full on a grant and back to view on a revoke, live', () => {
    const session = bindSession()
    unbind = session.unbind
    session.handshake(greeting({ id: 7, admin: false }, true))
    const fixture = mountShell()

    session.handshake(greeting({ id: 7, admin: true }, true))
    fixture.detectChanges()
    const fullLinks = (fixture.nativeElement as HTMLElement).querySelectorAll(
      '[data-id="nav-admin"]',
    )
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
        .querySelector('.bi-gear')
        ?.classList.contains('text-info-emphasis'),
    ).toBe(false)
    expect(fullLink.querySelectorAll('.bi-eye')).toHaveLength(0)

    session.handshake(greeting({ id: 7, admin: false }, true))
    fixture.detectChanges()
    const viewLinks = (fixture.nativeElement as HTMLElement).querySelectorAll(
      '[data-id="nav-admin"]',
    )
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
        .querySelector('.bi-gear')
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

    expect(gear(mountShell(FROZEN))).toBeNull()
  })
})

describe('HilosLayout view-mode strip (HIL-1260)', () => {
  let unbind: (() => void) | undefined

  afterEach(() => {
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
   * Stand the shell's router on one route.
   *
   * @param admin Whether the route is an admin one.
   */
  function routeOn(admin: boolean): void {
    TestBed.configureTestingModule({
      providers: [
        {
          provide: HILOS_ROUTER,
          useValue: routerOn(
            admin ? HilosPages.DASHBOARD : HilosPages.PROFILE_DATA,
            admin,
          ),
        },
      ],
    })
  }

  /**
   * The view-mode strip of a mounted shell, or null when it draws none.
   *
   * @param fixture The mounted shell.
   */
  function strip(fixture: { nativeElement: unknown }): Element | null {
    return (fixture.nativeElement as HTMLElement).querySelector(
      '[data-id="view-mode-banner"]',
    )
  }

  it('tells a viewer on an admin route that the screen may be looked at and not changed', () => {
    const session = bindSession()
    unbind = session.unbind
    session.handshake(viewerHandshake(false, true))
    routeOn(true)

    const shown = strip(mountShell())

    expect(shown?.classList.contains('alert')).toBe(true)
    expect(shown?.classList.contains('alert-secondary')).toBe(true)
    expect(shown?.classList.contains('border-0')).toBe(true)
    expect(shown?.querySelector('.bi-eye')?.getAttribute('aria-hidden')).toBe(
      'true',
    )
    const text = shown?.querySelector('#hilos-view-mode-strip-text')
    expect(text?.querySelector('strong')?.textContent?.trim()).toBe('View mode')
    expect(text?.textContent?.replace(/\s+/g, ' ').trim()).toBe(
      'View mode · You can look around, but not change anything.',
    )
  })

  it('stands after the session strips and before the project strip', () => {
    const session = bindSession()
    unbind = session.unbind
    session.handshake(viewerHandshake(false, true, NOW + 3 * DAY_MS))
    routeOn(true)

    const fixture = TestBed.createComponent(BannerHost)
    fixture.componentInstance.connection = fakeConnection(ADMITTED)
    fixture.detectChanges()

    const region = (fixture.nativeElement as HTMLElement).querySelector(
      '[data-id="app-banner"]',
    )
    const order = Array.from(region?.children ?? []).map((child) =>
      child.getAttribute('data-id'),
    )
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
    routeOn(adminRoute)

    expect(strip(mountShell())).toBeNull()
  })

  it('draws no strip under the maintenance surface', () => {
    const session = bindSession()
    unbind = session.unbind
    session.handshake(viewerHandshake(false, true))
    routeOn(true)

    expect(strip(mountShell(FROZEN))).toBeNull()
  })

  it('leaves on a grant and comes back on a revoke, live', () => {
    const session = bindSession()
    unbind = session.unbind
    session.handshake(viewerHandshake(false, true))
    routeOn(true)
    const fixture = mountShell()
    expect(strip(fixture)).not.toBeNull()

    session.handshake(viewerHandshake(true, true))
    fixture.detectChanges()
    expect(strip(fixture)).toBeNull()

    session.handshake(viewerHandshake(false, true))
    fixture.detectChanges()
    expect(strip(fixture)).not.toBeNull()
  })
})

/**
 * A host for the theme icon's place: the shell with a project's user region
 * projected, so the icon can be seen standing before it.
 */
@Component({
  selector: 'test-themed-host',
  imports: [HilosLayout],
  template: `
    <hilos-layout [connection]="connection">
      <span user data-id="user-slot">Ada</span>
      <p data-id="page-body">Page</p>
    </hilos-layout>
  `,
})
class ThemedHost {
  connection = fakeConnection()
}

describe('HilosLayout theme icon (HIL-1441)', () => {
  let unbind: (() => void) | undefined
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

  /**
   * Mount the shell around a projected user region.
   *
   * @param connection The connection double the shell reads.
   * @returns The host's root element, already checked once.
   */
  function renderThemed(connection: HilosConnection = fakeConnection()) {
    const fixture = TestBed.createComponent(ThemedHost)
    fixture.componentInstance.connection = connection
    fixture.detectChanges()

    return fixture.nativeElement as HTMLElement
  }

  function surface(container: Element, id: string): Element | null {
    return container.querySelector(`[data-id="${id}"]`)
  }

  afterEach(() => {
    unbind?.()
    unbind = undefined
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
      const container = renderThemed()
      const group = container.querySelector(
        'nav .d-flex.align-items-center.gap-2',
      ) as HTMLElement
      const theme = surface(group, 'nav-theme') as HTMLElement

      expect(group.firstElementChild?.contains(theme)).toBe(true)
      expect(group.firstElementChild?.tagName).toBe('HILOS-THEME-MENU')
      expect(
        theme.compareDocumentPosition(
          surface(group, 'user-slot') as HTMLElement,
        ) & Node.DOCUMENT_POSITION_FOLLOWING,
      ).not.toBe(0)
      expect(theme.getAttribute('aria-label')).toBe('Theme: System')
    },
  )

  it('draws no theme icon under maintenance', () => {
    const container = renderThemed(fakeConnection(FROZEN))

    expect(surface(container, 'maintenance')).not.toBeNull()
    expect(surface(container, 'nav-theme')).toBeNull()
    expect(container.querySelector('hilos-theme-menu')).toBeNull()
    expect(surface(container, 'conn-state')).not.toBeNull()
  })

  it('draws no theme icon during the held first frame', () => {
    const connection = fakeConnection()
    Object.defineProperty(connection, 'firstFrameHeld', { value: true })
    const container = renderThemed(connection)

    expect(surface(container, 'nav-theme')).toBeNull()
    expect(
      surface(container, 'hilos-boot-state')?.getAttribute('data-state'),
    ).toBe('held')
  })

  it('draws no theme icon when switching is off, and holds no room for it', () => {
    bindSnapshot(false)
    const container = renderThemed()

    expect(surface(container, 'nav-brand')).not.toBeNull()
    expect(surface(container, 'nav-theme')).toBeNull()
    expect(container.querySelector('hilos-theme-menu')?.classList).toContain(
      'd-none',
    )
  })

  it('keeps the icon in a takeover but closes it to writes', () => {
    const session = bindSession()
    unbind = session.unbind
    session.handshake(TAKEOVER)
    const container = renderThemed()
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

/** A conditional projection stays one nav slot, with no new project input. */
@Component({
  selector: 'test-responsive-shell-host',
  imports: [HilosLayout],
  template: `
    <hilos-layout [connection]="connection()">
      <ng-container ngProjectAs="[nav]">
        @if (visible()) {
          <a href="/hidden" hidden>Hidden</a>
          <a href="/first" data-id="project-nav-first">First</a>
          <a href="/second" data-id="project-nav-second">Second</a>
        }
      </ng-container>
      <ng-container ngProjectAs="[user]">
        <span data-id="user-bell">Bell</span>
        <span data-id="user-avatar">Avatar</span>
        <a href="/signin" data-id="user-signin">Sign in</a>
      </ng-container>
      <p>Page</p>
    </hilos-layout>
  `,
})
class ResponsiveShellHost {
  connection = signal(fakeConnection())
  visible = signal(true)
}

/** Mount the projection seam with the same navigation and connection changes as its peers. */
function renderResponsive(
  feed: ReturnType<typeof shellFeed>,
  initiallyVisible = true,
) {
  const route = createSignal<PageRouteMatch>({
    page: null,
    params: {},
    admin: false,
  })
  const router = { ...routerOn(null), currentRoute: route } as HilosRouter
  TestBed.configureTestingModule({
    providers: [{ provide: HILOS_ROUTER, useValue: router }],
  })
  const fixture = TestBed.createComponent(ResponsiveShellHost)
  fixture.componentInstance.connection.set(feed.connection)
  fixture.componentInstance.visible.set(initiallyVisible)
  fixture.detectChanges()
  const root = fixture.nativeElement as HTMLElement
  root.addEventListener('click', (event) => event.preventDefault())
  const settle = async (): Promise<void> => {
    fixture.detectChanges()
    await Promise.resolve()
    fixture.detectChanges()
    await fixture.whenStable()
    fixture.detectChanges()
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
        fixture.componentInstance.visible.set(value)
      }),
    connection: (value: HilosConnection) =>
      update(() => {
        fixture.componentInstance.connection.set(value)
      }),
    dispose(): void {
      fixture.destroy()
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

/** A real projected consumer; the shell's own view must see this same core instance. */
@Component({ selector: 'test-interaction-probe', template: '' })
class InteractionProbe {
  readonly interaction = injectConnectionInteraction()
  readonly state = inject(HILOS_CONNECTION_INTERACTION, { optional: true })
}

@Component({
  selector: 'test-viewing-action',
  imports: [LoadingButton],
  providers: [
    { provide: HILOS_ADMIN_VIEW_MODE, useValue: signal(true).asReadonly() },
  ],
  template:
    '<button hilosLoadingButton data-id="page-view" aria-describedby="validation">View</button>',
})
class ViewingAction {}

@Component({
  selector: 'test-interaction-host',
  providers: [
    { provide: HILOS_ROUTER, useFactory: () => routerOn(null, true) },
  ],
  imports: [HilosLayout, LoadingButton, InteractionProbe, ViewingAction],
  template: `
    <hilos-layout
      [connection]="connection()"
      [allowDisconnectedInteraction]="allow()"
    >
      <a nav href="/next">Next</a>
      <test-interaction-probe user />
      <test-interaction-probe />
      <test-viewing-action />
      <input
        data-id="draft"
        [attr.aria-describedby]="description()"
        [disabled]="disabled()"
      />
      <input type="file" data-id="file" />
      <select data-id="choice">
        <option value="a">A</option>
        <option value="b">B</option>
      </select>
      <button
        hilosLoadingButton
        [opensWindow]="true"
        data-id="page-open"
        (click)="clicks()"
      >
        Open
      </button>
      <button hilosLoadingButton data-id="page-mutate" (click)="clicks()">
        Save
      </button>
      <a href="/action" data-id="page-link" (click)="clicks()">Action</a>
    </hilos-layout>
  `,
})
class InteractionHost {
  readonly connection = signal(fakeConnection())
  readonly allow = signal(false)
  readonly disabled = signal(false)
  readonly description = signal('validation')
  readonly clicks = vi.fn()
}

/** Retain the actual shell, projected nodes and injector over live input changes. */
function renderInteraction(feed: ReturnType<typeof shellFeed>, allow = false) {
  const fixture = TestBed.createComponent(InteractionHost)
  const host = fixture.componentInstance
  host.connection.set(feed.connection)
  host.allow.set(allow)
  fixture.detectChanges()
  const settle = async () => {
    fixture.detectChanges()
    await fixture.whenStable()
    fixture.detectChanges()
  }
  const update = async (run: () => void) => {
    run()
    await settle()
  }
  const probes = fixture.debugElement
    .queryAll(By.directive(InteractionProbe))
    .map((element) => element.componentInstance as InteractionProbe)
  const layout = fixture.debugElement.query(By.directive(HilosLayout))
  return {
    root: fixture.nativeElement as HTMLElement,
    probes,
    layout,
    fixture,
    clicks: host.clicks,
    settle,
    update,
    configure: (options: {
      connection?: HilosConnection
      allow?: boolean
      disabled?: boolean
      description?: string
    }) =>
      update(() => {
        if (options.connection !== undefined)
          host.connection.set(options.connection)
        if (options.allow !== undefined) host.allow.set(options.allow)
        if (options.disabled !== undefined) host.disabled.set(options.disabled)
        if (options.description !== undefined)
          host.description.set(options.description)
      }),
    dispose() {
      fixture.destroy()
    },
  }
}
describe('HilosLayout connection interaction (HIL-1651)', () => {
  let shell: ReturnType<typeof renderInteraction> | undefined
  let unbind: (() => void) | undefined
  afterEach(() => {
    shell?.dispose()
    shell = undefined
    unbind?.()
    unbind = undefined
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

  it('shares one core state through projection and the shell-owned modal DI, with a readonly standalone fallback', async () => {
    const outside = TestBed.runInInjectionContext(() =>
      injectConnectionInteraction(),
    )
    expect(outside.unavailable.get()).toBe(false)
    expect(outside.blocked.get()).toBe(false)
    expect('set' in outside.blocked).toBe(false)
    const feed = shellFeed()
    shell = renderInteraction(feed)
    expect(shell.probes).toHaveLength(2)
    const state = shell.probes[0]!.state!.get()
    expect(shell.probes[1]!.state!.get()).toBe(state)
    const modal = shell.fixture.debugElement.query(By.directive(HilosModal))
    expect(modal.injector.get(HILOS_CONNECTION_INTERACTION).get()).toBe(state)
    await shell.update(() => feed.push({ state: 'disconnected' }, 'state'))
    for (const probe of shell.probes) {
      expect(probe.interaction.unavailable.get()).toBe(true)
      expect(probe.interaction.blocked.get()).toBe(true)
    }
    const independent = TestBed.createComponent(HilosLayout)
    independent.componentRef.setInput('connection', fakeConnection())
    independent.detectChanges()
    expect(
      independent.componentInstance.connectionInteraction.get()?.blocked.get(),
    ).toBe(false)
    independent.destroy()
  })

  it('updates opt-out and the connection live, releases replaced sources and remounts cleanly', async () => {
    const old = shellFeed()
    old.push({ state: 'disconnected' }, 'state')
    shell = renderInteraction(old)
    const fieldset = get<HTMLFieldSetElement>('page-interaction-scope')
    await shell.configure({ allow: true })
    expect(fieldset.disabled).toBe(false)
    expect(shell.probes[0]!.interaction.unavailable.get()).toBe(true)
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
      // Call the mounted listener and the shell method before Angular's next render.
      await shell.update(() => {
        feed.push({ state: 'reconnecting' }, 'state')
        button.click()
        const handler =
          id === 'nav-logout'
            ? 'onSignOut'
            : id === 'impersonation-stop'
              ? 'onImpersonationStop'
              : 'onKeepAccount'
        const layout = shell!.layout.componentInstance as Record<
          string,
          () => void
        >
        layout[handler]!()
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
