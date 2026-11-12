// The two parity fixes HIL-424 brought to the React outlet, each with no guard
// before this spec: the gate's modal must not draw a SECOND copy of the sign-in
// surface while the same surface already stands in place of a 401'd page, and
// the dialog must name itself without hard-coding a visible title the
// identifier-first surface owns itself — and since HIL-832 that name is the
// heading the surface draws, not a fixed string of the frame's own.
//
// And the outlet while a page waits for its first answer (HIL-983): a skeleton in
// the page's place instead of white, never before the delay, gone on the answer,
// and never over a refusal.
//
// And a page shell that draws the deletion of its own resource (HIL-1603): the
// outlet keeps the opted-in page mounted, its open dialog and draft with it,
// while the shell draws the one ErrorPage in place of its content.
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { act, cleanup, render } from '@testing-library/react'
import { StrictMode, useEffect } from 'react'
import type { ComponentType } from 'react'
import {
  createHilosRouter,
  createPageRouter,
  createSignal,
  DEFAULT_SKELETON_DELAY_MS,
  lazyPage,
  HilosPages,
} from '@hilos/core/base'
import type {
  HilosPageIdentity,
  HilosRouter,
  NavigablePages,
  NavigationEnvironment,
  PageSubscriptionError,
  PageView,
} from '@hilos/core/base'

import {
  registerHilosViewDeletionCases,
  runHilosViewDeletionD01,
} from '../../test-support/hilosViewDeletionCases.js'
import { withHilosViewDeletion } from './hilosViewDeletion.js'
import { registerHilosViewLazyCases } from '../../test-support/hilosViewLazyCases.js'
import { withHilosViewLazy } from './hilosViewLazy.js'
import { registerHilosViewStatusCases } from '../../test-support/hilosViewStatusCases.js'
import { registerHilosViewAccessCases } from '../../test-support/hilosViewAccessCases.js'
import { withHilosViewAccess } from './hilosViewAccess.js'
import { registerHilosViewOutletCases } from '../../test-support/hilosViewOutletCases.js'
import { withHilosViewOutlet } from './hilosViewOutlet.js'

import { HilosView, type HilosViewProps } from '../src/HilosView.js'
import { HilosRouterContext } from '../src/hilosRouterContext.js'

registerHilosViewDeletionCases(
  'React',
  { describe, it, expect },
  withHilosViewDeletion,
)

// M03b: native StrictMode replay shares every D01 precondition and continuation.
it('M03b retains the same eager card and draft under React StrictMode', () =>
  runHilosViewDeletionD01(
    { expect },
    (steps) => withHilosViewDeletion(steps, true),
    'eager',
    (mounts) => expect(mounts).toBe(2),
  ))

registerHilosViewOutletCases(
  'React',
  { describe, it, expect, vi },
  withHilosViewOutlet,
)

registerHilosViewLazyCases(
  'React',
  { describe, it, expect, vi },
  withHilosViewLazy,
)

registerHilosViewStatusCases(
  'React',
  { describe, it, expect, vi },
  withHilosViewLazy,
)

registerHilosViewAccessCases(
  'React',
  { describe, it, expect, vi },
  withHilosViewAccess,
  // M04: X01 runs its common steps under genuine StrictMode effect replay.
  (steps, afterUnmount) => withHilosViewAccess(steps, afterUnmount, true),
)

// The modal portals to <body>, so assertions query the document, not the render.
afterEach(() => {
  cleanup()
  document.body.classList.remove('modal-open')
})

function outlast(ms: number): void {
  act(() => {
    vi.advanceTimersByTime(ms)
  })
}

function pageSkeleton(): Element | null {
  return document.querySelector('[data-id="hilos-page-skeleton"]')
}

function KnownPage() {
  return <div data-id="known-page">Known</div>
}

function navigablePages(): NavigablePages {
  const pageLoading = createSignal(false)
  return {
    subscribe: () => {
      pageLoading.set(true)
      pageLoading.set(false)
      return null
    },
    unsubscribe: () => {},
    refuseUnbuilt: () => {},
    pageError: createSignal<PageSubscriptionError | null>(null),
    pageLoading,
    pageIdentity: createSignal(undefined),
    dashboardSections: createSignal(undefined),
    clearPageError: () => {},
    denyCurrentPage: () => {},
    awaitPageAnswer: () => {},
  }
}

function navigation(initial: string): NavigationEnvironment {
  let pathname = initial

  return {
    pathname: () => pathname,
    pushState: (next) => {
      pathname = next
    },
    replaceState: (next) => {
      pathname = next
    },
    onPopState: () => () => {},
  }
}

function deferred<T>() {
  let resolve!: (value: T) => void
  let reject!: (error: Error) => void
  const promise = new Promise<T>((yes, no) => {
    resolve = yes
    reject = no
  })
  return { promise, resolve, reject }
}

function trackedPage(id: string) {
  const mounted = vi.fn()
  function Component() {
    useEffect(() => {
      mounted()
    }, [])
    return <div data-id={id}>{id}</div>
  }
  return { component: Component, mounted }
}

