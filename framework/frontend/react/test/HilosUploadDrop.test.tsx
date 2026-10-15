import {
  createSignal,
  HILOS_UPLOAD_FIELD_COPY,
  type HilosUploadField,
  type HilosUploadFieldFile,
} from '@hilos/core'
import { act, cleanup, render } from '@testing-library/react'
import { afterEach, describe, expect, it, vi } from 'vitest'

import { HilosUploadDrop } from '../src/HilosUploadDrop.js'

afterEach(() => {
  cleanup()
})

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

function byId(id: string): HTMLElement | null {
  return document.querySelector(`[data-id="${id}"]`)
}

function draw(field: HilosUploadField) {
  return render(
    <HilosUploadDrop field={field} dataId="upload">
      <textarea data-id="content"></textarea>
    </HilosUploadDrop>,
  )
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
    draw(field)
    const root = byId('upload') as HTMLElement
    expect(root.classList.contains('position-relative')).toBe(true)
    expect(root.contains(byId('content'))).toBe(true)
    const alert = byId('upload-alert') as HTMLElement
    expect(alert.getAttribute('role')).toBe('alert')
    expect(alert.getAttribute('aria-live')).toBe('assertive')
    expect(alert.classList.contains('visually-hidden')).toBe(true)
    const status = byId('upload-status') as HTMLElement
    expect(status.getAttribute('role')).toBe('status')
    expect(status.getAttribute('aria-live')).toBe('polite')
    expect(status.classList.contains('visually-hidden')).toBe(true)
    expect(byId('upload-overlay')).toBeNull()
  })

  it('draws the overlay only while the field says files are dragged over', () => {
    const { field, dragging } = fieldDouble()
    draw(field)
    act(() => dragging.set(true))
    const overlay = byId('upload-overlay') as HTMLElement
    expect(overlay.textContent).toBe(HILOS_UPLOAD_FIELD_COPY.overlay)
    expect(overlay.classList.contains('hilos-upload-overlay')).toBe(true)
    expect(
      overlay.querySelector('i.bi-paperclip')?.getAttribute('aria-hidden'),
    ).toBe('true')
    act(() => dragging.set(false))
    expect(byId('upload-overlay')).toBeNull()
  })

  it('speaks what the field says in its regions', () => {
    const { field, alert, status } = fieldDouble()
    draw(field)
    act(() => {
      alert.set('site-backup.zip: File is larger than the allowed size')
      status.set('notes.txt uploaded')
    })
    expect(byId('upload-alert')?.textContent).toBe(
      'site-backup.zip: File is larger than the allowed size',
    )
    expect(byId('upload-status')?.textContent).toBe('notes.txt uploaded')
  })

  it('takes a file drag into the field and keeps the browser from opening it', () => {
    const { field } = fieldDouble()
    draw(field)
    const content = byId('content') as HTMLElement
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
    draw(field)
    const content = byId('content') as HTMLElement
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
    draw(field)
    const content = byId('content') as HTMLElement
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
    draw(field).unmount()
    expect(field.dispose).not.toHaveBeenCalled()
  })
})
