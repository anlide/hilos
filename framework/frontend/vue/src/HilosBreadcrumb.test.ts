import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import type { HilosCrumb } from '@hilos/core/base'

import HilosBreadcrumb from './HilosBreadcrumb.vue'

const TRAIL: HilosCrumb[] = [
  { page: 'hilos', label: 'Dashboard', to: '/hilos' },
  { page: 'hilos_billing', label: 'Billing', to: '/hilos/billing' },
]

describe('HilosBreadcrumb', () => {
  it('renders nothing for an empty trail', () => {
    const wrapper = mount(HilosBreadcrumb, { props: { crumbs: [] } })
    expect(wrapper.find('[data-id="hilos-breadcrumb"]').exists()).toBe(false)
  })

  it('links every crumb but the last, which is the active page', () => {
    const wrapper = mount(HilosBreadcrumb, { props: { crumbs: TRAIL } })

    const links = wrapper.findAll('a')
    expect(links).toHaveLength(1)
    expect(links[0].text()).toBe('Dashboard')
    expect(links[0].attributes('href')).toBe('/hilos')

    const active = wrapper.find('[aria-current="page"]')
    expect(active.text()).toContain('Billing')
    expect(active.attributes('aria-current')).toBe('page')
  })

  it('draws a crumb with no address as text, keeping the chain whole', () => {
    // A link that goes nowhere is worse than no link, but a missing link is
    // worse still: the ancestry would renumber and name the wrong parent.
    const wrapper = mount(HilosBreadcrumb, {
      props: {
        crumbs: [
          { page: 'hilos', label: 'Dashboard', to: '/hilos' },
          { page: 'unrouted', label: 'Section' },
          { page: 'hilos_billing', label: 'Billing', to: '/hilos/billing' },
        ],
      },
    })

    expect(wrapper.findAll('a')).toHaveLength(1)
    const items = wrapper.findAll('ol > li')
    expect(items).toHaveLength(3)
    expect(items[1].text()).toContain('Section')
    expect(wrapper.findAll('[aria-current="page"]')).toHaveLength(1)
    expect(items[2].attributes('aria-current')).toBe('page')
  })

  it('names the navigation by the label it is given', () => {
    const wrapper = mount(HilosBreadcrumb, {
      props: { crumbs: TRAIL, label: 'Path to this page' },
    })

    expect(
      wrapper.get('[data-id="hilos-breadcrumb"]').attributes('aria-label'),
    ).toBe('Path to this page')
  })

  it('keeps its old name when no label is given', () => {
    const wrapper = mount(HilosBreadcrumb, { props: { crumbs: TRAIL } })

    expect(
      wrapper.get('[data-id="hilos-breadcrumb"]').attributes('aria-label'),
    ).toBe('breadcrumb')
  })

  it('renders decorative aria-hidden slashes on non-first crumbs without breadcrumb-item', () => {
    const wrapper = mount(HilosBreadcrumb, {
      props: {
        crumbs: [
          { page: 'hilos', label: 'Dashboard', to: '/hilos' },
          { page: 'hilos_settings', label: 'Settings', to: '/hilos/settings' },
          { page: 'hilos_billing', label: 'Billing', to: '/hilos/billing' },
        ],
      },
    })

    const items = wrapper.findAll('ol > li')
    expect(items).toHaveLength(3)

    // Target a11y regression: no li carries breadcrumb-item
    expect(wrapper.find('.breadcrumb-item').exists()).toBe(false)

    // First crumb has no slash separator
    expect(items[0].find('span[aria-hidden="true"]').exists()).toBe(false)
    expect(items[0].get('a').text()).toBe('Dashboard')

    // Second crumb has exactly one aria-hidden slash span, separate from the link
    const secondSlash = items[1].findAll('span[aria-hidden="true"]')
    expect(secondSlash).toHaveLength(1)
    expect(secondSlash[0].text()).toBe('/')
    const secondLink = items[1].get('a')
    expect(secondLink.text()).toBe('Settings')
    expect(secondLink.find('span[aria-hidden="true"]').exists()).toBe(false)

    // Third (last) crumb has exactly one aria-hidden slash span, and is current page text
    const thirdSlash = items[2].findAll('span[aria-hidden="true"]')
    expect(thirdSlash).toHaveLength(1)
    expect(thirdSlash[0].text()).toBe('/')
    expect(items[2].find('a').exists()).toBe(false)
    expect(items[2].attributes('aria-current')).toBe('page')
    expect(items[2].text()).toContain('Billing')
  })

  it('renders a single crumb as active text without links or separator', () => {
    const wrapper = mount(HilosBreadcrumb, {
      props: {
        crumbs: [{ page: 'hilos', label: 'Dashboard', to: '/hilos' }],
      },
    })

    const items = wrapper.findAll('ol > li')
    expect(items).toHaveLength(1)
    expect(items[0].attributes('aria-current')).toBe('page')
    expect(items[0].text()).toBe('Dashboard')
    expect(items[0].find('a').exists()).toBe(false)
    expect(wrapper.find('span[aria-hidden="true"]').exists()).toBe(false)
    expect(wrapper.text()).not.toContain('/')
  })
})
