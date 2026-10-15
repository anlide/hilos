import {
  createSignal,
  type HilosUploadField,
  type HilosUploadFieldFile,
} from '@hilos/core'
import { TestBed, type ComponentFixture } from '@angular/core/testing'
import { describe, expect, it, vi } from 'vitest'

import { HilosUploadPicker } from '../src/HilosUploadPicker.js'

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

async function draw(
  field: HilosUploadField,
  accept?: string,
): Promise<ComponentFixture<HilosUploadPicker>> {
  const fixture = TestBed.createComponent(HilosUploadPicker)
  fixture.componentRef.setInput('field', field)
  fixture.componentRef.setInput('dataId', 'upload-picker')
  if (accept !== undefined) {
    fixture.componentRef.setInput('accept', accept)
  }
  fixture.detectChanges()
  await fixture.whenStable()
  return fixture
}

function byId(
  fixture: ComponentFixture<HilosUploadPicker>,
  id: string,
): HTMLElement | null {
  return (fixture.nativeElement as HTMLElement).querySelector(
    `[data-id="${id}"]`,
  )
}

describe('HilosUploadPicker', () => {
  it('draws the paperclip over a hidden multiple chooser', async () => {
    const { field } = fieldDouble()
    const fixture = await draw(field, 'image/*,.pdf')
    const button = byId(fixture, 'upload-picker') as HTMLElement
    expect(button.getAttribute('type')).toBe('button')
    expect(button.getAttribute('aria-label')).toBe('Attach files')
    expect(
      button.querySelector('i.bi-paperclip')?.getAttribute('aria-hidden'),
    ).toBe('true')
    const input = byId(fixture, 'upload-picker-input') as HTMLInputElement
    expect(input.type).toBe('file')
    expect(input.multiple).toBe(true)
    expect(input.getAttribute('accept')).toBe('image/*,.pdf')
    expect(input.classList.contains('d-none')).toBe(true)
  })

  it('opens the chooser and hands the chosen files to the field', async () => {
    const { field } = fieldDouble()
    const fixture = await draw(field)
    const input = byId(fixture, 'upload-picker-input') as HTMLInputElement
    const click = vi.spyOn(input, 'click').mockImplementation(() => undefined)
    byId(fixture, 'upload-picker')?.click()
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
    const fixture = await draw(field)
    disabled.set(true)
    fixture.detectChanges()
    await fixture.whenStable()
    expect((byId(fixture, 'upload-picker') as HTMLButtonElement).disabled).toBe(
      true,
    )
  })
})
