// The Vue entry of the shared upload picker cases.
// Steps and assertions live in test-support; this adapter owns only lifecycle.
import type { HilosUploadField } from '@hilos/core/base'
import { flushPromises, mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import { nextTick } from 'vue'

import { registerUploadPickerCases } from '../../test-support/uploadPickerCases.js'
import type { UploadView } from '../../test-support/uploadFieldTestSupport.js'
import HilosUploadPicker from './HilosUploadPicker.vue'

/** Mount the real component on its own host and flush Vue's signal updates. */
async function mountUploadPicker(
  field: HilosUploadField,
  accept?: string,
): Promise<UploadView> {
  const host = document.body.appendChild(document.createElement('div'))
  try {
    const wrapper = mount(HilosUploadPicker, {
      attachTo: host,
      props: { field, dataId: 'upload-picker', accept },
    })
    return {
      root: host,
      async update(change) {
        change()
        await nextTick()
      },
      async flush() {
        await nextTick()
        await flushPromises()
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

registerUploadPickerCases(
  'Vue',
  { describe, it, expect, vi },
  mountUploadPicker,
)
