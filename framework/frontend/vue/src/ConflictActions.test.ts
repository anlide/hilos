import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import { ref } from 'vue'

import ConflictActions from './ConflictActions.vue'
import { hilosAdminViewModeKey } from './hilosAdminViewMode.js'

/** What a page in the admin view mode provides to what it holds. */
const IN_VIEW_MODE = {
  global: { provide: { [hilosAdminViewModeKey as symbol]: ref(true) } },
}

/** The window's Cancel, handed in through the slot the way a page does. */
const CANCEL_SLOT = {
  'cancel-button':
    '<button type="button" data-id="host-cancel">Cancel</button>',
}

/** Document order of every data-id under the mounted root. */
function dataIds(root: Element): string[] {
  return [...root.querySelectorAll('[data-id]')].map(
    (el) => el.getAttribute('data-id') ?? '',
  )
}

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
    expect(wrapper.find('[data-id="conflict-merge"]').exists()).toBe(false)
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

  it('shows merge only when the surface asks for it (mergeable: true)', async () => {
    const wrapper = mount(ConflictActions, { props: { conflict: true } })
    expect(wrapper.find('[data-id="conflict-accept-mine"]').exists()).toBe(true)
    expect(wrapper.find('[data-id="conflict-merge"]').exists()).toBe(false)

    await wrapper.setProps({ mergeable: true })
    expect(wrapper.find('[data-id="conflict-merge"]').exists()).toBe(true)
  })

  it('shapes the root as a button group and renders resolution buttons without btn-sm', () => {
    const wrapper = mount(ConflictActions, {
      props: { conflict: true, mergeable: true },
    })
    const root = wrapper.element as HTMLElement
    expect(root.classList.contains('hilos-button-group')).toBe(true)
    expect(root.classList.contains('d-md-flex')).toBe(true)

    const mine = wrapper.find('[data-id="conflict-accept-mine"]')
    const theirs = wrapper.find('[data-id="conflict-accept-theirs"]')
    const merge = wrapper.find('[data-id="conflict-merge"]')

    for (const btn of [mine, theirs, merge]) {
      expect(btn.classes()).not.toContain('btn-sm')
      expect(btn.classes()).toContain('btn')
    }
  })

  it('stands the choices, then the handed Cancel, then Save', () => {
    const wrapper = mount(ConflictActions, {
      props: { conflict: true },
      slots: CANCEL_SLOT,
    })

    expect(dataIds(wrapper.element)).toEqual([
      'conflict-choices',
      'conflict-accept-mine',
      'conflict-accept-theirs',
      'host-cancel',
      'conflict-save',
    ])
  })

  it("holds the choices' room with an idle twin while no conflict stands", () => {
    const wrapper = mount(ConflictActions, {
      props: { conflict: false, mergeable: true },
      slots: CANCEL_SLOT,
    })
    const twin = wrapper.get('[data-id="conflict-choices-idle"]')

    expect(twin.classes()).toContain('hilos-conflict-choices')
    expect(twin.classes()).toContain('invisible')
    expect(twin.attributes('aria-hidden')).toBe('true')
    expect(twin.findAll('button')).toHaveLength(0)
    expect(twin.findAll('span').map((span) => span.text())).toEqual([
      'Keep mine',
      'Take theirs',
      'Merge',
    ])
    expect(wrapper.find('[data-id="conflict-choices"]').exists()).toBe(false)
    expect(dataIds(wrapper.element)).toEqual([
      'conflict-choices-idle',
      'host-cancel',
      'conflict-save',
    ])
  })

  it('swaps the twin for the choices with the same classes and labels', async () => {
    const wrapper = mount(ConflictActions, {
      props: { conflict: false, mergeable: true },
    })
    const read = (id: string): { className: string; text: string }[] =>
      [...wrapper.get(`[data-id="${id}"]`).element.children].map((node) => ({
        className: (node as HTMLElement).className,
        text: (node.textContent ?? '').trim(),
      }))

    const twin = read('conflict-choices-idle')
    await wrapper.setProps({ conflict: true })
    expect(wrapper.find('[data-id="conflict-choices-idle"]').exists()).toBe(
      false,
    )
    expect(read('conflict-choices')).toEqual(twin)

    await wrapper.setProps({ conflict: false })
    expect(wrapper.find('[data-id="conflict-choices"]').exists()).toBe(false)
    expect(read('conflict-choices-idle')).toEqual(twin)
  })
})

describe('ConflictActions in the admin view mode', () => {
  it('disables the default save and points it at the strip', async () => {
    const wrapper = mount(ConflictActions, IN_VIEW_MODE)
    const save = wrapper.find('[data-id="conflict-save"]')

    expect(save.attributes('disabled')).toBeDefined()
    expect(save.attributes('aria-describedby')).toBe(
      'hilos-view-mode-strip-text',
    )
    await save.trigger('click')
    expect(wrapper.emitted('save')).toBeUndefined()
  })

  it('hands the slotted save a disabled state', () => {
    const seen: boolean[] = []
    mount(ConflictActions, {
      ...IN_VIEW_MODE,
      slots: {
        'save-button': (slot: { disabled: boolean }) => {
          seen.push(slot.disabled)

          return 'Save'
        },
      },
    })

    expect(seen).toEqual([true])
  })

  it('keeps the conflict choices, which edit only the draft', async () => {
    const wrapper = mount(ConflictActions, {
      ...IN_VIEW_MODE,
      props: { conflict: true, mergeable: true },
    })

    for (const choice of ['accept-mine', 'accept-theirs', 'merge']) {
      const button = wrapper.find(`[data-id="conflict-${choice}"]`)
      expect(button.attributes('disabled')).toBeUndefined()
      await button.trigger('click')
      expect(wrapper.emitted(choice)).toHaveLength(1)
    }
  })

  it('leaves the default save untouched outside the mode', () => {
    const wrapper = mount(ConflictActions)
    const save = wrapper.find('[data-id="conflict-save"]')

    expect(save.attributes('disabled')).toBeUndefined()
    expect(save.attributes('aria-describedby')).toBeUndefined()
  })
})
