import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, describe, expect, it, vi } from 'vitest'
import {
  createSSRApp,
  h,
  nextTick,
  provide,
  ref,
  shallowRef,
  Teleport,
} from 'vue'
import { renderToString } from 'vue/server-renderer'
import {
  ActionLifecycle,
  bindAccessLoss,
  bindAccountBlocked,
  bindAccountStanding,
  bindAdminAccess,
  bindLegalReconsent,
  createSignal,
  bindImpersonation,
  bindSessionScope,
  bindSignOut,
  hilosToasts,
  IMPERSONATION_ACTION_STOP,
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
} from '@hilos/core/base'

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
import { mountLayoutLifetime } from '../test/layoutLifetimeMount.js'
import { mountLayoutBlocked } from '../test/layoutBlockedMount.js'
import { mountLayoutChrome } from '../test/layoutChromeMount.js'
import HilosLayout from './HilosLayout.vue'
import LoadingButton from './LoadingButton.vue'
import {
  useConnectionInteraction,
  type ConnectionInteraction,
} from './hilosConnectionInteraction.js'
import { hilosAdminViewModeKey } from './hilosAdminViewMode.js'
import { hilosRouterKey } from './hilosRouterKey.js'

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
 * @param router The outlet whose page waits after a live access loss.
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

  return {
    source,
    unbind(): void {
      unbindStrip()
      unbindCard()
      unbindStanding()
      unbindSignOut()
      unbindAdminAccess()
      unbindReconsent()
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

registerLayoutImpersonationCases(
  'Vue',
  { describe, it, expect },
  mountLayoutChrome,
)
registerLayoutSignOutCases('Vue', { describe, it, expect }, mountLayoutChrome)
registerLayoutStandingChromeCases(
  'Vue',
  { describe, it, expect, vi },
  mountLayoutChrome,
)
registerLayoutProtectedResidueCases(
  'Vue',
  { describe, it, expect, vi },
  mountLayoutChrome,
)
registerLayoutCompactStalenessCases(
  'Vue',
  { describe, it, expect },
  mountLayoutChrome,
)
registerLayoutControlOrderCases(
  'Vue',
  { describe, it, expect },
  mountLayoutChrome,
  bindSession,
)

registerLayoutStandingMomentCases(
  'Vue',
  { describe, it, expect, vi },
  mountLayoutChrome,
)

registerLayoutStandingVisibilityCases(
  'Vue',
  { describe, it, expect, vi },
  mountLayoutChrome,
  { dataId: 'test-banner', text: 'A trial notice' },
)

registerLayoutStandingCancelCases(
  'Vue',
  { describe, it, expect },
  mountLayoutChrome,
)

registerLayoutStandingIdentityCases(
  'Vue',
  { describe, it, expect },
  mountLayoutChrome,
)

registerLayoutBlockedLiveCases(
  'Vue',
  { describe, it, expect },
  mountLayoutBlocked,
)

registerLayoutBlockedCases('Vue', { describe, it, expect }, mountLayoutChrome)

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

registerLayoutReconsentReminderCases(
  'Vue',
  { describe, it, expect, vi },
  mountLayoutChrome,
)

registerLayoutReconsentFrozenCases(
  'Vue',
  { describe, it, expect },
  mountLayoutChrome,
)

registerLayoutReconsentLoadingCases(
  'Vue',
  { describe, it, expect },
  mountLayoutChrome,
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

/** The shell, including projected regions and a teleport, over a controlled connection. */
function renderInteraction(
  feed: { connection: HilosConnection },
  allow = false,
) {
  const bound = shallowRef(feed.connection)
  const allowed = ref(allow)
  const ownDisabled = ref(false)
  const ownDescription = ref('validation')
  const ViewingAction = {
    setup() {
      provide(hilosAdminViewModeKey, ref(true))
      return () =>
        h(
          LoadingButton,
          { 'data-id': 'page-view', 'aria-describedby': 'validation' },
          () => 'View',
        )
    },
  }
  const interactions: ConnectionInteraction[] = []
  const clicks = vi.fn()
  const Probe = {
    setup() {
      const interaction = useConnectionInteraction()
      interactions.push(interaction)
      return () => h('span', 'Context')
    },
  }
  const host = document.createElement('div')
  document.body.append(host)
  const wrapper = mount(
    {
      setup: () => () =>
        h(
          HilosLayout,
          {
            connection: bound.value,
            allowDisconnectedInteraction: allowed.value,
          },
          {
            nav: () => h('a', { href: '/next' }, 'Next'),
            user: () => [h(Probe), h(Teleport, { to: 'body' }, h(Probe))],
            default: () => [
              h(Probe),
              h(ViewingAction),
              h('input', {
                'data-id': 'draft',
                'aria-describedby': ownDescription.value,
                disabled: ownDisabled.value,
              }),
              h('input', { type: 'file', 'data-id': 'file' }),
              h('select', { 'data-id': 'choice' }, [
                h('option', { value: 'a' }, 'A'),
                h('option', { value: 'b' }, 'B'),
              ]),
              h(
                LoadingButton,
                { opensWindow: true, onClick: clicks, 'data-id': 'page-open' },
                () => 'Open',
              ),
              h(
                LoadingButton,
                { onClick: clicks, 'data-id': 'page-mutate' },
                () => 'Save',
              ),
              h(
                'a',
                { href: '/action', onClick: clicks, 'data-id': 'page-link' },
                'Action',
              ),
            ],
          },
        ),
    },
    {
      attachTo: host,
      global: {
        provide: { [hilosRouterKey as symbol]: routerOn('', true) },
        // A shell-owned window's injection boundary, independent of page slots.
        stubs: { HilosOAuthWaitModal: Probe },
      },
    },
  )
  const settle = async () => {
    await nextTick()
    await flushPromises()
    await nextTick()
  }
  return {
    wrapper,
    root: host,
    interactions,
    clicks,
    bound,
    allowed,
    ownDisabled,
    ownDescription,
    settle,
    dispose() {
      wrapper.unmount()
      host.remove()
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
      shell.wrapper
        .findAllComponents(LoadingButton)
        .find((component) => component.attributes('data-id') === id)!
        .vm.$emit('click', new MouseEvent('click'))
    },
    async flush(run) {
      run?.()
      await shell.settle()
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
  'Vue',
  { describe, it, expect },
  mountConnectionShell,
  bindSession,
)

registerLayoutConnectionBranchCases(
  'Vue',
  { describe, it, expect },
  mountConnectionShell,
  bindSession,
)

describe('HilosLayout connection interaction (HIL-1651)', () => {
  let shell: ReturnType<typeof renderInteraction> | undefined
  afterEach(() => {
    shell?.dispose()
    shell = undefined
    hilosToasts.clear()
  })

  it('releases its connection subscription after server rendering', async () => {
    const feed = shellFeed()
    const html = await renderToString(
      createSSRApp({
        render: () => h(HilosLayout, { connection: feed.connection }),
      }),
    )
    expect(html).toContain('page-interaction-scope')
    expect(feed.listeners.get('state')?.size).toBe(0)
  })

  // Vue provide/inject object identity and exact setup captures through a real
  // Teleport and the OAuth component stub are native wiring proof; a foreign renderer
  // cannot reproduce those setup boundaries.
  it('keeps Vue provide/inject identity across its exact native setup captures', async () => {
    shell = renderInteraction(shellFeed())
    await shell.settle()
    expect(shell.interactions).toHaveLength(4)
    expect(new Set(shell.interactions).size).toBe(1)
  })
})

registerLayoutThemeCases('Vue', { describe, it, expect }, mountLayoutChrome)

registerLayoutViewModeCases(
  'Vue',
  { describe, it, expect, afterEach },
  mountLayoutChrome,
)

registerLayoutAdminGearCases(
  'Vue',
  { describe, it, expect, afterEach },
  mountLayoutChrome,
)

registerLayoutBannerCases('Vue', { describe, it, expect }, mountLayoutChrome)

registerLayoutNavigationCases(
  'Vue',
  { describe, it, expect, vi },
  mountLayoutChrome,
)

registerLayoutFooterCases(
  'Vue',
  { describe, it, expect, vi },
  mountLayoutChrome,
)

registerLayoutLifetimeCases(
  'Vue',
  { describe, it, expect },
  mountLayoutLifetime,
)
