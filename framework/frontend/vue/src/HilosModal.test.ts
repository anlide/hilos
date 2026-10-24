// @vitest-environment jsdom
// jsdom, not the package's happy-dom: a control inside a disabled fieldset
// matches `:disabled` only in jsdom, and that inheritance is what the locked
// body gives and what the focus trap reads.

import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { h, nextTick, ref } from 'vue'
import {
  CONNECTION_INTERACTION_COPY,
  FocusTrap,
  HILOS_CONNECTION_STATUS_TEXT_ID,
  PROTECTED_MODE_INACTIVE,
  RT_STALENESS_FRESH,
  type ConnectionState,
  type HilosConnection,
} from '@hilos/core/base'

import HilosLayout from './HilosLayout.vue'
import HilosModal from './HilosModal.vue'
import LoadingButton from './LoadingButton.vue'

// HilosModal teleports to <body>, so assertions query the document, not the wrapper.
const realClipboard = navigator.clipboard
const written: string[] = []

afterEach(() => {
  document.body.innerHTML = ''
  document.body.classList.remove('modal-open')
  setClipboard(realClipboard)
  written.length = 0
  vi.useRealTimers()
  vi.restoreAllMocks()
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
 * The elements carrying the given data ids, in the order the ids are given.
 *
 * @param ids The `data-id` of each element; every one must be in the document.
 * @returns The elements, one per id.
 */
function byIds(ids: string[]): HTMLElement[] {
  return ids.map((id) => {
    const element = document.querySelector<HTMLElement>(`[data-id="${id}"]`)
    expect(element).not.toBeNull()
    return element as HTMLElement
  })
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

  it('is not busy without the input: the close button, Copy and the body are live', () => {
    recordingClipboard()
    mount(HilosModal, {
      props: { modelValue: true, copyText: 'hilos restore --archive x' },
      slots: { default: '<input data-id="field" />' },
    })
    expect(closeButton()?.disabled).toBe(false)
    expect(
      document.querySelector<HTMLButtonElement>('[data-id="modal-copy"]')
        ?.disabled,
    ).toBe(false)
    expect(
      document.querySelector<HTMLFieldSetElement>('.modal-body > fieldset')
        ?.disabled,
    ).toBe(false)
    expect(byIds(['field'])[0]?.matches(':disabled')).toBe(false)
  })

  it('locks the body fields through one fieldset while busy, keeping the nodes and the draft', async () => {
    const wrapper = mount(HilosModal, {
      props: { modelValue: true },
      slots: {
        default: `<form data-id="form">
          <input data-id="text" />
          <select data-id="choice">
            <option value="first">First</option>
            <option value="second">Second</option>
          </select>
          <textarea data-id="note"></textarea>
          <input type="checkbox" data-id="flag" />
          <input data-id="own" disabled />
        </form>`,
        actions:
          '<button type="button" data-id="footer-cancel">Cancel</button>',
      },
    })
    const ids = ['text', 'choice', 'note', 'flag']
    const fields = byIds(ids)
    const [text, choice, note, flag] = fields as [
      HTMLInputElement,
      HTMLSelectElement,
      HTMLTextAreaElement,
      HTMLInputElement,
    ]
    const [own, footerCancel] = byIds(['own', 'footer-cancel'])
    text.value = 'draft'
    choice.value = 'second'
    note.value = 'a note'
    flag.checked = true
    const fieldset = document.querySelector<HTMLFieldSetElement>(
      '.modal-body > fieldset',
    )
    // No border and no spacing: the lock changes nothing on screen.
    expect(fieldset?.className).toBe('m-0 p-0 border-0')
    expect(fieldset?.contains(byIds(['form'])[0] ?? null)).toBe(true)
    expect(fields.map((field) => field.matches(':disabled'))).toEqual([
      false,
      false,
      false,
      false,
    ])

    await wrapper.setProps({ busy: true })
    expect(
      document.querySelector<HTMLFieldSetElement>('.modal-body > fieldset'),
    ).toBe(fieldset)
    expect(fieldset?.disabled).toBe(true)
    expect(fields.map((field) => field.matches(':disabled'))).toEqual([
      true,
      true,
      true,
      true,
    ])
    // Inherited from the fieldset, never written onto the field itself.
    expect(fields.map((field) => field.hasAttribute('disabled'))).toEqual([
      false,
      false,
      false,
      false,
    ])
    // The footer stands outside the lock: its owner disables its buttons.
    expect(footerCancel?.matches(':disabled')).toBe(false)

    await wrapper.setProps({ busy: false })
    expect(byIds(ids)).toEqual(fields)
    expect(fields.map((field) => field.matches(':disabled'))).toEqual([
      false,
      false,
      false,
      false,
    ])
    expect([text.value, choice.value, note.value, flag.checked]).toEqual([
      'draft',
      'second',
      'a note',
      true,
    ])
    // A field disabled for a reason of its own stays disabled.
    expect(own?.matches(':disabled')).toBe(true)
  })

  it('draws #body in place of the fieldset, handing it the current busy', async () => {
    const busy = ref(false)
    mount({
      components: { HilosModal },
      setup: () => ({ busy }),
      template: `
        <HilosModal :model-value="true" :busy="busy">
          <template #header>
            <h5 class="modal-title mb-0" data-id="head">Theme</h5>
          </template>
          <template #default>
            <p data-id="fallback-only">Never drawn</p>
          </template>
          <template #body="{ busy: locked }">
            <p data-id="said">{{ locked ? 'locked' : 'open' }}</p>
            <input data-id="field" :disabled="locked" />
          </template>
          <template #actions>
            <button type="button" data-id="footer-cancel">Cancel</button>
          </template>
        </HilosModal>
      `,
    })
    await flushPromises()
    const [said, field] = byIds(['said', 'field'])

    expect(said?.parentElement?.classList.contains('modal-body')).toBe(true)
    expect(document.querySelectorAll('.modal-body')).toHaveLength(1)
    expect(document.querySelector('.modal-body fieldset')).toBeNull()
    expect(document.querySelector('[data-id="fallback-only"]')).toBeNull()
    expect(said?.textContent).toBe('open')

    busy.value = true
    await nextTick()
    expect(said?.textContent).toBe('locked')
    expect(field?.matches(':disabled')).toBe(true)

    busy.value = false
    await nextTick()
    expect(said?.textContent).toBe('open')
    expect(byIds(['said', 'field'])).toEqual([said, field])
    expect(field?.matches(':disabled')).toBe(false)

    // The rest of the dialog stands as it did: header, one footer, the
    // scrollable body, the layer.
    expect(document.querySelector('[data-id="head"]')).not.toBeNull()
    const footers = document.querySelectorAll('.modal-footer')
    expect(footers).toHaveLength(1)
    expect(
      footers[0]?.querySelector('[data-id="footer-cancel"]'),
    ).not.toBeNull()
    expect(
      document
        .querySelector('.modal-dialog')
        ?.classList.contains('modal-dialog-scrollable'),
    ).toBe(true)
    expect(layerDepths('[data-id="modal"]')).toEqual(['0'])
  })

  it('lets a submitting LoadingButton in #body lock itself, keeping its look and focus until 300 ms', async () => {
    const busy = ref(false)
    let sent = 0
    mount({
      components: { HilosModal, LoadingButton },
      setup: () => ({
        busy,
        send: () => {
          sent++
          busy.value = true
        },
      }),
      template: `
        <HilosModal :model-value="true" :busy="busy" title="Sign in">
          <template #body="{ busy: locked }">
            <input data-id="field" :disabled="locked" />
            <LoadingButton
              class="btn-primary"
              :loading="busy"
              data-autofocus
              data-id="send"
              @click="send"
              >Continue</LoadingButton
            >
          </template>
        </HilosModal>
      `,
    })
    await flushPromises()
    const [field, send] = byIds(['field', 'send'])
    const spinner = () =>
      document.querySelector('[data-id="loading-button-spinner"]')
    expect(send?.closest('fieldset')?.getAttribute('data-id')).toBe(
      'modal-interaction-scope',
    )
    expect(send?.matches(':disabled')).toBe(false)
    expect(document.activeElement).toBe(send)

    vi.useFakeTimers()
    send?.click()
    await nextTick()
    expect(sent).toBe(1)
    expect(field?.matches(':disabled')).toBe(true)
    expect(send?.getAttribute('aria-busy')).toBe('true')
    expect(send?.getAttribute('aria-disabled')).toBe('true')
    expect(send?.matches(':disabled')).toBe(false)
    expect(spinner()).toBeNull()
    expect(document.activeElement).toBe(send)

    vi.advanceTimersByTime(299)
    await nextTick()
    expect(spinner()).toBeNull()
    expect(send?.classList.contains('disabled')).toBe(false)

    vi.advanceTimersByTime(1)
    await nextTick()
    expect(spinner()).not.toBeNull()
    expect(send?.classList.contains('disabled')).toBe(true)
    expect(send?.matches(':disabled')).toBe(false)

    send?.click()
    await nextTick()
    expect(sent).toBe(1)
    expect(document.activeElement).toBe(send)
  })

  it('turns its own Copy off while busy and back on once idle', async () => {
    recordingClipboard()
    const wrapper = mount(HilosModal, {
      props: { modelValue: true, copyText: 'hilos restore --archive x' },
    })
    const copy = () =>
      document.querySelector<HTMLButtonElement>('[data-id="modal-copy"]')

    await wrapper.setProps({ busy: true })
    expect(copy()?.disabled).toBe(true)
    copy()?.click()
    await flushPromises()
    expect(written).toEqual([])

    await wrapper.setProps({ busy: false })
    expect(copy()?.disabled).toBe(false)
    copy()?.click()
    await flushPromises()
    expect(written).toEqual(['hilos restore --archive x'])
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

  it('keeps the draft and an open confirm step when busy flips', async () => {
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
    expect(field?.matches(':disabled')).toBe(true)
    await wrapper.setProps({ busy: false })
    await flushPromises()
    expect(document.querySelector('[data-id="field"]')).toBe(field)
    expect(field?.matches(':disabled')).toBe(false)
    expect(field?.value).toBe('draft')

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
    expect(document.querySelector('[data-id="field"]')).toBe(field)
    expect(field?.value).toBe('draft')

    // Idle again, Discard closes the dialog as it did before.
    await wrapper.setProps({ busy: false })
    document
      .querySelector<HTMLButtonElement>('[data-id="modal-confirm-discard"]')
      ?.click()
    await flushPromises()
    expect(wrapper.emitted('update:modelValue')?.[0]).toEqual([false])
    expect(wrapper.emitted('cancel')).toHaveLength(1)
  })

  it('cycles Tab over the buttons busy leaves available', async () => {
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

    // The close button and the locked field are out of the cycle: the
    // footer's own button is all that is left to take focus.
    await wrapper.setProps({ busy: true })
    expect(field?.matches(':disabled')).toBe(true)
    save?.focus()
    press(save, 'Tab')
    expect(document.activeElement).toBe(save)
    press(save, 'Tab', true)
    expect(document.activeElement).toBe(save)

    await wrapper.setProps({ busy: false })
    press(save, 'Tab')
    expect(document.activeElement).toBe(closeButton())
    press(closeButton(), 'Tab', true)
    expect(document.activeElement).toBe(save)
  })

  it('keeps Tab on the dialog itself when busy leaves nothing to focus', async () => {
    const wrapper = mount(HilosModal, {
      props: { modelValue: true },
      slots: { default: '<input data-autofocus data-id="field" />' },
    })
    await flushPromises()
    const dialog = document.querySelector<HTMLElement>('[data-id="modal"]')

    await wrapper.setProps({ busy: true })
    dialog?.focus()
    const tab = new KeyboardEvent('keydown', {
      key: 'Tab',
      bubbles: true,
      cancelable: true,
    })
    dialog?.dispatchEvent(tab)
    expect(tab.defaultPrevented).toBe(true)
    expect(document.activeElement).toBe(dialog)
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
    // The marked field is locked, so the dialog itself takes the focus.
    expect(document.activeElement).toBe(
      document.querySelector('[data-id="modal"]'),
    )
    expect(document.body.classList.contains('modal-open')).toBe(true)

    await wrapper.setProps({ modelValue: false })
    await flushPromises()

    expect(document.querySelector('[data-id="modal"]')).toBeNull()
    expect(document.querySelector('.modal-backdrop')).toBeNull()
    expect(document.body.classList.contains('modal-open')).toBe(false)
    expect(document.activeElement).toBe(opener)
  })

  it('puts focus on the marked field again once busy clears with the dialog itself focused', async () => {
    const wrapper = mount(HilosModal, {
      props: { modelValue: true },
      slots: { default: '<input data-autofocus data-id="field" />' },
    })
    await flushPromises()
    const dialog = document.querySelector<HTMLElement>('[data-id="modal"]')
    const field = document.querySelector<HTMLElement>('[data-id="field"]')

    await wrapper.setProps({ busy: true })
    // A step that moved in flight looks for its mark and, the field being
    // locked, lands on the dialog itself.
    dialog?.focus()
    expect(document.activeElement).toBe(dialog)

    await wrapper.setProps({ busy: false })
    await flushPromises()
    expect(document.activeElement).toBe(field)
  })

  it('leaves focus on the control inside the dialog that holds it when busy clears', async () => {
    const wrapper = mount(HilosModal, {
      props: { modelValue: true },
      slots: {
        default: '<input data-autofocus data-id="field" />',
        actions: '<button type="button" data-id="save">Save</button>',
      },
    })
    await flushPromises()
    const save = document.querySelector<HTMLElement>('[data-id="save"]')

    await wrapper.setProps({ busy: true })
    save?.focus()

    await wrapper.setProps({ busy: false })
    await flushPromises()
    expect(document.activeElement).toBe(save)
  })

  it('puts focus on the dialog itself when busy clears under initialFocus dialog', async () => {
    const wrapper = mount(HilosModal, {
      props: { modelValue: true, initialFocus: 'dialog' },
      slots: { default: '<input data-autofocus data-id="field" />' },
    })
    await flushPromises()
    const dialog = document.querySelector<HTMLElement>('[data-id="modal"]')

    await wrapper.setProps({ busy: true })
    dialog?.blur()
    expect(document.activeElement).toBe(document.body)

    await wrapper.setProps({ busy: false })
    await flushPromises()
    expect(document.activeElement).toBe(dialog)
  })

  it('puts focus on the marked field again once busy clears with the page body focused', async () => {
    const wrapper = mount(HilosModal, {
      props: { modelValue: true },
      slots: { default: '<input data-autofocus data-id="field" />' },
    })
    await flushPromises()
    const dialog = document.querySelector<HTMLElement>('[data-id="modal"]')
    const field = document.querySelector<HTMLElement>('[data-id="field"]')

    await wrapper.setProps({ busy: true })
    // A disabled field cannot be blurred; focus the dialog and let go of it.
    dialog?.focus()
    dialog?.blur()
    expect(document.activeElement).toBe(document.body)

    await wrapper.setProps({ busy: false })
    await flushPromises()
    expect(document.activeElement).toBe(field)
  })

  it('leaves focus on a modal standing over this one when busy clears', async () => {
    const lower = mount(HilosModal, {
      props: { modelValue: true, busy: true },
      slots: { default: '<input data-autofocus data-id="lower-field" />' },
    })
    mount(HilosModal, {
      props: { modelValue: true },
      slots: { default: '<input data-autofocus data-id="upper-field" />' },
    })
    await flushPromises()
    const upperField = document.querySelector<HTMLElement>(
      '[data-id="upper-field"]',
    )
    expect(document.activeElement).toBe(upperField)

    await lower.setProps({ busy: false })
    await flushPromises()
    expect(document.activeElement).toBe(upperField)
  })

  it('leaves focus on a control outside the dialog when busy clears', async () => {
    const outside = document.createElement('button')
    document.body.appendChild(outside)

    const wrapper = mount(HilosModal, {
      props: { modelValue: true },
      slots: { default: '<input data-autofocus data-id="field" />' },
    })
    await flushPromises()

    await wrapper.setProps({ busy: true })
    outside.focus()
    expect(document.activeElement).toBe(outside)

    await wrapper.setProps({ busy: false })
    await flushPromises()
    expect(document.activeElement).toBe(outside)
  })

  it('leaves focus on an open confirm step when busy clears', async () => {
    const wrapper = mount(HilosModal, {
      props: { modelValue: true, confirmOnClose: true },
      slots: { default: '<input data-autofocus data-id="field" />' },
    })
    await flushPromises()
    closeButton()?.click()
    await flushPromises()
    const confirm = document.querySelector('[data-id="modal-confirm"]')
    expect(document.activeElement).toBe(confirm)

    await wrapper.setProps({ busy: true })
    await wrapper.setProps({ busy: false })
    await flushPromises()
    expect(document.activeElement).toBe(confirm)
  })

  it('returns focus to the opener when the parent closes the dialog as busy clears', async () => {
    const opener = document.createElement('button')
    document.body.appendChild(opener)
    opener.focus()

    const wrapper = mount(HilosModal, {
      props: { modelValue: true, busy: true },
      slots: { default: '<input data-autofocus data-id="field" />' },
    })
    await flushPromises()

    await wrapper.setProps({ modelValue: false, busy: false })
    await flushPromises()
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

  it('returns focus when unmounted and leaves no queued activation behind', async () => {
    const opener = document.createElement('button')
    document.body.append(opener)
    opener.focus()
    const wrapper = mount(HilosModal, { props: { modelValue: true } })
    await flushPromises()
    expect(document.activeElement).toBe(
      document.querySelector('[data-id="modal"]'),
    )
    wrapper.unmount()
    expect(document.activeElement).toBe(opener)
    const late = mount(HilosModal, { props: { modelValue: true } })
    late.unmount()
    await flushPromises()
    expect(document.activeElement).toBe(opener)
    expect(document.body.classList.contains('modal-open')).toBe(false)
  })
})

/** A live shell source; the real layout owns the availability state and subscriptions. */
function modalFeed() {
  const listeners = new Map<string, Set<(value: never) => void>>()
  const source = {
    state: 'connected' as ConnectionState,
    protectedMode: PROTECTED_MODE_INACTIVE,
    rtStaleness: RT_STALENESS_FRESH,
    reconnectDragging: false,
    firstFrameHeld: false,
    on(event: string, listener: (value: never) => void): () => void {
      const set = listeners.get(event) ?? new Set()
      listeners.set(event, set)
      set.add(listener)
      return () => {
        set.delete(listener)
      }
    },
  }
  return {
    connection: source as unknown as HilosConnection,
    listeners,
    push(state: ConnectionState): void {
      source.state = state
      for (const listener of listeners.get('state') ?? [])
        listener(state as never)
    },
  }
}

/** Mount real teleported content under the shell, including the custom body escape from busy. */
function connectedModal() {
  const feed = modalFeed()
  const open = ref(true)
  const present = ref(true)
  const second = ref(false)
  const allow = ref(false)
  const busy = ref(false)
  const dirty = ref(false)
  const disabled = ref(false)
  const description = ref('validation')
  const clicks = vi.fn()
  const canceled = vi.fn()
  let requestClose = () => {}
  let bodyBusy = false
  const host = document.createElement('div')
  document.body.append(host)
  const wrapper = mount(
    {
      setup: () => () =>
        h(
          HilosLayout,
          {
            connection: feed.connection,
            allowDisconnectedInteraction: allow.value,
          },
          {
            nav: () =>
              h('a', { href: '#nav', 'data-id': 'modal-nav' }, 'Navigation'),
            default: () => [
              h(
                LoadingButton,
                { opensWindow: true, 'data-id': 'modal-open', onClick: clicks },
                () => 'Open',
              ),
              present.value
                ? h(
                    HilosModal,
                    {
                      modelValue: open.value,
                      busy: busy.value,
                      confirmOnClose: dirty.value,
                      title: 'Edit',
                      copyText: 'technical details',
                      onCancel: canceled,
                      'onUpdate:modelValue': (value: boolean) => {
                        open.value = value
                      },
                    },
                    {
                      header: () =>
                        h(
                          'a',
                          {
                            href: '#header',
                            'data-id': 'header-action',
                            onClick: clicks,
                          },
                          'Header action',
                        ),
                      body: ({ busy: locked }: { busy: boolean }) => {
                        bodyBusy = locked
                        return h(
                          'form',
                          { 'data-id': 'modal-form', onSubmit: clicks },
                          [
                            h('input', {
                              'data-autofocus': '',
                              'data-id': 'modal-draft',
                              disabled: disabled.value,
                              'aria-describedby': description.value,
                              onBeforeinput: clicks,
                              onDrop: clicks,
                            }),
                            h('input', {
                              type: 'file',
                              'data-id': 'modal-file',
                            }),
                            h('select', { 'data-id': 'modal-choice' }, [
                              h('option', { value: 'a' }, 'A'),
                              h('option', { value: 'b' }, 'B'),
                            ]),
                            h(
                              'button',
                              {
                                type: 'button',
                                'data-id': 'body-action',
                                onClick: clicks,
                              },
                              'Body action',
                            ),
                            h(
                              'a',
                              {
                                href: '#action',
                                role: 'button',
                                'data-id': 'body-link',
                                onClick: clicks,
                                onKeydown: clicks,
                              },
                              'Link action',
                            ),
                            h(
                              LoadingButton,
                              {
                                loading: busy.value,
                                'data-id': 'modal-save',
                                onClick: clicks,
                              },
                              () => 'Save',
                            ),
                          ],
                        )
                      },
                      actions: ({
                        requestClose: close,
                      }: {
                        requestClose: () => void
                      }) => {
                        requestClose = close
                        return h(
                          'button',
                          {
                            type: 'button',
                            'data-id': 'modal-cancel',
                            onClick: close,
                          },
                          'Cancel',
                        )
                      },
                    },
                  )
                : null,
              second.value
                ? h(HilosModal, {
                    modelValue: true,
                    confirmOnClose: true,
                    title: 'Second',
                  })
                : null,
            ],
          },
        ),
    },
    { attachTo: host },
  )
  return {
    wrapper,
    feed,
    open,
    present,
    second,
    allow,
    busy,
    dirty,
    disabled,
    description,
    clicks,
    canceled,
    close: () => requestClose(),
    bodyBusy: () => bodyBusy,
  }
}

/** The modal test's required DOM surface. */
function modalElement(id: string): HTMLElement {
  return byIds([id])[0]!
}

/** Check the description's reading visibility, independently of aria-describedby. */
function expectConnectionDescription(
  root: HTMLElement,
  blocked: boolean,
): void {
  const description = root.querySelector<HTMLElement>(
    '[data-id="modal-connection-description"]',
  )!
  expect(description).not.toBeNull()
  expect(description.textContent).toBe(CONNECTION_INTERACTION_COPY.unavailable)
  expect(description.hidden).toBe(!blocked)
  if (blocked) expect(getComputedStyle(description).display).not.toBe('none')
  else expect(getComputedStyle(description).display).toBe('none')
}

describe('HilosModal connection interaction (HIL-1651)', () => {
  it('keeps the teleported draft, selection, files, scroll and lifecycle through every outage state', async () => {
    const activate = vi.spyOn(FocusTrap.prototype, 'activate')
    recordingClipboard()
    const shell = connectedModal()
    await flushPromises()
    const root = modalElement('modal')
    expectConnectionDescription(root, false)
    const field = modalElement('modal-draft') as HTMLInputElement
    const file = modalElement('modal-file') as HTMLInputElement
    const files = file.files
    const choice = modalElement('modal-choice') as HTMLSelectElement
    const scope = modalElement('modal-interaction-scope')
    const body = root.querySelector('.modal-body')!
    const layers = layerDepths('.hilos-modal-layer')
    const activations = activate.mock.calls.length
    field.value = 'draft remains'
    field.setSelectionRange(2, 7)
    choice.value = 'b'
    body.scrollTop = 83
    field.focus()
    expect(document.querySelector('main')?.contains(root)).toBe(false)
    for (const state of [
      'connecting',
      'reconnecting',
      'disconnected',
    ] as const) {
      shell.feed.push(state)
      // Controller and binder observe the source before Vue renders.
      shell.close()
      expect(shell.open.value).toBe(true)
      expect(field.matches(':disabled')).toBe(true)
      expect(document.activeElement).toBe(root)
      expect(scope).toBe(modalElement('modal-interaction-scope'))
      expect(shell.bodyBusy()).toBe(false)
      expect(modalElement('modal-open').matches(':disabled')).toBe(true)
      for (const id of [
        'modal-close',
        'modal-copy',
        'modal-cancel',
        'header-action',
        'body-action',
        'body-link',
        'modal-save',
      ]) {
        modalElement(id).dispatchEvent(
          new MouseEvent('click', { bubbles: true, cancelable: true }),
        )
      }
      for (const key of ['Enter', ' ']) press(modalElement('body-link'), key)
      for (const type of ['beforeinput', 'drop']) {
        const event = new Event(type, { bubbles: true, cancelable: true })
        field.dispatchEvent(event)
        expect(event.defaultPrevented).toBe(true)
      }
      modalElement('modal-form').dispatchEvent(
        new Event('submit', { bubbles: true, cancelable: true }),
      )
      press(root, 'Escape')
      root.dispatchEvent(new MouseEvent('click', { bubbles: true }))
      press(root, 'Tab')
      await flushPromises()
      expectConnectionDescription(root, true)
      expect(shell.clicks).not.toHaveBeenCalled()
      expect(shell.canceled).not.toHaveBeenCalled()
      expect(written).toEqual([])
      expect(modalElement('modal-draft')).toBe(field)
      expect(modalElement('modal-file')).toBe(file)
      expect(file.files).toBe(files)
      expect(choice.value).toBe('b')
      expect(field.value).toBe('draft remains')
      expect([field.selectionStart, field.selectionEnd]).toEqual([2, 7])
      expect(body.scrollTop).toBe(83)
      expect(layerDepths('.hilos-modal-layer')).toEqual(layers)
      expect(document.body.classList.contains('modal-open')).toBe(true)
      expect(activate.mock.calls).toHaveLength(activations)
      expect(
        root.querySelector('[data-id="loading-button-spinner"]'),
      ).toBeNull()
    }
    shell.feed.push('connected')
    await flushPromises()
    expectConnectionDescription(root, false)
    expect(field.matches(':disabled')).toBe(false)
    expect(document.activeElement).toBe(field)
    expect(shell.clicks).not.toHaveBeenCalled()
    modalElement('body-action').click()
    expect(shell.clicks).toHaveBeenCalledTimes(1)
    shell.close()
    await flushPromises()
    expect(document.querySelector('[data-id="modal"]')).toBeNull()
    activate.mockRestore()
  })

  it('holds an existing confirm, its local description and its focus until a fresh gesture', async () => {
    const shell = connectedModal()
    shell.dirty.value = true
    await flushPromises()
    shell.close()
    await flushPromises()
    const confirm = modalElement('modal-confirm')
    expectConnectionDescription(modalElement('modal'), false)
    expectConnectionDescription(confirm, false)
    modalElement('modal-confirm-discard').focus()
    shell.feed.push('disconnected')
    await flushPromises()
    expect(document.activeElement).toBe(confirm)
    const root = modalElement('modal')
    const ids = [root, confirm].map((dialog) => {
      const description = dialog.querySelector<HTMLElement>(
        '[data-id="modal-connection-description"]',
      )!
      expect(description.textContent).toBe(
        CONNECTION_INTERACTION_COPY.unavailable,
      )
      expectConnectionDescription(dialog, true)
      expect(description.classList.contains('visually-hidden')).toBe(true)
      expect(
        description.closest('[aria-hidden="true"], [inert], [aria-live]'),
      ).toBeNull()
      expect(dialog.getAttribute('aria-describedby')).toBe(description.id)
      for (const button of dialog.querySelectorAll('button')) {
        expect(button.matches(':disabled')).toBe(true)
        expect(button.getAttribute('aria-describedby')).toContain(
          description.id,
        )
      }
      return description.id
    })
    expect(new Set(ids).size).toBe(2)
    expect(ids).not.toContain(HILOS_CONNECTION_STATUS_TEXT_ID)
    for (const id of ['modal-confirm-cancel', 'modal-confirm-discard'])
      modalElement(id).click()
    press(confirm, 'Escape')
    shell.close()
    await flushPromises()
    expect(modalElement('modal-confirm')).toBe(confirm)
    shell.feed.push('connected')
    await flushPromises()
    expect(modalElement('modal-confirm')).toBe(confirm)
    expectConnectionDescription(modalElement('modal'), false)
    expectConnectionDescription(confirm, false)
    expect(confirm.hasAttribute('aria-describedby')).toBe(false)
    modalElement('modal-confirm-cancel').click()
    await flushPromises()
    expect(document.querySelector('[data-id="modal-confirm"]')).toBeNull()
    shell.close()
    await flushPromises()
    modalElement('modal-confirm-discard').click()
    await flushPromises()
    expect(shell.open.value).toBe(false)
  })

  it('preserves live owner disabled, descriptions and busy without intercepting a different focus choice', async () => {
    const shell = connectedModal()
    await flushPromises()
    shell.feed.push('disconnected')
    shell.disabled.value = true
    shell.description.value = 'new-validation look-only'
    shell.busy.value = true
    await flushPromises()
    const field = modalElement('modal-draft')
    expect(field.getAttribute('aria-describedby')).toContain(
      'new-validation look-only',
    )
    modalElement('modal-nav').focus()
    shell.feed.push('connected')
    await flushPromises()
    expect(document.activeElement).toBe(modalElement('modal-nav'))
    expect(field.matches(':disabled')).toBe(true)
    expect(field.getAttribute('aria-describedby')).toBe(
      'new-validation look-only',
    )
    expect(closeButton()?.disabled).toBe(true)
    expect(
      (modalElement('modal-interaction-scope') as HTMLFieldSetElement).disabled,
    ).toBe(false)
    shell.close()
    expect(shell.open.value).toBe(true)
    shell.busy.value = false
    await flushPromises()
    expect(field.matches(':disabled')).toBe(true)
  })

  it('honors the shell opt-out, allows external close and cleans a replaced root on unmount', async () => {
    const shell = connectedModal()
    await flushPromises()
    const old = modalElement('modal')
    shell.feed.push('disconnected')
    shell.allow.value = true
    await flushPromises()
    expectConnectionDescription(modalElement('modal'), false)
    expect(modalElement('modal-draft').matches(':disabled')).toBe(false)
    modalElement('body-action').click()
    expect(shell.clicks).toHaveBeenCalledTimes(1)
    shell.allow.value = false
    await flushPromises()
    shell.open.value = false
    await flushPromises()
    expect(old.hasAttribute('data-hilos-interaction-region')).toBe(false)
    expect(document.body.classList.contains('modal-open')).toBe(false)
    expect(shell.canceled).not.toHaveBeenCalled()
    shell.open.value = true
    await flushPromises()
    expect(modalElement('modal')).not.toBe(old)
    expect(modalElement('modal-draft').matches(':disabled')).toBe(true)
    shell.present.value = false
    await flushPromises()
    shell.present.value = true
    await flushPromises()
    shell.wrapper.unmount()
    expect(
      [...shell.feed.listeners.values()].every((set) => set.size === 0),
    ).toBe(true)
    shell.feed.push('connected')
    expect(old.hasAttribute('aria-describedby')).toBe(false)
  })

  it('keeps a standalone modal interactive beside an unavailable shell', async () => {
    const shell = connectedModal()
    await flushPromises()
    shell.feed.push('disconnected')
    const standalone = mount(HilosModal, {
      props: { modelValue: true, title: 'Standalone' },
    })
    await flushPromises()
    const root = document.querySelector<HTMLElement>(
      '[aria-label="Standalone"]',
    )!
    const close = root.querySelector<HTMLButtonElement>(
      '[data-id="modal-close"]',
    )!
    expectConnectionDescription(root, false)
    expect(close.matches(':disabled')).toBe(false)
    expect(root.hasAttribute('aria-describedby')).toBe(false)
    close.click()
    await flushPromises()
    expect(standalone.emitted('cancel')).toHaveLength(1)
    expect(shell.canceled).not.toHaveBeenCalled()
  })

  it('gives concurrent instances and their confirm layers four different local descriptions', async () => {
    const shell = connectedModal()
    shell.dirty.value = true
    await flushPromises()
    shell.close()
    await flushPromises()
    shell.second.value = true
    await flushPromises()
    document
      .querySelector<HTMLButtonElement>(
        '[aria-label="Second"] [data-id="modal-close"]',
      )!
      .click()
    await flushPromises()
    shell.feed.push('disconnected')
    const descriptions = [
      ...document.querySelectorAll<HTMLElement>(
        '[data-id="modal-connection-description"]',
      ),
    ]
    expect(descriptions).toHaveLength(4)
    expect(new Set(descriptions.map((node) => node.id)).size).toBe(4)
    for (const node of descriptions)
      expect(
        node.closest('[aria-modal]')?.getAttribute('aria-describedby'),
      ).toBe(node.id)
  })
})
