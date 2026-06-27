import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'

import LoadingButton from './LoadingButton.vue'

describe('LoadingButton', () => {
  it('renders its slot content', () => {
    const wrapper = mount(LoadingButton, { slots: { default: 'Save' } })
    expect(wrapper.text()).toContain('Save')
  })

  it('emits click when enabled', async () => {
    const wrapper = mount(LoadingButton)
    expect(wrapper.find('button').attributes('aria-busy')).toBeUndefined()
    await wrapper.find('button').trigger('click')
    expect(wrapper.emitted('click')).toHaveLength(1)
  })

  it('disables, marks itself busy, and swallows clicks while loading', async () => {
    const wrapper = mount(LoadingButton, { props: { loading: true } })
    expect(wrapper.find('button').attributes('disabled')).toBeDefined()
    expect(wrapper.find('button').attributes('aria-busy')).toBe('true')
    await wrapper.find('button').trigger('click')
    expect(wrapper.emitted('click')).toBeUndefined()
  })

  it('shows the spinner only after the delay', async () => {
    vi.useFakeTimers()
    try {
      const wrapper = mount(LoadingButton, { props: { loadingDelay: 300 } })
      await wrapper.setProps({ loading: true })
      expect(wrapper.find('[data-id="loading-button-spinner"]').exists()).toBe(
        false,
      )
      vi.advanceTimersByTime(300)
      await wrapper.vm.$nextTick()
      expect(wrapper.find('[data-id="loading-button-spinner"]').exists()).toBe(
        true,
      )
    } finally {
      vi.useRealTimers()
    }
  })
})
