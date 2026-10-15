import {
  createSignal,
  type HilosUploadField,
  type HilosUploadFieldFile,
} from '@hilos/core'
import { act, cleanup, fireEvent, render } from '@testing-library/react'
import { afterEach, describe, expect, it, vi } from 'vitest'

import { HilosUploadPicker } from '../src/HilosUploadPicker.js'

afterEach(() => {
  cleanup()
})

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

function byId(id: string): HTMLElement | null {
  return document.querySelector(`[data-id="${id}"]`)
}

describe('HilosUploadPicker', () => {
  it('draws the paperclip over a hidden multiple chooser', () => {
    const { field } = fieldDouble()
    render(
      <HilosUploadPicker
        field={field}
        dataId="upload-picker"
        accept="image/*,.pdf"
      />,
    )
    const button = byId('upload-picker') as HTMLElement
    expect(button.getAttribute('type')).toBe('button')
    expect(button.getAttribute('aria-label')).toBe('Attach files')
    expect(
      button.querySelector('i.bi-paperclip')?.getAttribute('aria-hidden'),
    ).toBe('true')
    const input = byId('upload-picker-input') as HTMLInputElement
    expect(input.type).toBe('file')
    expect(input.multiple).toBe(true)
    expect(input.getAttribute('accept')).toBe('image/*,.pdf')
    expect(input.classList.contains('d-none')).toBe(true)
  })

  it('opens the chooser and hands the chosen files to the field', () => {
    const { field } = fieldDouble()
    render(<HilosUploadPicker field={field} dataId="upload-picker" />)
    const input = byId('upload-picker-input') as HTMLInputElement
    const click = vi.spyOn(input, 'click').mockImplementation(() => undefined)
    fireEvent.click(byId('upload-picker') as HTMLElement)
    expect(click).toHaveBeenCalledOnce()

    const chosen = [new File(['x'], 'notes.txt')]
    const emptied = vi.fn()
    Object.defineProperty(input, 'files', { value: chosen })
    Object.defineProperty(input, 'value', { get: () => '', set: emptied })
    fireEvent.change(input)
    expect(field.add).toHaveBeenCalledWith(chosen)
    expect(emptied).toHaveBeenCalledWith('')
  })

  it('is inert while the field is disabled', () => {
    const { field, disabled } = fieldDouble()
    render(<HilosUploadPicker field={field} dataId="upload-picker" />)
    act(() => disabled.set(true))
    expect((byId('upload-picker') as HTMLButtonElement).disabled).toBe(true)
  })
})
