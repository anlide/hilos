import { enableAutoUnmount, mount } from '@vue/test-utils'
import { afterEach, describe, expect, it } from 'vitest'

import History from './History.vue'

enableAutoUnmount(afterEach)

describe('History', () => {
  it('names the page once and labels each period by its own heading', () => {
    const wrapper = mount(History)

    const titles = wrapper.findAll('h1')
    expect(titles).toHaveLength(1)
    expect(titles[0].text()).toBe('History')

    expect(
      wrapper
        .findAll('h2')
        .map((heading) => heading.text())
        .sort(),
    ).toEqual(
      [
        '2009 — a module for an engine without a name',
        '2009–2017 — the Subversion years',
        '2018 — v1',
        '2023',
        '2025 — why v2',
        '2026 — the factory',
      ].sort(),
    )

    const sections = wrapper.findAll('section')
    expect(sections).toHaveLength(6)
    for (const section of sections) {
      expect(section.get('h2').attributes('id')).toBe(
        section.attributes('aria-labelledby'),
      )
    }
  })

  it('quotes the 2023 wish', () => {
    const wrapper = mount(History)

    expect(wrapper.get('blockquote').text()).toBe(
      '"An old project, rewritten from scratch five times in about fifteen years. I really want to bring it to a presentable state, as a free public Composer package."',
    )
  })
})