/** Real navigation order, with independently controlled server answers. */
function controlledRouter(
  initial = '/',
  unbuilt: ReadonlySet<string> = new Set(),
) {
  const pageLoading = createSignal(false)
  const pageError = createSignal<PageSubscriptionError | null>(null)
  const pageIdentity = createSignal<HilosPageIdentity | undefined>(undefined)
  const awaitPageAnswer = () => {
    pageError.set(null)
    pageLoading.set(true)
  }
  const subscribe = vi.fn(awaitPageAnswer)
  const router = createHilosRouter(
    createPageRouter({
      main: { path: '/', admin: false },
      lazy: { path: '/lazy/{id?}', admin: true },
      other: { path: '/other', admin: true },
      [HilosPages.PUBLIC_STATUS]: { path: '/status', admin: false },
    }),
    {
      ...navigablePages(),
      pageLoading,
      pageError,
      pageIdentity,
      subscribe,
      unsubscribe: () => {
        pageLoading.set(false)
        pageError.set(null)
      },
      refuseUnbuilt: (page) => {
        pageError.set({
          page,
          httpCode: 501,
          errorCode: 'not_built',
          title: 'Not built',
          message: 'This page is not built.',
        })
        pageLoading.set(false)
      },
      clearPageError: () => pageError.set(null),
      awaitPageAnswer,
    },
    navigation(initial),
    undefined,
    unbuilt,
  )
  router.start()
  const answer = () => {
    pageError.set(null)
    pageLoading.set(false)
  }
  return { router, pageLoading, pageError, pageIdentity, subscribe, answer }
}

function renderPages(
  router: HilosRouter,
  pages: Record<string, PageView<ComponentType>>,
  extra: Omit<HilosViewProps, 'pages'> = {},
) {
  return render(<HilosView pages={pages} {...extra} />, {
    wrapper: ({ children }) => (
      <HilosRouterContext.Provider value={router}>
        {children}
      </HilosRouterContext.Provider>
    ),
  })
}

function marker(): string | null | undefined {
  return document
    .querySelector('[data-id="hilos-page-state"]')
    ?.getAttribute('data-state')
}

async function settleCode(): Promise<void> {
  await act(async () => {
    await Promise.resolve()
  })
}

// These checks exercise this SDK's tracking API or development lifecycle.
describe('HilosView local lazy lifecycle', () => {
  beforeEach(() => vi.useFakeTimers())
  afterEach(() => vi.useRealTimers())

  it('notices a replaced entry in the same map on rerender', async () => {
    const pending = deferred<ComponentType>()
    const stale = trackedPage('stale-page')
    const pages: Record<string, PageView<ComponentType>> = {
      lazy: lazyPage(() => pending.promise),
    }
    const { router, answer } = controlledRouter('/lazy')
    const { rerender, container } = renderPages(router, pages)
    act(answer)
    pages.lazy = KnownPage
    rerender(<HilosView pages={pages} />)
    expect(container.querySelector('[data-id="known-page"]')).not.toBeNull()
    pending.resolve(stale.component)
    await settleCode()
    expect(stale.mounted).not.toHaveBeenCalled()
    expect(container.querySelector('[data-id="known-page"]')).not.toBeNull()
  })

  it('reconnects effects in StrictMode and leaves no controller listening after unmount', async () => {
    const pending = deferred<ComponentType>()
    const loader = vi.fn(() => pending.promise)
    const otherLoader = vi.fn(() => Promise.resolve(KnownPage))
    const { router, answer } = controlledRouter('/lazy')
    const leaveListeners = new Set<() => void>()
    const onLeave = router.onLeave
    vi.spyOn(router, 'onLeave').mockImplementation((listener) => {
      leaveListeners.add(listener)
      const off = onLeave(listener)
      return () => {
        off()
        leaveListeners.delete(listener)
      }
    })
    const { container, unmount } = render(
      <StrictMode>
        <HilosRouterContext.Provider value={router}>
          <HilosView
            pages={{ lazy: lazyPage(loader), other: lazyPage(otherLoader) }}
          />
        </HilosRouterContext.Provider>
      </StrictMode>,
    )
    expect(loader).toHaveBeenCalledTimes(1)
    expect(leaveListeners.size).toBe(2)
    pending.resolve(KnownPage)
    await settleCode()
    expect(container.querySelector('[data-id="known-page"]')).toBeNull()
    outlast(DEFAULT_SKELETON_DELAY_MS)
    expect(pageSkeleton()).not.toBeNull()
    act(answer)
    expect(container.querySelector('[data-id="known-page"]')).not.toBeNull()
    expect(marker()).toBe('ready')
    act(() => router.navigate('/other'))
    expect(otherLoader).toHaveBeenCalledTimes(1)
    act(answer)
    await settleCode()
    expect(marker()).toBe('ready')
    unmount()
    expect(leaveListeners.size).toBe(0)
    act(() => router.navigate('/lazy'))
    expect(loader).toHaveBeenCalledTimes(1)
    expect(vi.getTimerCount()).toBe(0)
  })
})
