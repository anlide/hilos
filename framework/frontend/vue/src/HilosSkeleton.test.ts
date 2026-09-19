import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'

import HilosSkeleton from './HilosSkeleton.vue'

describe('HilosSkeleton', () => {
  it('draws the three default bars, 9, 6 and 10 columns wide', () => {
    const wrapper = mount(HilosSkeleton)
    const bars = wrapper.findAll('.placeholder')

    expect(bars.map((bar) => bar.classes())).toEqual([
      expect.arrayContaining(['col-9', 'mb-2']),
      expect.arrayContaining(['col-6', 'mb-2']),
      expect.arrayContaining(['col-10']),
    ])
    expect(bars[2].classes()).not.toContain('mb-2')
  })

  it('draws the bars it is given', () => {
    const wrapper = mount(HilosSkeleton, { props: { lines: [4, 12] } })
    const bars = wrapper.findAll('.placeholder')

    expect(bars).toHaveLength(2)
    expect(bars[0].classes()).toContain('col-4')
    expect(bars[1].classes()).toContain('col-12')
  })

  it('hides every bar from a screen reader', () => {
    const wrapper = mount(HilosSkeleton)

    for (const bar of wrapper.findAll('.placeholder')) {
      expect(bar.attributes('aria-hidden')).toBe('true')
    }
  })

  it('stays silent without a label', () => {
    const wrapper = mount(HilosSkeleton)

    expect(wrapper.findAll('[role="status"]')).toHaveLength(0)
  })

  it('announces a label once for the whole frame', () => {
    const wrapper = mount(HilosSkeleton, { props: { label: 'Loading…' } })
    const status = wrapper.findAll('[role="status"]')

    expect(status).toHaveLength(1)
    expect(status[0].text()).toBe('Loading…')
  })
})
