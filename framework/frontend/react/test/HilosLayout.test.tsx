import { afterEach, describe, expect, it, vi } from 'vitest'
import { StrictMode, type ReactNode } from 'react'
import { createPortal } from 'react-dom'
import { act, cleanup, fireEvent, render } from '@testing-library/react'
import {
  ActionLifecycle,
  applyServerTime,
  bindAccessLoss,
  bindAccountBlocked,
  bindAccountStanding,
  bindAdminAccess,
  bindImpersonation,
  bindLegalReconsent,
  createSignal,
  bindSessionScope,
  bindSignOut,
  hilosLegalReconsent,
  hilosLegalReconsentOpen,
  hilosToasts,
  IMPERSONATION_ACTION_STOP,
  offsetMs,
  PROTECTED_MODE_INACTIVE,
  RT_STALENESS_FRESH,
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
import {
  layoutReconsentHandshake,
  layoutReconsentSession,
} from '../../test-support/layoutReconsentFixtures.js'
import {
  layoutStandingGreeting,
  layoutStandingSession,
  STANDING_NOW,
} from '../../test-support/layoutStandingFixtures.js'
import {
  BLOCKED_GREETING,
  layoutBlockedSession,
} from '../../test-support/layoutBlockedFixtures.js'
import { layoutChromeConnection } from '../../test-support/layoutChromeFixtures.js'
import { registerLayoutImpersonationCases } from '../../test-support/layoutImpersonationCases.js'
import { registerLayoutSignOutCases } from '../../test-support/layoutSignOutCases.js'
import { registerLayoutThemeCases } from '../../test-support/layoutThemeCases.js'
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
import { mountLayoutLifetime } from './layoutLifetimeMount.js'
import { mountLayoutBlocked } from './layoutBlockedMount.js'
import { mountLayoutChrome } from './layoutChromeMount.js'
import { HilosLayout } from '../src/HilosLayout.js'
import { LoadingButton } from '../src/LoadingButton.js'
import {
  useConnectionInteraction,
  type ConnectionInteraction,
} from '../src/hilosConnectionInteraction.js'
import { HilosAdminViewModeContext } from '../src/hilosLookOnly.js'
import { HilosRouterContext } from '../src/hilosRouterContext.js'

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
function bindSession(router: HilosRouter = routerOn('main')) {
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
  const unbindAccessLoss = bindAccessLoss(scopes, router)
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
        unbindAccessLoss()
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

// React updates from source rejection and effect cleanup must settle inside awaited act.
describe('HilosLayout blocked fixture pending dismiss cleanup', () => {
  it.each([0, 300])(
    'settles pending dismiss at native teardown after %d ms',
    async (elapsed) => {
      let session: Awaited<ReturnType<typeof layoutBlockedSession>> | undefined
      let view: Awaited<ReturnType<typeof mountLayoutChrome>> | undefined
      // Only timer functions are replaced; Date and the server offset stay real.
      expect(vi.isFakeTimers()).toBe(false)
      try {
        vi.useFakeTimers({
          toFake: [
            'setTimeout',
            'clearTimeout',
            'setInterval',
            'clearInterval',
          ],
        })
        expect(vi.getTimerCount()).toBe(0)
        session = await layoutBlockedSession()
        session.handshake(BLOCKED_GREETING)
        view = await mountLayoutChrome({ connection: layoutChromeConnection() })
        const dismiss = surface(
          view.root,
          'account-blocked-sign-out',
        ) as HTMLButtonElement
        await view.flush(() => dismiss.click())
        expect(session.sent).toEqual([
          {
            action: 'hilos_dismiss_account_blocked',
            data: {},
            requestId: expect.any(String),
          },
        ])
        expect(session.sent[0]?.requestId).toBeTruthy()
        await view.flush(() => vi.advanceTimersByTime(elapsed))

        await view.unmount()
        await act(session.release)
        expect(vi.getTimerCount()).toBe(0)
        const settledToasts = hilosToasts.toasts.get()
        expect(settledToasts).toEqual([
          expect.objectContaining({
            severity: 'error',
            message:
              'The connection dropped before the action completed. Please try again.',
            repeats: 1,
          }),
        ])

        await act(session.release)
        await act(async () => {
          vi.advanceTimersByTime(60_000)
        })
        expect(vi.getTimerCount()).toBe(0)
        expect(hilosToasts.toasts.get()).toEqual(settledToasts)
      } finally {
        try {
          await view?.unmount()
        } finally {
          try {
            if (session) await act(session.release)
          } finally {
            try {
              hilosToasts.clear()
            } finally {
              vi.useRealTimers()
            }
          }
        }
      }
    },
  )
})

// React updates from source rejection and effect cleanup must settle inside awaited act.
describe('HilosLayout standing fixture pending Keep cleanup', () => {
  it.each([0, 300])(
    'settles pending Keep at native teardown after %d ms',
    async (elapsed) => {
      const entryOffset = offsetMs()
      let session: Awaited<ReturnType<typeof layoutStandingSession>> | undefined
      let view: Awaited<ReturnType<typeof mountLayoutChrome>> | undefined
      try {
        vi.useFakeTimers({
          toFake: [
            'Date',
            'setInterval',
            'clearInterval',
            'setTimeout',
            'clearTimeout',
          ],
        })
        vi.setSystemTime(STANDING_NOW)
        expect(vi.getTimerCount()).toBe(0)
        session = await layoutStandingSession()
        session.handshake(
          layoutStandingGreeting(
            'deletion_scheduled',
            false,
            1_800_259_200_000,
            null,
          ),
        )
        view = await mountLayoutChrome({
          connection: layoutChromeConnection(),
        })
        const keep = surface(
          view.root,
          'account-deletion-strip-keep',
        ) as HTMLButtonElement
        await view.flush(() => keep.click())
        expect(session.sent).toEqual([
          {
            action: 'hilos_account_deletion_cancel',
            data: {},
            requestId: expect.any(String),
          },
        ])
        expect(session.sent[0]?.requestId).toBeTruthy()
        await view.flush(() => vi.advanceTimersByTime(elapsed))

        await view.unmount()
        await act(session.release)

        expect(vi.getTimerCount()).toBe(0)
        const settledToasts = hilosToasts.toasts.get()
        expect(settledToasts).toEqual([
          expect.objectContaining({
            severity: 'error',
            message:
              'The connection dropped before the action completed. Please try again.',
            repeats: 1,
          }),
        ])
        await act(session.release)
        await act(async () => {
          vi.advanceTimersByTime(60_000)
        })
        expect(vi.getTimerCount()).toBe(0)
        expect(hilosToasts.toasts.get()).toEqual(settledToasts)
      } finally {
        try {
          await view?.unmount()
        } finally {
          try {
            if (session) await act(session.release)
          } finally {
            try {
              hilosToasts.clear()
            } finally {
              try {
                applyServerTime(Date.now() + entryOffset)
              } finally {
                vi.useRealTimers()
              }
            }
          }
        }
      }
    },
  )
})

// A frozen screen has no open modal; its pending read and singleton state still
// belong to the released session fixture.
describe('HilosLayout legal fixture pending frozen read cleanup', () => {
  it('settles the frozen read and clears its closed-window store at native teardown', async () => {
    let session: Awaited<ReturnType<typeof layoutReconsentSession>> | undefined
    let view: Awaited<ReturnType<typeof mountLayoutChrome>> | undefined
    function expectIdle(): void {
      expect(hilosLegalReconsent.loading.get()).toBe(false)
      expect(hilosLegalReconsent.error.get()).toBeNull()
      expect(hilosLegalReconsent.content.get()).toBeNull()
      expect(hilosLegalReconsent.busy.get()).toBe(false)
      expect(hilosLegalReconsentOpen.get()).toBe(false)
      expect(surface(document.body, 'legal-reconsent-modal')).toBeNull()
    }
    expect(vi.isFakeTimers()).toBe(false)
    try {
      // Only timer functions are replaced; Date and the server offset stay real.
      vi.useFakeTimers({
        toFake: ['setTimeout', 'clearTimeout', 'setInterval', 'clearInterval'],
      })
      expect(vi.getTimerCount()).toBe(0)
      session = await layoutReconsentSession()
      session.handshake(
        layoutReconsentHandshake({
          lapsed: [{ document: 'terms', deadline: '2026-09-01' }],
          frozen: true,
        }),
      )
      view = await mountLayoutChrome({ connection: layoutChromeConnection() })
      expect(surface(view.root, 'legal-frozen-screen')).not.toBeNull()
      expect(surface(document.body, 'legal-reconsent-modal')).toBeNull()
      expect(hilosLegalReconsentOpen.get()).toBe(false)
      expect(hilosLegalReconsent.loading.get()).toBe(true)
      expect(session.sent.at(-1)).toEqual({
        action: 'hilos_legal_reconsent',
        data: {},
        requestId: expect.any(String),
      })
      expect(session.sent.at(-1)?.requestId).toBeTruthy()
      const sent = [...session.sent]

      await view.unmount()
      expect(hilosLegalReconsent.loading.get()).toBe(true)
      expect(vi.getTimerCount()).toBeGreaterThan(0)
      await act(session.release)
      expect(vi.getTimerCount()).toBe(0)
      expectIdle()
      expect(hilosToasts.toasts.get()).toEqual([])

      await act(session.release)
      await act(async () => {
        vi.advanceTimersByTime(60_000)
      })
      expect(vi.getTimerCount()).toBe(0)
      expectIdle()
      expect(hilosToasts.toasts.get()).toEqual([])
      expect(session.sent).toEqual(sent)
    } finally {
      try {
        await view?.unmount()
      } finally {
        try {
          if (session) await act(session.release)
        } finally {
          try {
            hilosToasts.clear()
          } finally {
            vi.useRealTimers()
          }
        }
      }
    }
  })
})

registerLayoutImpersonationCases(
  'React',
  {
    describe,
    it,
    expect,
    async releaseBindings(release) {
      await act(release)
    },
  },
  mountLayoutChrome,
)

registerLayoutStandingMomentCases(
  'React',
  {
    describe,
    it,
    expect,
    vi,
    async releaseBindings(release) {
      await act(async () => {
        await release()
      })
    },
  },
  mountLayoutChrome,
)
registerLayoutStandingChromeCases(
  'React',
  {
    describe,
    it,
    expect,
    vi,
    async releaseBindings(release) {
      await act(async () => {
        await release()
      })
    },
  },
  mountLayoutChrome,
)
registerLayoutProtectedResidueCases(
  'React',
  { describe, it, expect, vi },
  mountLayoutChrome,
)
registerLayoutCompactStalenessCases(
  'React',
  { describe, it, expect },
  mountLayoutChrome,
)
registerLayoutControlOrderCases(
  'React',
  { describe, it, expect },
  mountLayoutChrome,
  bindSession,
)
registerLayoutSignOutCases(
  'React',
  {
    describe,
    it,
    expect,
    async releaseBindings(release) {
      await act(release)
    },
  },
  mountLayoutChrome,
)

registerLayoutStandingVisibilityCases(
  'React',
  {
    describe,
    it,
    expect,
    vi,
    async releaseBindings(release) {
      await act(async () => {
        await release()
      })
    },
  },
  mountLayoutChrome,
  { dataId: 'test-banner', text: 'A trial notice' },
)

registerLayoutStandingCancelCases(
  'React',
  {
    describe,
    it,
    expect,
    async releaseBindings(release) {
      await act(async () => {
        await release()
      })
    },
  },
  mountLayoutChrome,
)

registerLayoutStandingIdentityCases(
  'React',
  {
    describe,
    it,
    expect,
    async releaseBindings(release) {
      await act(async () => {
        await release()
      })
    },
  },
  mountLayoutChrome,
)

registerLayoutBlockedLiveCases(
  'React',
  {
    describe,
    it,
    expect,
    async releaseBindings(release) {
      await act(release)
    },
  },
  mountLayoutBlocked,
)

registerLayoutBlockedCases(
  'React',
  {
    describe,
    it,
    expect,
    async releaseBindings(release) {
      await act(release)
    },
  },
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
  'React',
  {
    describe,
    it,
    expect,
    vi,
    async releaseBindings(release) {
      await act(async () => {
        await release()
      })
    },
  },
  mountLayoutChrome,
)

registerLayoutReconsentFrozenCases(
  'React',
  {
    describe,
    it,
    expect,
    async releaseBindings(release) {
      await act(async () => {
        await release()
      })
    },
  },
  mountLayoutChrome,
)

registerLayoutReconsentLoadingCases(
  'React',
  {
    describe,
    it,
    expect,
    async releaseBindings(release) {
      await act(async () => {
        await release()
      })
    },
  },
  mountLayoutChrome,
)

registerLayoutReconsentAcceptanceCases(
  'React',
  {
    describe,
    it,
    expect,
    vi,
    async releaseBindings(release) {
      await act(async () => {
        await release()
      })
    },
  },
  mountLayoutChrome,
)

registerLayoutConnectionPageCases(
  'React',
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
function renderInteraction(
  feed: { connection: HilosConnection },
  allow = false,
) {
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
    },
    async flush(run) {
      await shell.update(run ?? (() => {}))
    },
    async unmount() {
      if (destroyed) return
      destroyed = true
      try {
        await act(async () => shell.dispose())
      } finally {
        shell.root.remove()
      }
    },
  }
}

registerLayoutConnectionShellCases(
  'React',
  { describe, it, expect },
  mountConnectionShell,
  bindSession,
)

registerLayoutConnectionBranchCases(
  'React',
  { describe, it, expect },
  mountConnectionShell,
  bindSession,
)

registerLayoutConnectionModalCases(
  'React',
  { describe, it, expect },
  mountLayoutConnection,
)

describe('HilosLayout connection interaction (HIL-1651)', () => {
  let shell: ReturnType<typeof renderInteraction> | undefined
  afterEach(() => {
    shell?.dispose()
    shell = undefined
    cleanup()
    hilosToasts.clear()
  })

  // React context identity and exact committed portal capture keys depend on React
  // rendering and StrictMode replay, which remain in the React native wrapper.
  it('keeps React context identity and committed portal capture keys under StrictMode', () => {
    shell = renderInteraction(shellFeed())
    expect(new Set(shell.interactions.values()).size).toBe(1)
    expect([...shell.interactions.keys()].sort()).toEqual([
      'page',
      'portal',
      'shell',
    ])
  })
})

registerLayoutThemeCases(
  'React',
  {
    describe,
    it,
    expect,
    async releaseBindings(release) {
      await act(release)
    },
  },
  mountLayoutChrome,
)

registerLayoutViewModeCases(
  'React',
  {
    describe,
    it,
    expect,
    // Observable binding release follows native unmount inside React's act boundary.
    afterEach: (release) => {
      afterEach(async () => {
        await act(release)
      })
    },
  },
  mountLayoutChrome,
)

registerLayoutAdminGearCases(
  'React',
  {
    describe,
    it,
    expect,
    // Observable binding release follows native unmount inside React's act boundary.
    afterEach: (release) => {
      afterEach(async () => {
        await act(release)
      })
    },
  },
  mountLayoutChrome,
)

registerLayoutBannerCases('React', { describe, it, expect }, mountLayoutChrome)

registerLayoutNavigationCases(
  'React',
  { describe, it, expect, vi },
  mountLayoutChrome,
)

registerLayoutFooterCases(
  'React',
  { describe, it, expect, vi },
  mountLayoutChrome,
)

registerLayoutLifetimeCases(
  'React',
  { describe, it, expect },
  mountLayoutLifetime,
)

registerLayoutConnectionContextCases(
  'React',
  { describe, it, expect },
  mountLayoutConnection,
  mountStandaloneConnection,
)
