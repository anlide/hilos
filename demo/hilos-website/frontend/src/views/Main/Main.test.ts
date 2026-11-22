import { enableAutoUnmount, mount } from '@vue/test-utils'
import { afterEach, describe, expect, it } from 'vitest'

import Main from './Main.vue'

enableAutoUnmount(afterEach)

describe('Main', () => {
  it('links the line under the heading to the history page', () => {
    const wrapper = mount(Main, {
      global: { stubs: { DemoCatalog: true, HomeVideo: true } },
    })

    const link = wrapper
      .findAll('a')
      .find((anchor) => anchor.text() === 'read its history')
    if (!link) throw new Error('Missing link: read its history')
    expect(link.attributes('href')).toBe('/history')
    expect(
      link.element.parentElement?.textContent?.replace(/\s+/g, ' ').trim(),
    ).toBe('Since 2009 — read its history.')
  })
})
