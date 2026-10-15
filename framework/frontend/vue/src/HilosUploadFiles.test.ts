import {
  createSignal,
  HILOS_UPLOAD_FIELD_CHIP_CANCELING_CLASS,
  type HilosUploadField,
  type HilosUploadFieldFile,
} from '@hilos/core'
import { mount } from '@vue/test-utils'
import { nextTick } from 'vue'
import { describe, expect, it, vi } from 'vitest'

import HilosUploadFiles from './HilosUploadFiles.vue'

function fieldDouble() {
  const files = createSignal<readonly HilosUploadFieldFile[]>([])
  const refusal = createSignal<string | null>(null)
  const disabled = createSignal(false)
  const field: HilosUploadField = {
    files,
    refusal,
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
  return { field, files, refusal, disabled }
}

function file(
  clientUploadId: string,
  reading: HilosUploadFieldFile['reading'],
  extra: Partial<HilosUploadFieldFile> = {},
): HilosUploadFieldFile {
  return {
    clientUploadId,
    filename: `${clientUploadId}.pdf`,
    reading,
    percent: 0,
    canceling: false,
    ...extra,
  }
}

function mountFiles(field: HilosUploadField) {
  return mount(HilosUploadFiles, { props: { field, dataId: 'upload' } })
}

describe('HilosUploadFiles', () => {
  it('draws nothing and takes no room while there are no files', () => {
    const { field } = fieldDouble()
    const wrapper = mountFiles(field)
    expect(wrapper.find('[data-id="upload"]').exists()).toBe(false)
    expect(wrapper.find('[data-id="upload-error-slot"]').exists()).toBe(false)
  })

  it('holds the refusal line from the first file and shows the refusal in it', async () => {
    const { field, files, refusal } = fieldDouble()
    files.set([file('a', 'waiting')])
    const wrapper = mountFiles(field)
    expect(wrapper.find('[data-id="upload"]').classes()).toContain('mb-2')
    expect(
      wrapper.find('[data-id="upload-error-idle"]').attributes('aria-hidden'),
    ).toBe('true')
    refusal.set('File is larger than the allowed size')
    await nextTick()
    expect(wrapper.find('[data-id="upload-error"]').text()).toContain(
      'File is larger than the allowed size',
    )
    expect(
      wrapper.find('[data-id="upload-error-slot"]').attributes('role'),
    ).toBeUndefined()
  })

  it('draws every reading with its icon, its word and its tone', () => {
    const { field, files } = fieldDouble()
    files.set([
      file('a', 'waiting'),
      file('b', 'uploading', { percent: 62 }),
      file('c', 'received'),
      file('d', 'failed'),
    ])
    const chips = mountFiles(field).findAll('[data-id="upload-file"]')
    expect(chips.map((chip) => chip.attributes('data-state'))).toEqual([
      'waiting',
      'uploading',
      'received',
      'failed',
    ])
    const [waiting, uploading, received, failed] = chips
    expect(waiting.find('i.bi-clock').attributes('aria-hidden')).toBe('true')
    expect(waiting.find('.visually-hidden').text()).toBe('Waiting')
    expect(uploading.find('.visually-hidden').text()).toBe('Uploading')
    expect(uploading.find('.hilos-upload-percent').text()).toBe('62%')
    expect(uploading.find('i').exists()).toBe(false)
    expect(received.find('i.bi-paperclip').attributes('aria-hidden')).toBe(
      'true',
    )
    expect(received.find('.visually-hidden').exists()).toBe(false)
    expect(
      failed.find('i.bi-exclamation-circle').attributes('aria-hidden'),
    ).toBe('true')
    expect(failed.find('.visually-hidden').text()).toBe('Failed')
    expect(waiting.classes()).toContain('bg-secondary-subtle')
    expect(received.classes()).toContain('bg-secondary-subtle')
    expect(failed.classes()).toContain('bg-danger-subtle')
    expect(failed.classes()).not.toContain('bg-secondary-subtle')
  })

  it('names each chip and removes it by its own ×', async () => {
    const { field, files } = fieldDouble()
    files.set([file('a', 'received'), file('b', 'waiting')])
    const wrapper = mountFiles(field)
    const name = wrapper.find('[data-id="upload-file-name"]')
    expect(name.text()).toBe('a.pdf')
    expect(name.attributes('title')).toBe('a.pdf')
    expect(name.classes()).toContain('text-truncate')
    const removes = wrapper.findAll('[data-id="upload-file-remove"]')
    expect(removes[1].attributes('aria-label')).toBe('Remove b.pdf')
    expect(removes[1].attributes('type')).toBe('button')
    await removes[1].trigger('click')
    expect(field.remove).toHaveBeenCalledWith('b')
  })

  it('dims a chip whose cancel waits and locks its ×', () => {
    const { field, files } = fieldDouble()
    files.set([file('a', 'uploading', { percent: 54, canceling: true })])
    const wrapper = mountFiles(field)
    expect(wrapper.find('[data-id="upload-file"]').classes()).toContain(
      HILOS_UPLOAD_FIELD_CHIP_CANCELING_CLASS,
    )
    expect(
      wrapper.find('[data-id="upload-file-remove"]').attributes('disabled'),
    ).toBeDefined()
  })

  it('locks every × while the field is disabled', async () => {
    const { field, files, disabled } = fieldDouble()
    files.set([file('a', 'received'), file('b', 'received')])
    const wrapper = mountFiles(field)
    expect(
      wrapper.find('[data-id="upload-file-remove"]').attributes('disabled'),
    ).toBeUndefined()
    disabled.set(true)
    await nextTick()
    const removes = wrapper.findAll('[data-id="upload-file-remove"]')
    expect(
      removes.every((remove) => remove.attributes('disabled') !== undefined),
    ).toBe(true)
  })
})
