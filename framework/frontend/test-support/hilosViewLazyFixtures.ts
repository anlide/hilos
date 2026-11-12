// Native components and external router inputs for the lazy outlet scenarios.
import {
  createHilosRouter,
  createPageRouter,
  createSignal,
  type AuthGate,
  type HilosRouter,
  type PageError,
  type PageSubscriptionError,
  type PageView,
} from '@hilos/core/base'
import type { Mock } from 'vitest'

import { PAGE_ERROR_NOT_FOUND } from '../core/src/protocol/pageError.js'

/** Real components and their observed mount lifecycle. */
export interface LazyTrackedPage<T> {
  component: T
  mounted: Mock<() => void>
}

/** Inputs to the real HilosView, with code and server readiness independent. */
export interface LazyOutletInput<T> {
  router: HilosRouter
  pages: Record<string, PageView<T>>
  pageSkeletons?: Record<string, T>
  authSurface?: T
  authGate?: AuthGate
}

/** Lifecycle only; the real core owns readiness, caching and disposal. */
export interface LazyOutlet<T> {
  readonly root: ParentNode
  flush(change?: () => void): Promise<void>
  setPages(pages: Record<string, PageView<T>>): Promise<void>
  unmount(): Promise<void>
  /** Vue's public VTU props reader, used only by its local API assertions. */
  errorInput?: () => PageError
}

/** Subject fixtures are native components, including the actual admin registry. */
export interface HilosViewLazyKit<T> {
  main: T
  known: T
  skeleton: T
  tracked(id: string): LazyTrackedPage<T>
  adminViews(): Record<string, PageView<T>>
  mount(input: LazyOutletInput<T>): Promise<LazyOutlet<T>>
}

/** Every created outlet is released even when mounting or an assertion fails. */
export type WithHilosViewLazy<T> = (
  steps: (kit: HilosViewLazyKit<T>) => Promise<void>,
) => Promise<void>

/** Independently controlled import completion; it does not implement a cache. */
export function lazyDeferred<T>() {
  let resolve!: (value: T) => void
  let reject!: (error: Error) => void
  const promise = new Promise<T>((yes, no) => {
    resolve = yes
    reject = no
  })
  return { promise, resolve, reject }
}

/** A server refusal with deliberately recognizable server-authored wording. */
export const LAZY_NOT_FOUND: PageSubscriptionError = {
  page: 'lazy',
  httpCode: 404,
  errorCode: PAGE_ERROR_NOT_FOUND,
  title: 'Page Not Found',
  message: 'Not found',
}

/** Real navigation order and subscription calls; only the server answer is held. */
export function lazyRouter(
  subscribe: Mock<(page: string, params: Record<string, string>) => void>,
  initial = '/',
  unbuilt: ReadonlySet<string> = new Set(),
) {
  let pathname = initial
  const pageLoading = createSignal(false)
  const pageError = createSignal<PageSubscriptionError | null>(null)
  const awaitPageAnswer = () => {
    pageError.set(null)
    pageLoading.set(true)
  }
  subscribe.mockImplementation(awaitPageAnswer)
  const router = createHilosRouter(
    createPageRouter({
      main: { path: '/', admin: false },
      lazy: { path: '/lazy/{id?}', admin: true },
      other: { path: '/other', admin: true },
    }),
    {
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
      pageLoading,
      pageError,
      pageIdentity: createSignal(undefined),
      dashboardSections: createSignal(undefined),
      clearPageError: () => pageError.set(null),
      denyCurrentPage: () => {},
      awaitPageAnswer,
    },
    {
      pathname: () => pathname,
      pushState: (next) => {
        pathname = next
      },
      replaceState: (next) => {
        pathname = next
      },
      onPopState: () => () => {},
    },
    undefined,
    unbuilt,
  )
  router.start()
  return {
    router,
    pageLoading,
    pageError,
    subscribe,
    answer() {
      pageError.set(null)
      pageLoading.set(false)
    },
  }
}
