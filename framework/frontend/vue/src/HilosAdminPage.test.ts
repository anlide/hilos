import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import { HilosPages, createSignal } from '@hilos/core'
import type { HilosRouter, PageRouteMatch } from '@hilos/core'

import HilosAdminPage from './HilosAdminPage.vue'
import { hilosRouterKey } from './hilosRouterKey.js'

function router(): HilosRouter {
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
    clearPageError: () => {},
    denyCurrentPage: () => {},
    awaitPageAnswer: () => {},
    navigate: () => {},
    replacePath: () => {},
    start: () => {},
    stop: () => {},
  }
}

function mountPage(page: string, slots: Record<string, string> = {}) {
  return mount(HilosAdminPage, {
    props: { page },
    slots,
    global: { provide: { [hilosRouterKey as symbol]: router() } },
  })
}

describe('HilosAdminPage', () => {
  it('renders the heading, breadcrumb, and child cards for a section', () => {
    const wrapper = mountPage(HilosPages.I18N)
    expect(wrapper.find('[data-id="hilos-admin-title"]').text()).toContain(
      'Internationalization',
    )
    expect(wrapper.find('[data-id="hilos-breadcrumb"]').exists()).toBe(true)
    expect(wrapper.find('[data-id="hilos-admin-children"]').exists()).toBe(true)
  })

  it('renders the empty stub for a leaf', () => {
    const wrapper = mountPage(HilosPages.I18N_LANGUAGE)
    expect(wrapper.find('[data-id="hilos-admin-empty"]').exists()).toBe(true)
    expect(wrapper.find('[data-id="hilos-admin-children"]').exists()).toBe(
      false,
    )
  })

  it('draws a section root body after the child cards rather than in place of them', () => {
    const wrapper = mountPage(HilosPages.I18N, {
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
