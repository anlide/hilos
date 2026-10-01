import { HIDDEN_VALUE } from '@hilos/core'
import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import { h } from 'vue'

import HilosHideable from './HilosHideable.vue'

describe('HilosHideable', () => {
  it('prints a value that is not hidden as text when given no slot', () => {
    const wrapper = mount(HilosHideable, { props: { value: 'Olena' } })

    expect(wrapper.text()).toBe('Olena')
    expect(wrapper.find('[data-id="hilos-hidden"]').exists()).toBe(false)
  })

  it('hands a value that is not hidden to the slot', () => {
    const wrapper = mount(HilosHideable, {
      props: { value: 'olena@example.com' },
      slots: {
        default: ({ value }: { value: unknown }) =>
          h('code', { 'data-id': 'shown' }, String(value).toUpperCase()),
      },
    })

    expect(wrapper.get('[data-id="shown"]').text()).toBe('OLENA@EXAMPLE.COM')
  })

  it('draws the hidden mark for a hidden value and never calls the slot', () => {
    let called = false
    const wrapper = mount(HilosHideable, {
      props: { value: HIDDEN_VALUE },
      slots: {
        default: () => {
          called = true

          return h('code', 'never')
        },
      },
    })

    expect(wrapper.get('[data-id="hilos-hidden"]').text()).toBe('Hidden')
    expect(called).toBe(false)
  })
})
