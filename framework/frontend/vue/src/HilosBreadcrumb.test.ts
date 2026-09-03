import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import type { HilosCrumb } from '@hilos/core'

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

    const active = wrapper.find('.breadcrumb-item.active')
    expect(active.text()).toBe('Billing')
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
    expect(wrapper.findAll('.breadcrumb-item')).toHaveLength(3)
    expect(wrapper.findAll('.breadcrumb-item')[1].text()).toBe('Section')
  })
})
