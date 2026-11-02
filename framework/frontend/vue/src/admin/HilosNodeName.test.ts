import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'

import HilosNodeName from './HilosNodeName.vue'

describe('HilosNodeName', () => {
  it('renders names as text and keeps the id once when the name becomes unknown', async () => {
    const nodeName = '<b>Узел 🌍</b>' + 'x'.repeat(50)
    const view = mount(HilosNodeName, {
      props: { nodeId: 'n1', nodeName },
    })
    expect(view.text()).toContain(nodeName)
    expect(view.text()).toContain('n1')
    expect(view.find('b').exists()).toBe(false)
    await view.setProps({ nodeName: null })
    expect(view.text()).toBe('n1')
    view.unmount()
  })
})
