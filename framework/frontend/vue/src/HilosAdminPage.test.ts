import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import { HilosPages, createSignal } from '@hilos/core'
import type {
  HilosPageIdentity,
  HilosRouter,
  PageRouteMatch,
} from '@hilos/core'

import HilosAdminPage from './HilosAdminPage.vue'
import { hilosRouterKey } from './hilosRouterKey.js'

/** The identity a section answers with: a chain above it and cards below. */
const SECTION_IDENTITY: HilosPageIdentity = {
  label: 'Internationalization',
  lead: 'Languages, countries, and translation screens.',
  breadcrumb: [
    { page: HilosPages.DASHBOARD, label: 'Hilos' },
    { page: HilosPages.I18N, label: 'Internationalization' },
  ],
  children: [
    {
      page: HilosPages.I18N_LANGUAGES,
      label: 'Languages',
      lead: 'The languages the product ships in.',
      icon: null,
    },
  ],
}

/** The identity a leaf answers with: a chain above it and nothing below. */
const LEAF_IDENTITY: HilosPageIdentity = {
  label: 'Language',
  lead: 'A single language.',
  breadcrumb: [{ page: HilosPages.I18N_LANGUAGE, label: 'Language' }],
  children: [],
}

function router(identity: HilosPageIdentity | undefined): HilosRouter {
  return {
    currentRoute: createSignal<PageRouteMatch>({
      page: '',
      params: {},
      admin: false,
    }),
    currentPath: createSignal(''),
    currentTitle: createSignal(''),
    pageError: createSignal(null),
    pageLoading: createSignal(false),
    pageIdentity: createSignal(identity),
    dashboardSections: createSignal(undefined),
    resolvePath: (page) => `/hilos/${page}`,
    clearPageError: () => {},
    denyCurrentPage: () => {},
    awaitPageAnswer: () => {},
    navigate: () => {},
    replacePath: () => {},
    start: () => {},
    stop: () => {},
  }
}

function mountPage(
  page: string,
  identity: HilosPageIdentity | undefined,
  slots: Record<string, string> = {},
) {
  return mount(HilosAdminPage, {
    props: { page },
    slots,
    global: { provide: { [hilosRouterKey as symbol]: router(identity) } },
  })
}

describe('HilosAdminPage', () => {
  it('renders the heading, breadcrumb, and child cards a section answered with', () => {
    const wrapper = mountPage(HilosPages.I18N, SECTION_IDENTITY)
    expect(wrapper.find('[data-id="hilos-admin-title"]').text()).toContain(
      'Internationalization',
    )
    expect(wrapper.find('[data-id="hilos-breadcrumb"]').exists()).toBe(true)
    expect(wrapper.find('[data-id="hilos-admin-children"]').exists()).toBe(true)
  })

  it('renders the empty stub for a leaf', () => {
    const wrapper = mountPage(HilosPages.I18N_LANGUAGE, LEAF_IDENTITY)
    expect(wrapper.find('[data-id="hilos-admin-empty"]').exists()).toBe(true)
    expect(wrapper.find('[data-id="hilos-admin-children"]').exists()).toBe(
      false,
    )
  })

  it('draws a skeleton and nothing else while the name is still on the wire', () => {
    // The empty h1 under the same data-id is what this rules out: a test could
    // not tell "the name did not arrive" from "the name arrived empty".
    const wrapper = mountPage(HilosPages.I18N, undefined)

    expect(
      wrapper.find('[data-id="hilos-admin-title-skeleton"]').exists(),
    ).toBe(true)
    expect(wrapper.find('[data-id="hilos-admin-title"]').exists()).toBe(false)
    expect(wrapper.find('[data-id="hilos-breadcrumb"]').exists()).toBe(false)
    expect(wrapper.find('[data-id="hilos-admin-empty"]').exists()).toBe(false)
    // The page key is internal and never printed, least of all as a heading.
    expect(wrapper.text()).not.toContain(HilosPages.I18N)
  })

  it('draws a section root body after the child cards rather than in place of them', () => {
    const wrapper = mountPage(HilosPages.I18N, SECTION_IDENTITY, {
      body: '<p data-id="section-body">Figures of this section</p>',
    })

    const cards = wrapper.find('[data-id="hilos-admin-children"]')
    const body = wrapper.find('[data-id="section-body"]')
    expect(cards.exists()).toBe(true)
    expect(body.exists()).toBe(true)
    // A section root needs both, so the order is the contract and not an accident:
    // the way onward stays above the figures it is offered alongside.
    expect(
      cards.element.compareDocumentPosition(body.element) &
        Node.DOCUMENT_POSITION_FOLLOWING,
    ).toBeTruthy()
  })
})
