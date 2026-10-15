import {
  createSignal,
  HILOS_UPLOAD_FIELD_CHIP_CANCELING_CLASS,
  type HilosUploadField,
  type HilosUploadFieldFile,
} from '@hilos/core'
import { TestBed, type ComponentFixture } from '@angular/core/testing'
import { afterEach, describe, expect, it, vi } from 'vitest'

import { HilosUploadFiles } from '../src/HilosUploadFiles.js'

afterEach(() => {
  document.body.classList.remove('modal-open')
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

async function draw(
  field: HilosUploadField,
): Promise<ComponentFixture<HilosUploadFiles>> {
  const fixture = TestBed.createComponent(HilosUploadFiles)
  fixture.componentRef.setInput('field', field)
  fixture.componentRef.setInput('dataId', 'upload')
  fixture.detectChanges()
  await fixture.whenStable()
  return fixture
}

async function settle(fixture: ComponentFixture<HilosUploadFiles>) {
  fixture.detectChanges()
  await fixture.whenStable()
}

function allById(
  fixture: ComponentFixture<HilosUploadFiles>,
  id: string,
): HTMLElement[] {
  return Array.from(
    (fixture.nativeElement as HTMLElement).querySelectorAll<HTMLElement>(
      `[data-id="${id}"]`,
    ),
  )
}

function byId(
  fixture: ComponentFixture<HilosUploadFiles>,
  id: string,
): HTMLElement | null {
  return allById(fixture, id)[0] ?? null
}

describe('HilosUploadFiles', () => {
  it('draws nothing and takes no room while there are no files', async () => {
    const { field } = fieldDouble()
    const fixture = await draw(field)
    expect(byId(fixture, 'upload')).toBeNull()
    expect(byId(fixture, 'upload-error-slot')).toBeNull()
  })

  it('holds the refusal line from the first file and shows the refusal in it', async () => {
    const { field, files, refusal } = fieldDouble()
    files.set([file('a', 'waiting')])
    const fixture = await draw(field)
    expect(byId(fixture, 'upload')?.classList.contains('mb-2')).toBe(true)
    expect(
      byId(fixture, 'upload-error-idle')?.getAttribute('aria-hidden'),
    ).toBe('true')
    refusal.set('File is larger than the allowed size')
    await settle(fixture)
    expect(byId(fixture, 'upload-error')?.textContent).toContain(
      'File is larger than the allowed size',
    )
    expect(byId(fixture, 'upload-error-slot')?.getAttribute('role')).toBeNull()
  })

  it('draws every reading with its icon, its word and its tone', async () => {
    const { field, files } = fieldDouble()
    files.set([
      file('a', 'waiting'),
      file('b', 'uploading', { percent: 62 }),
      file('c', 'received'),
      file('d', 'failed'),
    ])
    const fixture = await draw(field)
    const chips = allById(fixture, 'upload-file')
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

  it('names each chip and removes it by its own ×', async () => {
    const { field, files } = fieldDouble()
    files.set([file('a', 'received'), file('b', 'waiting')])
    const fixture = await draw(field)
    const name = byId(fixture, 'upload-file-name') as HTMLElement
    expect(name.textContent).toBe('a.pdf')
    expect(name.getAttribute('title')).toBe('a.pdf')
    expect(name.classList.contains('text-truncate')).toBe(true)
    const removes = allById(fixture, 'upload-file-remove')
    expect(removes[1].getAttribute('aria-label')).toBe('Remove b.pdf')
    expect(removes[1].getAttribute('type')).toBe('button')
    removes[1].click()
    expect(field.remove).toHaveBeenCalledWith('b')
  })

  it('dims a chip whose cancel waits and locks its ×', async () => {
    const { field, files } = fieldDouble()
    files.set([file('a', 'uploading', { percent: 54, canceling: true })])
    const fixture = await draw(field)
    expect(
      byId(fixture, 'upload-file')?.classList.contains(
        HILOS_UPLOAD_FIELD_CHIP_CANCELING_CLASS,
      ),
    ).toBe(true)
    expect(
      (byId(fixture, 'upload-file-remove') as HTMLButtonElement).disabled,
    ).toBe(true)
  })

  it('locks every × while the field is disabled', async () => {
    const { field, files, disabled } = fieldDouble()
    files.set([file('a', 'received'), file('b', 'received')])
    const fixture = await draw(field)
    expect(
      (byId(fixture, 'upload-file-remove') as HTMLButtonElement).disabled,
    ).toBe(false)
    disabled.set(true)
    await settle(fixture)
    expect(
      allById(fixture, 'upload-file-remove').every(
        (remove) => (remove as HTMLButtonElement).disabled,
      ),
    ).toBe(true)
  })
})
