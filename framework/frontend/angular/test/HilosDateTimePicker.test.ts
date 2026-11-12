// The Angular entry of HilosDateTimePicker's shared field, calendar and narrow
// cases (../../test-support/dateTimePickerFieldCases.ts,
// ../../test-support/dateTimePickerCalendarCases.ts,
// ../../test-support/dateTimePickerNarrowCases.ts): the steps and expectations
// live there, and this file only mounts the real component and drives
// Angular's lifecycle for them. The component is created directly: a host
// declared in this file would not have its signal inputs visible to TestBed.
import { type ComponentFixture, TestBed } from '@angular/core/testing'
import { afterAll, beforeAll, describe, expect, it } from 'vitest'

import { registerDateTimePickerCalendarCases } from '../../test-support/dateTimePickerCalendarCases.js'
import {
  registerDateTimePickerFieldCases,
  type DateTimePickerFieldInput,
  type DateTimePickerFieldView,
} from '../../test-support/dateTimePickerFieldCases.js'
import { registerDateTimePickerNarrowCases } from '../../test-support/dateTimePickerNarrowCases.js'
import { HilosDateTimePicker } from '../src/HilosDateTimePicker.js'

let scrollDescriptor: PropertyDescriptor | undefined
let stubbedScroll = false

beforeAll(() => {
  scrollDescriptor = Object.getOwnPropertyDescriptor(
    HTMLElement.prototype,
    'scrollIntoView',
  )
  // jsdom draws no layout and so implements no scrollIntoView.
  if (typeof HTMLElement.prototype.scrollIntoView !== 'function') {
    Object.defineProperty(HTMLElement.prototype, 'scrollIntoView', {
      configurable: true,
      writable: true,
      value() {},
    })
    stubbedScroll = true
  }
})

afterAll(() => {
  if (!stubbedScroll) return
  if (scrollDescriptor === undefined) {
    Reflect.deleteProperty(HTMLElement.prototype, 'scrollIntoView')
  } else {
    Object.defineProperty(
      HTMLElement.prototype,
      'scrollIntoView',
      scrollDescriptor,
    )
  }
})

/**
 * Write each provided input. A required input left out of the patch keeps its
 * value. An optional one present in the patch is written even when undefined:
 * that is how a case takes the form's description or selector away.
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
  if (Object.hasOwn(patch, 'describedBy')) {
    fixture.componentRef.setInput('describedBy', patch.describedBy)
  }
  if (Object.hasOwn(patch, 'dataId')) {
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
registerDateTimePickerCalendarCases(
  'Angular',
  { describe, it, expect },
  mountField,
)
registerDateTimePickerNarrowCases(
  'Angular',
  { describe, it, expect },
  mountField,
)
