// Public reader assembly uses real navigation and independently supplied frames.
import {
  createHilosRouter,
  createPageRouter,
  createSignal,
  HilosPages,
  lazyPage,
  resolveHilosPath,
  type PageError,
  type PageSubscriptionError,
} from '@hilos/core/base'
import type * as Vitest from 'vitest'
import type { Mock } from 'vitest'

import { PAGE_ERROR_NOT_FOUND } from '../core/src/protocol/pageError.js'
import {
  lazyDeferred,
  type HilosViewLazyKit,
  type LazyOutlet,
  type WithHilosViewLazy,
} from './hilosViewLazyFixtures.js'

type StatusRunner = Pick<typeof Vitest, 'describe' | 'it' | 'expect' | 'vi'>

function statusRouter(
  subscribe: Mock<(page: string, params: Record<string, string>) => void>,
) {
  let pathname = '/'
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
      other: { path: '/other', admin: false },
      [HilosPages.PUBLIC_STATUS]: {
        path: resolveHilosPath(HilosPages.PUBLIC_STATUS)!,
        admin: false,
      },
    }),
    {
      subscribe,
      unsubscribe: () => {
        pageLoading.set(false)
        pageError.set(null)
      },
      refuseUnbuilt: () => {},
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

/** Register the union of public Status inputs through each real SDK outlet. */
export function registerHilosViewStatusCases<T>(
  sdk: string,
  { describe, it, expect, vi }: StatusRunner,
  withKit: WithHilosViewLazy<T>,
  checkErrorInput?: (view: LazyOutlet<T>, expected: PageError) => void,
): void {
  const statusPath = resolveHilosPath(HilosPages.PUBLIC_STATUS)!
  const find = (view: LazyOutlet<T>, id: string) =>
    view.root.querySelector<HTMLElement>(`[data-id="${id}"]`)
  const copy = (view: LazyOutlet<T>) => find(view, 'hilos-page-departure')!
  const marker = (view: LazyOutlet<T>) =>
    find(view, 'hilos-page-state')?.getAttribute('data-state')
  const refusal: PageSubscriptionError = {
    page: 'main',
    httpCode: 404,
    errorCode: PAGE_ERROR_NOT_FOUND,
    title: 'Page Not Found',
    message: 'Not found',
  }
  const localFailure: PageError = {
    httpCode: 503,
    errorCode: 'page_load_failed',
    title: 'Page could not be loaded',
    message: 'Please try opening this page again.',
  }
  const run = async (
    steps: (
      kit: HilosViewLazyKit<T>,
      state: ReturnType<typeof statusRouter>,
    ) => Promise<void>,
  ) => {
    vi.useFakeTimers()
    const state = statusRouter(vi.fn())
    try {
      await withKit((kit) => steps(kit, state))
    } finally {
      state.router.stop()
      vi.restoreAllMocks()
      vi.useRealTimers()
    }
  }

  describe(`HilosView public Status on ${sdk}`, () => {
    it.each(['pending', 'ready', 'stale', 401, 403, 404] as const)(
      'S01 leaves the previous %s subscription and restores ordinary waiting',
      async (previous) => {
        await run(
          async (
            kit,
            { router, answer, pageError, pageLoading, subscribe },
          ) => {
            const status = kit.tracked('public-file-reader')
            const auth = kit.tracked('auth-surface')
            const view = await kit.mount({
              router,
              pages: {
                main: kit.known,
                [HilosPages.PUBLIC_STATUS]: status.component,
              },
              authSurface: auth.component,
            })
            if (previous !== 'pending') await view.flush(answer)
            if (previous === 'stale')
              await view.flush(() => pageLoading.set(true))
            if (typeof previous === 'number') {
              await view.flush(() =>
                pageError.set({ ...refusal, httpCode: previous }),
              )
              expect(find(view, 'auth-surface') !== null).toBe(previous === 401)
            }
            subscribe.mockClear()
            await view.flush(() => router.navigate(statusPath))
            expect(find(view, 'public-file-reader')).not.toBeNull()
            expect(find(view, 'auth-surface')).toBeNull()
            expect(find(view, 'page-error')).toBeNull()
            expect(copy(view).childElementCount).toBe(0)
            expect(subscribe).not.toHaveBeenCalled()
            await view.flush(() => {
              pageError.set({ ...refusal, httpCode: 401 })
              pageLoading.set(true)
              router.denyCurrentPage()
              router.awaitPageAnswer()
            })
            expect(find(view, 'public-file-reader')).not.toBeNull()
            expect(marker(view)).toBe('ready')
            expect(subscribe).not.toHaveBeenCalled()
            await view.flush(() => router.navigate('/'))
            expect(find(view, 'known-page')).toBeNull()
            expect(marker(view)).toBe('loading')
            await view.flush(answer)
            expect(find(view, 'known-page')).not.toBeNull()
          },
        )
      },
    )

    for (const previous of ['refused403', 'ready'] as const) {
      it.each(['resolve', 'reject'] as const)(
        `S02 handles lazy public code %s after ${previous} independently of old frames`,
        async (outcome) => {
          await run(async (kit, { router, answer, pageError, pageLoading }) => {
            const pending = lazyDeferred<T>()
            const status = kit.tracked('public-file-reader')
            const view = await kit.mount({
              router,
              pages: {
                main: kit.known,
                [HilosPages.PUBLIC_STATUS]: lazyPage(() => pending.promise),
              },
            })
            if (previous === 'ready') {
              await view.flush(answer)
              expect(find(view, 'known-page')).not.toBeNull()
            } else {
              await view.flush(() =>
                pageError.set({ ...refusal, httpCode: 403 }),
              )
              expect(find(view, 'page-error')).not.toBeNull()
            }
            await view.flush(() => router.navigate(statusPath))
            expect(marker(view)).toBe('loading')
            expect(status.mounted).not.toHaveBeenCalled()
            expect(find(view, 'known-page')).toBeNull()
            expect(copy(view).childElementCount).toBe(0)
            await view.flush(() => {
              pageError.set({ ...refusal, httpCode: 401 })
              pageLoading.set(true)
            })
            expect(marker(view)).toBe('loading')
            expect(find(view, 'page-error')).toBeNull()
            expect(find(view, 'public-file-reader')).toBeNull()
            expect(copy(view).childElementCount).toBe(0)
            await view.flush(() => {
              if (outcome === 'resolve') pending.resolve(status.component)
              else
                pending.reject(
                  new Error('secret engine message /assets/chunk.js'),
                )
            })
            expect(marker(view)).toBe(outcome === 'resolve' ? 'ready' : 'error')
            expect(find(view, 'public-file-reader') !== null).toBe(
              outcome === 'resolve',
            )
            if (outcome === 'reject') {
              const error = find(view, 'page-error')!
              expect(error.getAttribute('data-error-code')).toBe('503')
              expect(error.querySelector('h1')?.textContent?.trim()).toBe('503')
              expect(
                [...error.querySelectorAll('p')].map((node) =>
                  node.textContent?.trim(),
                ),
              ).toEqual([
                'Page could not be loaded',
                'Please try opening this page again.',
              ])
              expect(view.root.textContent).not.toContain(
                'secret engine message',
              )
              expect(view.root.textContent).not.toContain('/assets/chunk.js')
              checkErrorInput?.(view, localFailure)
            }
            expect(copy(view).childElementCount).toBe(0)
          })
        },
      )
    }

    it.each(['eager', 'pending'] as const)(
      'S03 clears an existing departure for an %s public entry without a server answer',
      async (kind) => {
        await run(async (kit, { router, answer, pageLoading }) => {
          const pending = lazyDeferred<T>()
          answer()
          const view = await kit.mount({
            router,
            pages: {
              main: kit.known,
              [HilosPages.PUBLIC_STATUS]:
                kind === 'pending' ? lazyPage(() => pending.promise) : kit.main,
            },
          })
          await view.flush(() => router.navigate('/other'))
          expect(copy(view).childElementCount).toBeGreaterThan(0)
          await view.flush(() => router.navigate(statusPath))
          expect(copy(view).childElementCount).toBe(0)
          expect(find(view, 'known-page')).toBeNull()
          if (kind === 'pending') {
            expect(find(view, 'main')).toBeNull()
            expect(marker(view)).toBe('loading')
            await view.flush(() => pending.resolve(kit.main))
          }
          expect(find(view, 'main')).not.toBeNull()
          expect(copy(view).childElementCount).toBe(0)
          await view.flush(() => pageLoading.set(false))
          expect(copy(view).childElementCount).toBe(0)
        })
      },
    )
  })
}
