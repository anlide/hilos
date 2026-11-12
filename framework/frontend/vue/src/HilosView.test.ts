// The routed outlet while a page waits for its first answer (HIL-983): a skeleton
// in the page's place instead of white, never before the delay, gone on the
// answer, and never over a refusal. The page-state marker the e2e suites of all
// three demos wait on keeps its three values through all of it.
import { mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { defineComponent, h, markRaw, nextTick, shallowReactive } from 'vue'
import type { Component } from 'vue'
import {
  createHilosRouter,
  createPageRouter,
  createSignal,
  HilosPages,
  lazyPage,
} from '@hilos/core/base'
import type {
  HilosPageIdentity,
  HilosRouter,
  NavigablePages,
  NavigationEnvironment,
  PageSubscriptionError,
  PageView,
} from '@hilos/core/base'

import { registerHilosViewDeletionCases } from '../../test-support/hilosViewDeletionCases.js'
import { withHilosViewDeletion } from '../test/hilosViewDeletion.js'
import { registerHilosViewLazyCases } from '../../test-support/hilosViewLazyCases.js'
import { withHilosViewLazy } from '../test/hilosViewLazy.js'
import { registerHilosViewStatusCases } from '../../test-support/hilosViewStatusCases.js'
import { registerHilosViewAccessCases } from '../../test-support/hilosViewAccessCases.js'
import { withHilosViewAccess } from '../test/hilosViewAccess.js'
import { registerHilosViewOutletCases } from '../../test-support/hilosViewOutletCases.js'
import { withHilosViewOutlet } from '../test/hilosViewOutlet.js'

import HilosView from './HilosView.vue'
import { hilosRouterKey } from './hilosRouterKey.js'

registerHilosViewDeletionCases(
  'Vue',
  { describe, it, expect },
  withHilosViewDeletion,
)

registerHilosViewOutletCases(
  'Vue',
  { describe, it, expect, vi },
  withHilosViewOutlet,
)

registerHilosViewLazyCases(
  'Vue',
  { describe, it, expect, vi },
  withHilosViewLazy,
  // M05: VTU exposes the complete ErrorPage input, including non-DOM fields.
  (view, expected) => expect(view.errorInput!()).toEqual(expected),
)

registerHilosViewStatusCases(
  'Vue',
  { describe, it, expect, vi },
  withHilosViewLazy,
  // M05: VTU also exposes errorCode and page, which the DOM does not render.
  (view, expected) => expect(view.errorInput!()).toEqual(expected),
)

registerHilosViewAccessCases(
  'Vue',
  { describe, it, expect, vi },
  withHilosViewAccess,
)

const KnownPage = defineComponent(
  () => () => h('div', { 'data-id': 'known-page' }, 'Known'),
)

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
  const component = markRaw(
    defineComponent(() => {
      mounted()
      return () => h('div', { 'data-id': id }, id)
    }),
  )
  return { component, mounted }
}

function waitingRouter(
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

function mountPages(
  router: HilosRouter,
  pages: Record<string, PageView<Component>>,
) {
  return mount(HilosView, {
    props: { pages },
    global: { provide: { [hilosRouterKey as symbol]: router } },
  })
}

async function settleCode(): Promise<void> {
  await Promise.resolve()
  await nextTick()
}

// These checks exercise this SDK's tracking API or development lifecycle.
describe('HilosView local lazy lifecycle', () => {
  beforeEach(() => vi.useFakeTimers())
  afterEach(() => vi.useRealTimers())

  it('notices an entry replacement inside the same reactive map', async () => {
    const pending = deferred<Component>()
    const stale = trackedPage('stale-page')
    const pages = shallowReactive<Record<string, PageView<Component>>>({
      lazy: lazyPage(() => pending.promise),
    })
    const { router, answer } = waitingRouter('/lazy')
    const wrapper = mountPages(router, pages)
    answer()
    pages.lazy = markRaw(KnownPage)
    await nextTick()
    expect(wrapper.find('[data-id="known-page"]').exists()).toBe(true)
    pending.resolve(stale.component)
    await settleCode()
    expect(stale.mounted).not.toHaveBeenCalled()
    expect(wrapper.find('[data-id="known-page"]').exists()).toBe(true)
    wrapper.unmount()
  })

  // M01: mutating a Vue reactive property after disposal must not reconnect it.
  it.each(['resolve', 'reject'] as const)(
    'does not track a reactive map after unmount and late %s',
    async (outcome) => {
      const pending = deferred<Component>()
      const nextLoader = vi.fn(() => Promise.resolve(markRaw(KnownPage)))
      const pages = shallowReactive<Record<string, PageView<Component>>>({
        lazy: lazyPage(() => pending.promise),
      })
      const { router } = waitingRouter('/lazy')
      try {
        await withHilosViewLazy(async (kit) => {
          const stale = kit.tracked('stale-page')
          const view = await kit.mount({ router, pages })
          await view.unmount()
          if (outcome === 'resolve') pending.resolve(stale.component)
          else pending.reject(new Error('Disposed chunk failed'))
          pages.lazy = lazyPage(nextLoader)
          await view.flush()
          expect(nextLoader).not.toHaveBeenCalled()
          expect(stale.mounted).not.toHaveBeenCalled()
        })
      } finally {
        router.stop()
      }
    },
  )
})
