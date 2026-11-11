// The React entry of HilosDateTimePicker's shared field cases
// (../../test-support/dateTimePickerFieldCases.ts): the steps and expectations
// live there, and this file only renders the real component and drives React's
// lifecycle for them.
import { act, render } from '@testing-library/react'
import { afterAll, beforeAll, describe, expect, it } from 'vitest'

import {
  registerDateTimePickerFieldCases,
  type DateTimePickerFieldInput,
  type DateTimePickerFieldView,
} from '../../test-support/dateTimePickerFieldCases.js'
import { HilosDateTimePicker } from '../src/HilosDateTimePicker.js'

const environment = globalThis as typeof globalThis & {
  IS_REACT_ACT_ENVIRONMENT?: boolean
}

let previousActEnvironment: boolean | undefined

beforeAll(() => {
  previousActEnvironment = environment.IS_REACT_ACT_ENVIRONMENT
  // Headless controller signals mutate outside React events; RTL act wrappers enable the flag for their own turns.
  environment.IS_REACT_ACT_ENVIRONMENT = false
})

afterAll(() => {
  environment.IS_REACT_ACT_ENVIRONMENT = previousActEnvironment
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
