import { mount } from '@vue/test-utils'
import { afterEach, describe, expect, it } from 'vitest'

import HilosFormError from './HilosFormError.vue'

// The detail panel is a HilosModal, which teleports to <body>: assertions about
// it query the document, not the wrapper.
afterEach(() => {
  document.body.innerHTML = ''
  document.body.classList.remove('modal-open')
})

/** Put a clipboard in the document, or take it away — plain http has none. */
function setClipboard(clipboard: Clipboard | undefined): void {
  Object.defineProperty(navigator, 'clipboard', {
    value: clipboard,
    configurable: true,
  })
}

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
    expect(details.attributes('aria-label')).toBe('Show error details')
    await details.trigger('click')

    const full = document.querySelector('[data-id="auth-error-full"]')
    expect(full?.textContent?.trim()).toBe('A very long refusal')
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

  it('makes the slot a live region only when asked, and never the row', () => {
    const quiet = mount(HilosFormError, {
      props: { message: 'Refused', dataId: 'auth-error' },
    })
    const quietSlot = quiet.find('[data-id="auth-error-slot"]')
    expect(quietSlot.attributes('role')).toBeUndefined()
    expect(quietSlot.attributes('aria-live')).toBeUndefined()

    const loud = mount(HilosFormError, {
      props: { message: 'Refused', dataId: 'auth-error', announce: true },
    })
    const loudSlot = loud.find('[data-id="auth-error-slot"]')
    expect(loudSlot.attributes('role')).toBe('alert')
    expect(loudSlot.attributes('aria-live')).toBe('assertive')
    // The region is the slot; a role on the row too would say it twice.
    expect(
      loud.find('[data-id="auth-error"]').attributes('role'),
    ).toBeUndefined()
  })

  it('draws the class name inside the details button, and only there', () => {
    const typed = mount(HilosFormError, {
      props: { message: 'Refused', dataId: 'e', errorType: 'PDOException' },
    })
    const type = typed.find('[data-id="e-details"] [data-id="e-type"]')
    expect(type.exists()).toBe(true)
    expect(type.text()).toBe('PDOException')

    const plain = mount(HilosFormError, {
      props: { message: 'Refused', dataId: 'e' },
    })
    expect(plain.find('[data-id="e-type"]').exists()).toBe(false)

    // The twin holds the room of the bare icon, not of a name.
    const idle = mount(HilosFormError, {
      props: { message: null, dataId: 'e', errorType: 'PDOException' },
    })
    expect(idle.find('[data-id="e-type"]').exists()).toBe(false)
  })

  it('gives the details button and its twin the same classes', () => {
    const shown = mount(HilosFormError, {
      props: { message: 'Refused', dataId: 'e' },
    })
    const idle = mount(HilosFormError, {
      props: { message: null, dataId: 'e' },
    })

    const button = shown.find('[data-id="e-details"]').classes()
    const twin = idle.find('[data-id="e-idle"] span.btn').classes()
    expect(button).toEqual(twin)
    expect(button).toContain('btn-link')
    expect(button).toContain('text-decoration-none')
  })

  it('shows the original text under its class name in the panel', async () => {
    const wrapper = mount(HilosFormError, {
      props: {
        message: 'Could not save',
        dataId: 'e',
        errorType: 'PDOException',
        errorDetail: 'SQLSTATE[23000]',
      },
    })
    await wrapper.find('[data-id="e-details"]').trigger('click')

    expect(document.querySelector('.modal-title')?.textContent).toContain(
      'Error details',
    )
    expect(document.querySelector('[data-id="e-close"]')).not.toBeNull()
    expect(document.querySelector('[data-id="e-detail"]')?.textContent).toBe(
      'PDOException\nSQLSTATE[23000]',
    )
  })

  it('draws no original-text block without one', async () => {
    const wrapper = mount(HilosFormError, {
      props: { message: 'Refused', dataId: 'e' },
    })
    await wrapper.find('[data-id="e-details"]').trigger('click')

    expect(document.querySelector('[data-id="e-full"]')).not.toBeNull()
    expect(document.querySelector('[data-id="e-close"]')).not.toBeNull()
    expect(document.querySelector('[data-id="e-detail"]')).toBeNull()
  })

  it('offers Copy only when given something to copy', async () => {
    // Copy stands only where there is a clipboard to write to.
    const realClipboard = navigator.clipboard
    setClipboard({ writeText: async () => {} } as unknown as Clipboard)
    const bare = mount(HilosFormError, {
      props: { message: 'Refused', dataId: 'e' },
    })
    await bare.find('[data-id="e-details"]').trigger('click')
    expect(document.querySelector('[data-id="modal-copy"]')).toBeNull()
    bare.unmount()
    document.body.innerHTML = ''

    const copied = mount(HilosFormError, {
      props: { message: 'Refused', dataId: 'e', copyText: 'Refused' },
    })
    await copied.find('[data-id="e-details"]').trigger('click')
    expect(document.querySelector('[data-id="modal-copy"]')).not.toBeNull()
    setClipboard(realClipboard)
  })
})
