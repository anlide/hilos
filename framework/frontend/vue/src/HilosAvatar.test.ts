import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'

import HilosAvatar from './HilosAvatar.vue'

describe('HilosAvatar', () => {
  it('draws initials in a decorative circle of the default size', () => {
    const wrapper = mount(HilosAvatar, { props: { name: 'Мария Ковалёва' } })

    expect(wrapper.text()).toBe('МК')
    expect(wrapper.attributes('data-id')).toBe('hilos-avatar')
    expect(wrapper.attributes('aria-hidden')).toBe('true')
    expect(wrapper.attributes('role')).toBeUndefined()
    expect(wrapper.attributes('tabindex')).toBeUndefined()
    expect(wrapper.attributes('title')).toBeUndefined()
    expect(wrapper.classes()).toContain('hilos-avatar-sm')
  })

  it.each(['', '🤖'])('draws the person icon for %j', (name) => {
    const wrapper = mount(HilosAvatar, { props: { name } })

    expect(wrapper.find('i.bi-person').exists()).toBe(true)
    expect(wrapper.text()).toBe('')
  })

  it.each(['sm', 'md', 'lg'] as const)('draws size %s', (size) => {
    const wrapper = mount(HilosAvatar, { props: { name: 'Alexander', size } })

    expect(wrapper.classes()).toContain(`hilos-avatar-${size}`)
  })

  it('updates the initials and fallback when the name changes', async () => {
    const wrapper = mount(HilosAvatar, { props: { name: 'Alexander Baranov' } })

    expect(wrapper.text()).toBe('AB')
    await wrapper.setProps({ name: 'Мария Ковалёва' })
    expect(wrapper.text()).toBe('МК')
    await wrapper.setProps({ name: '🤖' })
    expect(wrapper.text()).toBe('')
    expect(wrapper.find('i.bi-person').exists()).toBe(true)
    await wrapper.setProps({ name: 'Alexander' })
    expect(wrapper.text()).toBe('A')
    expect(wrapper.find('i.bi-person').exists()).toBe(false)
  })
})
