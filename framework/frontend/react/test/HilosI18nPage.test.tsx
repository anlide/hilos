import { afterEach, describe, expect, it, vi } from 'vitest'
import { act, cleanup, fireEvent, render } from '@testing-library/react'
import type { ComponentType } from 'react'
import {
  createAppPageRouter,
  createSignal,
  HilosPages,
  type HilosPageIdentity,
  type HilosRouter,
  type LazyPageView,
  type PageRouteMatch,
} from '@hilos/core'

import { hilosAdminViews, HilosRouterContext } from '../src/index.js'
import { HilosI18nPage } from '../src/admin/i18n/HilosI18nPage.js'

/** The root's subscription answer: only the two completed reference sections. */
const IDENTITY: HilosPageIdentity = {
  label: 'Languages and countries',
  lead: 'Reference languages, locales, countries and their names.',
  breadcrumb: [{ page: HilosPages.DASHBOARD, label: 'Hilos' }],
  children: [
    {
      page: HilosPages.I18N_LANGUAGES,
      label: 'Languages',
      lead: 'Languages and their locales.',
      icon: null,
    },
    {
      page: HilosPages.I18N_COUNTRIES,
      label: 'Countries',
      lead: 'Countries and their names.',
      icon: null,
    },
  ],
}

/** Mount the real default root over the existing framework route declarations. */
async function mount(identity: HilosPageIdentity | undefined) {
  const routes = createAppPageRouter({})
  const navigate = vi.fn()
  const pageIdentity = createSignal(identity)
  const navigator: HilosRouter = {
    currentRoute: createSignal<PageRouteMatch>({
      page: HilosPages.I18N,
      params: {},
      admin: true,
    }),
    currentPath: createSignal('/hilos/i18n'),
    currentTitle: createSignal('Languages and countries'),
    pageError: createSignal(null),
    pageLoading: createSignal(false),
    pageIdentity,
    dashboardSections: createSignal(undefined),
    resolvePath: routes.path,
    clearPageError: () => {},
    denyCurrentPage: () => {},
    awaitPageAnswer: () => {},
    onLeave: () => () => {},
    navigate,
    replacePath: () => {},
    start: () => {},
    stop: () => {},
  }
  const descriptor = hilosAdminViews()[
    HilosPages.I18N
  ] as LazyPageView<ComponentType>
  const Root = await descriptor.load()
  const rendered = render(
    <HilosRouterContext.Provider value={navigator}>
      <Root />
    </HilosRouterContext.Provider>,
  )
  return { ...rendered, pageIdentity, navigate }
}

describe('HilosI18nPage', () => {
  afterEach(cleanup)

  it('keeps the self-contained root default and requires bindings for its seven pages', async () => {
    const views = hilosAdminViews()
    const descriptor = views[HilosPages.I18N] as LazyPageView<ComponentType>
    expect(await descriptor.load()).toBe(HilosI18nPage)
    for (const page of [
      HilosPages.I18N_LANGUAGES,
      HilosPages.I18N_LANGUAGE,
      HilosPages.I18N_LANGUAGE_NAMES,
      HilosPages.I18N_LANGUAGE_LOCALES,
      HilosPages.I18N_COUNTRIES,
      HilosPages.I18N_COUNTRY,
      HilosPages.I18N_COUNTRY_NAMES,
    ]) {
      expect(views).not.toHaveProperty(page)
    }
  })

  it('renders one heading and exactly the Languages and Countries child links', async () => {
    const { container, navigate } = await mount(IDENTITY)
    expect(container.querySelectorAll('h1')).toHaveLength(1)
    expect(container.querySelector('h1')?.textContent).toBe(IDENTITY.label)
    const cards = container.querySelectorAll('[data-id^="hilos-admin-child-"]')
    expect([...cards].map((card) => card.getAttribute('href'))).toEqual([
      '/hilos/i18n/languages',
      '/hilos/i18n/countries',
    ])
    expect(cards[0].textContent).toContain('Languages')
    expect(cards[1].textContent).toContain('Countries')
    expect(container.querySelector('[data-id="hilos-admin-empty"]')).toBeNull()
    fireEvent.click(cards[0])
    expect(navigate).toHaveBeenCalledExactlyOnceWith('/hilos/i18n/languages')
    fireEvent.click(cards[1])
    expect(navigate).toHaveBeenLastCalledWith('/hilos/i18n/countries')
  })

  it('shows the standard placeholder until identity arrives without printing raw keys', async () => {
    const { container, pageIdentity } = await mount(undefined)
    expect(
      container.querySelector('[data-id="hilos-admin-title-skeleton"]'),
    ).not.toBeNull()
    expect(container.querySelector('h1')).toBeNull()
    expect(container.querySelector('a')).toBeNull()
    expect(container.querySelector('[data-id="hilos-admin-empty"]')).toBeNull()
    expect(container.textContent).not.toContain(HilosPages.I18N)

    act(() => pageIdentity.set(IDENTITY))
    expect(
      container.querySelector('[data-id="hilos-admin-title-skeleton"]'),
    ).toBeNull()
    expect(container.querySelectorAll('h1')).toHaveLength(1)
    expect(
      container.querySelectorAll('[data-id^="hilos-admin-child-"]'),
    ).toHaveLength(2)
    expect(container.textContent).not.toContain(HilosPages.I18N)
  })
})
