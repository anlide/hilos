// The React entry of HilosDateTimePicker's shared field, calendar and narrow cases
// (../../test-support/dateTimePickerFieldCases.ts,
// ../../test-support/dateTimePickerCalendarCases.ts,
// ../../test-support/dateTimePickerNarrowCases.ts): the steps and expectations
// live there, and this file only renders the real component and drives React's
// lifecycle for them.
import { act, render } from '@testing-library/react'
import { afterAll, beforeAll, describe, expect, it } from 'vitest'

import { registerDateTimePickerCalendarCases } from '../../test-support/dateTimePickerCalendarCases.js'
import {
  registerDateTimePickerFieldCases,
  type DateTimePickerFieldInput,
  type DateTimePickerFieldView,
} from '../../test-support/dateTimePickerFieldCases.js'
import { registerDateTimePickerNarrowCases } from '../../test-support/dateTimePickerNarrowCases.js'
import { HilosDateTimePicker } from '../src/HilosDateTimePicker.js'

const environment = globalThis as typeof globalThis & {
  IS_REACT_ACT_ENVIRONMENT?: boolean
}

let previousActEnvironment: boolean | undefined
let scrollDescriptor: PropertyDescriptor | undefined
let stubbedScroll = false

beforeAll(() => {
  previousActEnvironment = environment.IS_REACT_ACT_ENVIRONMENT
  // Headless controller signals mutate outside React events; RTL act wrappers enable the flag for their own turns.
  environment.IS_REACT_ACT_ENVIRONMENT = false
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
  environment.IS_REACT_ACT_ENVIRONMENT = previousActEnvironment
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

async function mountField(
  input: DateTimePickerFieldInput,
): Promise<DateTimePickerFieldView> {
  const container = document.body.appendChild(document.createElement('div'))
  try {
    let current = input
    const { rerender, unmount } = render(<HilosDateTimePicker {...current} />, {
      container,
    })

    return {
      root: document.body,
      async focus(target) {
        await act(async () => {
          target.focus()
        })
      },
      async press(target, key, modifiers = {}) {
        const event = new KeyboardEvent('keydown', {
          key,
          bubbles: true,
          cancelable: true,
          ...modifiers,
        })
        await act(async () => {
          target.dispatchEvent(event)
        })
        return event
      },
      async flush() {
        await act(async () => {})
      },
      async update(patch) {
        current = { ...current, ...patch }
        rerender(<HilosDateTimePicker {...current} />)
      },
      async unmount() {
        unmount()
        container.remove()
      },
    }
  } catch (error) {
    container.remove()
    throw error
  }
}

registerDateTimePickerFieldCases('React', { describe, it, expect }, mountField)
registerDateTimePickerCalendarCases(
  'React',
  { describe, it, expect },
  mountField,
)
registerDateTimePickerNarrowCases('React', { describe, it, expect }, mountField)
