// The React peer of vue/src/admin/analytics/HilosAnalyticsPage.test.ts: the
// overview's empty state, its way to the users list, and the shell around it.
import { afterEach, describe, expect, it } from 'vitest'
import { cleanup, render } from '@testing-library/react'
import {
  ANALYTICS_OVERVIEW_EMPTY_TEXT,
  ANALYTICS_OVERVIEW_OPEN_USERS_LABEL,
  HilosPages,
  createSignal,
  type HilosPageIdentity,
  type HilosRouter,
  type PageRouteMatch,
} from '@hilos/core'

import { HilosAnalyticsPage } from '../src/admin/analytics/HilosAnalyticsPage.js'
import { HilosRouterContext } from '../src/hilosRouterContext.js'

const IDENTITY: HilosPageIdentity = {
  label: 'Analytics',
  lead: '',
  breadcrumb: [],
  children: [],
}

function router(
  usersPath: string | undefined,
  identity: HilosPageIdentity | undefined,
): HilosRouter {
  return {
    currentRoute: createSignal<PageRouteMatch>({
      page: HilosPages.ANALYTICS,
      params: {},
      admin: true,
    }),
    currentPath: createSignal('/hilos/analytics'),
    currentTitle: createSignal(''),
    pageError: createSignal(null),
    pageLoading: createSignal(false),
    pageIdentity: createSignal(identity),
    dashboardSections: createSignal(undefined),
    resolvePath: (page) => (page === HilosPages.USERS ? usersPath : undefined),
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

function renderPage(
  usersPath: string | undefined,
  identity: HilosPageIdentity | undefined,
): HTMLElement {
  return render(
    <HilosRouterContext.Provider value={router(usersPath, identity)}>
      <HilosAnalyticsPage />
    </HilosRouterContext.Provider>,
  ).container
}

describe('HilosAnalyticsPage', () => {
  afterEach(() => cleanup())

  it('draws the shared empty-state text instead of the shell stub', () => {
    const view = renderPage('/people', IDENTITY)

    expect(
      view.querySelector('[data-id="hilos-analytics-overview-empty"] p')
        ?.textContent,
    ).toBe(ANALYTICS_OVERVIEW_EMPTY_TEXT)
    expect(view.querySelector('[data-id="hilos-admin-empty"]')).toBeNull()
    expect(view.querySelector('i.bi-inbox')?.getAttribute('aria-hidden')).toBe(
      'true',
    )
  })

  it('links to the users path resolved by the router', () => {
    const view = renderPage('/people', IDENTITY)
    const link = view.querySelector(
      '[data-id="hilos-analytics-overview-open-users"]',
    )

    expect(link?.textContent).toBe(ANALYTICS_OVERVIEW_OPEN_USERS_LABEL)
    expect(link?.getAttribute('href')).toBe('/people')
  })

  it('keeps the text without a link when the users path is unavailable', () => {
    const view = renderPage(undefined, IDENTITY)

    expect(
      view.querySelector('[data-id="hilos-analytics-overview-empty"]')
        ?.textContent,
    ).toBe(ANALYTICS_OVERVIEW_EMPTY_TEXT)
    expect(
      view.querySelector('[data-id="hilos-analytics-overview-open-users"]'),
    ).toBeNull()
  })

  it('draws the empty state while the shell waits for the page identity', () => {
    const view = renderPage('/people', undefined)

    expect(
      view.querySelector('[data-id="hilos-admin-title-skeleton"]'),
    ).not.toBeNull()
    expect(
      view.querySelector('[data-id="hilos-analytics-overview-empty"] p')
        ?.textContent,
    ).toBe(ANALYTICS_OVERVIEW_EMPTY_TEXT)
  })
})
