import { describe, expect, it } from 'vitest'

import {
  createHilosRouter,
  type NavigablePages,
  type NavigationEnvironment,
} from '../../src/routing/HilosRouter.js'
import { createPageRouter } from '../../src/routing/PageRouter.js'
import {
  HilosPages,
  HILOS_ROUTE_DECLARATIONS,
} from '../../src/routing/hilosPages.js'
import { hilosUnbuiltPages } from '../../src/routing/hilosUnbuiltPages.js'
import { resolvePageTitle } from '../../src/routing/pageTitle.js'
import { hilosTabLinks } from '../../src/routing/hilosAdmin.js'
import { type PageSubscriptionError } from '../../src/protocol/pageError.js'
import { ROUTE_NOT_FOUND_ERROR } from '../../src/routing/pageError.js'
import {
  type HilosDashboardSection,
  type HilosPageIdentity,
} from '../../src/admin/identity/hilosPageIdentity.js'
import { createSignal } from '../../src/state/signal.js'
import { ScopeManager } from '../../src/state/ScopeManager.js'
import { PageSubscription } from '../../src/subscription/PageSubscription.js'

const router = createPageRouter({
  main: { path: '/', admin: false },
  dash: { path: '/hilos', admin: true },
  user: { path: '/user/{id}', admin: false },
})

// A router carrying a page whose own route params name what it shows — the
// shape replacePath exists for.
const viewerRouter = createPageRouter({
  main: { path: '/', admin: false },
  dash: { path: '/hilos', admin: true },
  viewer: {
    path: '/hilos/logs/view/{nodeId?}/{source?}/{stream?}',
    admin: true,
  },
})

// A fake browser binding: an in-memory pathname plus a single popstate
// listener, so the navigator is exercised with no DOM. Pushes and replaces are
// counted apart, because rewriting the address must not grow the history.
function fakeEnvironment(initial: string) {
  let pathname = initial
  let popListener: (() => void) | null = null
  let pushed = 0
  let replaced = 0

  const env: NavigationEnvironment = {
    pathname: () => pathname,
    pushState: (next) => {
      pathname = next
      pushed += 1
    },
    replaceState: (next) => {
      pathname = next
      replaced += 1
    },
    onPopState: (listener) => {
      popListener = listener

      return () => {
        popListener = null
      }
    },
  }

  return {
    env,
    /** Simulate a back/forward navigation to `next`. */
    pop: (next: string) => {
      pathname = next
      popListener?.()
    },
    isPopAttached: () => popListener !== null,
    pushCount: () => pushed,
    replaceCount: () => replaced,
  }
}

// A fake page subscription that records what it was asked to subscribe and
// carries the writable page-error and page-loading signals the navigator
// proxies.
function fakePages() {
  const calls: Array<{ page: string; params: Record<string, string> }> = []
  const pageError = createSignal<PageSubscriptionError | null>(null)
  const pageLoading = createSignal(false)
  const pageIdentity = createSignal<HilosPageIdentity | undefined>(undefined)
  const dashboardSections = createSignal<HilosDashboardSection[] | undefined>(
    undefined,
  )
  let cleared = 0
  let unsubscribed = 0
  const calledOnPages: string[] = []
  const refused: string[] = []
  const pages: NavigablePages = {
    subscribe: (page, params = {}) => {
      calls.push({ page, params })

      return null
    },
    unsubscribe: () => {
      unsubscribed += 1
    },
    refuseUnbuilt: (page) => {
      refused.push(page)
    },
    pageError,
    pageLoading,
    pageIdentity,
    dashboardSections,
    clearPageError: () => {
      cleared += 1
      pageError.set(null)
    },
    denyCurrentPage: () => calledOnPages.push('deny'),
    awaitPageAnswer: () => calledOnPages.push('await'),
  }

  return {
    pages,
    calls,
    pageError,
    pageLoading,
    pageIdentity,
    dashboardSections,
    clearedCount: () => cleared,
    unsubscribeCount: () => unsubscribed,
    calledOnPages,
    refused,
  }
}

