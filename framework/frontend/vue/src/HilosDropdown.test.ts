import { mount, type VueWrapper } from '@vue/test-utils'
import { afterEach, describe, expect, it } from 'vitest'
import { nextTick } from 'vue'

import HilosDropdown from './HilosDropdown.vue'
import type { HilosDropdownOption } from './hilosDropdown.js'

const OPTIONS: HilosDropdownOption<string>[] = [
  { value: 'a', label: 'Alpha' },
  { value: 'b', label: 'Beta' },
]

// Three options, because the roving cases have to tell "the next one" apart
// from "the last one", and with two of them those are the same node.
const ROVING_OPTIONS: HilosDropdownOption<string>[] = [
  { value: 'a', label: 'Alpha' },
  { value: 'b', label: 'Beta' },
  { value: 'c', label: 'Gamma' },
]

const OPTIONS_WITH_DISABLED: HilosDropdownOption<string>[] = [
  { value: 'a', label: 'Alpha' },
  { value: 'b', label: 'Beta', disabled: true },
  { value: 'c', label: 'Gamma' },
]

/**
 * The props a case mounts the dropdown with.
 *
 * Written as an alias rather than an interface because `mount` takes props as a
 * `Record<string, unknown>`, and only an alias satisfies one implicitly.
 */
type DropdownProps = {
  /** The selected option's value; null when nothing is chosen. */
  modelValue: string | null
  /** The selectable options, in display order. */
  options: HilosDropdownOption<string>[]
  /** The message shown inside an empty menu, when the case sets it. */
  emptyText?: string
}

// The wrappers a case attached to the live document, unmounted after it: the
// outside-click listener goes on `document` in onMounted, so a wrapper left
// mounted keeps answering clicks made by the case that follows.
const attached: VueWrapper[] = []

afterEach(() => {
  while (attached.length > 0) {
    attached.pop()?.unmount()
  }
})

/**
 * Mount the dropdown into the live document.
 *
 * Everything about keys needs a real document: focus only moves inside one, and
 * the outside-click listener sits on the document itself.
 *
 * @param props The props the case mounts with.
 * @param slots The scoped slots the case fills, when it substitutes the look.
 * @returns The mounted wrapper, unmounted for the case after it ends.
 */
function mountAttached(
  props: DropdownProps,
  slots: Record<string, string> = {},
): VueWrapper {
  const wrapper = mount(HilosDropdown, {
    props,
    slots,
    attachTo: document.body,
  }) as unknown as VueWrapper
  attached.push(wrapper)

  return wrapper
}

/**
 * The option buttons of a mounted dropdown, in display order.
 *
 * The element is named too, because the empty row wears `.dropdown-item` as
 * well and it is a span rather than an option.
 *
 * @param wrapper The mounted dropdown.
 * @returns The buttons, including the ones that are disabled.
 */
