import {
  createSignal,
  HILOS_UPLOAD_FIELD_CHIP_CANCELING_CLASS,
  type HilosUploadField,
  type HilosUploadFieldFile,
} from '@hilos/core'
import { act, cleanup, fireEvent, render } from '@testing-library/react'
import { afterEach, describe, expect, it, vi } from 'vitest'

import { HilosUploadFiles } from '../src/HilosUploadFiles.js'

afterEach(() => {
  cleanup()
})

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

function byId(id: string): HTMLElement | null {
  return document.querySelector(`[data-id="${id}"]`)
}

function allById(id: string): HTMLElement[] {
  return Array.from(document.querySelectorAll(`[data-id="${id}"]`))
}

function draw(field: HilosUploadField) {
  return render(<HilosUploadFiles field={field} dataId="upload" />)
}

describe('HilosUploadFiles', () => {
  it('draws nothing and takes no room while there are no files', () => {
    const { field } = fieldDouble()
    draw(field)
    expect(byId('upload')).toBeNull()
    expect(byId('upload-error-slot')).toBeNull()
  })

  it('holds the refusal line from the first file and shows the refusal in it', () => {
    const { field, files, refusal } = fieldDouble()
    files.set([file('a', 'waiting')])
    draw(field)
    expect(byId('upload')?.classList.contains('mb-2')).toBe(true)
    expect(byId('upload-error-idle')?.getAttribute('aria-hidden')).toBe('true')
    act(() => refusal.set('File is larger than the allowed size'))
    expect(byId('upload-error')?.textContent).toContain(
      'File is larger than the allowed size',
    )
    expect(byId('upload-error-slot')?.getAttribute('role')).toBeNull()
  })

  it('draws every reading with its icon, its word and its tone', () => {
    const { field, files } = fieldDouble()
    files.set([
      file('a', 'waiting'),
      file('b', 'uploading', { percent: 62 }),
      file('c', 'received'),
      file('d', 'failed'),
    ])
    draw(field)
    const chips = allById('upload-file')
    expect(chips.map((chip) => chip.getAttribute('data-state'))).toEqual([
      'waiting',
      'uploading',
      'received',
      'failed',
    ])
    const [waiting, uploading, received, failed] = chips
    expect(
      waiting.querySelector('i.bi-clock')?.getAttribute('aria-hidden'),
    ).toBe('true')
    expect(waiting.querySelector('.visually-hidden')?.textContent).toBe(
      'Waiting',
    )
    expect(uploading.querySelector('.visually-hidden')?.textContent).toBe(
      'Uploading',
    )
    expect(uploading.querySelector('.hilos-upload-percent')?.textContent).toBe(
      '62%',
    )
    expect(uploading.querySelector('i')).toBeNull()
    expect(
      received.querySelector('i.bi-paperclip')?.getAttribute('aria-hidden'),
    ).toBe('true')
    expect(received.querySelector('.visually-hidden')).toBeNull()
    expect(
      failed
        .querySelector('i.bi-exclamation-circle')
        ?.getAttribute('aria-hidden'),
    ).toBe('true')
    expect(failed.querySelector('.visually-hidden')?.textContent).toBe('Failed')
    expect(waiting.classList.contains('bg-secondary-subtle')).toBe(true)
    expect(received.classList.contains('bg-secondary-subtle')).toBe(true)
    expect(failed.classList.contains('bg-danger-subtle')).toBe(true)
    expect(failed.classList.contains('bg-secondary-subtle')).toBe(false)
  })

  it('names each chip and removes it by its own ×', () => {
    const { field, files } = fieldDouble()
    files.set([file('a', 'received'), file('b', 'waiting')])
    draw(field)
    const name = byId('upload-file-name') as HTMLElement
    expect(name.textContent).toBe('a.pdf')
    expect(name.getAttribute('title')).toBe('a.pdf')
    expect(name.classList.contains('text-truncate')).toBe(true)
    const removes = allById('upload-file-remove')
    expect(removes[1].getAttribute('aria-label')).toBe('Remove b.pdf')
    expect(removes[1].getAttribute('type')).toBe('button')
    fireEvent.click(removes[1])
    expect(field.remove).toHaveBeenCalledWith('b')
  })

  it('dims a chip whose cancel waits and locks its ×', () => {
    const { field, files } = fieldDouble()
    files.set([file('a', 'uploading', { percent: 54, canceling: true })])
    draw(field)
    expect(
      byId('upload-file')?.classList.contains(
        HILOS_UPLOAD_FIELD_CHIP_CANCELING_CLASS,
      ),
    ).toBe(true)
    expect((byId('upload-file-remove') as HTMLButtonElement).disabled).toBe(
      true,
    )
  })

  it('locks every × while the field is disabled', () => {
    const { field, files, disabled } = fieldDouble()
    files.set([file('a', 'received'), file('b', 'received')])
    draw(field)
    expect((byId('upload-file-remove') as HTMLButtonElement).disabled).toBe(
      false,
    )
    act(() => disabled.set(true))
    expect(
      allById('upload-file-remove').every(
        (remove) => (remove as HTMLButtonElement).disabled,
      ),
    ).toBe(true)
  })
})