describe('public Status without a server page', () => {
  function setup(path: string, layer: 'vue' | 'react' | 'angular' = 'vue') {
    const frames: unknown[] = []
    const scopes = new ScopeManager()
    const pages = new PageSubscription(
      {
        state: 'connected',
        reconnectDragging: false,
        send: (text) => {
          frames.push(JSON.parse(text))
          return true
        },
        on: () => () => {},
        forgetPageFrames: () => {},
        tableWindowDescriptors: () => ({}),
      },
      scopes,
    )
    const browser = fakeEnvironment(path)
    const navigator = createHilosRouter(
      createPageRouter(HILOS_ROUTE_DECLARATIONS),
      pages,
      browser.env,
      (page) => resolvePageTitle(page, {}, '', undefined, false),
      hilosUnbuiltPages(layer),
    )
    navigator.start()
    return { navigator, pages, scopes, frames, browser }
  }

  it('cold-loads without a handshake, subscription or page scope', () => {
    const { navigator, pages, scopes, frames } = setup('/status')
    expect(navigator.currentRoute.get()).toEqual({
      page: HilosPages.PUBLIC_STATUS,
      params: {},
      admin: false,
    })
    expect(navigator.currentTitle.get()).toBe('Status')
    expect(navigator.pageError.get()).toBeNull()
    expect(navigator.pageLoading.get()).toBe(false)
    expect(scopes.page()).toBeUndefined()
    expect(frames).toEqual([])
    pages.releaseOnSession()
    expect(frames).toEqual([])
    navigator.stop()
  })

  it.each(['pending', 'denied'] as const)(
    'leaves a %s subscription and ignores its late answer and error',
    (previous) => {
      const { navigator, pages, scopes, frames, browser } = setup('/hilos')
      pages.releaseOnSession()
      if (previous === 'denied') pages.denyCurrentPage()
      navigator.navigate('/status')
      expect(frames).toEqual([
        { type: 'page_subscribe', page: HilosPages.DASHBOARD, params: {} },
        { type: 'page_unsubscribe', page: HilosPages.DASHBOARD },
      ])
      expect(scopes.page()).toBeUndefined()
      expect(pages.ingestPageResponse(HilosPages.DASHBOARD, {})).toBe(false)
      expect(
        pages.handleSubscriptionError({
          page: HilosPages.DASHBOARD,
          httpCode: 403,
          errorCode: 'forbidden',
          title: 'Forbidden',
          message: 'Access was removed.',
        }),
      ).toBe(false)
      navigator.clearPageError()
      navigator.denyCurrentPage()
      navigator.awaitPageAnswer()
      expect(navigator.pageError.get()).toBeNull()
      expect(navigator.pageLoading.get()).toBe(false)
      expect(frames).toHaveLength(2)

      browser.pop('/hilos')
      expect(navigator.pageLoading.get()).toBe(true)
      expect(frames[2]).toEqual({
        type: 'page_subscribe',
        page: HilosPages.DASHBOARD,
        params: {},
      })
      pages.ingestPageResponse(HilosPages.DASHBOARD, {})
      expect(navigator.pageLoading.get()).toBe(false)
      navigator.stop()
    },
  )

  it.each(['react', 'angular'] as const)(
    'refuses an unbuilt %s Status before applying the file-reader exception',
    (layer) => {
      const { navigator, pages, frames } = setup('/status', layer)
      expect(navigator.resolvePath(HilosPages.PUBLIC_STATUS)).toBeUndefined()
      expect(navigator.pageError.get()).toMatchObject({
        httpCode: 404,
        errorCode: 'not_served',
      })
      expect(navigator.pageLoading.get()).toBe(false)
      pages.releaseOnSession()
      expect(frames).toEqual([])
      navigator.stop()
    },
  )

  it('keeps other public pages subscribed and restores route-miss behavior', () => {
    const { navigator, pages, frames } = setup('/status')
    navigator.replacePath('/about')
    expect(navigator.pageLoading.get()).toBe(true)
    expect(frames).toEqual([])
    pages.releaseOnSession()
    expect(frames).toEqual([
      { type: 'page_subscribe', page: HilosPages.ABOUT, params: {} },
    ])
    navigator.denyCurrentPage()
    expect(navigator.pageError.get()?.httpCode).toBe(403)
    navigator.navigate('/status/missing')
    expect(navigator.pageError.get()).toBe(ROUTE_NOT_FOUND_ERROR)
    expect(navigator.resolvePath('unknown')).toBeUndefined()
    navigator.replacePath('/status')
    expect(navigator.pageError.get()).toBeNull()
    expect(navigator.pageLoading.get()).toBe(false)
    navigator.stop()
  })

  it('projects only Status independently of old subscription signals and generic hooks', () => {
    const port = fakePages()
    const navigator = createHilosRouter(
      createPageRouter(HILOS_ROUTE_DECLARATIONS),
      port.pages,
      fakeEnvironment('/status').env,
    )
    port.pageLoading.set(true)
    port.pageError.set({
      page: HilosPages.DASHBOARD,
      httpCode: 401,
      errorCode: 'unauthorized',
      title: 'Sign in',
      message: 'Please sign in.',
    })
    navigator.start()
    navigator.clearPageError()
    navigator.denyCurrentPage()
    navigator.awaitPageAnswer()
    expect(navigator.pageError.get()).toBeNull()
    expect(navigator.pageLoading.get()).toBe(false)
    expect(port.clearedCount()).toBe(0)
    expect(port.calledOnPages).toEqual([])
    expect(port.pageError.get()?.httpCode).toBe(401)
    expect(port.pageLoading.get()).toBe(true)
    expect(port.calls).toEqual([])
    expect(port.unsubscribeCount()).toBe(1)
    navigator.stop()
  })
})

