import { mount } from '@vue/test-utils'
import { afterEach, describe, expect, it } from 'vitest'

import HilosFormError from './HilosFormError.vue'

// The detail panel is a HilosModal, which teleports to <body>: assertions about
// it query the document, not the wrapper.
afterEach(() => {
  document.body.innerHTML = ''
  document.body.classList.remove('modal-open')
})

describe('HilosFormError', () => {
  it('keeps the slot and draws no row while there is no refusal', () => {
    const wrapper = mount(HilosFormError, {
      props: { message: null, dataId: 'auth-error' },
    })

    const slot = wrapper.find('[data-id="auth-error-slot"]')
    expect(slot.exists()).toBe(true)
    // No role anywhere on what is seen: the surface's own live region does the
    // announcing, and a second role would say the sentence twice.
    expect(slot.attributes('role')).toBeUndefined()
    expect(wrapper.find('[data-id="auth-error"]').exists()).toBe(false)
  })

  it('holds the room with a hidden twin of the whole row', () => {
    const wrapper = mount(HilosFormError, {
      props: { message: null, dataId: 'auth-error' },
    })

    const idle = wrapper.find('[data-id="auth-error-idle"]')
    expect(idle.exists()).toBe(true)
    expect(idle.attributes('aria-hidden')).toBe('true')
    expect(idle.classes()).toContain('invisible')
    // Every element of the row in its tallest form — the icon, the line of text
    // and a button-shaped stand-in — or the room would be short of the truth.
    expect(idle.find('i.bi-exclamation-circle').exists()).toBe(true)
    expect(idle.find('span.text-truncate').exists()).toBe(true)
    expect(idle.find('span.btn i.bi-info-circle').exists()).toBe(true)
    // A span and not a button: the twin holds room, it does not take focus.
    expect(idle.find('button').exists()).toBe(false)
  })

  it('treats an empty message exactly as no message', () => {
    const wrapper = mount(HilosFormError, {
      props: { message: '', dataId: 'auth-error' },
    })

    expect(wrapper.find('[data-id="auth-error-idle"]').exists()).toBe(true)
    expect(wrapper.find('[data-id="auth-error"]').exists()).toBe(false)
  })

  it('draws the refusal in one truncated line and carries no role', () => {
    const wrapper = mount(HilosFormError, {
      props: { message: 'Incorrect password', dataId: 'auth-error' },
    })

    const row = wrapper.find('[data-id="auth-error"]')
    expect(row.exists()).toBe(true)
    expect(row.attributes('role')).toBeUndefined()
    expect(wrapper.find('[data-id="auth-error-idle"]').exists()).toBe(false)
    const text = row.find('span.flex-grow-1')
    expect(text.text()).toBe('Incorrect password')
    expect(text.classes()).toContain('text-truncate')
  })

  it('opens the whole sentence from the details button', async () => {
    const wrapper = mount(HilosFormError, {
      props: { message: 'A very long refusal', dataId: 'auth-error' },
    })

    const details = wrapper.find('[data-id="auth-error-details"]')
    expect(details.exists()).toBe(true)
    expect(details.attributes('aria-label')).toBe('Show the full message')
    await details.trigger('click')

    const full = document.querySelector('[data-id="auth-error-full"]')
    expect(full?.textContent).toBe('A very long refusal')
  })

  it('closes the panel when the refusal is cleared', async () => {
    const wrapper = mount(HilosFormError, {
      props: { message: 'A very long refusal', dataId: 'auth-error' },
    })
    await wrapper.find('[data-id="auth-error-details"]').trigger('click')
    expect(document.querySelector('[data-id="auth-error-full"]')).not.toBeNull()

    // The form re-arms on the next attempt, and a panel left open would be
    // showing the previous refusal's text.
    await wrapper.setProps({ message: null })
    expect(document.querySelector('[data-id="auth-error-full"]')).toBeNull()
  })
})
