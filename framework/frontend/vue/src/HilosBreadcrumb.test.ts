import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import type { HilosCrumb } from '@hilos/core'

import HilosBreadcrumb from './HilosBreadcrumb.vue'

const TRAIL: HilosCrumb[] = [
  { label: 'Dashboard', to: '/hilos' },
  { label: 'Billing', to: '/hilos/billing' },
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
})
