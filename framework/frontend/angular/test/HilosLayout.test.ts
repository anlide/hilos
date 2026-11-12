// Banner cases are shared through layoutBannerCases and the native mount.
// The remaining Layout areas below keep their local scenarios and fixtures.
//
// Impersonation strip cases share their steps through layoutImpersonationCases.
//
// Standing identity, moments, visibility and cancellation use shared cases.
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
import { Component, DestroyRef, inject, signal } from '@angular/core'
import { By } from '@angular/platform-browser'
import { TestBed } from '@angular/core/testing'
import type { ComponentFixture } from '@angular/core/testing'
import {
  ActionLifecycle,
  bindAccountBlocked,
  bindAccessLoss,
  bindAdminAccess,
  bindAccountStanding,
  bindImpersonation,
  bindLegalReconsent,
  createSignal,
  HilosPages,
  bindSessionScope,
  bindSignOut,
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
  type HilosRouter,
  type PageRouteMatch,
  type ProjectSignal,
  type ProtectedModeStatus,
  type RtStalenessStatus,
  type WritableSignal,
} from '@hilos/core/base'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { registerLayoutConnectionPageCases } from '../../test-support/layoutConnectionPageCases.js'
import { registerLayoutConnectionModalCases } from '../../test-support/layoutConnectionModalCases.js'
import { registerLayoutConnectionContextCases } from '../../test-support/layoutConnectionContextCases.js'
import {
  mountLayoutConnection,
  mountStandaloneConnection,
} from './layoutConnectionMount.js'
import { registerLayoutBlockedLiveCases } from '../../test-support/layoutBlockedLiveCases.js'
import { registerLayoutBlockedCases } from '../../test-support/layoutBlockedCases.js'
import { registerLayoutStandingCancelCases } from '../../test-support/layoutStandingCancelCases.js'
import { registerLayoutStandingChromeCases } from '../../test-support/layoutStandingChromeCases.js'
import { registerLayoutStandingIdentityCases } from '../../test-support/layoutStandingIdentityCases.js'
import { registerLayoutProtectedResidueCases } from '../../test-support/layoutProtectedResidueCases.js'
import { registerLayoutCompactStalenessCases } from '../../test-support/layoutCompactStalenessCases.js'
import { registerLayoutControlOrderCases } from '../../test-support/layoutControlOrderCases.js'
import { registerLayoutStandingMomentCases } from '../../test-support/layoutStandingMomentCases.js'
import { registerLayoutStandingVisibilityCases } from '../../test-support/layoutStandingVisibilityCases.js'
import { registerLayoutReconsentReminderCases } from '../../test-support/layoutReconsentReminderCases.js'
import { registerLayoutReconsentFrozenCases } from '../../test-support/layoutReconsentFrozenCases.js'
import { registerLayoutReconsentLoadingCases } from '../../test-support/layoutReconsentLoadingCases.js'
import { registerLayoutReconsentAcceptanceCases } from '../../test-support/layoutReconsentAcceptanceCases.js'
import { registerLayoutImpersonationCases } from '../../test-support/layoutImpersonationCases.js'
import { registerLayoutSignOutCases } from '../../test-support/layoutSignOutCases.js'
import {
  registerLayoutThemeCases,
  type LayoutThemeCheckpoint,
} from '../../test-support/layoutThemeCases.js'
import { registerLayoutViewModeCases } from '../../test-support/layoutViewModeCases.js'
import { registerLayoutAdminGearCases } from '../../test-support/layoutAdminGearCases.js'
import { registerLayoutFooterCases } from '../../test-support/layoutFooterCases.js'
import { registerLayoutBannerCases } from '../../test-support/layoutBannerCases.js'
import { registerLayoutNavigationCases } from '../../test-support/layoutNavigationCases.js'
import { registerLayoutLifetimeCases } from '../../test-support/layoutLifetimeCases.js'
import {
  registerLayoutConnectionShellCases,
  type LayoutConnectionShellView,
} from '../../test-support/layoutConnectionShellCases.js'
import { registerLayoutConnectionBranchCases } from '../../test-support/layoutConnectionBranchCases.js'
import {
  mountLayoutLifetime,
  type AngularLayoutLifetimeView,
} from './layoutLifetimeMount.js'
import type { LayoutLifetimeCheckpoint } from '../../test-support/layoutLifetimeFixtures.js'
import { mountLayoutBlocked } from './layoutBlockedMount.js'
import { mountLayoutChrome } from './layoutChromeMount.js'
import { HilosLayout } from '../src/HilosLayout.js'
import { HilosPageTemplate } from '../src/HilosPageTemplate.js'
import { HilosView } from '../src/HilosView.js'
import { HilosStatusPage as HilosPublicStatusPage } from '../src/public/HilosStatusPage.js'
import { LoadingButton } from '../src/LoadingButton.js'
import { HilosModal } from '../src/HilosModal.js'
import { HilosOAuthWaitModal } from '../src/auth/HilosOAuthWaitModal.js'
import {
  HILOS_CONNECTION_INTERACTION,
  injectConnectionInteraction,
} from '../src/hilosConnectionInteraction.js'
import { HILOS_ADMIN_VIEW_MODE } from '../src/hilosLookOnly.js'
import { HILOS_ROUTER } from '../src/hilosRouterToken.js'

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

