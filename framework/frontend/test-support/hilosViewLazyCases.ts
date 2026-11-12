// One text of lazy code, server-answer and outlet-lifetime behavior for all SDKs.
import {
  createAuthGate,
  createSignal,
  DEFAULT_SKELETON_DELAY_MS,
  HilosPages,
  lazyPage,
  type LazyPageView,
  type PageError,
} from '@hilos/core/base'
import type * as Vitest from 'vitest'

import { outletRouter } from './hilosViewFixtures.js'
import {
  LAZY_NOT_FOUND,
  lazyDeferred,
  lazyRouter,
  type HilosViewLazyKit,
  type LazyOutlet,
  type WithHilosViewLazy,
} from './hilosViewLazyFixtures.js'

type LazyRunner = Pick<typeof Vitest, 'describe' | 'it' | 'expect' | 'vi'>

/** Register lazy semantics without asking an adapter to decide any outcome. */
export function registerHilosViewLazyCases<T>(
  sdk: string,
  { describe, it, expect, vi }: LazyRunner,
  withKit: WithHilosViewLazy<T>,
  checkErrorInput?: (view: LazyOutlet<T>, expected: PageError) => void,
): void {
  const find = (root: ParentNode, id: string) =>
    root.querySelector<HTMLElement>(`[data-id="${id}"]`)
  const marker = (view: LazyOutlet<T>) =>
    find(view.root, 'hilos-page-state')?.getAttribute('data-state')
  const skeleton = (view: LazyOutlet<T>) =>
    find(view.root, 'hilos-page-skeleton')
  const departure = (view: LazyOutlet<T>) =>
    find(view.root, 'hilos-page-departure')!
  const statuses = (view: LazyOutlet<T>) =>
    [...view.root.querySelectorAll('[role="status"]')].map((node) =>
      node.textContent?.trim(),
    )
  const outlast = (view: LazyOutlet<T>, ms: number) =>
    view.flush(() => vi.advanceTimersByTime(ms))
  const navigation = (
    initial = '/',
    unbuilt: ReadonlySet<string> = new Set(),
  ) => lazyRouter(vi.fn(), initial, unbuilt)
  const run = async (
    steps: (
      kit: HilosViewLazyKit<T>,
      navigate: typeof navigation,
    ) => Promise<void>,
  ) => {
    vi.useFakeTimers()
    const routers: ReturnType<typeof navigation>[] = []
    try {
      await withKit(async (kit) =>
        steps(kit, (initial, unbuilt) => {
          const control = navigation(initial, unbuilt)
          routers.push(control)
          return control
        }),
      )
    } finally {
      for (const { router } of routers) router.stop()
      vi.restoreAllMocks()
      vi.useRealTimers()
    }
  }
  // The local failure is the subject: keep the full expected shape explicit.
  const localFailure: PageError = {
    httpCode: 503,
    errorCode: 'page_load_failed',
    title: 'Page could not be loaded',
    message: 'Please try opening this page again.',
  }
  const errorSurface = (view: LazyOutlet<T>, expected: PageError) => {
    const surface = find(view.root, 'page-error')
    expect(surface).not.toBeNull()
    expect(surface?.getAttribute('data-error-code')).toBe(
      String(expected.httpCode),
    )
    expect(surface?.querySelector('h1')?.textContent?.trim()).toBe(
      String(expected.httpCode),
    )
    expect(
      [...surface!.querySelectorAll('p')].map((node) =>
        node.textContent?.trim(),
      ),
    ).toEqual([expected.title, expected.message])
    checkErrorInput?.(view, expected)
  }

  describe(`HilosView lazy pages on ${sdk}`, () => {
    it('L01 keeps the copy and original deadline after a fast server answer', async () => {
      await run(async (kit, navigate) => {
        const pending = lazyDeferred<T>()
        const page = kit.tracked('lazy-page')
        const loader = vi.fn(() => pending.promise)
        const { router, answer, pageLoading, subscribe } = navigate()
        answer()
        const view = await kit.mount({
          router,
          pages: { main: kit.main, lazy: lazyPage(loader) },
          pageSkeletons: { lazy: kit.skeleton },
        })
        const copy = departure(view)
        const frame = copy.parentElement!
        frame.scrollTop = 90
        expect(loader).not.toHaveBeenCalled()
        await view.flush(() => router.navigate('/lazy'))
        await outlast(view, 100)
        await view.flush(answer)
        expect(pageLoading.get()).toBe(false)
        expect(copy.textContent).toBe('Leaving page')
        expect(copy.getAttribute('aria-hidden')).toBe('true')
        expect(copy.hasAttribute('inert')).toBe(true)
        expect(copy.querySelector('[data-id]')).toBeNull()
        expect(page.mounted).not.toHaveBeenCalled()
        expect(marker(view)).toBe('loading')
        await outlast(view, DEFAULT_SKELETON_DELAY_MS - 101)
        expect(copy.textContent).toBe('Leaving page')
        expect(skeleton(view)).toBeNull()
        await outlast(view, 1)
        expect(copy.childElementCount).toBe(0)
        expect(frame.scrollTop).toBe(0)
        expect(find(view.root, 'main-skeleton')).not.toBeNull()
        expect(statuses(view)).toEqual(['', 'Loading…'])
        await view.flush(() => pending.resolve(page.component))
        expect(page.mounted).toHaveBeenCalledTimes(1)
        expect(loader).toHaveBeenCalledTimes(1)
        expect(find(view.root, 'lazy-page')).not.toBeNull()
        expect(skeleton(view)).toBeNull()
        expect(marker(view)).toBe('ready')
        expect(subscribe).toHaveBeenCalledTimes(2)
      })
    })

    it('L02 waits for the server after fast code without restarting the deadline', async () => {
      await run(async (kit, navigate) => {
        const pending = lazyDeferred<T>()
        const page = kit.tracked('lazy-page')
        const { router, answer } = navigate('/lazy')
        const view = await kit.mount({
          router,
          pages: { lazy: lazyPage(() => pending.promise) },
        })
        await outlast(view, 100)
        await view.flush(() => pending.resolve(page.component))
        expect(page.mounted).not.toHaveBeenCalled()
        expect(marker(view)).toBe('loading')
        await outlast(view, DEFAULT_SKELETON_DELAY_MS - 101)
        expect(skeleton(view)).toBeNull()
        await outlast(view, 1)
        expect(skeleton(view)).not.toBeNull()
        expect(page.mounted).not.toHaveBeenCalled()
        await view.flush(answer)
        expect(page.mounted).toHaveBeenCalledTimes(1)
        expect(marker(view)).toBe('ready')
        expect(statuses(view)).toEqual([''])
      })
    })

    it.each(['resolve', 'reject'] as const)(
      'L03 preserves one deadline and ignores the first import %s',
      async (outcome) => {
        await run(async (kit, navigate) => {
          const first = lazyDeferred<T>()
          const second = lazyDeferred<T>()
          const stale = kit.tracked('stale-page')
          const current = kit.tracked('current-page')
          const { router, answer } = navigate()
          answer()
          const view = await kit.mount({
            router,
            pages: {
              main: kit.main,
              lazy: lazyPage(() => first.promise),
              other: lazyPage(() => second.promise),
            },
          })
          const copy = departure(view)
          await view.flush(() => router.navigate('/lazy'))
          await outlast(view, 200)
          const copied = copy.firstElementChild
          await view.flush(() => {
            router.navigate('/other')
            answer()
          })
          expect(copy.firstElementChild).toBe(copied)
          expect(copy.textContent).toBe('Leaving page')
          await outlast(view, DEFAULT_SKELETON_DELAY_MS - 201)
          expect(skeleton(view)).toBeNull()
          await outlast(view, 1)
          expect(skeleton(view)).not.toBeNull()
          expect(copy.childElementCount).toBe(0)
          await view.flush(() => {
            if (outcome === 'resolve') first.resolve(stale.component)
            else first.reject(new Error('Old chunk failed'))
          })
          expect(stale.mounted).not.toHaveBeenCalled()
          expect(current.mounted).not.toHaveBeenCalled()
          expect(find(view.root, 'page-error')).toBeNull()
          expect(marker(view)).toBe('loading')
          await view.flush(() => second.resolve(current.component))
          expect(current.mounted).toHaveBeenCalledTimes(1)
          expect(marker(view)).toBe('ready')
        })
      },
    )

    it('L04 removes the copy immediately when both halves finish before the deadline', async () => {
      await run(async (kit, navigate) => {
        const pending = lazyDeferred<T>()
        const page = kit.tracked('lazy-page')
        const { router, answer } = navigate()
        answer()
        const view = await kit.mount({
          router,
          pages: { main: kit.main, lazy: lazyPage(() => pending.promise) },
        })
        await view.flush(() => router.navigate('/lazy'))
        await outlast(view, 100)
        await view.flush(() => {
          answer()
          pending.resolve(page.component)
        })
        expect(departure(view).childElementCount).toBe(0)
        expect(page.mounted).toHaveBeenCalledTimes(1)
        await outlast(view, DEFAULT_SKELETON_DELAY_MS)
        expect(skeleton(view)).toBeNull()
        expect(statuses(view)).toEqual([''])
      })
    })

    it('L05 shows a safe error, navigates home and retries only on revisit', async () => {
      await run(async (kit, navigate) => {
        const first = lazyDeferred<T>()
        const retry = lazyDeferred<T>()
        const page = kit.tracked('lazy-page')
        const loader = vi
          .fn<() => Promise<T>>()
          .mockReturnValueOnce(first.promise)
          .mockReturnValueOnce(retry.promise)
        const { router, answer, subscribe } = navigate()
        answer()
        const view = await kit.mount({
          router,
          pages: { main: kit.main, lazy: lazyPage(loader) },
        })
        await view.flush(() => router.navigate('/lazy'))
        await view.flush(() =>
          first.reject(new Error('secret engine message /assets/chunk.js')),
        )
        errorSurface(view, localFailure)
        expect(view.root.textContent).not.toContain('secret engine message')
        expect(view.root.textContent).not.toContain('/assets/chunk.js')
        expect(marker(view)).toBe('error')
        expect(departure(view).childElementCount).toBe(0)
        await outlast(view, DEFAULT_SKELETON_DELAY_MS)
        expect(loader).toHaveBeenCalledTimes(1)
        expect(skeleton(view)).toBeNull()
        await view.flush(() => find(view.root, 'page-error-home')!.click())
        expect(router.currentPath.get()).toBe('/')
        expect(subscribe).toHaveBeenLastCalledWith('main', {})
        await view.flush(answer)
        expect(find(view.root, 'main')).not.toBeNull()
        await view.flush(() => router.navigate('/lazy'))
        expect(loader).toHaveBeenCalledTimes(2)
        await view.flush(() => {
          answer()
          retry.resolve(page.component)
        })
        expect(page.mounted).toHaveBeenCalledTimes(1)
        expect(find(view.root, 'page-error')).toBeNull()
      })
    })

    it.each([
      { httpCode: 401, auth: true },
      { httpCode: 401, auth: false },
      { httpCode: 403, auth: true },
      { httpCode: 404, auth: true },
    ])(
      'L06 server refusal $httpCode with auth=$auth wins over a late rejection',
      async ({ httpCode, auth }) => {
        await run(async (kit, navigate) => {
          const pending = lazyDeferred<T>()
          const page = kit.tracked('lazy-page')
          const surface = kit.tracked('auth-surface')
          const { router, answer, pageError } = navigate()
          answer()
          const view = await kit.mount({
            router,
            pages: { main: kit.main, lazy: lazyPage(() => pending.promise) },
            authSurface: auth ? surface.component : undefined,
          })
          await view.flush(() => router.navigate('/lazy'))
          const refusal = {
            ...LAZY_NOT_FOUND,
            httpCode,
            title: 'Server title',
            message: 'Server refusal words',
          }
          await view.flush(() => pageError.set(refusal))
          expect(departure(view).childElementCount).toBe(0)
          expect(marker(view)).toBe('error')
          expect(find(view.root, 'auth-surface') !== null).toBe(
            httpCode === 401 && auth,
          )
          if (httpCode !== 401 || !auth) errorSurface(view, refusal)
          await view.flush(() => pending.reject(new Error('Chunk failed')))
          await outlast(view, DEFAULT_SKELETON_DELAY_MS)
          expect(page.mounted).not.toHaveBeenCalled()
          expect(skeleton(view)).toBeNull()
          expect(find(view.root, 'auth-surface') !== null).toBe(
            httpCode === 401 && auth,
          )
          if (httpCode !== 401 || !auth) errorSurface(view, refusal)
        })
      },
    )

    it('L07 keeps resolved code gated until a new answer after authentication', async () => {
      await run(async (kit, navigate) => {
        const pending = lazyDeferred<T>()
        const page = kit.tracked('lazy-page')
        const surface = kit.tracked('auth-surface')
        const loader = vi.fn(() => pending.promise)
        const { router, pageError, pageLoading, answer } = navigate('/lazy')
        const currentUserId = createSignal<number | null>(null)
        const gate = createAuthGate({ router, currentUserId })
        const view = await kit.mount({
          router,
          pages: { lazy: lazyPage(loader) },
          authSurface: surface.component,
          authGate: gate,
        })
        expect(surface.mounted).not.toHaveBeenCalled()
        await view.flush(() => {
          pageError.set({ ...LAZY_NOT_FOUND, httpCode: 401 })
          pageLoading.set(false)
          gate.requireAuth()
        })
        await view.flush(() => pending.resolve(page.component))
        expect(
          view.root.querySelectorAll('[data-id="auth-surface"]'),
        ).toHaveLength(1)
        expect(find(view.root, 'page-error')).toBeNull()
        expect(page.mounted).not.toHaveBeenCalled()
        expect(document.querySelector('[role="dialog"]')).toBeNull()
        await view.flush(() => {
          router.awaitPageAnswer()
          currentUserId.set(42)
        })
        expect(page.mounted).not.toHaveBeenCalled()
        await view.flush(answer)
        expect(page.mounted).toHaveBeenCalledTimes(1)
        expect(loader).toHaveBeenCalledTimes(1)
        expect(find(view.root, 'auth-surface')).toBeNull()
        expect(gate.modalOpen.get()).toBe(false)
      })
    })

    it('L08 mounts action auth only in an open modal and mounts it anew on reopen', async () => {
      await run(async (kit, navigate) => {
        const surface = kit.tracked('auth-surface')
        const { router, answer } = navigate()
        answer()
        const gate = createAuthGate({
          router,
          currentUserId: createSignal<number | null>(null),
        })
        const view = await kit.mount({
          router,
          pages: { main: kit.main },
          authSurface: surface.component,
          authGate: gate,
        })
        expect(surface.mounted).not.toHaveBeenCalled()
        expect(document.querySelector('[data-id="auth-surface"]')).toBeNull()
        await view.flush(() => gate.requireAuth())
        expect(surface.mounted).toHaveBeenCalledTimes(1)
        expect(
          document.querySelector('[role="dialog"] [data-id="auth-surface"]'),
        ).not.toBeNull()
        expect(find(view.root, 'main')).not.toBeNull()
        await view.flush(() => gate.dismiss())
        expect(document.querySelector('[data-id="auth-surface"]')).toBeNull()
        await view.flush(() => gate.requireAuth())
        expect(surface.mounted).toHaveBeenCalledTimes(2)
      })
    })

    it('L09 lets a later server refusal replace the local import error', async () => {
      await run(async (kit, navigate) => {
        const pending = lazyDeferred<T>()
        const { router, pageError } = navigate('/lazy')
        const view = await kit.mount({
          router,
          pages: { lazy: lazyPage(() => pending.promise) },
        })
        await view.flush(() => pending.reject(new Error('Chunk failed')))
        errorSurface(view, localFailure)
        const refusal = { ...LAZY_NOT_FOUND, httpCode: 403 }
        await view.flush(() => pageError.set(refusal))
        errorSurface(view, refusal)
      })
    })

    it('L10 replaces the active descriptor without resetting its deadline', async () => {
      await run(async (kit, navigate) => {
        const old = lazyDeferred<T>()
        const replacement = lazyDeferred<T>()
        const stale = kit.tracked('stale-page')
        const current = kit.tracked('current-page')
        const firstLoader = vi.fn(() => old.promise)
        const nextLoader = vi.fn(() => replacement.promise)
        const { router, answer } = navigate('/lazy')
        const view = await kit.mount({
          router,
          pages: { lazy: lazyPage(firstLoader) },
        })
        await view.flush(answer)
        await outlast(view, 100)
        await view.setPages({ lazy: lazyPage(nextLoader) })
        await view.flush(() => old.resolve(stale.component))
        expect(stale.mounted).not.toHaveBeenCalled()
        expect(firstLoader).toHaveBeenCalledTimes(1)
        expect(nextLoader).toHaveBeenCalledTimes(1)
        await outlast(view, DEFAULT_SKELETON_DELAY_MS - 100)
        expect(skeleton(view)).not.toBeNull()
        await view.flush(() => replacement.resolve(current.component))
        expect(current.mounted).toHaveBeenCalledTimes(1)
        await view.setPages({ lazy: kit.known })
        expect(find(view.root, 'known-page')).not.toBeNull()
        await view.setPages({})
        expect(find(view.root, 'known-page')).toBeNull()
        expect(marker(view)).toBe('ready')
      })
    })

    it('L11 shares in-flight code across maps but gives a new outlet its own cache', async () => {
      await run(async (kit, navigate) => {
        const pending = lazyDeferred<T>()
        const page = kit.tracked('lazy-page')
        const loader = vi.fn(() => pending.promise)
        const descriptor = lazyPage(loader)
        const { router, answer } = navigate('/lazy')
        const view = await kit.mount({ router, pages: { lazy: descriptor } })
        await view.setPages({ lazy: descriptor, main: kit.main })
        expect(loader).toHaveBeenCalledTimes(1)
        await view.flush(() => {
          pending.resolve(page.component)
          answer()
        })
        expect(page.mounted).toHaveBeenCalledTimes(1)
        await view.unmount()
        const next = await kit.mount({ router, pages: { lazy: descriptor } })
        expect(loader).toHaveBeenCalledTimes(2)
        await next.flush()
        expect(page.mounted).toHaveBeenCalledTimes(2)
      })
    })

    it.each(['resolve', 'reject'] as const)(
      'L12 keeps the eager home after a departed import %s',
      async (outcome) => {
        await run(async (kit, navigate) => {
          const pending = lazyDeferred<T>()
          const stale = kit.tracked('stale-page')
          const { router, answer } = navigate('/lazy')
          const view = await kit.mount({
            router,
            pages: { lazy: lazyPage(() => pending.promise), main: kit.main },
          })
          await view.flush(() => {
            router.navigate('/')
            answer()
          })
          expect(find(view.root, 'main')).not.toBeNull()
          await view.flush(() => {
            if (outcome === 'resolve') pending.resolve(stale.component)
            else pending.reject(new Error('Departed chunk failed'))
          })
          expect(stale.mounted).not.toHaveBeenCalled()
          expect(find(view.root, 'main')).not.toBeNull()
          expect(find(view.root, 'page-error')).toBeNull()
          expect(skeleton(view)).toBeNull()
          expect(marker(view)).toBe('ready')
        })
      },
    )

    it('L13 reuses code across maps and params while waiting for each new answer', async () => {
      await run(async (kit, navigate) => {
        const pending = lazyDeferred<T>()
        const page = kit.tracked('lazy-page')
        const loader = vi.fn(() => pending.promise)
        const descriptor = lazyPage(loader)
        const { router, answer } = navigate('/lazy/1')
        const view = await kit.mount({ router, pages: { lazy: descriptor } })
        await view.flush(() => {
          pending.resolve(page.component)
          answer()
        })
        await view.setPages({ lazy: descriptor, main: kit.main })
        expect(page.mounted).toHaveBeenCalledTimes(1)
        await view.flush(() => router.navigate('/lazy/2'))
        expect(
          view.root.querySelector('.hilos-page-slot [data-id="lazy-page"]'),
        ).toBeNull()
        expect(marker(view)).toBe('loading')
        await view.flush(answer)
        expect(page.mounted).toHaveBeenCalledTimes(2)
        await view.flush(() => {
          router.navigate('/')
          answer()
        })
        await view.flush(() => {
          router.navigate('/lazy/3')
          answer()
        })
        expect(page.mounted).toHaveBeenCalledTimes(3)
        expect(loader).toHaveBeenCalledTimes(1)
      })
    })

    it.each(['resolve', 'reject'] as const)(
      'L14 releases copy, bindings and timers before a late %s',
      async (outcome) => {
        await run(async (kit, navigate) => {
          const pending = lazyDeferred<T>()
          const page = kit.tracked('lazy-page')
          const otherLoader = vi.fn(() => Promise.resolve(kit.known))
          const { router, answer } = navigate()
          answer()
          const view = await kit.mount({
            router,
            pages: {
              main: kit.main,
              lazy: lazyPage(() => pending.promise),
              other: lazyPage(otherLoader),
            },
          })
          await view.flush(() => router.navigate('/lazy'))
          const copy = departure(view)
          expect(copy.childElementCount).toBe(2)
          expect(
            copy.children[0]
              ?.querySelector('[role="status"]')
              ?.textContent?.trim(),
          ).toBe('')
          expect(copy.children[0]?.getAttribute('tabindex')).toBe('-1')
          expect(copy.children[1]?.textContent?.trim()).toBe('Leaving page')
          await view.unmount()
          expect(copy.childElementCount).toBe(0)
          expect(vi.getTimerCount()).toBe(0)
          await view.flush(() => {
            if (outcome === 'resolve') pending.resolve(page.component)
            else pending.reject(new Error('Disposed chunk failed'))
            router.navigate('/other')
            answer()
          })
          expect(otherLoader).not.toHaveBeenCalled()
          expect(page.mounted).not.toHaveBeenCalled()
          expect(copy.childElementCount).toBe(0)
          expect(vi.getTimerCount()).toBe(0)
        })
      },
    )

    it.each(['unbuilt', 'unknown', 'refused'] as const)(
      'L15 does not start code for a known %s page',
      async (kind) => {
        await run(async (kit, navigate) => {
          const loader = vi.fn(() => Promise.resolve(kit.known))
          const { router, pageError } = navigate(
            kind === 'unknown' ? '/unknown' : '/lazy',
            new Set(kind === 'unbuilt' ? ['lazy'] : []),
          )
          if (kind === 'refused') pageError.set(LAZY_NOT_FOUND)
          const view = await kit.mount({
            router,
            pages: { lazy: lazyPage(loader) },
          })
          await view.flush()
          expect(loader).not.toHaveBeenCalled()
          expect(find(view.root, 'page-error')).not.toBeNull()
          expect(skeleton(view)).toBeNull()
        })
      },
    )

    it('L16 mounts the project override without loading the real default admin descriptor', async () => {
      await run(async (kit) => {
        const defaults = kit.adminViews()
        const descriptor = defaults[HilosPages.DASHBOARD] as LazyPageView<T>
        const load = vi.spyOn(descriptor, 'load')
        const { router } = outletRouter(HilosPages.DASHBOARD, false)
        const view = await kit.mount({
          router,
          pages: { ...defaults, [HilosPages.DASHBOARD]: kit.known },
        })
        expect(find(view.root, 'known-page')).not.toBeNull()
        expect(load).not.toHaveBeenCalled()
      })
    })
  })
}
