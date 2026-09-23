import { mount } from '@vue/test-utils'
import { afterEach, describe, expect, it } from 'vitest'

import HilosEditNotice from './HilosEditNotice.vue'

// The detail panel is a HilosModal, which teleports to <body>: assertions about
// it query the document, not the wrapper.
afterEach(() => {
  document.body.innerHTML = ''
  document.body.classList.remove('modal-open')
})

describe('HilosEditNotice', () => {
  it('holds the room with a hidden twin while there is nothing to say', () => {
    const wrapper = mount(HilosEditNotice, {
      props: { kind: null, text: '', dataId: 'edit-notice' },
    })

    expect(wrapper.find('[data-id="edit-notice"]').exists()).toBe(false)
    const idle = wrapper.find('[data-id="edit-notice-idle"]')
    expect(idle.exists()).toBe(true)
    expect(idle.attributes('aria-hidden')).toBe('true')
    expect(idle.classes()).toContain('invisible')
    // Every element of the row in its tallest form — the icon, the line of text
    // and a button-shaped stand-in — or the room would be short of the truth.
    expect(idle.find('i.bi').exists()).toBe(true)
    expect(idle.find('span.text-truncate').exists()).toBe(true)
    expect(idle.find('span.btn i.bi-info-circle').exists()).toBe(true)
    // A span and not a button: the twin holds room, it does not take focus.
    expect(idle.find('button').exists()).toBe(false)
  })

  it('draws a conflict and a deletion as warnings and an update as a quiet note', () => {
    const tones = {
      conflict: ['alert-warning', 'bi-exclamation-triangle'],
      deleted: ['alert-warning', 'bi-exclamation-triangle'],
      updated: ['alert-secondary', 'bi-arrow-repeat'],
    } as const
    for (const [kind, [tone, icon]] of Object.entries(tones)) {
      const wrapper = mount(HilosEditNotice, {
        props: {
          kind: kind as keyof typeof tones,
          text: 'Something',
          dataId: 'edit-notice',
        },
      })

      const row = wrapper.find('[data-id="edit-notice"]')
      expect(row.exists()).toBe(true)
      expect(row.classes()).toContain(tone)
      expect(row.find(`i.${icon}`).exists()).toBe(true)
      expect(wrapper.find('[data-id="edit-notice-idle"]').exists()).toBe(false)
      // The row shows; the slot above it announces. A role here too would say
      // the sentence twice.
      expect(row.attributes('role')).toBeUndefined()
    }
  })

  it('keeps one truncated line and opens the whole text from the details button', async () => {
    const text =
      'Changed elsewhere to "a value long enough to run past the row"'
    const wrapper = mount(HilosEditNotice, {
      props: { kind: 'conflict', text, dataId: 'edit-notice' },
    })

    const line = wrapper.find('[data-id="edit-notice"] span.flex-grow-1')
    expect(line.text()).toBe(text)
    expect(line.classes()).toContain('text-truncate')
    const details = wrapper.find('[data-id="edit-notice-details"]')
    expect(details.attributes('aria-label')).toBe('Show details')
    await details.trigger('click')

    const full = document.querySelector('[data-id="edit-notice-full"]')
    expect(full?.textContent?.trim()).toBe(text)
  })

  it('closes the panel when the message goes', async () => {
    const wrapper = mount(HilosEditNotice, {
      props: {
        kind: 'updated',
        text: 'Updated just now',
        dataId: 'edit-notice',
      },
    })
    await wrapper.find('[data-id="edit-notice-details"]').trigger('click')
    expect(
      document.querySelector('[data-id="edit-notice-full"]'),
    ).not.toBeNull()

    await wrapper.setProps({ kind: null, text: '' })
    expect(document.querySelector('[data-id="edit-notice-full"]')).toBeNull()
  })

  it('makes the slot a polite status region, with or without a message', async () => {
    const wrapper = mount(HilosEditNotice, {
      props: { kind: null, text: '', dataId: 'edit-notice' },
    })
    const slot = wrapper.find('[data-id="edit-notice-slot"]')
    // A region that stands there before it has anything to say is the only
    // kind the reader announces from (accessibility.md, "Live regions").
    expect(slot.attributes('role')).toBe('status')
    expect(slot.attributes('aria-live')).toBe('polite')

    await wrapper.setProps({ kind: 'updated', text: 'Updated just now' })
    expect(slot.attributes('role')).toBe('status')
    expect(slot.text()).toContain('Updated just now')
  })
})
