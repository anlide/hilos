import {
  createSignal,
  type HilosUploadField,
  type HilosUploadFieldFile,
} from '@hilos/core'
import { mount } from '@vue/test-utils'
import { nextTick } from 'vue'
import { describe, expect, it, vi } from 'vitest'

import HilosUploadPicker from './HilosUploadPicker.vue'

function fieldDouble() {
  const disabled = createSignal(false)
  const field: HilosUploadField = {
    files: createSignal<readonly HilosUploadFieldFile[]>([]),
    refusal: createSignal<string | null>(null),
    busy: createSignal(false),
    ready: createSignal<readonly string[]>([]),
    disabled,
    dragging: createSignal(false),
    alert: createSignal(''),
    status: createSignal(''),
    setDisabled: vi.fn(),
    dragEnter: vi.fn(),
    dragLeave: vi.fn(),
    drop: vi.fn(),
    add: vi.fn(),
    remove: vi.fn(),
    dispose: vi.fn(),
  }
  return { field, disabled }
}

describe('HilosUploadPicker', () => {
  it('draws the paperclip over a hidden multiple chooser', () => {
    const { field } = fieldDouble()
    const wrapper = mount(HilosUploadPicker, {
      props: { field, dataId: 'upload-picker', accept: 'image/*,.pdf' },
    })
    const button = wrapper.find('[data-id="upload-picker"]')
    expect(button.attributes('type')).toBe('button')
    expect(button.attributes('aria-label')).toBe('Attach files')
    expect(button.find('i.bi-paperclip').attributes('aria-hidden')).toBe('true')
    const input = wrapper.find('[data-id="upload-picker-input"]')
    expect(input.attributes('type')).toBe('file')
    expect(input.attributes('multiple')).toBeDefined()
    expect(input.attributes('accept')).toBe('image/*,.pdf')
    expect(input.classes()).toContain('d-none')
  })

  it('opens the chooser and hands the chosen files to the field', async () => {
    const { field } = fieldDouble()
    const wrapper = mount(HilosUploadPicker, {
      props: { field, dataId: 'upload-picker' },
    })
    const input = wrapper.find('[data-id="upload-picker-input"]')
      .element as HTMLInputElement
    const click = vi.spyOn(input, 'click').mockImplementation(() => undefined)
    await wrapper.find('[data-id="upload-picker"]').trigger('click')
    expect(click).toHaveBeenCalledOnce()

    const chosen = [new File(['x'], 'notes.txt')]
    const emptied = vi.fn()
    Object.defineProperty(input, 'files', { value: chosen })
    Object.defineProperty(input, 'value', { get: () => '', set: emptied })
    input.dispatchEvent(new Event('change'))
    expect(field.add).toHaveBeenCalledWith(chosen)
    expect(emptied).toHaveBeenCalledWith('')
  })

  it('is inert while the field is disabled', async () => {
    const { field, disabled } = fieldDouble()
    const wrapper = mount(HilosUploadPicker, {
      props: { field, dataId: 'upload-picker' },
    })
    disabled.set(true)
    await nextTick()
    expect(
      wrapper.find('[data-id="upload-picker"]').attributes('disabled'),
    ).toBeDefined()
  })
})