describe('unbuilt pages', () => {
  it('refuses direct entry to the unbuilt user tabs in every view layer', () => {
    const userRouter = createPageRouter(HILOS_ROUTE_DECLARATIONS)
    const userTabs = [
      [HilosPages.USER_ROLES, '/hilos/user/7/roles', { userId: '7' }],
      [HilosPages.USER_ACCESS, '/hilos/user/7/access', { userId: '7' }],
      [HilosPages.USER_SESSIONS, '/hilos/user/7/sessions', { userId: '7' }],
    ] as const

    for (const layer of ['vue', 'react', 'angular'] as const) {
      for (const [page, path, params] of userTabs) {
        const { env } = fakeEnvironment(path)
        const { pages, calls, refused } = fakePages()
        const navigator = createHilosRouter(
          userRouter,
          pages,
          env,
          undefined,
          hilosUnbuiltPages(layer),
        )
        navigator.start()

        expect(navigator.currentRoute.get()).toEqual({
          page,
          params,
          admin: true,
        })
        expect(refused).toEqual([page])
        expect(calls).toEqual([])
        expect(navigator.resolvePath(page, params)).toBeUndefined()
        navigator.stop()
      }
    }
  })

  it('serves Circle in Vue and keeps its direct address and links closed in React and Angular', () => {
    for (const layer of ['vue', 'react', 'angular'] as const) {
      const { env } = fakeEnvironment('/hilos/maintenance/circle')
      const { pages, calls, refused } = fakePages()
      const navigator = createHilosRouter(
        createPageRouter(HILOS_ROUTE_DECLARATIONS),
        pages,
        env,
        undefined,
        hilosUnbuiltPages(layer),
      )
      navigator.start()

      expect(navigator.currentRoute.get()).toEqual({
        page: HilosPages.MAINTENANCE_CIRCLE,
        params: {},
        admin: true,
      })
      const tabs = [
        { page: HilosPages.MAINTENANCE, label: 'Overview' },
        { page: HilosPages.MAINTENANCE_CIRCLE, label: 'Circle' },
      ]
      if (layer === 'vue') {
        expect(refused).toEqual([])
        expect(calls).toEqual([
          { page: HilosPages.MAINTENANCE_CIRCLE, params: {} },
        ])
        expect(navigator.resolvePath(HilosPages.MAINTENANCE_CIRCLE)).toBe(
          '/hilos/maintenance/circle',
        )
        expect(hilosTabLinks(tabs, {}, navigator.resolvePath)).toEqual([
          { ...tabs[0], to: '/hilos/maintenance' },
          { ...tabs[1], to: '/hilos/maintenance/circle' },
        ])
      } else {
        expect(refused).toEqual([HilosPages.MAINTENANCE_CIRCLE])
        expect(calls).toEqual([])
        expect(
          navigator.resolvePath(HilosPages.MAINTENANCE_CIRCLE),
        ).toBeUndefined()
        expect(hilosTabLinks(tabs, {}, navigator.resolvePath)).toEqual([
          { ...tabs[0], to: '/hilos/maintenance' },
        ])
      }
      expect(navigator.resolvePath(HilosPages.MAINTENANCE)).toBe(
        '/hilos/maintenance',
      )
      navigator.stop()
    }
  })

  it('serves the eight i18n URLs in every view layer and keeps translation closed', () => {
    const i18nRouter = createPageRouter(HILOS_ROUTE_DECLARATIONS)
    for (const layer of ['vue', 'react', 'angular'] as const) {
      for (const [page, path, params] of [
        [HilosPages.I18N, '/hilos/i18n', {}],
        [HilosPages.I18N_LANGUAGES, '/hilos/i18n/languages', {}],
        [HilosPages.I18N_COUNTRIES, '/hilos/i18n/countries', {}],
        [
          HilosPages.I18N_LANGUAGE,
          '/hilos/i18n/languages/en',
          { languageCode: 'en' },
        ],
        [
          HilosPages.I18N_LANGUAGE_NAMES,
          '/hilos/i18n/languages/en/names',
          { languageCode: 'en' },
        ],
        [
          HilosPages.I18N_LANGUAGE_LOCALES,
          '/hilos/i18n/languages/en/locales',
          { languageCode: 'en' },
        ],
        [
          HilosPages.I18N_COUNTRY,
          '/hilos/i18n/countries/pl',
          { countryCode: 'pl' },
        ],
        [
          HilosPages.I18N_COUNTRY_NAMES,
          '/hilos/i18n/countries/pl/names',
          { countryCode: 'pl' },
        ],
        [HilosPages.I18N_ENTITIES, '/hilos/i18n/entities', {}],
      ] as const) {
        const { env } = fakeEnvironment(path)
        const { pages, calls, refused } = fakePages()
        const navigator = createHilosRouter(
          i18nRouter,
          pages,
          env,
          undefined,
          hilosUnbuiltPages(layer),
        )
        navigator.start()

        expect(navigator.currentRoute.get()).toEqual({
          page,
          params,
          admin: true,
        })
        if (page !== HilosPages.I18N_ENTITIES) {
          expect(refused).toEqual([])
          expect(calls).toEqual([{ page, params }])
          expect(navigator.resolvePath(page, params)).toBe(path)
        } else {
          expect(refused).toEqual([page])
          expect(calls).toEqual([])
          expect(navigator.resolvePath(page, params)).toBeUndefined()
        }
        navigator.stop()
      }
    }
  })

  it('refuses a cold entry and resolves no address for it', () => {
    const { env } = fakeEnvironment('/hilos')
    const { pages, calls, refused } = fakePages()
    const navigator = createHilosRouter(
      router,
      pages,
      env,
      undefined,
      new Set(['dash']),
    )
    navigator.start()

    expect(calls).toEqual([])
    expect(refused).toEqual(['dash'])
    expect(navigator.currentRoute.get().page).toBe('dash')
    expect(navigator.resolvePath('dash')).toBeUndefined()
    expect(navigator.resolvePath('user', { id: '42' })).toBe('/user/42')
  })

  it('refuses a navigation and subscribes again when history returns', () => {
    const { env, pop } = fakeEnvironment('/')
    const { pages, calls, refused } = fakePages()
    const navigator = createHilosRouter(
      router,
      pages,
      env,
      undefined,
      new Set(['dash']),
    )
    navigator.start()
    navigator.navigate('/hilos')

    expect(calls).toEqual([{ page: 'main', params: {} }])
    expect(refused).toEqual(['dash'])
    pop('/')
    expect(calls).toEqual([
      { page: 'main', params: {} },
      { page: 'main', params: {} },
    ])
    pop('/hilos')
    expect(refused).toEqual(['dash', 'dash'])
  })
})

