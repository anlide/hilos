import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'

import HilosDropdown from './HilosDropdown.vue'
import type { HilosDropdownOption } from './hilosDropdown.js'

const OPTIONS: HilosDropdownOption<string>[] = [
  { value: 'a', label: 'Alpha' },
  { value: 'b', label: 'Beta' },
]

describe('HilosDropdown', () => {
  it('links the toggle to the menu and exposes the listbox ARIA', () => {
    const wrapper = mount(HilosDropdown, {
      props: { modelValue: null, options: OPTIONS },
    })

    const toggle = wrapper.find('[data-id="hilos-dropdown-toggle"]')
    const menu = wrapper.find('[data-id="hilos-dropdown-menu"]')

    // aria-controls on the toggle resolves to the listbox's id.
    const menuId = menu.attributes('id')
    expect(menuId).toBeTruthy()
    expect(toggle.attributes('aria-controls')).toBe(menuId)

    expect(toggle.attributes('aria-haspopup')).toBe('listbox')
    expect(toggle.attributes('aria-expanded')).toBe('false')
    expect(menu.attributes('role')).toBe('listbox')
  })
})