/** A node frozen for a restore: the shell turns into the maintenance surface. */
const FROZEN: ProtectedModeStatus = {
  ...PROTECTED_MODE_INACTIVE,
  active: true,
  operation: 'restore',
  title: 'Restoring a backup',
  message: 'The application will be back in a few minutes.',
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
  // Only Angular projects carry a document base, so only here a fragment link would leave the page.
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

registerLayoutImpersonationCases(
  'Angular',
  { describe, it, expect },
  mountLayoutChrome,
)
registerLayoutSignOutCases(
  'Angular',
  { describe, it, expect },
  mountLayoutChrome,
)

registerLayoutStandingMomentCases(
  'Angular',
  { describe, it, expect, vi },
  mountLayoutChrome,
)

registerLayoutStandingChromeCases(
  'Angular',
  { describe, it, expect, vi },
  mountLayoutChrome,
)
registerLayoutProtectedResidueCases(
  'Angular',
  { describe, it, expect, vi },
  mountLayoutChrome,
)
registerLayoutCompactStalenessCases(
  'Angular',
  { describe, it, expect },
  mountLayoutChrome,
)
registerLayoutControlOrderCases(
  'Angular',
  { describe, it, expect },
  mountLayoutChrome,
  bindSession,
)

registerLayoutStandingVisibilityCases(
  'Angular',
  { describe, it, expect, vi },
  mountLayoutChrome,
  { dataId: 'test-banner', text: 'Acting for someone else' },
)

registerLayoutStandingCancelCases(
  'Angular',
  { describe, it, expect },
  mountLayoutChrome,
)

registerLayoutStandingIdentityCases(
  'Angular',
  { describe, it, expect },
  mountLayoutChrome,
)

registerLayoutBlockedLiveCases(
  'Angular',
  { describe, it, expect },
  mountLayoutBlocked,
)

registerLayoutBlockedCases(
  'Angular',
  { describe, it, expect },
  mountLayoutChrome,
)

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

registerLayoutReconsentReminderCases(
  'Angular',
  { describe, it, expect, vi },
  mountLayoutChrome,
)

registerLayoutReconsentFrozenCases(
  'Angular',
  { describe, it, expect },
  mountLayoutChrome,
)

registerLayoutReconsentLoadingCases(
  'Angular',
  { describe, it, expect },
  mountLayoutChrome,
)

registerLayoutReconsentAcceptanceCases(
  'Angular',
  {
    describe,
    it,
    expect,
    vi,
    createBusyRetryCheckpoint() {
      // Angular exposes a persistent hilos-form-error component host; Vue and React render no equivalent native host element.
      let error: Element | null = null
      return (root, phase) => {
        const host = root.querySelector(
          '[data-id="legal-reconsent"] hilos-form-error',
        )
        if (phase === 'ready') {
          expect(host).not.toBeNull()
          error = host
        } else {
          expect(host).toBe(error)
        }
      }
    },
  },
  mountLayoutChrome,
)

registerLayoutConnectionPageCases(
  'Angular',
  { describe, it, expect },
  mountLayoutConnection,
)

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
  imports: [
    HilosLayout,
    HilosPageTemplate,
    LoadingButton,
    HilosModal,
    InteractionProbe,
    ViewingAction,
  ],
  template: `
    <hilos-layout
      [connection]="connection()"
      [allowDisconnectedInteraction]="allow()"
    >
      <a nav href="/next">Next</a>
      <test-interaction-probe user />
      <ng-template hilosPage>
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
        <hilos-modal
          title="Edit draft"
          [open]="modalOpen()"
          [confirmOnClose]="true"
        >
          <input data-id="modal-initial" data-autofocus />
          <input data-id="modal-draft" />
        </hilos-modal>
      </ng-template>
    </hilos-layout>
  `,
})
class InteractionHost {
  readonly connection = signal(fakeConnection())
  readonly allow = signal(false)
  readonly modalOpen = signal(false)
  readonly disabled = signal(false)
  readonly description = signal('validation')
  readonly clicks = vi.fn()
}

