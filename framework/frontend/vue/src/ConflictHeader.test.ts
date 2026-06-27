import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'

import ConflictHeader from './ConflictHeader.vue'

describe('ConflictHeader', () => {
  it('shows the title without a badge', () => {
    const wrapper = mount(ConflictHeader, { props: { title: 'Edit user' } })
    expect(wrapper.text()).toContain('Edit user')
    expect(wrapper.find('[data-id="conflict-badge"]').exists()).toBe(false)
  })

  it('flags an unresolved conflict with a badge', () => {
    const wrapper = mount(ConflictHeader, {
      props: { title: 'Edit user', conflict: true },
    })
    expect(wrapper.find('[data-id="conflict-badge"]').exists()).toBe(true)
  })
})
