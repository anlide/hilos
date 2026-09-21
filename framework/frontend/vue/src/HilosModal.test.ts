import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils'
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

// Vitest runs afterEach hooks last-in-first-out: unmount teleporting wrappers
// before the cleanup above removes their DOM anchors.
enableAutoUnmount(afterEach)

/** Put a clipboard in the document, or take it away — plain http has none. */
function setClipboard(clipboard: Clipboard | undefined): void {
  Object.defineProperty(navigator, 'clipboard', {
    value: clipboard,
    configurable: true,
  })
}

/**
 * The modal layer depths of every element a selector matches, in DOM order.
 *
 * @param selector The elements to read, each of which must be a modal layer.
 * @returns The value of `--hilos-modal-depth` on each of them.
 */
function layerDepths(selector: string): string[] {
  return [...document.querySelectorAll<HTMLElement>(selector)].map(
    (element) => {
      expect(element.classList.contains('hilos-modal-layer')).toBe(true)
      return element.style.getPropertyValue('--hilos-modal-depth')
    },
  )
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

  it('keeps scroll locked when an open modal contains a closed modal', () => {
    mount({
      components: { HilosModal },
      template: `
        <HilosModal :model-value="true">
          <HilosModal :model-value="false" />
        </HilosModal>
      `,
    })

    expect(document.body.classList.contains('modal-open')).toBe(true)
  })

  it('keeps scroll locked when one of two open modals closes', async () => {
    const first = mount(HilosModal, { props: { modelValue: true } })
    mount(HilosModal, { props: { modelValue: true } })

    await first.setProps({ modelValue: false })

    expect(document.body.classList.contains('modal-open')).toBe(true)
  })

  it('stands a lone modal on layer 0, backdrop and dialog alike', () => {
    mount(HilosModal, { props: { modelValue: true, title: 'Edit' } })

    expect(layerDepths('.modal-backdrop')).toEqual(['0'])
    expect(layerDepths('[data-id="modal"]')).toEqual(['0'])
  })

  it('stands a modal opened inside an open modal on layer 1', async () => {
    mount({
      components: { HilosModal },
      template: `
        <HilosModal :model-value="true" title="Sign in">
          <HilosModal :model-value="true" title="Error details" />
        </HilosModal>
      `,
    })
    await flushPromises()

    expect(layerDepths('.modal-backdrop').sort()).toEqual(['0', '1'])
    expect(layerDepths('[aria-label="Sign in"]')).toEqual(['0'])
    expect(layerDepths('[aria-label="Error details"]')).toEqual(['1'])
  })

  it('puts the confirm step on the layer of its own modal', async () => {
    mount({
      components: { HilosModal },
      template: `
        <HilosModal :model-value="true" title="Sign in">
          <HilosModal :model-value="true" title="Edit" confirm-on-close />
        </HilosModal>
      `,
    })
    document
      .querySelector<HTMLButtonElement>(
        '[aria-label="Edit"] [data-id="modal-close"]',
      )
      ?.click()
    await flushPromises()

    expect(layerDepths('[data-id="modal-confirm"]')).toEqual(['1'])
  })

  it('opens the next modal over a lone one on layer 1 again after the upper closed', async () => {
    mount(HilosModal, { props: { modelValue: true, title: 'Sign in' } })
    const upper = mount(HilosModal, {
      props: { modelValue: true, title: 'Error details' },
    })

    await upper.setProps({ modelValue: false })
    await upper.setProps({ modelValue: true })

    expect(layerDepths('[aria-label="Error details"]')).toEqual(['1'])
  })

  it('closes only the upper layer on Escape pressed in it', async () => {
    mount({
      components: { HilosModal },
      data: () => ({ signInOpen: true, detailsOpen: true }),
      template: `
        <HilosModal v-model="signInOpen" title="Sign in">
          <HilosModal v-model="detailsOpen" title="Error details" />
        </HilosModal>
      `,
    })

    document
      .querySelector('[aria-label="Error details"]')
      ?.dispatchEvent(
        new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }),
      )
    await flushPromises()

    expect(layerDepths('[data-id="modal"]')).toEqual(['0'])
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

  it('stands wide when the surface asks for it, and keeps the confirm step narrow', async () => {
    const wrapper = mount(HilosModal, {
      props: { modelValue: true, confirmOnClose: true },
    })
    document
      .querySelector<HTMLButtonElement>('[data-id="modal-close"]')
      ?.click()
    await wrapper.vm.$nextTick()

    let dialogs = document.querySelectorAll('.modal-dialog')
    expect(dialogs).toHaveLength(2)
    dialogs.forEach((dialog) => {
      expect(dialog.classList.contains('modal-lg')).toBe(false)
    })

    await wrapper.setProps({ size: 'wide' })
    dialogs = document.querySelectorAll('.modal-dialog')
    expect(dialogs[0]?.classList.contains('modal-lg')).toBe(true)
    expect(dialogs[1]?.classList.contains('modal-lg')).toBe(false)
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

  it('focuses a marked child when the dialog opens', async () => {
    mount(HilosModal, {
      props: { modelValue: true },
      slots: {
        default: '<input data-autofocus data-id="field" />',
      },
    })
    await flushPromises()
    expect(document.activeElement).toBe(
      document.querySelector('[data-id="field"]'),
    )
  })

  it('focuses the dialog when initialFocus is dialog', async () => {
    mount(HilosModal, {
      props: { modelValue: true, initialFocus: 'dialog' },
    })
    await flushPromises()
    expect(document.activeElement).toBe(
      document.querySelector('[data-id="modal"]'),
    )
  })

  it('focuses the confirm dialog, not Discard, on the discard-confirm step', async () => {
    const wrapper = mount(HilosModal, {
      props: { modelValue: true, confirmOnClose: true },
    })
    document
      .querySelector<HTMLButtonElement>('[data-id="modal-close"]')
      ?.click()
    await wrapper.vm.$nextTick()
    await flushPromises()
    expect(document.activeElement).toBe(
      document.querySelector('[data-id="modal-confirm"]'),
    )
    expect(document.activeElement).not.toBe(
      document.querySelector('[data-id="modal-confirm-discard"]'),
    )
  })
})
