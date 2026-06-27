import { mount } from '@vue/test-utils'
import { afterEach, describe, expect, it } from 'vitest'

import HilosModal from './HilosModal.vue'

// HilosModal teleports to <body>, so assertions query the document, not the wrapper.
afterEach(() => {
  document.body.innerHTML = ''
  document.body.classList.remove('modal-open')
})

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

  it('emits ok from the default OK button', async () => {
    const wrapper = mount(HilosModal, { props: { modelValue: true } })
    document
      .querySelector<HTMLButtonElement>('.modal-footer .btn-primary')
      ?.click()
    await wrapper.vm.$nextTick()
    expect(wrapper.emitted('ok')).toHaveLength(1)
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
