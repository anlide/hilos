// Angular's mount and lifecycle only; the shared registrar owns all expectations.
// The component is created directly: a host declared in this file would not
// have its signal inputs visible to TestBed.
import { type ComponentFixture, TestBed } from '@angular/core/testing'
import { describe, expect, it } from 'vitest'

import {
  registerDateTimePickerFieldCases,
  type DateTimePickerFieldInput,
  type DateTimePickerFieldView,
} from '../../test-support/dateTimePickerFieldCases.js'
import { HilosDateTimePicker } from '../src/HilosDateTimePicker.js'

/**
 * Write each provided input. An absent optional field is left unset: passing
 * undefined is not a value the component's input accepts.
 *
 * @param fixture The mounted field.
 * @param patch The inputs to apply.
 */
function apply(
  fixture: ComponentFixture<HilosDateTimePicker>,
  patch: Partial<DateTimePickerFieldInput>,
): void {
  if (patch.picker !== undefined) {
    fixture.componentRef.setInput('picker', patch.picker)
  }
  if (patch.id !== undefined) {
    fixture.componentRef.setInput('id', patch.id)
  }
  if (patch.label !== undefined) {
    fixture.componentRef.setInput('label', patch.label)
  }
  if (patch.describedBy !== undefined) {
    fixture.componentRef.setInput('describedBy', patch.describedBy)
  }
  if (patch.dataId !== undefined) {
    fixture.componentRef.setInput('dataId', patch.dataId)
  }
}

/**
 * Mount the real field. A mount that throws destroys what it had already created.
 *
 * @param input What the component is mounted with.
 * @returns The mounted view.
 */
async function mountField(
  input: DateTimePickerFieldInput,
): Promise<DateTimePickerFieldView> {
  const fixture = TestBed.createComponent(HilosDateTimePicker)
  let alive = true
  const unmount = (): void => {
    if (!alive) return
    alive = false
    fixture.destroy()
    fixture.nativeElement.remove()
  }
  // The shared cases call flush once more after unmount. detectChanges on a
  // destroyed fixture throws, so a finished view has nothing left to draw.
  const settle = async (): Promise<void> => {
    if (!alive) return
    fixture.detectChanges()
    await fixture.whenStable()
  }
  try {
    apply(fixture, input)
    await settle()
    return {
      root: document.body,
      async focus(target) {
        target.focus()
        await settle()
      },
      async press(target, key, modifiers = {}) {
        const event = new KeyboardEvent('keydown', {
          key,
          bubbles: true,
          cancelable: true,
          ...modifiers,
        })
        target.dispatchEvent(event)
        await settle()
        return event
      },
      flush: settle,
      async update(patch) {
        apply(fixture, patch)
        await settle()
      },
      async unmount() {
        unmount()
      },
    }
  } catch (error) {
    unmount()
    throw error
  }
}

registerDateTimePickerFieldCases(
  'Angular',
  { describe, it, expect },
  mountField,
)
