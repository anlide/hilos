import { mount } from '@vue/test-utils'
import type { HilosStaticPageNavigation } from '@hilos/core/base'
import { afterEach, describe, expect, it } from 'vitest'

import HilosAboutPage from './HilosAboutPage.vue'

function publicNavigation(
  page = 'fixture_public_page',
  project = 'first',
): HilosStaticPageNavigation {
  return {
    page,
    tabs: [
      { page: 'fixture_public_page', label: 'Public page' },
      { page: 'fixture_history', label: 'Revision history' },
    ],
    params: { project },
    resolvePath: (target, params = {}) =>
      `/projects/${params.project}/${target}`,
    label: 'Public pages',
  }
}

// The dialog teleports to <body>, so its assertions query the document rather
// than the wrapper — the same as HilosModal's own test.
afterEach(() => {
  document.body.innerHTML = ''
  document.body.classList.remove('modal-open')
})

describe('HilosAboutPage', () => {
  it('renders its exact public head and forwards optional navigation reactively', async () => {
    const wrapper = mount(HilosAboutPage)

    expect(wrapper.findAll('h1')).toHaveLength(1)
    expect(wrapper.get('[data-id="hilos-page-head-title"]').text()).toBe(
      'About',
    )
    const icon = wrapper.get(
      '[data-id="hilos-page-head-block"] i.bi-info-circle',
    )
    expect(icon.element.parentElement?.getAttribute('aria-hidden')).toBe('true')
    expect(wrapper.get('[data-id="hilos-page-head-block"] p').text()).toBe(
      'What this project is, who makes it, and how it keeps going.',
    )
    expect(wrapper.find('[data-id="hilos-breadcrumb"]').exists()).toBe(false)
    expect(wrapper.find('[data-id="hilos-page-tabs"]').exists()).toBe(false)

    await wrapper.setProps({ navigation: publicNavigation() })
    const tabs = wrapper.get('[data-id="hilos-page-tabs"]')
    expect(tabs.attributes('aria-label')).toBe('Public pages')
    expect(
      tabs.findAll('a').map((link) => [link.text(), link.attributes('href')]),
    ).toEqual([
      ['Public page', '/projects/first/fixture_public_page'],
      ['Revision history', '/projects/first/fixture_history'],
    ])
    expect(tabs.get('[aria-current="page"]').attributes('data-id')).toBe(
      'hilos-page-tab-fixture_public_page',
    )

    await wrapper.setProps({
      navigation: publicNavigation('fixture_history', 'next'),
    })
    expect(tabs.attributes('aria-label')).toBe('Public pages')
    expect(tabs.get('[aria-current="page"]').attributes('data-id')).toBe(
      'hilos-page-tab-fixture_history',
    )
    expect(
      tabs.get('[data-id="hilos-page-tab-fixture_history"]').attributes('href'),
    ).toBe('/projects/next/fixture_history')
  })

  it('draws the project’s prose and the support block, with no dialog open', () => {
    const wrapper = mount(HilosAboutPage, {
      slots: { default: '<p>This project is a demonstration.</p>' },
    })

    expect(wrapper.text()).toContain('This project is a demonstration.')
    expect(wrapper.find('[data-id="hilos-about-support"]').exists()).toBe(true)
    // The heading is a real h2, never bold text standing in for one.
    expect(wrapper.find('[data-id="hilos-about-support"] h2').text()).toContain(
      'no ads and no data selling',
    )
    expect(document.querySelector('[data-id="modal"]')).toBeNull()
  })

  it('opens the support dialog on the block’s one button', async () => {
    const wrapper = mount(HilosAboutPage)

    await wrapper.find('[data-id="hilos-about-support-open"]').trigger('click')

    expect(document.querySelector('[data-id="modal"]')).not.toBeNull()
    expect(
      document.querySelector('[data-id="hilos-about-tier-medium"]'),
    ).not.toBeNull()
  })
})
