import { describe, expect, it } from 'vitest'

import {
  createHilosRouter,
  type NavigationEnvironment,
} from '../../src/routing/HilosRouter.js'
import { createPageRouter } from '../../src/routing/PageRouter.js'
import { ScopeManager } from '../../src/state/ScopeManager.js'
import { bindPageMove } from '../../src/subscription/bindPageMove.js'
import { PageSubscription } from '../../src/subscription/PageSubscription.js'

const router = createPageRouter({
  main: { path: '/', admin: false },
  product: { path: '/product/{slug}', admin: false },
  category: { path: '/category/{slug}', admin: false },
  gallery: { path: '/gallery', admin: false },
})

/** The page this application's view layer has not built. */
const UNBUILT = new Set(['gallery'])

/**
 * A real page subscription and navigator over a connection that records its
 * frames and a browser binding that counts pushed and replaced entries apart,
 * standing on `path` with the session answered and the move bound.
 */
function setup(path: string) {
  const frames: Array<Record<string, unknown>> = []
  const scopes = new ScopeManager()
  const pages = new PageSubscription(
    {
      state: 'connected',
      reconnectDragging: false,
      send: (text) => {
        frames.push(JSON.parse(text) as Record<string, unknown>)
        return true
      },
      on: () => () => {},
      forgetPageFrames: () => {},
      tableWindowDescriptors: () => ({}),
    },
    scopes,
  )
  let pathname = path
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
    onPopState: () => () => {},
  }
  const navigator = createHilosRouter(router, pages, env, undefined, UNBUILT)
  let left = 0
  navigator.onLeave(() => {
    left += 1
  })
  const stop = bindPageMove(pages, navigator)
  navigator.start()
  pages.releaseOnSession()
  left = 0

  return {
    pages,
    navigator,
    scopes,
    frames,
    stop,
    pathname: () => pathname,
    pushCount: () => pushed,
    replaceCount: () => replaced,
    leaveCount: () => left,
  }
}

/** The page_moved the server sends once the product leaves its old slug. */
function productMoved(
  targetPage: string,
  targetParams: Record<string, string>,
) {
  return {
    page: 'product',
    params: { slug: 'morning-meadow' },
    targetPage,
    targetParams,
  }
}

describe('bindPageMove', () => {
  it('rewrites the address of an open page in place and subscribes it under the new params', () => {
    const tab = setup('/product/morning-meadow')
    const scope = tab.scopes.page()
    tab.pages.ingestPageResponse('product', {
      data: { name: 'Morning meadow' },
    })

    expect(
      tab.pages.handlePageMoved(
        productMoved('product', { slug: 'summer-meadow' }),
      ),
    ).toBe(true)

    expect(tab.pathname()).toBe('/product/summer-meadow')
    expect(tab.replaceCount()).toBe(1)
    expect(tab.pushCount()).toBe(0)
    expect(tab.navigator.currentPath.get()).toBe('/product/summer-meadow')
    expect(tab.navigator.currentRoute.get()).toEqual({
      page: 'product',
      params: { slug: 'summer-meadow' },
      admin: false,
    })
    expect(tab.frames).toEqual([
      {
        type: 'page_subscribe',
        page: 'product',
        params: { slug: 'morning-meadow' },
      },
      {
        type: 'page_subscribe',
        page: 'product',
        params: { slug: 'summer-meadow' },
      },
    ])
    expect(tab.leaveCount()).toBe(0)
    expect(tab.navigator.pageLoading.get()).toBe(false)
    expect(tab.navigator.pageError.get()).toBeNull()
    expect(tab.scopes.page()).toBe(scope)
  })

  it('keeps an opening at the old address waiting until the new address answers', () => {
    const tab = setup('/product/morning-meadow')
    expect(tab.navigator.pageLoading.get()).toBe(true)

    tab.pages.handlePageMoved(
      productMoved('product', { slug: 'summer-meadow' }),
    )

    expect(tab.pathname()).toBe('/product/summer-meadow')
    expect(tab.pushCount()).toBe(0)
    expect(tab.frames.at(-1)).toEqual({
      type: 'page_subscribe',
      page: 'product',
      params: { slug: 'summer-meadow' },
    })
    expect(tab.navigator.pageLoading.get()).toBe(true)
    tab.pages.ingestPageResponse('product', {
      data: { name: 'Summer meadow' },
    })
    expect(tab.navigator.pageLoading.get()).toBe(false)
  })

  it('leaves for another page the ordinary way, still without a history entry', () => {
    const tab = setup('/product/morning-meadow')
    tab.pages.ingestPageResponse('product', {
      data: { name: 'Morning meadow' },
    })

    tab.pages.handlePageMoved(productMoved('category', { slug: 'meadows' }))

    expect(tab.pathname()).toBe('/category/meadows')
    expect(tab.replaceCount()).toBe(1)
    expect(tab.pushCount()).toBe(0)
    expect(tab.leaveCount()).toBe(1)
    expect(tab.frames).toEqual([
      {
        type: 'page_subscribe',
        page: 'product',
        params: { slug: 'morning-meadow' },
      },
      {
        type: 'page_subscribe',
        page: 'category',
        params: { slug: 'meadows' },
      },
    ])
    expect(tab.pages.pageKey()).toBe('category')
    expect(tab.navigator.pageLoading.get()).toBe(true)
  })

  it.each([
    ['a page with no route', 'poster', { slug: 'summer-meadow' }],
    ['an uncovered slot', 'product', {}],
    ['a page the view layer has not built', 'gallery', {}],
  ])(
    'refuses %s as not served and keeps the address',
    (_case, targetPage, targetParams) => {
      const tab = setup('/product/morning-meadow')

      tab.pages.handlePageMoved(productMoved(targetPage, targetParams))

      expect(tab.pathname()).toBe('/product/morning-meadow')
      expect(tab.replaceCount()).toBe(0)
      expect(tab.pushCount()).toBe(0)
      expect(tab.navigator.currentPath.get()).toBe('/product/morning-meadow')
      expect(tab.navigator.pageError.get()).toMatchObject({
        page: targetPage,
        httpCode: 404,
        errorCode: 'not_served',
      })
      expect(tab.navigator.pageLoading.get()).toBe(false)
      expect(tab.frames.at(-1)).toEqual({
        type: 'page_unsubscribe',
        page: 'product',
      })
    },
  )

  it('stops following once released', () => {
    const tab = setup('/product/morning-meadow')
    tab.stop()

    tab.pages.handlePageMoved(
      productMoved('product', { slug: 'summer-meadow' }),
    )

    expect(tab.pathname()).toBe('/product/morning-meadow')
    expect(tab.frames).toHaveLength(1)
  })
})
