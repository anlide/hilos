// The routed outlet while a page waits for its first answer (HIL-983): a skeleton
// in the page's place instead of white, never before the delay, gone on the
// answer, and never over a refusal. The page-state marker the e2e suites of all
// three demos wait on keeps its three values through all of it.
import { mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { defineComponent, h, nextTick } from 'vue'
import { createSignal, DEFAULT_SKELETON_DELAY_MS } from '@hilos/core'
import type {
  HilosRouter,
  PageRouteMatch,
  PageSubscriptionError,
  WritableSignal,
} from '@hilos/core'

import HilosView from './HilosView.vue'
import { hilosRouterKey } from './hilosRouterKey.js'

const NOT_FOUND: PageSubscriptionError = {
  page: 'main',
  httpCode: 404,
  errorCode: 'not_found',
  message: 'Not found',
}

const MainPage = defineComponent(() => () => h('div', { 'data-id': 'main' }))
const MainSkeleton = defineComponent(
  () => () => h('div', { 'data-id': 'main-skeleton' }),
)

interface TestRouter {
  router: HilosRouter
  pageLoading: WritableSignal<boolean>
  pageError: WritableSignal<PageSubscriptionError | null>
}

function testRouter(page: string): TestRouter {
  const pageLoading = createSignal(true)
  const pageError = createSignal<PageSubscriptionError | null>(null)

  return {
    pageLoading,
    pageError,
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
      resolvePath: () => undefined,
      clearPageError: () => {},
      denyCurrentPage: () => {},
      awaitPageAnswer: () => {},
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
    expect(status).toHaveLength(1)
    expect(status[0].text()).toBe('Loading…')
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
