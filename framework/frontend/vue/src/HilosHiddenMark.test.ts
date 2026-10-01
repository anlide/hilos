import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'

import HilosHiddenMark from './HilosHiddenMark.vue'

describe('HilosHiddenMark', () => {
  it('draws a soft grey pill with a decorative struck-out eye and the word', () => {
    const wrapper = mount(HilosHiddenMark)

    expect(wrapper.attributes('data-id')).toBe('hilos-hidden')
    expect(wrapper.classes()).toEqual([
      'badge',
      'rounded-pill',
      'bg-body-secondary',
      'text-body-secondary',
      'fw-medium',
    ])
    const icon = wrapper.get('i')
    expect(icon.classes()).toContain('bi-eye-slash')
    expect(icon.attributes('aria-hidden')).toBe('true')
    expect(wrapper.text()).toBe('Hidden')
  })
})
