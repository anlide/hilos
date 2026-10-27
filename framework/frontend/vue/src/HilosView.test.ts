// The routed outlet while a page waits for its first answer (HIL-983): a skeleton
// in the page's place instead of white, never before the delay, gone on the
// answer, and never over a refusal. The page-state marker the e2e suites of all
// three demos wait on keeps its three values through all of it.
import { mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import {
  defineComponent,
  h,
  markRaw,
  nextTick,
  ref,
  shallowReactive,
} from 'vue'
import type { Component } from 'vue'
import {
  bindAccessLoss,
  bindPageScope,
  bindSessionScope,
  createAuthGate,
  createHilosRouter,
  createPageRouter,
  createSignal,
  DEFAULT_SKELETON_DELAY_MS,
  HilosPages,
  lazyPage,
  ScopeManager,
  sessionUserId,
} from '@hilos/core/base'
import type {
  HilosConnection,
  HilosPageIdentity,
  HilosRouter,
  NavigablePages,
  NavigationEnvironment,
  PageError,
  PageRouteMatch,
  PageSubscriptionError,
  PageView,
  ProjectSignal,
  WritableSignal,
} from '@hilos/core/base'

import ErrorPage from './ErrorPage.vue'
import HilosAdminPage from './HilosAdminPage.vue'
import HilosModal from './HilosModal.vue'
import HilosView from './HilosView.vue'
import { hilosRouterKey } from './hilosRouterKey.js'

const NOT_FOUND: PageSubscriptionError = {
  page: 'main',
  httpCode: 404,
  errorCode: 'not_found',
  title: 'Page Not Found',
  message: 'Not found',
}

const LOCAL_FAILURE: PageError = {
  httpCode: 503,
  errorCode: 'page_load_failed',
  title: 'Page could not be loaded',
  message: 'Please try opening this page again.',
}

const MainPage = markRaw(
  defineComponent(() => () => h('div', { 'data-id': 'main' }, 'Leaving page')),
)
const MainSkeleton = markRaw(
  defineComponent(() => () => h('div', { 'data-id': 'main-skeleton' })),
)

interface TestRouter {
  router: HilosRouter
  pageLoading: WritableSignal<boolean>
  pageError: WritableSignal<PageSubscriptionError | null>
  depart(): void
}

function testRouter(page: string): TestRouter {
  const pageLoading = createSignal(true)
  const pageError = createSignal<PageSubscriptionError | null>(null)
  const leaveListeners = new Set<() => void>()

  return {
    pageLoading,
    pageError,
    depart: () => {
      for (const listener of [...leaveListeners]) {
        listener()
      }
      pageLoading.set(true)
    },
    router: {
      currentRoute: createSignal<PageRouteMatch>({
        page,
        params: {},
        admin: false,
      }),
      currentPath: createSignal(''),
      currentTitle: createSignal(''),
      pageError,
      pageLoading,
      pageIdentity: createSignal(undefined),
      dashboardSections: createSignal(undefined),
      resolvePath: (key) => `/${key}`,
      clearPageError: () => {},
      denyCurrentPage: () => {},
      awaitPageAnswer: () => {},
      onLeave: (listener) => {
        leaveListeners.add(listener)
        return () => {
          leaveListeners.delete(listener)
        }
      },
      navigate: () => {},
      replacePath: () => {},
      start: () => {},
      stop: () => {},
    },
  }
}

function mountView(router: HilosRouter, withSkeletons = false) {
  return mount(HilosView, {
    props: {
      pages: { main: MainPage },
      ...(withSkeletons ? { pageSkeletons: { main: MainSkeleton } } : {}),
    },
    global: { provide: { [hilosRouterKey as symbol]: router } },
  })
}

async function outlast(ms: number): Promise<void> {
  await nextTick()
  vi.advanceTimersByTime(ms)
  await nextTick()
}

describe('HilosView skeleton', () => {
  beforeEach(() => {
    vi.useFakeTimers()
  })
  afterEach(() => {
    vi.useRealTimers()
  })

  it('draws nothing in the first moments of the wait', async () => {
    const { router } = testRouter('main')
    const wrapper = mountView(router)

    await outlast(DEFAULT_SKELETON_DELAY_MS - 1)
    expect(wrapper.find('[data-id="hilos-page-skeleton"]').exists()).toBe(false)
  })

  it('draws the default skeleton once the wait outlasts the delay', async () => {
    const { router } = testRouter('main')
    const wrapper = mountView(router)

    await outlast(DEFAULT_SKELETON_DELAY_MS)
    const skeleton = wrapper.find('[data-id="hilos-page-skeleton"]')
    expect(skeleton.exists()).toBe(true)
    expect(skeleton.find('[data-id="hilos-skeleton"]').exists()).toBe(true)
  })

  it('announces the page skeleton once, whatever it draws', async () => {
    const { router } = testRouter('main')
    const wrapper = mountView(router)

    await outlast(DEFAULT_SKELETON_DELAY_MS)
    const status = wrapper.findAll('[role="status"]')
    expect(status).toHaveLength(2)
    expect(status.map((region) => region.text())).toEqual(['', 'Loading…'])
  })

  it('gives way to the page on its answer', async () => {
    const { router, pageLoading } = testRouter('main')
    const wrapper = mountView(router)

    await outlast(DEFAULT_SKELETON_DELAY_MS)
    pageLoading.set(false)
    await nextTick()
    expect(wrapper.find('[data-id="hilos-page-skeleton"]').exists()).toBe(false)
    expect(wrapper.find('[data-id="main"]').exists()).toBe(true)
  })

  it('draws the skeleton the project declared for the page key', async () => {
    const { router } = testRouter('main')
    const wrapper = mountView(router, true)

    await outlast(DEFAULT_SKELETON_DELAY_MS)
    expect(wrapper.find('[data-id="main-skeleton"]').exists()).toBe(true)
    expect(wrapper.find('[data-id="hilos-skeleton"]').exists()).toBe(false)
  })

  it('falls back to the default skeleton for an undeclared page key', async () => {
    const { router } = testRouter('settings')
    const wrapper = mountView(router, true)

    await outlast(DEFAULT_SKELETON_DELAY_MS)
    expect(wrapper.find('[data-id="main-skeleton"]').exists()).toBe(false)
    expect(wrapper.find('[data-id="hilos-skeleton"]').exists()).toBe(true)
  })

  it('shows the refusal, not a skeleton, when the page is denied', async () => {
    const { router, pageError } = testRouter('main')
    const wrapper = mountView(router)

    pageError.set(NOT_FOUND)
    await outlast(DEFAULT_SKELETON_DELAY_MS)
    expect(wrapper.find('[data-id="hilos-page-skeleton"]').exists()).toBe(false)
    expect(wrapper.find('[data-id="page-error"]').exists()).toBe(true)
  })

  it('keeps the page-state marker going from loading to ready', async () => {
    const { router, pageLoading } = testRouter('main')
    const wrapper = mountView(router)
    const marker = () => wrapper.find('[data-id="hilos-page-state"]')

    await outlast(DEFAULT_SKELETON_DELAY_MS)
    expect(marker().attributes('data-state')).toBe('loading')
    pageLoading.set(false)
    await nextTick()
    expect(marker().attributes('data-state')).toBe('ready')
  })
})

describe('HilosView departure', () => {
  beforeEach(() => {
    vi.useFakeTimers()
  })
  afterEach(() => {
    vi.useRealTimers()
  })

  it('keeps an inert copy until the next page answers', async () => {
    const { router, pageLoading, depart } = testRouter('main')
    pageLoading.set(false)
    const wrapper = mountView(router)

    depart()
    await nextTick()
    const departure = wrapper.find('[data-id="hilos-page-departure"]')
    expect(departure.attributes('aria-hidden')).toBe('true')
    expect(departure.attributes()).toHaveProperty('inert')
    expect(departure.text()).toBe('Leaving page')
    expect(departure.find('[data-id]').exists()).toBe(false)

    pageLoading.set(false)
    await nextTick()
    expect(departure.element.childElementCount).toBe(0)
  })

  it('removes the copy on an error or the skeleton threshold', async () => {
    const { router, pageLoading, pageError, depart } = testRouter('main')
    pageLoading.set(false)
    const wrapper = mountView(router)
    const departure = () => wrapper.find('[data-id="hilos-page-departure"]')

    depart()
    await nextTick()
    pageError.set(NOT_FOUND)
    expect(departure().element.childElementCount).toBe(0)

    pageError.set(null)
    pageLoading.set(false)
    await nextTick()
    depart()
    await nextTick()
    await outlast(DEFAULT_SKELETON_DELAY_MS)
    expect(wrapper.find('[data-id="hilos-page-skeleton"]').exists()).toBe(true)
    expect(departure().element.childElementCount).toBe(0)
  })

  it('does not copy a loading change and keeps a copy through repeated departures', async () => {
    const { router, pageLoading, depart } = testRouter('main')
    pageLoading.set(false)
    const wrapper = mountView(router)
    const departure = wrapper.find('[data-id="hilos-page-departure"]')

    pageLoading.set(true)
    await nextTick()
    expect(departure.element.childElementCount).toBe(0)

    pageLoading.set(false)
    await nextTick()
    depart()
    const copied = departure.element.firstElementChild
    depart()
    expect(departure.element.firstElementChild).toBe(copied)
  })
})

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

describe('HilosView route miss', () => {
  it('shows the route error instead of the old page and returns home', async () => {
    const pageRouter = createPageRouter({
      main: { path: '/', admin: false },
      known: { path: '/known', admin: false },
    })
    const navigator = createHilosRouter(
      pageRouter,
      navigablePages(),
      navigation('/known'),
    )
    navigator.start()
    const wrapper = mount(HilosView, {
      props: { pages: { main: markRaw(MainPage), known: markRaw(KnownPage) } },
      global: { provide: { [hilosRouterKey as symbol]: navigator } },
    })

    expect(wrapper.find('[data-id="known-page"]').exists()).toBe(true)

    navigator.navigate('/nope')
    await nextTick()

    const surface = wrapper.find('[data-id="page-error"]')
    expect(
      wrapper.find('[data-id="hilos-page-state"]').attributes('data-state'),
    ).toBe('error')
    expect(surface.exists()).toBe(true)
    expect(wrapper.findAll('h1')).toHaveLength(1)
    expect(surface.find('h1').text()).toBe('404')
    expect(surface.text()).toContain('Page Not Found')
    expect(surface.text()).toContain(
      'The page you are looking for does not exist.',
    )
    expect(wrapper.find('[data-id="known-page"]').exists()).toBe(false)
    expect(wrapper.find('[data-id="main"]').exists()).toBe(false)
    expect(wrapper.find('[data-id="hilos-page-skeleton"]').exists()).toBe(false)

    await wrapper.get('[data-id="page-error-home"]').trigger('click')
    await nextTick()

    expect(wrapper.find('[data-id="main"]').exists()).toBe(true)
    expect(wrapper.find('[data-id="page-error"]').exists()).toBe(false)
    expect(
      wrapper.find('[data-id="hilos-page-state"]').attributes('data-state'),
    ).toBe('ready')
  })
})

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

describe('HilosView public Status assembly (HIL-1690)', () => {
  it.each(['pending', 'ready', 'stale', 401, 403, 404] as const)(
    'leaves the previous %s subscription behind and restores ordinary waiting',
    async (previous) => {
      const { router, answer, pageError, pageLoading, subscribe } =
        waitingRouter()
      const status = trackedPage('public-file-reader')
      const auth = trackedPage('auth-surface')
      const wrapper = mount(HilosView, {
        props: {
          pages: {
            main: MainPage,
            [HilosPages.PUBLIC_STATUS]: status.component,
          },
          authSurface: auth.component,
        },
        global: { provide: { [hilosRouterKey as symbol]: router } },
      })
      if (previous !== 'pending') answer()
      await nextTick()
      if (previous === 'stale') pageLoading.set(true)
      if (typeof previous === 'number') {
        pageError.set({ ...NOT_FOUND, httpCode: previous })
        await nextTick()
        expect(wrapper.find('[data-id="auth-surface"]').exists()).toBe(
          previous === 401,
        )
      }
      subscribe.mockClear()
      router.navigate('/status')
      await nextTick()
      expect(wrapper.find('[data-id="public-file-reader"]').exists()).toBe(true)
      expect(wrapper.find('[data-id="auth-surface"]').exists()).toBe(false)
      expect(wrapper.findComponent(ErrorPage).exists()).toBe(false)
      expect(
        wrapper.get('[data-id="hilos-page-departure"]').element
          .childElementCount,
      ).toBe(0)
      expect(subscribe).not.toHaveBeenCalled()
      // Late old refusal/loading and generic session hooks cannot replace files.
      pageError.set({ ...NOT_FOUND, httpCode: 401 })
      pageLoading.set(true)
      router.denyCurrentPage()
      router.awaitPageAnswer()
      await nextTick()
      expect(wrapper.find('[data-id="public-file-reader"]').exists()).toBe(true)
      expect(
        wrapper.get('[data-id="hilos-page-state"]').attributes('data-state'),
      ).toBe('ready')
      router.navigate('/')
      await nextTick()
      expect(wrapper.find('[data-id="main"]').exists()).toBe(false)
      expect(
        wrapper.get('[data-id="hilos-page-state"]').attributes('data-state'),
      ).toBe('loading')
      answer()
      await nextTick()
      expect(wrapper.find('[data-id="main"]').exists()).toBe(true)
      wrapper.unmount()
    },
  )

  it.each(['resolve', 'reject'] as const)(
    'still waits for public lazy code and handles its %s independently of old frames',
    async (outcome) => {
      const pending = deferred<Component>()
      const { router, pageError, pageLoading } = waitingRouter()
      const status = trackedPage('public-file-reader')
      const wrapper = mountPages(router, {
        main: MainPage,
        [HilosPages.PUBLIC_STATUS]: lazyPage(() => pending.promise),
      })
      pageError.set({ ...NOT_FOUND, httpCode: 403 })
      router.navigate('/status')
      await nextTick()
      expect(
        wrapper.get('[data-id="hilos-page-state"]').attributes('data-state'),
      ).toBe('loading')
      expect(status.mounted).not.toHaveBeenCalled()
      pageError.set({ ...NOT_FOUND, httpCode: 401 })
      pageLoading.set(true)
      if (outcome === 'resolve') pending.resolve(status.component)
      else pending.reject(new Error('Import failed'))
      await settleCode()
      await settleCode()
      expect(
        wrapper.get('[data-id="hilos-page-state"]').attributes('data-state'),
      ).toBe(outcome === 'resolve' ? 'ready' : 'error')
      expect(wrapper.find('[data-id="public-file-reader"]').exists()).toBe(
        outcome === 'resolve',
      )
      if (outcome === 'reject')
        expect(wrapper.getComponent(ErrorPage).props('error')).toEqual(
          LOCAL_FAILURE,
        )
      wrapper.unmount()
    },
  )
})

describe('HilosView lazy pages', () => {
  beforeEach(() => vi.useFakeTimers())
  afterEach(() => vi.useRealTimers())

  it('keeps the departure and original skeleton deadline after a fast server answer', async () => {
    const pending = deferred<Component>()
    const page = trackedPage('lazy-page')
    const loader = vi.fn(() => pending.promise)
    const { router, answer, pageLoading } = waitingRouter()
    answer()
    const wrapper = mountPages(router, {
      main: markRaw(MainPage),
      lazy: lazyPage(loader),
    })
    await wrapper.setProps({ pageSkeletons: { lazy: markRaw(MainSkeleton) } })
    const departure = wrapper.get('[data-id="hilos-page-departure"]')
    const frame = departure.element.parentElement!
    frame.scrollTop = 90

    router.navigate('/lazy')
    await outlast(100)
    answer()
    await nextTick()
    expect(pageLoading.get()).toBe(false)
    expect(departure.text()).toBe('Leaving page')
    expect(departure.attributes('aria-hidden')).toBe('true')
    expect(departure.attributes()).toHaveProperty('inert')
    expect(departure.find('[data-id]').exists()).toBe(false)
    expect(page.mounted).not.toHaveBeenCalled()
    expect(
      wrapper.get('[data-id="hilos-page-state"]').attributes('data-state'),
    ).toBe('loading')

    await outlast(DEFAULT_SKELETON_DELAY_MS - 101)
    expect(departure.text()).toBe('Leaving page')
    expect(wrapper.find('[data-id="hilos-page-skeleton"]').exists()).toBe(false)
    await outlast(1)
    expect(departure.element.childElementCount).toBe(0)
    expect(frame.scrollTop).toBe(0)
    expect(wrapper.find('[data-id="main-skeleton"]').exists()).toBe(true)
    expect(wrapper.findAll('[role="status"]')).toHaveLength(2)
    expect(
      wrapper.findAll('[role="status"]').map((region) => region.text()),
    ).toEqual(['', 'Loading…'])

    pending.resolve(page.component)
    await settleCode()
    expect(page.mounted).toHaveBeenCalledTimes(1)
    expect(loader).toHaveBeenCalledTimes(1)
    expect(wrapper.find('[data-id="lazy-page"]').exists()).toBe(true)
    expect(wrapper.find('[data-id="hilos-page-skeleton"]').exists()).toBe(false)
    expect(
      wrapper.get('[data-id="hilos-page-state"]').attributes('data-state'),
    ).toBe('ready')
    wrapper.unmount()
  })

  it('does not mount fast code before the server answers or restart its skeleton timer', async () => {
    const pending = deferred<Component>()
    const page = trackedPage('lazy-page')
    const { router, answer } = waitingRouter('/lazy')
    const wrapper = mountPages(router, {
      lazy: lazyPage(() => pending.promise),
    })

    await outlast(100)
    pending.resolve(page.component)
    await settleCode()
    expect(page.mounted).not.toHaveBeenCalled()
    expect(
      wrapper.get('[data-id="hilos-page-state"]').attributes('data-state'),
    ).toBe('loading')
    await outlast(DEFAULT_SKELETON_DELAY_MS - 101)
    expect(wrapper.find('[data-id="hilos-page-skeleton"]').exists()).toBe(false)
    await outlast(1)
    expect(wrapper.find('[data-id="hilos-page-skeleton"]').exists()).toBe(true)
    expect(page.mounted).not.toHaveBeenCalled()

    answer()
    await nextTick()
    expect(page.mounted).toHaveBeenCalledTimes(1)
    expect(
      wrapper.get('[data-id="hilos-page-state"]').attributes('data-state'),
    ).toBe('ready')
    expect(wrapper.findAll('[role="status"]')).toHaveLength(1)
    expect(wrapper.get('[role="status"]').text()).toBe('')
    wrapper.unmount()
  })

  it.each(['resolve', 'reject'] as const)(
    'keeps one deadline through a second navigation and ignores a late %s',
    async (outcome) => {
      const first = deferred<Component>()
      const second = deferred<Component>()
      const stale = trackedPage('stale-page')
      const current = trackedPage('current-page')
      const { router, answer } = waitingRouter()
      answer()
      const wrapper = mountPages(router, {
        main: markRaw(MainPage),
        lazy: lazyPage(() => first.promise),
        other: lazyPage(() => second.promise),
      })
      const departure = wrapper.get('[data-id="hilos-page-departure"]')

      router.navigate('/lazy')
      await outlast(200)
      const copied = departure.element.firstElementChild
      router.navigate('/other')
      answer()
      await nextTick()
      expect(departure.element.firstElementChild).toBe(copied)
      expect(departure.text()).toBe('Leaving page')
      await outlast(DEFAULT_SKELETON_DELAY_MS - 201)
      expect(wrapper.find('[data-id="hilos-page-skeleton"]').exists()).toBe(
        false,
      )
      await outlast(1)
      expect(wrapper.find('[data-id="hilos-page-skeleton"]').exists()).toBe(
        true,
      )
      expect(departure.element.childElementCount).toBe(0)

      if (outcome === 'resolve') {
        first.resolve(stale.component)
      } else {
        first.reject(new Error('Old chunk failed'))
      }
      await settleCode()
      expect(stale.mounted).not.toHaveBeenCalled()
      expect(current.mounted).not.toHaveBeenCalled()
      expect(wrapper.find('[data-id="page-error"]').exists()).toBe(false)
      expect(
        wrapper.get('[data-id="hilos-page-state"]').attributes('data-state'),
      ).toBe('loading')

      second.resolve(current.component)
      await settleCode()
      expect(current.mounted).toHaveBeenCalledTimes(1)
      expect(
        wrapper.get('[data-id="hilos-page-state"]').attributes('data-state'),
      ).toBe('ready')
      wrapper.unmount()
    },
  )

  it('removes the departure as soon as code and data are ready without flashing a skeleton', async () => {
    const pending = deferred<Component>()
    const page = trackedPage('lazy-page')
    const { router, answer } = waitingRouter()
    answer()
    const wrapper = mountPages(router, {
      main: markRaw(MainPage),
      lazy: lazyPage(() => pending.promise),
    })

    router.navigate('/lazy')
    await outlast(100)
    answer()
    pending.resolve(page.component)
    await settleCode()
    expect(
      wrapper.get('[data-id="hilos-page-departure"]').element.childElementCount,
    ).toBe(0)
    expect(page.mounted).toHaveBeenCalledTimes(1)
    await outlast(DEFAULT_SKELETON_DELAY_MS)
    expect(wrapper.find('[data-id="hilos-page-skeleton"]').exists()).toBe(false)
    expect(wrapper.findAll('[role="status"]')).toHaveLength(1)
    expect(wrapper.get('[role="status"]').text()).toBe('')
    wrapper.unmount()
  })

  it('shows the safe local error, returns home by SPA navigation and retries only on revisit', async () => {
    const first = deferred<Component>()
    const retry = deferred<Component>()
    const page = trackedPage('lazy-page')
    const loader = vi
      .fn()
      .mockReturnValueOnce(first.promise)
      .mockReturnValueOnce(retry.promise)
    const { router, answer, subscribe } = waitingRouter()
    answer()
    const wrapper = mountPages(router, {
      main: markRaw(MainPage),
      lazy: lazyPage(loader),
    })

    router.navigate('/lazy')
    await nextTick()
    first.reject(new Error('secret engine message /assets/chunk.js'))
    await settleCode()
    expect(wrapper.findComponent(ErrorPage).props('error')).toEqual(
      LOCAL_FAILURE,
    )
    expect(
      wrapper.get('[data-id="page-error"]').attributes('data-error-code'),
    ).toBe('503')
    expect(wrapper.text()).toContain('Page could not be loaded')
    expect(wrapper.text()).toContain('Please try opening this page again.')
    expect(wrapper.text()).not.toContain('secret engine message')
    expect(wrapper.text()).not.toContain('/assets/chunk.js')
    expect(
      wrapper.get('[data-id="hilos-page-state"]').attributes('data-state'),
    ).toBe('error')
    expect(
      wrapper.get('[data-id="hilos-page-departure"]').element.childElementCount,
    ).toBe(0)
    await outlast(DEFAULT_SKELETON_DELAY_MS)
    expect(loader).toHaveBeenCalledTimes(1)
    expect(wrapper.find('[data-id="hilos-page-skeleton"]').exists()).toBe(false)

    await wrapper.get('[data-id="page-error-home"]').trigger('click')
    expect(router.currentPath.get()).toBe('/')
    expect(subscribe).toHaveBeenLastCalledWith('main', {})
    answer()
    await nextTick()
    expect(wrapper.find('[data-id="main"]').exists()).toBe(true)
    router.navigate('/lazy')
    expect(loader).toHaveBeenCalledTimes(2)
    answer()
    retry.resolve(page.component)
    await settleCode()
    expect(page.mounted).toHaveBeenCalledTimes(1)
    expect(wrapper.find('[data-id="page-error"]').exists()).toBe(false)
    wrapper.unmount()
  })

  it.each([
    { httpCode: 401, auth: true },
    { httpCode: 401, auth: false },
    { httpCode: 403, auth: true },
    { httpCode: 404, auth: true },
  ])(
    'shows the server refusal immediately ($httpCode, auth=$auth) over a late import failure',
    async ({ httpCode, auth }) => {
      const pending = deferred<Component>()
      const page = trackedPage('lazy-page')
      const authSurface = markRaw(
        defineComponent(
          () => () => h('div', { 'data-id': 'auth-surface' }, 'Sign in'),
        ),
      )
      const { router, answer, pageError } = waitingRouter()
      answer()
      const wrapper = mountPages(router, {
        main: markRaw(MainPage),
        lazy: lazyPage(() => pending.promise),
      })
      if (auth) {
        await wrapper.setProps({ authSurface })
      }
      router.navigate('/lazy')
      await nextTick()
      const refusal = {
        ...NOT_FOUND,
        page: 'lazy',
        httpCode,
        title: 'Server title',
        message: 'Server refusal words',
      }
      pageError.set(refusal)
      await nextTick()
      expect(
        wrapper.get('[data-id="hilos-page-departure"]').element
          .childElementCount,
      ).toBe(0)
      expect(
        wrapper.get('[data-id="hilos-page-state"]').attributes('data-state'),
      ).toBe('error')
      expect(wrapper.find('[data-id="auth-surface"]').exists()).toBe(
        httpCode === 401 && auth,
      )
      if (httpCode !== 401 || !auth) {
        expect(wrapper.findComponent(ErrorPage).props('error')).toEqual(refusal)
        expect(wrapper.text()).toContain('Server refusal words')
      }
      pending.reject(new Error('Chunk failed'))
      await settleCode()
      await outlast(DEFAULT_SKELETON_DELAY_MS)
      expect(page.mounted).not.toHaveBeenCalled()
      expect(wrapper.find('[data-id="hilos-page-skeleton"]').exists()).toBe(
        false,
      )
      expect(wrapper.find('[data-id="auth-surface"]').exists()).toBe(
        httpCode === 401 && auth,
      )
      if (httpCode !== 401 || !auth) {
        expect(wrapper.findComponent(ErrorPage).props('error')).toEqual(refusal)
      }
      wrapper.unmount()
    },
  )

  it('keeps a resolved component gated by 401 and reuses it after authentication', async () => {
    const pending = deferred<Component>()
    const page = trackedPage('lazy-page')
    const loader = vi.fn(() => pending.promise)
    const { router, pageError, pageLoading, answer } = waitingRouter('/lazy')
    const currentUserId = createSignal<number | null>(null)
    const gate = createAuthGate({ router, currentUserId })
    const authSurface = markRaw(
      defineComponent(
        () => () => h('div', { 'data-id': 'auth-surface' }, 'Sign in'),
      ),
    )
    const wrapper = mountPages(router, { lazy: lazyPage(loader) })
    await wrapper.setProps({ authSurface, authGate: gate })

    pageError.set({ ...NOT_FOUND, page: 'lazy', httpCode: 401 })
    pageLoading.set(false)
    gate.requireAuth()
    pending.resolve(page.component)
    await settleCode()
    expect(wrapper.findAll('[data-id="auth-surface"]')).toHaveLength(1)
    expect(wrapper.findComponent(ErrorPage).exists()).toBe(false)
    expect(page.mounted).not.toHaveBeenCalled()
    expect(document.querySelector('[role="dialog"]')).toBeNull()

    router.awaitPageAnswer()
    currentUserId.set(42)
    await nextTick()
    expect(page.mounted).not.toHaveBeenCalled()
    answer()
    await nextTick()
    expect(page.mounted).toHaveBeenCalledTimes(1)
    expect(loader).toHaveBeenCalledTimes(1)
    expect(wrapper.find('[data-id="auth-surface"]').exists()).toBe(false)
    expect(gate.modalOpen.get()).toBe(false)
    wrapper.unmount()
  })

  it('lets a later server refusal replace the local load error', async () => {
    const pending = deferred<Component>()
    const { router, pageError } = waitingRouter('/lazy')
    const wrapper = mountPages(router, {
      lazy: lazyPage(() => pending.promise),
    })
    pending.reject(new Error('Chunk failed'))
    await settleCode()
    expect(wrapper.findComponent(ErrorPage).props('error')).toEqual(
      LOCAL_FAILURE,
    )

    const refusal = { ...NOT_FOUND, page: 'lazy', httpCode: 403 }
    pageError.set(refusal)
    await nextTick()
    expect(wrapper.findComponent(ErrorPage).props('error')).toEqual(refusal)
    wrapper.unmount()
  })

  it('replaces a descriptor at the same key and ignores the old resolution', async () => {
    const old = deferred<Component>()
    const replacement = deferred<Component>()
    const stale = trackedPage('stale-page')
    const current = trackedPage('current-page')
    const firstLoader = vi.fn(() => old.promise)
    const nextLoader = vi.fn(() => replacement.promise)
    const { router, answer } = waitingRouter('/lazy')
    const wrapper = mountPages(router, { lazy: lazyPage(firstLoader) })
    answer()
    await outlast(100)
    await wrapper.setProps({ pages: { lazy: lazyPage(nextLoader) } })
    old.resolve(stale.component)
    await settleCode()
    expect(stale.mounted).not.toHaveBeenCalled()
    expect(firstLoader).toHaveBeenCalledTimes(1)
    expect(nextLoader).toHaveBeenCalledTimes(1)
    await outlast(DEFAULT_SKELETON_DELAY_MS - 100)
    expect(wrapper.find('[data-id="hilos-page-skeleton"]').exists()).toBe(true)
    replacement.resolve(current.component)
    await settleCode()
    expect(current.mounted).toHaveBeenCalledTimes(1)

    await wrapper.setProps({ pages: { lazy: markRaw(KnownPage) } })
    expect(wrapper.find('[data-id="known-page"]').exists()).toBe(true)
    await wrapper.setProps({ pages: {} })
    expect(wrapper.find('[data-id="known-page"]').exists()).toBe(false)
    expect(
      wrapper.get('[data-id="hilos-page-state"]').attributes('data-state'),
    ).toBe('ready')
    wrapper.unmount()
  })

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

  it('keeps an in-flight descriptor across map updates and releases its cache on unmount', async () => {
    const pending = deferred<Component>()
    const page = trackedPage('lazy-page')
    const loader = vi.fn(() => pending.promise)
    const descriptor = lazyPage(loader)
    const { router, answer } = waitingRouter('/lazy')
    const wrapper = mountPages(router, { lazy: descriptor })
    await wrapper.setProps({
      pages: { lazy: descriptor, main: markRaw(MainPage) },
    })
    expect(loader).toHaveBeenCalledTimes(1)
    pending.resolve(page.component)
    answer()
    await settleCode()
    expect(page.mounted).toHaveBeenCalledTimes(1)
    wrapper.unmount()

    const remounted = mountPages(router, { lazy: descriptor })
    expect(loader).toHaveBeenCalledTimes(2)
    await settleCode()
    expect(page.mounted).toHaveBeenCalledTimes(2)
    remounted.unmount()
  })

  it.each(['resolve', 'reject'] as const)(
    'does not replace a ready eager page with a departed import %s',
    async (outcome) => {
      const pending = deferred<Component>()
      const stale = trackedPage('stale-page')
      const { router, answer } = waitingRouter('/lazy')
      const wrapper = mountPages(router, {
        lazy: lazyPage(() => pending.promise),
        main: markRaw(MainPage),
      })
      router.navigate('/')
      answer()
      await nextTick()
      expect(wrapper.find('[data-id="main"]').exists()).toBe(true)

      if (outcome === 'resolve') {
        pending.resolve(stale.component)
      } else {
        pending.reject(new Error('Departed chunk failed'))
      }
      await settleCode()
      expect(stale.mounted).not.toHaveBeenCalled()
      expect(wrapper.find('[data-id="main"]').exists()).toBe(true)
      expect(wrapper.find('[data-id="page-error"]').exists()).toBe(false)
      expect(wrapper.find('[data-id="hilos-page-skeleton"]').exists()).toBe(
        false,
      )
      expect(
        wrapper.get('[data-id="hilos-page-state"]').attributes('data-state'),
      ).toBe('ready')
      wrapper.unmount()
    },
  )

  it('reuses the descriptor across maps and parameters while waiting for each server answer', async () => {
    const pending = deferred<Component>()
    const page = trackedPage('lazy-page')
    const loader = vi.fn(() => pending.promise)
    const descriptor = lazyPage(loader)
    const { router, answer } = waitingRouter('/lazy/1')
    const wrapper = mountPages(router, { lazy: descriptor })
    pending.resolve(page.component)
    answer()
    await settleCode()
    await wrapper.setProps({
      pages: { lazy: descriptor, main: markRaw(MainPage) },
    })
    expect(page.mounted).toHaveBeenCalledTimes(1)

    router.navigate('/lazy/2')
    await nextTick()
    expect(wrapper.find('[data-id="lazy-page"]').exists()).toBe(false)
    expect(
      wrapper.get('[data-id="hilos-page-state"]').attributes('data-state'),
    ).toBe('loading')
    answer()
    await nextTick()
    expect(page.mounted).toHaveBeenCalledTimes(2)
    router.navigate('/')
    answer()
    await nextTick()
    router.navigate('/lazy/3')
    answer()
    await nextTick()
    expect(page.mounted).toHaveBeenCalledTimes(3)
    expect(loader).toHaveBeenCalledTimes(1)
    wrapper.unmount()
  })

  it.each(['resolve', 'reject'] as const)(
    'disposes listeners and timers before a late %s',
    async (outcome) => {
      const pending = deferred<Component>()
      const page = trackedPage('lazy-page')
      const otherLoader = vi.fn(() => Promise.resolve(markRaw(KnownPage)))
      const { router, answer } = waitingRouter()
      answer()
      const pages = shallowReactive<Record<string, PageView<Component>>>({
        main: markRaw(MainPage),
        lazy: lazyPage(() => pending.promise),
        other: lazyPage(otherLoader),
      })
      const wrapper = mountPages(router, pages)
      router.navigate('/lazy')
      await nextTick()
      const departure = wrapper.get('[data-id="hilos-page-departure"]').element
      expect(departure.childElementCount).toBe(2)
      wrapper.unmount()
      expect(departure.childElementCount).toBe(0)
      expect(vi.getTimerCount()).toBe(0)

      if (outcome === 'resolve') {
        pending.resolve(page.component)
      } else {
        pending.reject(new Error('Disposed chunk failed'))
      }
      pages.lazy = lazyPage(otherLoader)
      router.navigate('/other')
      answer()
      await settleCode()
      expect(otherLoader).not.toHaveBeenCalled()
      expect(page.mounted).not.toHaveBeenCalled()
      expect(departure.childElementCount).toBe(0)
      expect(vi.getTimerCount()).toBe(0)
    },
  )

  it.each(['unbuilt', 'unknown', 'refused'] as const)(
    'does not start a loader for a known %s page',
    async (kind) => {
      const loader = vi.fn(() => Promise.resolve(markRaw(KnownPage)))
      const { router, pageError } = waitingRouter(
        kind === 'unknown' ? '/unknown' : '/lazy',
        new Set(kind === 'unbuilt' ? ['lazy'] : []),
      )
      if (kind === 'refused') {
        pageError.set({ ...NOT_FOUND, page: 'lazy' })
      }
      const wrapper = mountPages(router, { lazy: lazyPage(loader) })
      await settleCode()
      expect(loader).not.toHaveBeenCalled()
      expect(wrapper.find('[data-id="page-error"]').exists()).toBe(true)
      expect(wrapper.find('[data-id="hilos-page-skeleton"]').exists()).toBe(
        false,
      )
      wrapper.unmount()
    },
  )
})

/** The ordinary 404 of a card whose resource no longer exists. */
const CARD_NOT_FOUND: PageSubscriptionError = {
  page: 'lazy',
  httpCode: 404,
  errorCode: 'not_found',
  title: 'Page Not Found',
  message: 'The page you are looking for does not exist.',
}

/** The live 404 of a card whose resource was deleted while it stood open. */
const CARD_DELETED: PageSubscriptionError = {
  ...CARD_NOT_FOUND,
  transitionMessage: 'It was deleted just now.',
}

const CARD_IDENTITY: HilosPageIdentity = {
  label: 'Poland',
  lead: 'One country.',
  breadcrumb: [{ page: 'lazy', label: 'Poland' }],
  children: [],
}

/**
 * A card on the admin shell with one dialog in #modals, opened from a button
 * in its content. The draft is the input's own value, so it lives exactly as
 * long as that input does.
 *
 * @param preserveModalDraftsOnDelete Whether the card opts in.
 */
function shellPage(preserveModalDraftsOnDelete: boolean) {
  const mounted = vi.fn()
  const component = markRaw(
    defineComponent(() => {
      mounted()
      const open = ref(false)

      return () =>
        h(
          HilosAdminPage,
          { page: 'lazy', preserveModalDraftsOnDelete },
          {
            default: () => [
              h('p', { 'data-id': 'page-body' }, 'The card'),
              h(
                'button',
                {
                  type: 'button',
                  'data-id': 'open-draft',
                  onClick: () => {
                    open.value = true
                  },
                },
                'Rename',
              ),
            ],
            modals: () =>
              h(
                HilosModal,
                {
                  modelValue: open.value,
                  title: 'Rename',
                  'onUpdate:modelValue': (next: boolean) => {
                    open.value = next
                  },
                },
                () => h('input', { 'data-id': 'draft', 'data-autofocus': '' }),
              ),
          },
        )
    }),
  )

  return { component, mounted }
}

describe('HilosView and a page shell under a live deletion', () => {
  const wrappers: { unmount(): void }[] = []

  // HilosModal teleports to <body>, so the document has to start each case
  // empty and every mount has to release its modal layer.
  afterEach(() => {
    for (const wrapper of wrappers.splice(0)) {
      wrapper.unmount()
    }
    document.body.innerHTML = ''
    document.body.classList.remove('modal-open')
  })

  /**
   * Open the card, its dialog from the card's button, and type a draft.
   *
   * @param preserve Whether the card opts in.
   * @param kind Whether the outlet maps the card eagerly or behind a loader.
   */
  async function openCard(preserve: boolean, kind: 'eager' | 'lazy' = 'eager') {
    const page = shellPage(preserve)
    const harness = waitingRouter('/lazy/1')
    harness.pageIdentity.set(CARD_IDENTITY)
    const wrapper = mount(HilosView, {
      props: {
        pages: {
          main: markRaw(MainPage),
          lazy:
            kind === 'eager'
              ? page.component
              : lazyPage(() => Promise.resolve(page.component)),
        },
      },
      global: { provide: { [hilosRouterKey as symbol]: harness.router } },
      attachTo: document.body,
    })
    wrappers.push(wrapper)
    harness.answer()
    await settleCode()
    const opener = wrapper.get('[data-id="open-draft"]')
    ;(opener.element as HTMLElement).focus()
    await opener.trigger('click')
    await settleCode()
    const draft = document.querySelector<HTMLInputElement>('[data-id="draft"]')!
    draft.value = 'my draft'
    expect(page.mounted).toHaveBeenCalledTimes(1)
    expect(document.activeElement).toBe(draft)

    return { ...harness, page, wrapper, draft }
  }

  it.each(['eager', 'lazy'] as const)(
    'keeps the %s card, its dialog and the draft while the shell draws the one error',
    async (kind) => {
      const { pageError, page, wrapper, draft } = await openCard(true, kind)
      expect(wrapper.get('[data-id="hilos-admin-title"]').text()).toBe('Poland')

      pageError.set(CARD_DELETED)
      await nextTick()

      expect(page.mounted).toHaveBeenCalledTimes(1)
      expect(document.querySelector('[data-id="draft"]')).toBe(draft)
      expect(draft.value).toBe('my draft')
      expect(wrapper.findAllComponents(ErrorPage)).toHaveLength(1)
      const shell = wrapper.get('[data-id="hilos-admin-page"]')
      expect(shell.find('[data-id="page-error"]').exists()).toBe(true)
      expect(
        shell.get('[data-id="page-error-transition-message"]').text(),
      ).toBe('It was deleted just now.')
      for (const gone of [
        'hilos-breadcrumb',
        'hilos-admin-title',
        'page-body',
        'open-draft',
      ]) {
        expect(wrapper.find(`[data-id="${gone}"]`).exists()).toBe(false)
      }
      expect(wrapper.findAll('h1')).toHaveLength(1)
      expect(
        wrapper.get('[data-id="hilos-page-state"]').attributes('data-state'),
      ).toBe('error')
    },
  )

  it('keeps the hold through a plain 404 that brings no line', async () => {
    const { pageError, page, wrapper, draft } = await openCard(true)
    pageError.set(CARD_DELETED)
    await nextTick()

    pageError.set({ ...CARD_NOT_FOUND })
    await nextTick()

    expect(
      wrapper.find('[data-id="page-error-transition-message"]').exists(),
    ).toBe(false)
    expect(wrapper.findAllComponents(ErrorPage)).toHaveLength(1)
    expect(
      wrapper
        .get('[data-id="hilos-admin-page"]')
        .find('[data-id="page-error"]')
        .exists(),
    ).toBe(true)
    expect(page.mounted).toHaveBeenCalledTimes(1)
    expect(document.querySelector('[data-id="draft"]')).toBe(draft)
    expect(draft.value).toBe('my draft')
  })

  it.each([
    { httpCode: 401, errorCode: 'unauthorized' },
    { httpCode: 403, errorCode: 'forbidden' },
    { httpCode: 503, errorCode: 'unavailable' },
  ])(
    'takes the held card and its dialog away on a $httpCode',
    async ({ httpCode, errorCode }) => {
      const { pageError, wrapper } = await openCard(true)
      pageError.set(CARD_DELETED)
      await nextTick()

      pageError.set({
        ...CARD_NOT_FOUND,
        httpCode,
        errorCode,
        title: 'Refused',
        message: 'Server refusal words',
      })
      await nextTick()

      expect(wrapper.find('[data-id="hilos-admin-page"]').exists()).toBe(false)
      expect(document.querySelector('[data-id="modal"]')).toBeNull()
      expect(wrapper.findAllComponents(ErrorPage)).toHaveLength(1)
      expect(
        wrapper.get('[data-id="page-error"]').attributes('data-error-code'),
      ).toBe(String(httpCode))
      expect(wrapper.text()).toContain('Server refusal words')
    },
  )

  it('takes the held card and its dialog away on navigation', async () => {
    const { pageError, answer, wrapper } = await openCard(true)
    pageError.set(CARD_DELETED)
    await nextTick()

    await wrapper.get('[data-id="page-error-home"]').trigger('click')
    answer()
    await nextTick()

    expect(wrapper.find('[data-id="main"]').exists()).toBe(true)
    expect(wrapper.find('[data-id="hilos-admin-page"]').exists()).toBe(false)
    expect(document.querySelector('[data-id="modal"]')).toBeNull()
    expect(wrapper.find('[data-id="page-error"]').exists()).toBe(false)
  })

  it('leaves a retained deleted card and its dialog for the public file reader', async () => {
    const { pageError, router, wrapper } = await openCard(true)
    const status = trackedPage('public-file-reader')
    await wrapper.setProps({
      pages: {
        ...wrapper.props('pages'),
        [HilosPages.PUBLIC_STATUS]: status.component,
      },
    })
    pageError.set(CARD_DELETED)
    await nextTick()
    expect(wrapper.findComponent(HilosAdminPage).exists()).toBe(true)
    expect(document.querySelector('[data-id="modal"]')).not.toBeNull()
    router.navigate('/status')
    await nextTick()
    expect(wrapper.find('[data-id="public-file-reader"]').exists()).toBe(true)
    expect(wrapper.findComponent(HilosAdminPage).exists()).toBe(false)
    expect(wrapper.findComponent(ErrorPage).exists()).toBe(false)
    expect(document.querySelector('[data-id="modal"]')).toBeNull()
    expect(
      wrapper.get('[data-id="hilos-page-departure"]').element.childElementCount,
    ).toBe(0)
  })

  it('replaces a card that did not opt in, line and all', async () => {
    const { pageError, page, wrapper } = await openCard(false)

    pageError.set(CARD_DELETED)
    await nextTick()

    expect(wrapper.find('[data-id="hilos-admin-page"]').exists()).toBe(false)
    expect(document.querySelector('[data-id="modal"]')).toBeNull()
    expect(wrapper.findAllComponents(ErrorPage)).toHaveLength(1)
    expect(
      wrapper.get('[data-id="page-error-transition-message"]').text(),
    ).toBe('It was deleted just now.')
    expect(page.mounted).toHaveBeenCalledTimes(1)
  })

  it('never mounts an opted-in card that is refused before it is shown', async () => {
    const page = shellPage(true)
    const { router, pageError, pageLoading } = waitingRouter('/lazy/1')
    pageError.set(CARD_DELETED)
    pageLoading.set(false)
    const wrapper = mountPages(router, { lazy: page.component })
    wrappers.push(wrapper)
    await settleCode()

    expect(page.mounted).not.toHaveBeenCalled()
    expect(wrapper.find('[data-id="hilos-admin-page"]').exists()).toBe(false)
    expect(wrapper.findAllComponents(ErrorPage)).toHaveLength(1)
  })

  it('leaves focus in the open dialog and returns it to Back to home on close', async () => {
    const { pageError, wrapper, draft } = await openCard(true)

    pageError.set(CARD_DELETED)
    await nextTick()
    expect(document.activeElement).toBe(draft)

    document.querySelector<HTMLElement>('[data-id="modal-close"]')!.click()
    await nextTick()

    expect(document.querySelector('[data-id="modal"]')).toBeNull()
    expect(document.activeElement).toBe(
      wrapper.get('[data-id="page-error-home"]').element,
    )
  })
})

describe('HilosView live access loss', () => {
  const releases: Array<() => void> = []
  const wrappers: Array<{ unmount(): void }> = []
  const AuthSurface = markRaw(
    defineComponent(
      () => () =>
        h('form', { 'data-id': 'auth-surface' }, [
          h('input', { 'data-id': 'auth-identifier', 'data-autofocus': '' }),
        ]),
    ),
  )
  const DirtyPage = markRaw(
    defineComponent(() => {
      const open = ref(false)
      return () =>
        h('div', { 'data-id': 'private-page' }, [
          h(
            'button',
            {
              'data-id': 'open-draft',
              onClick: () => {
                open.value = true
              },
            },
            'Edit',
          ),
          h(
            HilosModal,
            {
              modelValue: open.value,
              title: 'Edit',
              confirmOnClose: true,
              'onUpdate:modelValue': (next: boolean) => {
                open.value = next
              },
            },
            () => h('input', { 'data-id': 'draft', 'data-autofocus': '' }),
          ),
        ])
    }),
  )

  beforeEach(() => vi.useFakeTimers())
  afterEach(() => {
    for (const wrapper of wrappers.splice(0)) wrapper.unmount()
    for (const release of releases.splice(0)) release()
    document.body.innerHTML = ''
    document.body.classList.remove('modal-open')
    vi.useRealTimers()
  })

  /** Real boot bindings with session and page frames delivered on one source. */
  function session(initial = '/') {
    const listeners = new Set<(signal: ProjectSignal) => void>()
    const send = vi.fn(() => true)
    const connection = {
      state: 'connected',
      reconnectDragging: false,
      on(event: string, listener: (payload: never) => void): () => void {
        const projectListener = listener as (signal: ProjectSignal) => void
        if (event === 'projectSignal') listeners.add(projectListener)
        return () => listeners.delete(projectListener)
      },
      send,
      forgetPageFrames: () => {},
      tableWindowDescriptors: () => ({}),
    } as unknown as HilosConnection
    const scopes = new ScopeManager()
    bindSessionScope(connection, scopes)
    const pages = bindPageScope(connection, scopes)
    const router = createHilosRouter(
      createPageRouter({
        main: { path: '/', admin: false },
        lazy: { path: '/lazy/{id?}', admin: true },
        other: { path: '/other', admin: false },
      }),
      pages,
      navigation(initial),
    )
    releases.push(bindAccessLoss(scopes, router))
    pages.releaseOnSession()
    router.start()
    const gate = createAuthGate({
      router,
      currentUserId: sessionUserId(scopes),
    })
    const emit = (type: string, data: unknown): void => {
      for (const listener of listeners) {
        listener({ kind: 'project', type, data, envelope: {} } as ProjectSignal)
      }
    }
    const handshake = (
      id: number | null,
      data: Record<string, unknown> = {},
    ): void =>
      emit('handshake_response', {
        entities: { currentUser: id === null ? null : { id } },
        data: { accountBlocked: null, ...data },
      })
    const answer = (): void =>
      emit('page_response', {
        page: router.currentRoute.get().page,
        payload: {},
      })
    const refuse = (error: PageError): void =>
      emit('subscription_page_error', {
        ...error,
        page: router.currentRoute.get().page,
      })
    const render = (auth = true, page: Component = DirtyPage) => {
      const wrapper = mount(HilosView, {
        props: {
          pages: { main: page, lazy: page, other: MainPage },
          ...(auth ? { authSurface: AuthSurface, authGate: gate } : {}),
        },
        global: { provide: { [hilosRouterKey as symbol]: router } },
        attachTo: document.body,
      })
      wrappers.push(wrapper)
      return wrapper
    }
    return { router, send, gate, handshake, answer, refuse, render }
  }

  it('removes a dirty dialog without confirmation and focuses the sole in-place auth form', async () => {
    const s = session()
    s.handshake(7)
    s.answer()
    const wrapper = s.render()
    const region = wrapper.get('[role="status"]').element
    const opener = wrapper.get('[data-id="open-draft"]')
    ;(opener.element as HTMLElement).focus()
    await opener.trigger('click')
    await settleCode()
    const draft = document.querySelector<HTMLInputElement>('[data-id="draft"]')!
    draft.value = 'Unsaved phone number'
    expect(document.activeElement).toBe(draft)
    const sentBefore = s.send.mock.calls.length

    s.handshake(null, { accessLossReason: 'session_ended' })
    s.gate.requireAuth()
    await settleCode()

    const notice = wrapper.get('[data-id="access-loss-notice"]')
    expect(notice.text()).toBe(
      'Your session has ended. Any unsaved changes were lost.',
    )
    expect(notice.classes()).toContain('alert-warning')
    expect(notice.element.parentElement).toBe(region)
    expect(region.getAttribute('role')).toBe('status')
    expect(region.getAttribute('aria-live')).toBe('polite')
    expect(wrapper.find('[data-id="private-page"]').exists()).toBe(false)
    expect(draft.isConnected).toBe(false)
    expect(document.querySelector('[data-id="modal"]')).toBeNull()
    expect(document.querySelector('[data-id="modal-confirm"]')).toBeNull()
    expect(document.querySelectorAll('[data-id="auth-surface"]')).toHaveLength(
      1,
    )
    expect(document.activeElement).toBe(
      wrapper.get('[data-id="auth-identifier"]').element,
    )
    expect(s.send).toHaveBeenCalledTimes(sentBefore)
    expect(s.router.currentPath.get()).toBe('/')

    s.handshake(null)
    s.answer()
    await outlast(DEFAULT_SKELETON_DELAY_MS)
    expect(wrapper.get('[data-id="access-loss-notice"]').text()).toBe(
      notice.text(),
    )
    expect(wrapper.find('[data-id="private-page"]').exists()).toBe(false)
    expect(wrapper.find('[data-id="hilos-page-skeleton"]').exists()).toBe(false)
    expect(document.querySelectorAll('[data-id="auth-surface"]')).toHaveLength(
      1,
    )

    s.handshake(8)
    s.answer()
    await settleCode()
    expect(wrapper.find('[data-id="access-loss-notice"]').exists()).toBe(false)
    expect(wrapper.find('[data-id="private-page"]').exists()).toBe(true)
    expect(document.querySelector('[data-id="auth-surface"]')).toBeNull()
  })

  it('replaces an already open action-auth dialog with one in-place surface', async () => {
    const s = session()
    s.handshake(7)
    s.answer()
    const wrapper = s.render()
    s.gate.requireAuth()
    await settleCode()
    expect(document.querySelector('[data-id="modal"]')).not.toBeNull()
    expect(document.querySelectorAll('[data-id="auth-surface"]')).toHaveLength(
      1,
    )

    s.handshake(null, { accessLossReason: 'session_ended' })
    await settleCode()
    expect(document.querySelector('[data-id="modal"]')).toBeNull()
    expect(wrapper.findAll('[data-id="auth-surface"]')).toHaveLength(1)
    expect(document.querySelectorAll('[data-id="auth-surface"]')).toHaveLength(
      1,
    )
    expect(document.activeElement).toBe(
      wrapper.get('[data-id="auth-identifier"]').element,
    )
  })

  it.each(['session_ended', 'account_blocked'] as const)(
    'clears an existing departure copy synchronously on %s and never copies the lost surface on leave',
    async (reason) => {
      const s = session()
      s.handshake(7)
      s.answer()
      const wrapper = s.render()
      s.router.navigate('/other')
      const departure = wrapper.get('[data-id="hilos-page-departure"]')
      expect(departure.text()).toBe('Edit')
      expect(departure.element.childElementCount).toBe(2)

      s.handshake(null, {
        accessLossReason: reason,
        accountBlocked:
          reason === 'account_blocked'
            ? { identifier: 'ada@example.com' }
            : null,
      })
      expect(departure.element.childElementCount).toBe(0)
      await settleCode()
      s.answer()
      await settleCode()
      s.router.navigate('/')
      expect(departure.element.childElementCount).toBe(0)
      await settleCode()
      expect(wrapper.find('[data-id="access-loss-notice"]').exists()).toBe(
        false,
      )
      s.answer()
      await settleCode()
      expect(wrapper.find('[data-id="private-page"]').exists()).toBe(true)
    },
  )

  it.each(['loading', 'error', 'retained', 'public'] as const)(
    'gives live session end priority over a %s page and its later guest answer',
    async (state) => {
      const s = session(state === 'retained' ? '/lazy/1' : '/')
      s.handshake(7)
      if (state !== 'loading') s.answer()
      const wrapper = s.render(
        true,
        state === 'retained' ? shellPage(true).component : DirtyPage,
      )
      await settleCode()
      if (state === 'error') s.refuse(NOT_FOUND)
      if (state === 'retained') s.refuse(CARD_DELETED)
      await settleCode()
      if (state === 'retained') {
        expect(wrapper.find('[data-id="hilos-admin-page"]').exists()).toBe(true)
      }
      s.handshake(null, { accessLossReason: 'session_ended' })
      await outlast(DEFAULT_SKELETON_DELAY_MS)
      for (const id of [
        'page-error',
        'hilos-page-skeleton',
        'hilos-admin-page',
        'private-page',
      ]) {
        expect(wrapper.find(`[data-id="${id}"]`).exists()).toBe(false)
      }
      expect(wrapper.find('[data-id="access-loss-notice"]').exists()).toBe(true)
      expect(wrapper.findAll('[data-id="auth-surface"]')).toHaveLength(1)
      s.answer()
      await settleCode()
      expect(wrapper.find('[data-id="private-page"]').exists()).toBe(false)
      expect(wrapper.find('[data-id="access-loss-notice"]').exists()).toBe(true)
    },
  )

  it('removes the previous view and explains the loss even without an auth surface', async () => {
    const s = session()
    s.handshake(7)
    s.answer()
    const wrapper = s.render(false)
    s.handshake(null, { accessLossReason: 'session_ended' })
    await settleCode()
    expect(wrapper.find('[data-id="private-page"]').exists()).toBe(false)
    expect(wrapper.find('[data-id="auth-surface"]').exists()).toBe(false)
    expect(wrapper.get('[data-id="access-loss-notice"]').text()).toBe(
      'Your session has ended. Any unsaved changes were lost.',
    )
    expect(document.activeElement).toBe(
      wrapper.get('.hilos-page-slot > [tabindex="-1"]').element,
    )
    s.answer()
    await settleCode()
    expect(wrapper.find('[data-id="private-page"]').exists()).toBe(false)
  })

  it('preserves a deliberate focus choice outside the replaced page', async () => {
    const s = session()
    s.handshake(7)
    s.answer()
    const wrapper = s.render()
    const nav = document.createElement('button')
    document.body.append(nav)
    nav.focus()
    s.handshake(null, { accessLossReason: 'session_ended' })
    await settleCode()
    expect(wrapper.find('[data-id="access-loss-notice"]').exists()).toBe(true)
    expect(document.activeElement).toBe(nav)
  })

  it.each(['cold guest', 'new blocked sign-in', 'voluntary logout'])(
    'shows no loss notice for %s',
    async (kind) => {
      const s = session()
      if (kind === 'voluntary logout') s.handshake(7)
      const wrapper = s.render()
      s.handshake(
        null,
        kind === 'new blocked sign-in'
          ? { accountBlocked: { identifier: 'ada@example.com' } }
          : {},
      )
      s.answer()
      await settleCode()
      expect(wrapper.find('[data-id="access-loss-notice"]').exists()).toBe(
        false,
      )
    },
  )
})
