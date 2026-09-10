import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, describe, expect, it } from 'vitest'

import HilosModal from './HilosModal.vue'

// HilosModal teleports to <body>, so assertions query the document, not the wrapper.
const realClipboard = navigator.clipboard
const written: string[] = []

afterEach(() => {
  document.body.innerHTML = ''
  document.body.classList.remove('modal-open')
  setClipboard(realClipboard)
  written.length = 0
})

/** Put a clipboard in the document, or take it away — plain http has none. */
function setClipboard(clipboard: Clipboard | undefined): void {
  Object.defineProperty(navigator, 'clipboard', {
    value: clipboard,
    configurable: true,
  })
}

/** A clipboard that records what was written to it. */
function recordingClipboard(): void {
  setClipboard({
    writeText: async (text: string) => {
      written.push(text)
    },
  } as unknown as Clipboard)
}

describe('HilosModal', () => {
  it('renders nothing when closed', () => {
    mount(HilosModal, { props: { modelValue: false } })
    expect(document.querySelector('[data-id="modal"]')).toBeNull()
  })

  it('renders the dialog and backdrop when open', () => {
    mount(HilosModal, { props: { modelValue: true, title: 'Edit' } })
    expect(document.querySelector('[data-id="modal"]')).not.toBeNull()
    expect(document.querySelector('.modal-backdrop')).not.toBeNull()
  })

  it('names the dialog from ariaLabelledby when it carries no visible title', () => {
    mount(HilosModal, {
      props: {
        modelValue: true,
        ariaLabel: 'Sign in',
        ariaLabelledby: 'body-heading',
      },
    })
    const dialog = document.querySelector('[data-id="modal"]')
    expect(dialog?.getAttribute('aria-labelledby')).toBe('body-heading')
    // The fallback name stays put: a surface carrying no such heading leaves
    // aria-labelledby resolving to nothing, and the name falls back to it.
    expect(dialog?.getAttribute('aria-label')).toBe('Sign in')
  })

  it('drops ariaLabelledby when the dialog has a visible title', () => {
    mount(HilosModal, {
      props: {
        modelValue: true,
        title: 'Edit',
        ariaLabelledby: 'body-heading',
      },
    })
    const dialog = document.querySelector('[data-id="modal"]')
    expect(dialog?.getAttribute('aria-labelledby')).toBeNull()
    expect(dialog?.getAttribute('aria-label')).toBe('Edit')
  })

  it('renders no footer at all when the dialog declares no actions', () => {
    mount(HilosModal, { props: { modelValue: true } })
    expect(document.querySelector('.modal-footer')).toBeNull()
  })

  it('renders the footer with exactly the declared actions', () => {
    mount(HilosModal, {
      props: { modelValue: true },
      slots: {
        actions: '<button type="button" data-id="only-one">Close</button>',
      },
    })
    const footer = document.querySelector('.modal-footer')
    expect(footer).not.toBeNull()
    expect(footer?.querySelectorAll('button')).toHaveLength(1)
    expect(footer?.querySelector('[data-id="only-one"]')).not.toBeNull()
  })

  it('closes via the close button when not guarding', async () => {
    const wrapper = mount(HilosModal, { props: { modelValue: true } })
    document
      .querySelector<HTMLButtonElement>('[data-id="modal-close"]')
      ?.click()
    await wrapper.vm.$nextTick()
    expect(wrapper.emitted('update:modelValue')?.[0]).toEqual([false])
    expect(wrapper.emitted('cancel')).toHaveLength(1)
  })

  it('gives both dialogs a scrollable body and a narrow-screen sheet', async () => {
    const wrapper = mount(HilosModal, {
      props: { modelValue: true, confirmOnClose: true },
    })
    document
      .querySelector<HTMLButtonElement>('[data-id="modal-close"]')
      ?.click()
    await wrapper.vm.$nextTick()

    const dialogs = document.querySelectorAll('.modal-dialog')
    expect(dialogs).toHaveLength(2)
    dialogs.forEach((dialog) => {
      expect(dialog.classList.contains('modal-dialog-scrollable')).toBe(true)
      expect(dialog.classList.contains('hilos-modal-sheet')).toBe(true)
    })
  })

  it('draws a footer with just the copy button when there are no actions', () => {
    recordingClipboard()
    mount(HilosModal, {
      props: { modelValue: true, copyText: 'hilos restore --archive x' },
    })
    const footer = document.querySelector('.modal-footer')
    expect(footer).not.toBeNull()
    expect(footer?.querySelectorAll('button')).toHaveLength(1)
    expect(footer?.querySelector('[data-id="modal-copy"]')).not.toBeNull()
  })

  it('draws no copy button when there is nothing to copy', () => {
    recordingClipboard()
    mount(HilosModal, { props: { modelValue: true } })
    expect(document.querySelector('[data-id="modal-copy"]')).toBeNull()
  })

  it('draws no copy button when the document has no clipboard', () => {
    // Over plain http there is none, and a button that silently does nothing
    // is worse than no button at all.
    setClipboard(undefined)
    mount(HilosModal, {
      props: { modelValue: true, copyText: 'hilos restore --archive x' },
    })
    expect(document.querySelector('[data-id="modal-copy"]')).toBeNull()
  })

  it('writes the text to the clipboard and says it did', async () => {
    recordingClipboard()
    mount(HilosModal, {
      props: { modelValue: true, copyText: 'hilos restore --archive x' },
    })
    const button = document.querySelector<HTMLButtonElement>(
      '[data-id="modal-copy"]',
    )
    expect(button?.textContent?.trim()).toBe('Copy')

    button?.click()
    await flushPromises()
    expect(written).toEqual(['hilos restore --archive x'])
    expect(
      document.querySelector('[data-id="modal-copy"]')?.textContent?.trim(),
    ).toBe('Copied')
  })

  it('says Copy again the next time the dialog is opened', async () => {
    recordingClipboard()
    const wrapper = mount(HilosModal, {
      props: { modelValue: true, copyText: 'hilos restore --archive x' },
    })
    document.querySelector<HTMLButtonElement>('[data-id="modal-copy"]')?.click()
    await flushPromises()

    await wrapper.setProps({ modelValue: false })
    await wrapper.setProps({ modelValue: true })
    expect(
      document.querySelector('[data-id="modal-copy"]')?.textContent?.trim(),
    ).toBe('Copy')
  })

  it('raises a confirm step before closing a dirty modal, then discards', async () => {
    const wrapper = mount(HilosModal, {
      props: { modelValue: true, confirmOnClose: true },
    })
    document
      .querySelector<HTMLButtonElement>('[data-id="modal-close"]')
      ?.click()
    await wrapper.vm.$nextTick()
    expect(document.querySelector('[data-id="modal-confirm"]')).not.toBeNull()
    expect(wrapper.emitted('update:modelValue')).toBeUndefined()

    document
      .querySelector<HTMLButtonElement>('[data-id="modal-confirm-discard"]')
      ?.click()
    await wrapper.vm.$nextTick()
    expect(wrapper.emitted('update:modelValue')?.[0]).toEqual([false])
  })
})