/** Retain the actual shell, projected nodes and injector over live input changes. */
function renderInteraction(
  feed: { connection: HilosConnection },
  allow = false,
) {
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
/** Preserve this host's native stale-handler entry and lifecycle. */
function mountConnectionShell(input: {
  connection: HilosConnection
  allow?: boolean
}): LayoutConnectionShellView {
  const shell = renderInteraction(input, input.allow)
  let destroyed = false
  return {
    root: shell.root,
    invokeStale(id) {
      shell.root.querySelector<HTMLButtonElement>(`[data-id="${id}"]`)!.click()
      const handler =
        id === 'nav-logout'
          ? 'onSignOut'
          : id === 'impersonation-stop'
            ? 'onImpersonationStop'
            : 'onKeepAccount'
      const layout = shell.layout.componentInstance as Record<
        string,
        () => void
      >
      layout[handler]!()
    },
    async flush(run) {
      await shell.update(run ?? (() => {}))
    },
    async unmount() {
      if (destroyed) return
      destroyed = true
      try {
        shell.dispose()
      } finally {
        shell.root.remove()
      }
    },
  }
}

registerLayoutConnectionShellCases(
  'Angular',
  { describe, it, expect },
  mountConnectionShell,
  bindSession,
)

registerLayoutConnectionBranchCases(
  'Angular',
  { describe, it, expect },
  mountConnectionShell,
  bindSession,
)

registerLayoutConnectionModalCases(
  'Angular',
  { describe, it, expect },
  mountLayoutConnection,
)

describe('HilosLayout connection interaction (HIL-1651)', () => {
  let shell: ReturnType<typeof renderInteraction> | undefined
  afterEach(() => {
    shell?.dispose()
    shell = undefined
    hilosToasts.clear()
  })
  const get = <T extends HTMLElement = HTMLElement>(id: string): T =>
    shell!.root.querySelector<T>(`[data-id="${id}"]`)!

  // Angular readonly token-holder identity across projection and a component injector
  // is DI wiring proof. Address the shell-owned modal explicitly; the current first
  // HilosModal query does not establish its owner.
  it('keeps Angular token-holder identity through projection and the shell-owned OAuth modal', () => {
    shell = renderInteraction(shellFeed())
    expect(shell.probes).toHaveLength(2)
    const state = shell.probes[0]!.state!.get()
    expect(shell.probes[1]!.state!.get()).toBe(state)
    const oauth = shell.layout.query(By.directive(HilosOAuthWaitModal))
    const modal = oauth.query(By.directive(HilosModal))
    expect(modal.injector.get(HILOS_CONNECTION_INTERACTION).get()).toBe(state)
  })

  // The Angular modal is physically nested in the projected page fieldset. The
  // setAttribute spy proves nested-state publication and focus parking before Angular
  // writes the ancestor disabled attribute; Vue Teleport and React portal do not have
  // this native ancestry.
  it('publishes the nested modal lock and parks focus before writing the ancestor disabled attribute', async () => {
    const feed = shellFeed()
    shell = renderInteraction(feed)
    await shell.update(() =>
      shell!.fixture.componentInstance.modalOpen.set(true),
    )
    const pageScope = get<HTMLFieldSetElement>('page-interaction-scope')
    const dialog = get('modal')
    const modalScope = get<HTMLFieldSetElement>('modal-interaction-scope')
    const field = get<HTMLInputElement>('modal-draft')
    expect(pageScope.contains(dialog)).toBe(true)

    // jsdom does not blur a field when an ancestor fieldset disables it.
    // Observe the lock at that DOM write; the native browser owns the blur.
    const beforeDisable: {
      blocked: boolean
      modalDisabled: boolean
      focus: Element | null
    }[] = []
    const setAttribute = pageScope.setAttribute.bind(pageScope)
    const write = vi
      .spyOn(pageScope, 'setAttribute')
      .mockImplementation((name, value) => {
        if (name === 'disabled') {
          beforeDisable.push({
            blocked: shell!.probes[0]!.interaction.blocked.get(),
            modalDisabled: modalScope.disabled,
            focus: document.activeElement,
          })
        }
        setAttribute(name, value)
      })
    try {
      for (const state of ['disconnected', 'reconnecting'] as const) {
        field.focus()
        await shell.update(() => {
          feed.push({ state }, 'state')
          expect(beforeDisable.at(-1)).toEqual({
            blocked: true,
            modalDisabled: true,
            focus: dialog,
          })
        })
        await shell.update(() => feed.push({ state: 'connected' }, 'state'))
      }
      expect(beforeDisable).toHaveLength(2)
    } finally {
      write.mockRestore()
    }
  })

  // The Angular source observes route-driven DOM and focus values synchronously
  // inside route.set, before detectChanges/whenStable. Preserve this publication
  // timing locally; the shared outcome is observed after each renderer commits its
  // actual route update.
  it('publishes the Angular public-route lock and focus before native detection', async () => {
    const feed = shellFeed()
    shell = renderInteraction(feed)
    const route = shell.layout.injector.get(HILOS_ROUTER)
      .currentRoute as WritableSignal<PageRouteMatch>
    await shell.update(() =>
      shell!.fixture.componentInstance.modalOpen.set(true),
    )
    const pageScope = get<HTMLFieldSetElement>('page-interaction-scope')
    const modalScope = get<HTMLFieldSetElement>('modal-interaction-scope')
    const dialog = get('modal')
    get<HTMLInputElement>('modal-draft').focus()
    await shell.update(() => feed.push({ state: 'disconnected' }, 'state'))
    await shell.update(() => {
      route.set({ page: HilosPages.PUBLIC_STATUS, params: {}, admin: false })
      expect(pageScope.disabled).toBe(false)
      expect(modalScope.disabled).toBe(true)
      expect(document.activeElement).toBe(dialog)
    })
    await shell.update(() => {
      route.set({ page: null, params: {}, admin: false })
      expect(pageScope.disabled).toBe(true)
    })
  })
})

@Component({
  selector: 'test-public-status-route',
  imports: [HilosPublicStatusPage],
  template: `<hilos-public-status-page
    [connection]="host.connection"
    [actions]="host.actions"
  />`,
})
class PublicStatusRoute {
  protected readonly host = inject(PublicStatusShellHost)
}

@Component({
  selector: 'test-public-status-shell',
  imports: [HilosLayout, HilosPageTemplate, HilosView],
  template: `
    <hilos-layout [connection]="connection">
      <span brand>Public test brand</span>
      <ng-template hilosPage>
        <hilos-view [pages]="pages" />
      </ng-template>
    </hilos-layout>
  `,
})
class PublicStatusShellHost {
  connection = fakeConnection()
  readonly actions = new ActionLifecycle(new ReplyingSource())
  readonly pages = {
    [HilosPages.PUBLIC_STATUS]: PublicStatusRoute,
    main: LiveBlockedPage,
  }
}

describe('public Status in the real Angular shell and outlet', () => {
  let fixture: ComponentFixture<PublicStatusShellHost> | undefined
  let session: ReturnType<typeof bindSession> | undefined
  async function settle() {
    fixture!.detectChanges()
    await vi.advanceTimersByTimeAsync(0)
    fixture!.detectChanges()
  }
  function mount(feed: ReturnType<typeof shellFeed>) {
    const router = routerOn(HilosPages.PUBLIC_STATUS)
    router.resolvePath = (page) =>
      page === HilosPages.PUBLIC_STATUS ? '/status' : '/'
    TestBed.configureTestingModule({
      providers: [{ provide: HILOS_ROUTER, useValue: router }],
    })
    vi.stubGlobal(
      'fetch',
      vi.fn(async (url: string) => ({
        ok: true,
        json: async () =>
          url.endsWith('/status.json')
            ? {
                schemaVersion: 2,
                revision: 'shell-status',
                generatedAt: Date.now(),
                config: { pollIntervalSeconds: 30, staleAfterSeconds: 120 },
                components: [
                  {
                    componentKey: 'site',
                    name: 'Site',
                    description: null,
                    state: 'operational',
                  },
                ],
                candidates: [],
                outages: [],
                scheduledWorks: [],
                maintenancePeriods: [],
              }
            : {
                schemaVersion: 2,
                revision: 'shell-status',
                aliveAt: Date.now(),
              },
      })),
    )
    session = bindSession(router)
    fixture = TestBed.createComponent(PublicStatusShellHost)
    fixture.componentInstance.connection = feed.connection
    return router
  }
  beforeEach(() => vi.useFakeTimers())
  afterEach(() => {
    const errors: unknown[] = []
    const release = (action: () => void) => {
      try {
        action()
      } catch (error) {
        errors.push(error)
      }
    }
    const host = fixture?.nativeElement as HTMLElement | undefined
    release(() => fixture?.destroy())
    release(() => host?.remove())
    fixture = undefined
    release(() => session?.unbind())
    session = undefined
    release(() => vi.unstubAllGlobals())
    release(() => vi.useRealTimers())
    release(() => TestBed.resetTestingModule())
    if (errors.length)
      throw new AggregateError(errors, 'Angular public Status cleanup failed')
  })

  // Angular componentInstance and DestroyRef are native Angular lifecycle APIs.
  // Keep these checks local; preserve shared DOM identity and reader continuity separately.
  it('retains the native Angular reader instance and DestroyRef through maintenance', async () => {
    const feed = shellFeed()
    mount(feed)
    await settle()
    const reader = fixture!.debugElement.query(
      By.directive(HilosPublicStatusPage),
    ).componentInstance as HilosPublicStatusPage
    const destroyed = vi.fn()
    fixture!.debugElement
      .query(By.directive(HilosPublicStatusPage))
      .injector.get(DestroyRef)
      .onDestroy(destroyed)
    for (const protectedMode of [FROZEN, PROTECTED_MODE_INACTIVE]) {
      feed.push({ protectedMode }, 'protectedMode')
      await settle()
      expect(
        fixture!.debugElement.query(By.directive(HilosPublicStatusPage))
          .componentInstance,
      ).toBe(reader)
      expect(destroyed).not.toHaveBeenCalled()
    }
  })
})

// Angular retains a component host when its template is empty; this checks
// native host identity, removal and visibility, which Vue and React do not expose.
function observeNativeThemeHost(
  checkpoint: LayoutThemeCheckpoint,
  root: HTMLElement,
): void {
  switch (checkpoint) {
    case 'T01-mounted':
      expect(
        root.querySelector('[data-id="user-slot"]')?.parentElement
          ?.firstElementChild?.tagName,
      ).toBe('HILOS-THEME-MENU')
      break
    case 'T02-maintenance':
      expect(root.querySelector('hilos-theme-menu')).toBeNull()
      break
    case 'T04-off':
      expect(root.querySelector('hilos-theme-menu')?.classList).toContain(
        'd-none',
      )
      break
  }
}

registerLayoutThemeCases(
  'Angular',
  { describe, it, expect },
  mountLayoutChrome,
  observeNativeThemeHost,
)

registerLayoutViewModeCases(
  'Angular',
  { describe, it, expect, afterEach },
  mountLayoutChrome,
)

registerLayoutAdminGearCases(
  'Angular',
  { describe, it, expect, afterEach },
  mountLayoutChrome,
)

registerLayoutBannerCases(
  'Angular',
  { describe, it, expect },
  mountLayoutChrome,
)

registerLayoutNavigationCases(
  'Angular',
  { describe, it, expect, vi },
  mountLayoutChrome,
)

registerLayoutFooterCases(
  'Angular',
  { describe, it, expect, vi },
  mountLayoutChrome,
)

// TemplateRef retains the declaration injector; constructor, DestroyRef and
// signal checks are Angular-only complements to the common exterior steps.
function observeNativePageLifetime(
  checkpoint: LayoutLifetimeCheckpoint,
  view: AngularLayoutLifetimeView,
): void {
  const { instances, router } = view
  switch (checkpoint) {
    case 'L01-created':
      expect(instances).toHaveLength(1)
      expect(instances[0]!.router).toBe(router)
      break
    case 'L01-edited':
      expect(instances[0]!.draft()).toBe('Unsaved draft')
      break
    case 'L01-replaced':
    case 'L02-replaced':
      expect(instances).toHaveLength(1)
      expect(instances[0]!.destroyed).toHaveBeenCalledTimes(1)
      break
    case 'L01-restored':
      expect(instances).toHaveLength(2)
      expect(instances[1]).not.toBe(instances[0])
      expect(instances[0]!.destroyed).toHaveBeenCalledTimes(1)
      expect(instances[1]!.destroyed).not.toHaveBeenCalled()
      expect(instances[1]!.draft()).toBe('')
      break
    case 'L02-created':
    case 'L03-released':
      expect(instances).toHaveLength(1)
      break
    case 'L02-restored':
      expect(instances).toHaveLength(2)
      break
    case 'L03-held':
      expect(instances).toHaveLength(0)
      break
    case 'L04-created':
    case 'L04-disconnected':
    case 'L04-connected':
      expect(instances).toEqual([view.events[0]!.instance])
      expect(instances[0]!.destroyed).not.toHaveBeenCalled()
      expect(instances[0]!.draft()).toBe('Keep this draft')
      break
  }
}

registerLayoutLifetimeCases(
  'Angular',
  { describe, it, expect },
  mountLayoutLifetime,
  observeNativePageLifetime,
)

registerLayoutConnectionContextCases(
  'Angular',
  { describe, it, expect },
  mountLayoutConnection,
  mountStandaloneConnection,
)