describe('route parameter roundtrip through navigation', () => {
  it.each(['node-1', '42', 'node /?#%', '%2F', 'узел🌍'])(
    'keeps %s raw on cold start, navigate, popstate and replacePath',
    (value) => {
      const { env, pop } = fakeEnvironment(router.path('user', { id: value })!)
      const { pages, calls } = fakePages()
      const navigator = createHilosRouter(router, pages, env)
      navigator.start()
      expect(calls).toEqual([{ page: 'user', params: { id: value } }])
      expect(navigator.currentRoute.get().params).toEqual({ id: value })
      navigator.navigate(navigator.resolvePath('main')!)
      navigator.navigate(navigator.resolvePath('user', { id: value })!)
      expect(calls.at(-1)).toEqual({ page: 'user', params: { id: value } })
      pop('/')
      pop(navigator.resolvePath('user', { id: value })!)
      expect(calls.at(-1)).toEqual({ page: 'user', params: { id: value } })
      const count = calls.length
      navigator.replacePath(navigator.resolvePath('user', { id: '%2F' })!)
      expect(navigator.currentRoute.get().params).toEqual({ id: '%2F' })
      expect(calls).toHaveLength(count)
      navigator.stop()
    },
  )

  it('keeps optional captures raw on the subscription and address replacement', () => {
    const params = {
      nodeId: 'node /?#%',
      source: 'live',
      stream: 'worker /?#%2F.log',
    }
    const { env, pop } = fakeEnvironment(viewerRouter.path('viewer', params)!)
    const { pages, calls } = fakePages()
    const navigator = createHilosRouter(viewerRouter, pages, env)
    navigator.start()
    expect(calls).toEqual([{ page: 'viewer', params }])
    navigator.navigate(
      navigator.resolvePath('viewer', {
        nodeId: params.nodeId,
        source: 'live',
      })!,
    )
    expect(calls.at(-1)?.params).toEqual({
      nodeId: params.nodeId,
      source: 'live',
    })
    pop(navigator.resolvePath('viewer', params)!)
    expect(calls.at(-1)?.params).toEqual(params)
    const count = calls.length
    navigator.replacePath(navigator.resolvePath('viewer', params)!)
    expect(navigator.currentRoute.get().params).toEqual(params)
    expect(calls).toHaveLength(count)
    navigator.stop()
  })

  it('subscribes nothing for a malformed capture', () => {
    const { env } = fakeEnvironment('/user/%ED%A0%80')
    const { pages, calls, unsubscribeCount } = fakePages()
    const navigator = createHilosRouter(router, pages, env)
    navigator.start()
    expect(calls).toEqual([])
    expect(unsubscribeCount()).toBe(1)
    expect(navigator.currentRoute.get()).toEqual({
      page: null,
      params: {},
      admin: false,
    })
    expect(navigator.pageError.get()).toBe(ROUTE_NOT_FOUND_ERROR)
    expect(navigator.pageLoading.get()).toBe(false)
    expect(navigator.resolvePath('user', { id: '\ud800' })).toBeUndefined()
    navigator.stop()
  })

  it('keeps the unbuilt gate closed for encoded optional captures', () => {
    const { env } = fakeEnvironment(
      viewerRouter.path('viewer', { nodeId: 'node /?#%' })!,
    )
    const { pages, calls, refused } = fakePages()
    const navigator = createHilosRouter(
      viewerRouter,
      pages,
      env,
      undefined,
      new Set(['viewer']),
    )
    navigator.start()
    expect(calls).toEqual([])
    expect(refused).toEqual(['viewer'])
    expect(
      navigator.resolvePath('viewer', { nodeId: 'node /?#%' }),
    ).toBeUndefined()
    navigator.stop()
  })
})

