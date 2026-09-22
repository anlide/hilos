import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'

import HilosSwitch from './HilosSwitch.vue'

function input(wrapper: ReturnType<typeof mount>): HTMLInputElement {
  return wrapper.find('input').element as HTMLInputElement
}

describe('HilosSwitch', () => {
  it('keeps its position on click and emits the requested inversion', async () => {
    const wrapper = mount(HilosSwitch, {
      props: { checked: false, dataId: 'setting-toggle' },
    })

    await wrapper.find('input').trigger('click')

    expect(input(wrapper).checked).toBe(false)
    expect(wrapper.emitted('toggle')).toEqual([[true]])
  })

  it('follows the checked input', async () => {
    const wrapper = mount(HilosSwitch, {
      props: { checked: false, dataId: 'setting-toggle' },
    })

    await wrapper.setProps({ checked: true })

    expect(input(wrapper).checked).toBe(true)
  })

  it('disables and marks the input busy while saving', () => {
    const wrapper = mount(HilosSwitch, {
      props: {
        checked: false,
        busy: true,
        dataId: 'setting-toggle',
      },
    })

    expect(input(wrapper).disabled).toBe(true)
    expect(wrapper.find('input').attributes('aria-busy')).toBe('true')
  })

  it('shows the busy spinner only after the delay', async () => {
    vi.useFakeTimers()
    try {
      const wrapper = mount(HilosSwitch, {
        props: {
          checked: false,
          dataId: 'setting-toggle',
          spinnerDelay: 300,
        },
      })
      await wrapper.setProps({ busy: true })
      expect(wrapper.find('[role="status"]').exists()).toBe(false)

      vi.advanceTimersByTime(300)
      await wrapper.vm.$nextTick()

      expect(wrapper.find('[role="status"]').exists()).toBe(true)
    } finally {
      vi.useRealTimers()
    }
  })

  it('gives each instance an id and points its label at its own input', () => {
    const wrapper = mount({
      components: { HilosSwitch },
      template: `
        <div>
          <HilosSwitch :checked="false" data-id="first" label="First" />
          <HilosSwitch :checked="false" data-id="second" label="Second" />
        </div>
      `,
    })
    const inputs = wrapper.findAll('input')
    const labels = wrapper.findAll('label')

    expect(inputs[0]?.attributes('id')).not.toBe(inputs[1]?.attributes('id'))
    expect(labels[0]?.attributes('for')).toBe(inputs[0]?.attributes('id'))
    expect(labels[1]?.attributes('for')).toBe(inputs[1]?.attributes('id'))
  })
})
