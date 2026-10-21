import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils'
import { afterEach, describe, expect, it } from 'vitest'
import { ref } from 'vue'

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

/** The header close button of the open dialog. */
function closeButton(): HTMLButtonElement | null {
  return document.querySelector<HTMLButtonElement>('[data-id="modal-close"]')
}

/**
 * Press a key on an element the way a keyboard does: a bubbling keydown.
 *
 * @param target The element the key is pressed on.
 * @param key The key, as `KeyboardEvent.key` names it.
 * @param shiftKey Whether Shift is held.
 */
function press(target: Element | null, key: string, shiftKey = false): void {
  target?.dispatchEvent(
    new KeyboardEvent('keydown', { key, shiftKey, bubbles: true }),
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

  it('stands the confirm step one layer above its own modal', async () => {
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

    expect(layerDepths('[data-id="modal-confirm"]')).toEqual(['2'])
    expect(layerDepths('.modal-backdrop').sort()).toEqual(['0', '1', '2'])
  })

  it('stands the confirm step on layer 1 over a lone modal', async () => {
    mount(HilosModal, {
      props: { modelValue: true, title: 'Edit', confirmOnClose: true },
    })
    document
      .querySelector<HTMLButtonElement>('[data-id="modal-close"]')
      ?.click()
    await flushPromises()

    expect(layerDepths('[data-id="modal-confirm"]')).toEqual(['1'])
    expect(layerDepths('.modal-backdrop').sort()).toEqual(['0', '1'])
  })

  it('releases the confirm step layer on keep editing', async () => {
    mount(HilosModal, {
      props: { modelValue: true, title: 'Edit', confirmOnClose: true },
    })
    document
      .querySelector<HTMLButtonElement>('[data-id="modal-close"]')
      ?.click()
    await flushPromises()

    document
      .querySelector<HTMLButtonElement>('[data-id="modal-confirm-cancel"]')
      ?.click()
    await flushPromises()

    expect(layerDepths('.modal-backdrop')).toEqual(['0'])
    expect(document.querySelector('[data-id="modal-confirm"]')).toBeNull()

    mount(HilosModal, {
      props: { modelValue: true, title: 'Details' },
    })
    await flushPromises()

    expect(layerDepths('[aria-label="Details"]')).toEqual(['1'])
  })

  it('releases the confirm step layer on discard', async () => {
    mount(HilosModal, {
      props: { modelValue: true, title: 'Edit', confirmOnClose: true },
    })
    document
      .querySelector<HTMLButtonElement>('[data-id="modal-close"]')
      ?.click()
    await flushPromises()

    document
      .querySelector<HTMLButtonElement>('[data-id="modal-confirm-discard"]')
      ?.click()
    await flushPromises()

    expect(layerDepths('.modal-backdrop')).toEqual(['0'])
    expect(document.querySelector('[data-id="modal-confirm"]')).toBeNull()

    mount(HilosModal, {
      props: { modelValue: true, title: 'Details' },
    })
    await flushPromises()

    expect(layerDepths('[aria-label="Details"]')).toEqual(['1'])
  })

  it('releases both modal and confirm step layers on discard when modal closes', async () => {
    const editOpen = ref(true)
    const detailsOpen = ref(false)
    mount({
      components: { HilosModal },
      setup: () => ({ editOpen, detailsOpen }),
      template: `
        <HilosModal v-model="editOpen" title="Edit" confirm-on-close />
        <HilosModal v-model="detailsOpen" title="Details" />
      `,
    })
    document
      .querySelector<HTMLButtonElement>('[data-id="modal-close"]')
      ?.click()
    await flushPromises()

    document
      .querySelector<HTMLButtonElement>('[data-id="modal-confirm-discard"]')
      ?.click()
    await flushPromises()

    expect(layerDepths('.modal-backdrop')).toEqual([])
    expect(document.querySelector('[data-id="modal-confirm"]')).toBeNull()

    detailsOpen.value = true
    await flushPromises()

    expect(layerDepths('[aria-label="Details"]')).toEqual(['0'])
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

  it('shapes both footers with hilos-button-row and no column reverse', async () => {
    const wrapper = mount(HilosModal, {
      props: { modelValue: true, confirmOnClose: true },
      slots: {
        actions: '<button type="button">Save</button>',
      },
    })
    document
      .querySelector<HTMLButtonElement>('[data-id="modal-close"]')
      ?.click()
    await wrapper.vm.$nextTick()

    const footers = document.querySelectorAll('.modal-footer')
    expect(footers).toHaveLength(2)
    footers.forEach((footer) => {
      expect(footer.classList.contains('hilos-button-row')).toBe(true)
      expect(footer.classList.contains('flex-column-reverse')).toBe(false)
    })
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

  it('is not busy without the input: the close button is enabled', () => {
    mount(HilosModal, { props: { modelValue: true } })
    expect(closeButton()?.disabled).toBe(false)
  })

  it('refuses every close gesture while busy and closes again once idle', async () => {
    const wrapper = mount(HilosModal, {
      props: { modelValue: true, confirmOnClose: true },
      slots: {
        actions: `<template #actions="{ requestClose }">
          <button type="button" data-id="footer-cancel" @click="requestClose()">Cancel</button>
        </template>`,
      },
    })
    await wrapper.setProps({ busy: true })

    const close = closeButton()
    expect(close?.disabled).toBe(true)
    expect(close?.classList.contains('btn-close')).toBe(true)
    expect(close?.getAttribute('aria-label')).toBe('Close')

    close?.click()
    press(document.querySelector('[data-id="modal"]'), 'Escape')
    document
      .querySelector<HTMLElement>('[data-id="modal"]')
      ?.dispatchEvent(new MouseEvent('click', { bubbles: true }))
    document
      .querySelector<HTMLButtonElement>('[data-id="footer-cancel"]')
      ?.click()
    await flushPromises()

    expect(wrapper.emitted('update:modelValue')).toBeUndefined()
    expect(wrapper.emitted('cancel')).toBeUndefined()
    expect(document.querySelector('[data-id="modal-confirm"]')).toBeNull()
    expect(document.querySelector('[data-id="modal"]')).not.toBeNull()

    await wrapper.setProps({ busy: false })
    expect(closeButton()?.disabled).toBe(false)

    // Idle, the dirty draft goes through the same Discard changes? step.
    closeButton()?.click()
    await flushPromises()
    expect(document.querySelector('[data-id="modal-confirm"]')).not.toBeNull()
    expect(wrapper.emitted('update:modelValue')).toBeUndefined()

    document
      .querySelector<HTMLButtonElement>('[data-id="modal-confirm-discard"]')
      ?.click()
    await flushPromises()
    expect(wrapper.emitted('update:modelValue')?.[0]).toEqual([false])
    expect(wrapper.emitted('cancel')).toHaveLength(1)
  })

  it('closes on the close button again once busy clears, with no confirm guard', async () => {
    const wrapper = mount(HilosModal, {
      props: { modelValue: true, busy: true },
    })
    closeButton()?.click()
    await flushPromises()
    expect(wrapper.emitted('update:modelValue')).toBeUndefined()

    await wrapper.setProps({ busy: false })
    // Nothing refused while busy is replayed when it clears.
    expect(wrapper.emitted('update:modelValue')).toBeUndefined()

    closeButton()?.click()
    await flushPromises()
    expect(wrapper.emitted('update:modelValue')?.[0]).toEqual([false])
    expect(wrapper.emitted('cancel')).toHaveLength(1)
  })

  it('keeps the draft, the focus and an open confirm step when busy flips', async () => {
    const wrapper = mount(HilosModal, {
      props: { modelValue: true, confirmOnClose: true },
      slots: { default: '<input data-autofocus data-id="field" />' },
    })
    await flushPromises()
    const field = document.querySelector<HTMLInputElement>('[data-id="field"]')
    if (field) {
      field.value = 'draft'
    }

    await wrapper.setProps({ busy: true })
    await flushPromises()
    expect(document.activeElement).toBe(field)
    await wrapper.setProps({ busy: false })
    await flushPromises()
    expect(document.activeElement).toBe(field)
    expect(
      document.querySelector<HTMLInputElement>('[data-id="field"]')?.value,
    ).toBe('draft')

    closeButton()?.click()
    await flushPromises()
    const confirm = document.querySelector('[data-id="modal-confirm"]')
    expect(document.activeElement).toBe(confirm)

    await wrapper.setProps({ busy: true })
    press(confirm, 'Escape')
    document
      .querySelector<HTMLButtonElement>('[data-id="modal-confirm-discard"]')
      ?.click()
    await flushPromises()

    expect(document.querySelector('[data-id="modal-confirm"]')).toBe(confirm)
    expect(document.activeElement).toBe(confirm)
    expect(wrapper.emitted('update:modelValue')).toBeUndefined()
    expect(
      document.querySelector<HTMLInputElement>('[data-id="field"]')?.value,
    ).toBe('draft')
  })

  it('leaves the disabled close button out of the Tab cycle', async () => {
    const wrapper = mount(HilosModal, {
      props: { modelValue: true },
      slots: {
        default: '<input data-autofocus data-id="field" />',
        actions: '<button type="button" data-id="save">Save</button>',
      },
    })
    await flushPromises()
    const field = document.querySelector<HTMLElement>('[data-id="field"]')
    const save = document.querySelector<HTMLElement>('[data-id="save"]')

    save?.focus()
    press(save, 'Tab')
    expect(document.activeElement).toBe(closeButton())

    await wrapper.setProps({ busy: true })
    save?.focus()
    press(save, 'Tab')
    expect(document.activeElement).toBe(field)

    press(field, 'Tab', true)
    expect(document.activeElement).toBe(save)
  })

  it('closes when the parent sets it closed while busy, releasing scroll and focus', async () => {
    const opener = document.createElement('button')
    document.body.appendChild(opener)
    opener.focus()

    const wrapper = mount(HilosModal, {
      props: { modelValue: true, busy: true },
      slots: { default: '<input data-autofocus data-id="field" />' },
    })
    await flushPromises()
    expect(document.activeElement).toBe(
      document.querySelector('[data-id="field"]'),
    )
    expect(document.body.classList.contains('modal-open')).toBe(true)

    await wrapper.setProps({ modelValue: false })
    await flushPromises()

    expect(document.querySelector('[data-id="modal"]')).toBeNull()
    expect(document.querySelector('.modal-backdrop')).toBeNull()
    expect(document.body.classList.contains('modal-open')).toBe(false)
    expect(document.activeElement).toBe(opener)
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
