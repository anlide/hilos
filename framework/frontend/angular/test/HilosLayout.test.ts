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
// Order is read off the shell's child order rather than off heights: jsdom does
// not lay out, so every height there is zero and a measurement would pass on a
// broken region too.
//
// The wiring these cases run on — the DOM environment, the TestBed platform, and
// the module reset between cases — lives in the package's vitest.setup.ts and was
// laid down by HIL-848; this file configures none of it.
import { Component } from '@angular/core'
import { TestBed } from '@angular/core/testing'
import {
  ActionLifecycle,
  bindImpersonation,
  bindSessionScope,
  hilosToasts,
  IMPERSONATION_ACTION_STOP,
  PROTECTED_MODE_INACTIVE,
  RT_STALENESS_FRESH,
  ScopeManager,
  type ActionErrorSignal,
  type ActionLifecycleSource,
  type ActionSuccessSignal,
  type ConnectionState,
  type HilosConnection,
  type ProjectSignal,
  type ProtectedModeStatus,
  type RtStalenessStatus,
} from '@hilos/core'
import { afterEach, describe, expect, it } from 'vitest'

import { HilosLayout } from '../src/HilosLayout.js'

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

  succeed(requestId: string | undefined): void {
    for (const listener of this.success) {
      listener({
        kind: 'actionSuccess',
        action: IMPERSONATION_ACTION_STOP,
        requestId,
        envelope: { type: 'action_success', data: {} },
      } as ActionSuccessSignal)
    }
  }

  refuse(requestId: string | undefined, reason: string): void {
    for (const listener of this.error) {
      listener({
        kind: 'actionError',
        action: IMPERSONATION_ACTION_STOP,
        reason,
        requestId,
        envelope: { type: 'action_error', data: {} },
      } as ActionErrorSignal)
    }
  }
}

/**
 * Bind the strip's store the way bootHilos does, over a session scope fed by
 * handshakes this harness emits.
 *
 * @returns The lifecycle source, the unbind, and the handshake emitter.
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
  const unbind = bindImpersonation(scopes, new ActionLifecycle(source))

  return {
    source,
    unbind,
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

/**
 * Let the tracked driver settle on the reply it was just given.
 *
 * @returns Resolves after the driver's awaited handle has run its finally.
 */
async function settle(): Promise<void> {
  await new Promise((resolve) => setTimeout(resolve, 0))
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
    expect(stop.disabled).toBe(true)

    session.source.succeed(session.source.sent[0]?.requestId)
    await settle()
    fixture.detectChanges()

    expect(stop.disabled).toBe(false)
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
