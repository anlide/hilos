import {
  createSignal,
  HILOS_UPLOAD_FIELD_COPY,
  type HilosUploadField,
  type HilosUploadFieldFile,
} from '@hilos/core'
import { mount } from '@vue/test-utils'
import { nextTick } from 'vue'
import { describe, expect, it, vi } from 'vitest'

import HilosUploadDrop from './HilosUploadDrop.vue'

function fieldDouble() {
  const dragging = createSignal(false)
  const alert = createSignal('')
  const status = createSignal('')
  const field: HilosUploadField = {
    files: createSignal<readonly HilosUploadFieldFile[]>([]),
    refusal: createSignal<string | null>(null),
    busy: createSignal(false),
    ready: createSignal<readonly string[]>([]),
    disabled: createSignal(false),
    dragging,
    alert,
    status,
    setDisabled: vi.fn(),
    dragEnter: vi.fn(),
    dragLeave: vi.fn(),
    drop: vi.fn(),
    add: vi.fn(),
    remove: vi.fn(),
    dispose: vi.fn(),
  }
  return { field, dragging, alert, status }
}

function mountDrop(field: HilosUploadField) {
  return mount(HilosUploadDrop, {
    props: { field, dataId: 'upload' },
    slots: { default: '<textarea data-id="content"></textarea>' },
  })
}

/** A drag or paste event carrying what a browser would hand over. */
function transferEvent(
  type: string,
  key: 'dataTransfer' | 'clipboardData',
  transfer: { types: string[]; files: File[] },
): Event {
  const event = new Event(type, { bubbles: true, cancelable: true })
  Object.defineProperty(event, key, { value: transfer })
  return event
}

const photo = new File(['x'], 'site-photo.jpg', { type: 'image/jpeg' })

describe('HilosUploadDrop', () => {
  it('wraps the content with two live regions that are always there', () => {
    const { field } = fieldDouble()
    const wrapper = mountDrop(field)
    const root = wrapper.find('[data-id="upload"]')
    expect(root.classes()).toContain('position-relative')
    expect(root.find('[data-id="content"]').exists()).toBe(true)
    const alert = wrapper.find('[data-id="upload-alert"]')
    expect(alert.attributes('role')).toBe('alert')
    expect(alert.attributes('aria-live')).toBe('assertive')
    expect(alert.classes()).toContain('visually-hidden')
    const status = wrapper.find('[data-id="upload-status"]')
    expect(status.attributes('role')).toBe('status')
    expect(status.attributes('aria-live')).toBe('polite')
    expect(status.classes()).toContain('visually-hidden')
    expect(wrapper.find('[data-id="upload-overlay"]').exists()).toBe(false)
  })

  it('draws the overlay only while the field says files are dragged over', async () => {
    const { field, dragging } = fieldDouble()
    const wrapper = mountDrop(field)
    dragging.set(true)
    await nextTick()
    const overlay = wrapper.find('[data-id="upload-overlay"]')
    expect(overlay.text()).toBe(HILOS_UPLOAD_FIELD_COPY.overlay)
    expect(overlay.classes()).toContain('hilos-upload-overlay')
    expect(overlay.find('i.bi-paperclip').attributes('aria-hidden')).toBe(
      'true',
    )
    dragging.set(false)
    await nextTick()
    expect(wrapper.find('[data-id="upload-overlay"]').exists()).toBe(false)
  })

  it('speaks what the field says in its regions', async () => {
    const { field, alert, status } = fieldDouble()
    const wrapper = mountDrop(field)
    alert.set('site-backup.zip: File is larger than the allowed size')
    status.set('notes.txt uploaded')
    await nextTick()
    expect(wrapper.find('[data-id="upload-alert"]').text()).toBe(
      'site-backup.zip: File is larger than the allowed size',
    )
    expect(wrapper.find('[data-id="upload-status"]').text()).toBe(
      'notes.txt uploaded',
    )
  })

  it('takes a file drag into the field and keeps the browser from opening it', () => {
    const { field } = fieldDouble()
    const wrapper = mountDrop(field)
    const content = wrapper.find('[data-id="content"]').element
    const transfer = { types: ['Files'], files: [photo] }
    const enter = transferEvent('dragenter', 'dataTransfer', transfer)
    const over = transferEvent('dragover', 'dataTransfer', transfer)
    const leave = transferEvent('dragleave', 'dataTransfer', transfer)
    const drop = transferEvent('drop', 'dataTransfer', transfer)
    content.dispatchEvent(enter)
    content.dispatchEvent(over)
    content.dispatchEvent(leave)
    content.dispatchEvent(drop)
    expect(field.dragEnter).toHaveBeenCalledOnce()
    expect(field.dragLeave).toHaveBeenCalledOnce()
    expect(field.drop).toHaveBeenCalledWith([photo])
    expect(
      [enter, over, leave, drop].every((event) => event.defaultPrevented),
    ).toBe(true)
  })

  it('leaves a drag without files alone', () => {
    const { field } = fieldDouble()
    const wrapper = mountDrop(field)
    const content = wrapper.find('[data-id="content"]').element
    const transfer = { types: ['text/plain'], files: [] }
    const events = ['dragenter', 'dragover', 'dragleave', 'drop'].map((type) =>
      transferEvent(type, 'dataTransfer', transfer),
    )
    events.forEach((event) => content.dispatchEvent(event))
    expect(field.dragEnter).not.toHaveBeenCalled()
    expect(field.dragLeave).not.toHaveBeenCalled()
    expect(field.drop).not.toHaveBeenCalled()
    expect(events.some((event) => event.defaultPrevented)).toBe(false)
  })

  it('takes pasted files and lets a paste of text through', () => {
    const { field } = fieldDouble()
    const wrapper = mountDrop(field)
    const content = wrapper.find('[data-id="content"]').element
    const text = transferEvent('paste', 'clipboardData', {
      types: ['text/plain'],
      files: [],
    })
    content.dispatchEvent(text)
    expect(text.defaultPrevented).toBe(false)
    expect(field.add).not.toHaveBeenCalled()
    const files = transferEvent('paste', 'clipboardData', {
      types: ['Files'],
      files: [photo],
    })
    content.dispatchEvent(files)
    expect(files.defaultPrevented).toBe(true)
    expect(field.add).toHaveBeenCalledWith([photo])
  })

  it('leaves the field to the page that created it', () => {
    const { field } = fieldDouble()
    mountDrop(field).unmount()
    expect(field.dispose).not.toHaveBeenCalled()
  })
})
