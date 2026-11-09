// Vue's mount and lifecycle only; the shared registrar owns all expectations.
import { flushPromises, mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import { nextTick } from 'vue'

import {
  registerDateTimePickerFieldCases,
  type DateTimePickerFieldInput,
  type DateTimePickerFieldView,
} from '../../test-support/dateTimePickerFieldCases.js'
import HilosDateTimePicker from './HilosDateTimePicker.vue'

async function mountField(
  input: DateTimePickerFieldInput,
): Promise<DateTimePickerFieldView> {
  const host = document.body.appendChild(document.createElement('div'))
  try {
    const wrapper = mount(HilosDateTimePicker, { attachTo: host, props: input })
    return {
      root: document.body,
      async focus(target) {
        target.focus()
        await nextTick()
      },
      async press(target, key, modifiers = {}) {
        const event = new KeyboardEvent('keydown', {
          key,
          bubbles: true,
          cancelable: true,
          ...modifiers,
        })
        target.dispatchEvent(event)
        await flushPromises()
        return event
      },
      async flush() {
        await flushPromises()
      },
      async update(patch) {
        await wrapper.setProps(patch)
      },
      async unmount() {
        wrapper.unmount()
        host.remove()
      },
    }
  } catch (error) {
    host.remove()
    throw error
  }
}

registerDateTimePickerFieldCases('Vue', { describe, it, expect }, mountField)