describe('createHilosRouter', () => {
  it('announces start, navigation, and history before publishing or subscribing', () => {
    const { env, pop } = fakeEnvironment('/')
    const { pages, calls } = fakePages()
    const navigator = createHilosRouter(router, pages, env)
    const departures: Array<{ page: string | null; subscriptions: number }> = []
    navigator.onLeave(() => {
      departures.push({
        page: navigator.currentRoute.get().page,
        subscriptions: calls.length,
      })
    })

    navigator.start()
    navigator.navigate('/user/42')
    pop('/hilos')

    expect(departures).toEqual([
      { page: 'main', subscriptions: 0 },
      { page: 'main', subscriptions: 1 },
      { page: 'user', subscriptions: 2 },
    ])
    expect(navigator.currentRoute.get().page).toBe('dash')
  })

  it('does not announce address rewrites or access re-evaluation', () => {
    const { env } = fakeEnvironment('/')
    const { pages } = fakePages()
    const navigator = createHilosRouter(router, pages, env)
    navigator.start()
    let departures = 0
    navigator.onLeave(() => {
      departures += 1
    })

    navigator.replacePath('/')
    navigator.awaitPageAnswer()
    navigator.denyCurrentPage()
    navigator.clearPageError()

    expect(departures).toBe(0)
  })

  it('lets a departing listener unsubscribe without skipping its neighbor', () => {
    const { env } = fakeEnvironment('/')
    const { pages } = fakePages()
    const navigator = createHilosRouter(router, pages, env)
    const calls: string[] = []
    const offFirst = navigator.onLeave(() => {
      calls.push('first')
      offFirst()
    })
    const offSecond = navigator.onLeave(() => {
      calls.push('second')
    })

    navigator.start()
    navigator.navigate('/hilos')
    offSecond()
    navigator.navigate('/')

    expect(calls).toEqual(['first', 'second', 'second'])
  })

  it('seeds the current route from the location before start', () => {
    const { env } = fakeEnvironment('/hilos')
    const { pages } = fakePages()
    const navigator = createHilosRouter(router, pages, env)

    expect(navigator.currentRoute.get().page).toBe('dash')
    expect(navigator.currentPath.get()).toBe('/hilos')
  })

  it('derives the current title from the route via the resolver', () => {
    const { env } = fakeEnvironment('/')
    const { pages } = fakePages()
    const titles: Record<string, string> = { main: 'Home', user: 'User' }
    const navigator = createHilosRouter(router, pages, env, (page) =>
      page === null ? '' : (titles[page] ?? ''),
    )
    navigator.start()

    expect(navigator.currentTitle.get()).toBe('Home')

    navigator.navigate('/user/42')

    expect(navigator.currentTitle.get()).toBe('User')
  })

  it('defaults the current title to empty without a resolver', () => {
    const { env } = fakeEnvironment('/')
    const { pages } = fakePages()
    const navigator = createHilosRouter(router, pages, env)

    expect(navigator.currentTitle.get()).toBe('')
  })

  it('names a miss Page Not Found without a resolver', () => {
    const { env } = fakeEnvironment('/nope')
    const { pages } = fakePages()
    const navigator = createHilosRouter(router, pages, env)

    expect(navigator.currentTitle.get()).toBe('Page Not Found')
  })

  it('keeps an auth return a miss and does not title it Page Not Found', () => {
    for (const path of [
      '/auth/magic',
      '/auth/callback',
      '/auth/second-factor/cancel',
    ]) {
      const { env } = fakeEnvironment(path)
      const { pages, calls, unsubscribeCount } = fakePages()
      const navigator = createHilosRouter(router, pages, env)

      expect(navigator.currentRoute.get()).toEqual({
        page: null,
        params: {},
        admin: false,
      })
      expect(navigator.currentTitle.get()).toBe('')
      expect(navigator.pageLoading.get()).toBe(false)
      expect(navigator.pageError.get()).toBe(ROUTE_NOT_FOUND_ERROR)

      navigator.start()

      expect(calls).toEqual([])
      expect(unsubscribeCount()).toBe(1)
      expect(navigator.pageLoading.get()).toBe(false)
    }
  })

  it('subscribes the current page and attaches popstate on start', () => {
    const { env, isPopAttached } = fakeEnvironment('/hilos')
    const { pages, calls } = fakePages()
    const navigator = createHilosRouter(router, pages, env)

    navigator.start()

    expect(calls).toEqual([{ page: 'dash', params: {} }])
    expect(isPopAttached()).toBe(true)
  })

  it('navigates in place: pushes history, swaps route, re-subscribes', () => {
    const { env } = fakeEnvironment('/')
    const { pages, calls } = fakePages()
    const navigator = createHilosRouter(router, pages, env)
    navigator.start()

    navigator.navigate('/user/42')

    expect(env.pathname()).toBe('/user/42')
    expect(navigator.currentPath.get()).toBe('/user/42')
    expect(navigator.currentRoute.get()).toEqual({
      page: 'user',
      params: { id: '42' },
      admin: false,
    })
    expect(calls.at(-1)).toEqual({ page: 'user', params: { id: '42' } })
  })

  it('rewrites the address without re-subscribing the page', () => {
    const { env, replaceCount, pushCount } = fakeEnvironment('/hilos/logs/view')
    const { pages, calls } = fakePages()
    const navigator = createHilosRouter(viewerRouter, pages, env)
    navigator.start()
    const subscribesBefore = calls.length

    navigator.replacePath('/hilos/logs/view/node-2/live/worker-0.log')

    expect(env.pathname()).toBe('/hilos/logs/view/node-2/live/worker-0.log')
    expect(navigator.currentPath.get()).toBe(
      '/hilos/logs/view/node-2/live/worker-0.log',
    )
    expect(navigator.currentRoute.get()).toEqual({
      page: 'viewer',
      params: { nodeId: 'node-2', source: 'live', stream: 'worker-0.log' },
      admin: true,
    })
    // Another file of the same page is not another page: the subscription that
    // delivered the catalog stays, and the history does not grow an entry per
    // select.
    expect(calls.length).toBe(subscribesBefore)
    expect(replaceCount()).toBe(1)
    expect(pushCount()).toBe(0)
  })

  it('keeps navigate pushing history and re-subscribing', () => {
    const { env, replaceCount, pushCount } = fakeEnvironment('/hilos')
    const { pages, calls } = fakePages()
    const navigator = createHilosRouter(viewerRouter, pages, env)
    navigator.start()

    navigator.navigate('/hilos/logs/view')

    expect(calls.at(-1)).toEqual({ page: 'viewer', params: {} })
    expect(pushCount()).toBe(1)
    expect(replaceCount()).toBe(0)
  })

  it('re-applies the route on back/forward navigation', () => {
    const { env, pop } = fakeEnvironment('/')
    const { pages, calls } = fakePages()
    const navigator = createHilosRouter(router, pages, env)
    navigator.start()

    pop('/hilos')

    expect(navigator.currentRoute.get().page).toBe('dash')
    expect(calls.at(-1)).toEqual({ page: 'dash', params: {} })
  })

  it('keeps the delivered route and subscription across fragment-only history, then follows changed route params', () => {
    const { env, pop } = fakeEnvironment('/user/42')
    const { pages, calls } = fakePages()
    const navigator = createHilosRouter(router, pages, env)
    navigator.start()
    const deliveredRoute = navigator.currentRoute.get()
    let departures = 0
    navigator.onLeave(() => departures++)

    // The browser's skip link changes its hash, leaving pathname unchanged.
    pop('/user/42')
    pop('/user/42')

    expect(navigator.currentRoute.get()).toBe(deliveredRoute)
    expect(calls).toEqual([{ page: 'user', params: { id: '42' } }])
    expect(departures).toBe(0)
    expect(navigator.pageLoading.get()).toBe(false)

    pop('/user/43')
    pop('/user/42')

    expect(calls).toEqual([
      { page: 'user', params: { id: '42' } },
      { page: 'user', params: { id: '43' } },
      { page: 'user', params: { id: '42' } },
    ])
    expect(departures).toBe(2)
  })

  it('stops tracking history on stop', () => {
    const { env, pop, isPopAttached } = fakeEnvironment('/')
    const { pages, calls } = fakePages()
    const navigator = createHilosRouter(router, pages, env)
    navigator.start()

    navigator.stop()
    pop('/hilos')

    expect(isPopAttached()).toBe(false)
    expect(navigator.currentRoute.get().page).toBe('main')
    expect(calls).toEqual([{ page: 'main', params: {} }])
  })

  it('proxies the page subscription error from the page subscription', () => {
    const { env } = fakeEnvironment('/')
    const { pages, pageError } = fakePages()
    const navigator = createHilosRouter(router, pages, env)

    expect(navigator.pageError.get()).toBeNull()

    pageError.set({
      page: 'user',
      httpCode: 404,
      errorCode: 'not_found',
      title: 'Page Not Found',
      message: 'Resource #9 not found',
    })

    expect(navigator.pageError.get()?.httpCode).toBe(404)
  })

  it('clears the page error through the page subscription without navigating', () => {
    const { env, isPopAttached } = fakeEnvironment('/')
    const { pages, pageError, calls, clearedCount } = fakePages()
    const navigator = createHilosRouter(router, pages, env)
    navigator.start()
    pageError.set({
      page: 'user',
      httpCode: 401,
      errorCode: 'unauthorized',
      title: 'Unauthorized',
      message: 'Authentication required',
    })
    const subscribesBefore = calls.length

    navigator.clearPageError()

    expect(clearedCount()).toBe(1)
    expect(navigator.pageError.get()).toBeNull()
    // Resume in place: no re-subscribe, and history tracking is untouched.
    expect(calls.length).toBe(subscribesBefore)
    expect(isPopAttached()).toBe(true)
  })

  it('resolves a page key to its path through the route map it navigates over', () => {
    const { env } = fakeEnvironment('/')
    const { pages } = fakePages()
    const navigator = createHilosRouter(router, pages, env)

    expect(navigator.resolvePath('dash')).toBe('/hilos')
    expect(navigator.resolvePath('user', { id: '7' })).toBe('/user/7')
  })

  it('resolves nothing for a page the route map does not carry', () => {
    // The shell draws a crumb with no target as plain text and leaves a card
    // out entirely; silently answering some other page's address would send the
    // visitor somewhere they did not ask for.
    const { env } = fakeEnvironment('/')
    const { pages } = fakePages()
    const navigator = createHilosRouter(router, pages, env)

    expect(navigator.resolvePath('nowhere')).toBeUndefined()
    expect(navigator.resolvePath('user')).toBeUndefined()
  })

  it('re-exports the page identity and the dashboard sections as they arrive', () => {
    const { env } = fakeEnvironment('/')
    const { pages, pageIdentity, dashboardSections } = fakePages()
    const navigator = createHilosRouter(router, pages, env)

    expect(navigator.pageIdentity.get()).toBeUndefined()
    expect(navigator.dashboardSections.get()).toBeUndefined()

    pageIdentity.set({
      label: 'Logs',
      lead: 'Rotation stats.',
      breadcrumb: [{ page: 'dash', label: 'Hilos' }],
      children: [],
    })
    dashboardSections.set([{ title: 'Access', description: '', items: [] }])

    expect(navigator.pageIdentity.get()?.label).toBe('Logs')
    expect(navigator.dashboardSections.get()?.[0].title).toBe('Access')
  })

  it('passes the access re-decision controls to the page subscription', () => {
    const { env } = fakeEnvironment('/')
    const { pages, calls, calledOnPages } = fakePages()
    const navigator = createHilosRouter(router, pages, env)
    navigator.start()
    const subscribesBefore = calls.length

    navigator.denyCurrentPage()
    navigator.awaitPageAnswer()

    expect(calledOnPages).toEqual(['deny', 'await'])
    // Neither is a navigation: the subscription is not re-sent.
    expect(calls.length).toBe(subscribesBefore)
  })

  it('shows a route miss before start and never subscribes it', () => {
    const { env } = fakeEnvironment('/nope')
    const { pages, calls, unsubscribeCount, pageLoading } = fakePages()
    const navigator = createHilosRouter(router, pages, env)

    expect(navigator.currentRoute.get()).toEqual({
      page: null,
      params: {},
      admin: false,
    })
    expect(navigator.pageError.get()).toEqual(ROUTE_NOT_FOUND_ERROR)
    expect(navigator.pageLoading.get()).toBe(false)
    expect(calls).toEqual([])

    pageLoading.set(true)
    navigator.start()

    expect(calls).toEqual([])
    expect(unsubscribeCount()).toBe(1)
    expect(navigator.pageLoading.get()).toBe(false)
    expect(navigator.pageError.get()).toBe(ROUTE_NOT_FOUND_ERROR)
  })

  it('navigates from a known page through a miss and back home', () => {
    const { env, pop, pushCount } = fakeEnvironment('/')
    const { pages, calls, unsubscribeCount } = fakePages()
    const navigator = createHilosRouter(router, pages, env)
    navigator.start()

    navigator.navigate('/nope')

    expect(navigator.currentRoute.get().page).toBeNull()
    expect(calls).toEqual([{ page: 'main', params: {} }])
    expect(unsubscribeCount()).toBe(1)
    expect(navigator.pageError.get()).toEqual(ROUTE_NOT_FOUND_ERROR)
    expect(navigator.pageLoading.get()).toBe(false)

    navigator.navigate('/')

    expect(navigator.currentRoute.get()).toEqual({
      page: 'main',
      params: {},
      admin: false,
    })
    expect(calls).toEqual([
      { page: 'main', params: {} },
      { page: 'main', params: {} },
    ])
    expect(navigator.pageError.get()).toBeNull()
    expect(pushCount()).toBe(2)

    pop('/nope')
    expect(navigator.currentRoute.get().page).toBeNull()
    expect(calls).toHaveLength(2)
    expect(unsubscribeCount()).toBe(2)
    expect(navigator.pageError.get()?.errorCode).toBe('route_not_found')
  })

  it('keeps the route miss when clear, deny and await run', () => {
    const { env } = fakeEnvironment('/nope')
    const { pages, pageError, pageLoading, clearedCount, calledOnPages } =
      fakePages()
    pageError.set({
      page: 'main',
      httpCode: 403,
      errorCode: 'forbidden',
      title: 'Forbidden',
      message: 'No',
    })
    pageLoading.set(true)
    const navigator = createHilosRouter(router, pages, env)
    navigator.start()

    navigator.clearPageError()
    navigator.denyCurrentPage()
    navigator.awaitPageAnswer()

    expect(navigator.pageError.get()).toBe(ROUTE_NOT_FOUND_ERROR)
    expect(navigator.pageLoading.get()).toBe(false)
    expect(clearedCount()).toBe(0)
    expect(calledOnPages).toEqual([])
    expect(pageError.get()?.errorCode).toBe('forbidden')
  })

  it('applies replacePath across pages and misses without pushing history', () => {
    const { env, pushCount, replaceCount } = fakeEnvironment('/')
    const { pages, calls, unsubscribeCount } = fakePages()
    const navigator = createHilosRouter(router, pages, env)
    navigator.start()
    let departures = 0
    navigator.onLeave(() => {
      departures += 1
    })
    const afterStart = calls.length

    navigator.replacePath('/user/7')

    expect(departures).toBe(1)
    expect(calls.at(-1)).toEqual({ page: 'user', params: { id: '7' } })
    expect(pushCount()).toBe(0)
    expect(replaceCount()).toBe(1)

    const subscribes = calls.length
    navigator.replacePath('/user/8')

    expect(departures).toBe(1)
    expect(calls).toHaveLength(subscribes)
    expect(navigator.currentRoute.get().params).toEqual({ id: '8' })
    expect(pushCount()).toBe(0)

    navigator.replacePath('/nope')

    expect(departures).toBe(2)
    expect(navigator.currentRoute.get().page).toBeNull()
    expect(unsubscribeCount()).toBe(1)
    expect(calls).toHaveLength(subscribes)
    expect(navigator.pageError.get()).toBe(ROUTE_NOT_FOUND_ERROR)
    expect(pushCount()).toBe(0)

    navigator.replacePath('/also-missing')

    expect(departures).toBe(2)
    expect(unsubscribeCount()).toBe(1)
    expect(navigator.currentPath.get()).toBe('/also-missing')

    navigator.replacePath('/')

    expect(departures).toBe(3)
    expect(calls.at(-1)).toEqual({ page: 'main', params: {} })
    expect(navigator.pageError.get()).toBeNull()
    expect(pushCount()).toBe(0)
    expect(replaceCount()).toBe(5)
    expect(calls.length).toBe(afterStart + 2)
  })
})