function optionButtons(wrapper: VueWrapper): HTMLButtonElement[] {
  const root = wrapper.element as HTMLElement

  return Array.from(
    root.querySelectorAll<HTMLButtonElement>('button.dropdown-item'),
  )
}

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

  it('opens on the toggle and closes on the next click', async () => {
    const wrapper = mountAttached({ modelValue: null, options: OPTIONS })
    const toggle = wrapper.find('[data-id="hilos-dropdown-toggle"]')

    await toggle.trigger('click')
    expect(toggle.attributes('aria-expanded')).toBe('true')

    await toggle.trigger('click')
    expect(toggle.attributes('aria-expanded')).toBe('false')
  })

  it('closes on Escape and returns focus to the toggle', async () => {
    const wrapper = mountAttached({ modelValue: null, options: OPTIONS })
    const toggle = wrapper.find('[data-id="hilos-dropdown-toggle"]')
    const menu = wrapper.find('[data-id="hilos-dropdown-menu"]')

    await toggle.trigger('keydown', { key: 'ArrowDown' })
    await nextTick()
    expect(document.activeElement).toBe(optionButtons(wrapper)[0])

    await menu.trigger('keydown', { key: 'Escape' })
    expect(toggle.attributes('aria-expanded')).toBe('false')
    expect(document.activeElement).toBe(toggle.element)
  })

  it('closes on a click outside the dropdown', async () => {
    const wrapper = mountAttached({ modelValue: null, options: OPTIONS })
    const toggle = wrapper.find('[data-id="hilos-dropdown-toggle"]')
    const menu = wrapper.find('[data-id="hilos-dropdown-menu"]')

    await toggle.trigger('click')
    menu.element.dispatchEvent(new MouseEvent('click', { bubbles: true }))
    await nextTick()
    // A click that lands inside the dropdown is not an outside click.
    expect(toggle.attributes('aria-expanded')).toBe('true')

    document.body.dispatchEvent(new MouseEvent('click', { bubbles: true }))
    await nextTick()
    expect(toggle.attributes('aria-expanded')).toBe('false')
  })

  it('roves the options with the arrow keys and Home/End', async () => {
    const wrapper = mountAttached({ modelValue: null, options: ROVING_OPTIONS })
    const toggle = wrapper.find('[data-id="hilos-dropdown-toggle"]')
    const menu = wrapper.find('[data-id="hilos-dropdown-menu"]')
    const buttons = optionButtons(wrapper)

    await toggle.trigger('keydown', { key: 'ArrowDown' })
    await nextTick()
    expect(document.activeElement).toBe(buttons[0])

    await menu.trigger('keydown', { key: 'ArrowDown' })
    expect(document.activeElement).toBe(buttons[1])

    await menu.trigger('keydown', { key: 'ArrowUp' })
    expect(document.activeElement).toBe(buttons[0])

    // The ring: up from the first option lands on the last.
    await menu.trigger('keydown', { key: 'ArrowUp' })
    expect(document.activeElement).toBe(buttons[2])

    await menu.trigger('keydown', { key: 'Home' })
    expect(document.activeElement).toBe(buttons[0])

    await menu.trigger('keydown', { key: 'End' })
    expect(document.activeElement).toBe(buttons[2])
  })

  it('emits the chosen value, marks it selected, and closes', async () => {
    const wrapper = mountAttached({ modelValue: null, options: OPTIONS })
    const toggle = wrapper.find('[data-id="hilos-dropdown-toggle"]')

    await toggle.trigger('click')
    await wrapper.find('[data-id="hilos-dropdown-option-b"]').trigger('click')

    expect(wrapper.emitted('update:modelValue')).toEqual([['b']])
    expect(toggle.attributes('aria-expanded')).toBe('false')
    expect(document.activeElement).toBe(toggle.element)

    // v-model is the caller's to write back; the chosen option is the selected
    // one once it is.
    await wrapper.setProps({ modelValue: 'b' })
    expect(
      wrapper
        .find('[data-id="hilos-dropdown-option-b"]')
        .attributes('aria-selected'),
    ).toBe('true')
    expect(
      wrapper
        .find('[data-id="hilos-dropdown-option-a"]')
        .attributes('aria-selected'),
    ).toBe('false')
  })

  it('keeps a disabled option out of the selection and out of the roving', async () => {
    const wrapper = mountAttached({
      modelValue: null,
      options: OPTIONS_WITH_DISABLED,
    })
    const toggle = wrapper.find('[data-id="hilos-dropdown-toggle"]')
    const menu = wrapper.find('[data-id="hilos-dropdown-menu"]')
    const buttons = optionButtons(wrapper)

    await toggle.trigger('keydown', { key: 'ArrowDown' })
    await nextTick()
    expect(document.activeElement).toBe(buttons[0])

    // Beta sits between the two, and the roving steps straight over it.
    await menu.trigger('keydown', { key: 'ArrowDown' })
    expect(document.activeElement).toBe(buttons[2])

    await wrapper.find('[data-id="hilos-dropdown-option-b"]').trigger('click')
    expect(wrapper.emitted('update:modelValue')).toBeUndefined()
  })

  it('shows the empty text when there are no options', () => {
    const wrapper = mountAttached({
      modelValue: null,
      options: [],
      emptyText: 'Nothing to pick',
    })

    expect(wrapper.find('[data-id="hilos-dropdown-empty"]').text()).toBe(
      'Nothing to pick',
    )
    expect(optionButtons(wrapper)).toHaveLength(0)
  })

  it('replaces the toggle face and the option row through the slots', async () => {
    const wrapper = mountAttached(
      { modelValue: null, options: OPTIONS },
      {
        toggle: '<span data-id="test-toggle">{{ params.label }}</span>',
        option:
          '<button type="button" :data-id="`test-option-${params.option.value}`" @click="params.select()">{{ params.option.label }}</button>',
      },
    )

    const toggle = wrapper.find('[data-id="hilos-dropdown-toggle"]')
    expect(toggle.find('[data-id="test-toggle"]').text()).toBe('Select…')

    await wrapper.find('[data-id="test-option-a"]').trigger('click')
    expect(wrapper.emitted('update:modelValue')).toEqual([['a']])
  })
})
