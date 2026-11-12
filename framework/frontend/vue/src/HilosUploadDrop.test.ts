// The Vue entry of the shared upload drop cases.
// Steps and assertions live in test-support; this adapter owns only lifecycle.
import type { HilosUploadField } from '@hilos/core/base'
import { flushPromises, mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import { nextTick } from 'vue'

import { registerUploadDropCases } from '../../test-support/uploadDropCases.js'
import type { UploadView } from '../../test-support/uploadFieldTestSupport.js'
import HilosUploadDrop from './HilosUploadDrop.vue'

/** Mount the real component on its own host and flush Vue's signal updates. */
async function mountUploadDrop(field: HilosUploadField): Promise<UploadView> {
  const host = document.body.appendChild(document.createElement('div'))
  try {
    const wrapper = mount(HilosUploadDrop, {
      attachTo: host,
      props: { field, dataId: 'upload' },
      slots: { default: '<textarea data-id="content"></textarea>' },
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

registerUploadDropCases('Vue', { describe, it, expect, vi }, mountUploadDrop)
