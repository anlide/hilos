import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'

import ConflictActions from './ConflictActions.vue'

describe('ConflictActions', () => {
  it('shows only save without a conflict', () => {
    const wrapper = mount(ConflictActions)
    expect(wrapper.find('[data-id="conflict-save"]').exists()).toBe(true)
    expect(wrapper.find('[data-id="conflict-merge"]').exists()).toBe(false)
  })

  it('disables save and shows the resolutions on a conflict', () => {
    const wrapper = mount(ConflictActions, { props: { conflict: true } })
    expect(
      wrapper.find('[data-id="conflict-save"]').attributes('disabled'),
    ).toBeDefined()
    expect(wrapper.find('[data-id="conflict-accept-mine"]').exists()).toBe(true)
    expect(wrapper.find('[data-id="conflict-accept-theirs"]').exists()).toBe(
      true,
    )
    expect(wrapper.find('[data-id="conflict-merge"]').exists()).toBe(true)
  })

  it('emits the chosen resolution', async () => {
    const wrapper = mount(ConflictActions, { props: { conflict: true } })
    await wrapper.find('[data-id="conflict-accept-theirs"]').trigger('click')
    expect(wrapper.emitted('accept-theirs')).toHaveLength(1)
  })

  it('emits save from the default button', async () => {
    const wrapper = mount(ConflictActions)
    await wrapper.find('[data-id="conflict-save"]').trigger('click')
    expect(wrapper.emitted('save')).toHaveLength(1)
  })
})
