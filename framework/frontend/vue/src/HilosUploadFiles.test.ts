// The Vue entry of the shared upload files cases.
// Steps and assertions live in test-support; this adapter owns only lifecycle.
import type { HilosUploadField } from '@hilos/core/base'
import { flushPromises, mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import { nextTick } from 'vue'

import { registerUploadFilesCases } from '../../test-support/uploadFilesCases.js'
import type { UploadView } from '../../test-support/uploadFieldTestSupport.js'
import HilosUploadFiles from './HilosUploadFiles.vue'

/** Mount the real component on its own host and flush Vue's signal updates. */
async function mountUploadFiles(field: HilosUploadField): Promise<UploadView> {
  const host = document.body.appendChild(document.createElement('div'))
  try {
    const wrapper = mount(HilosUploadFiles, {
      attachTo: host,
      props: { field, dataId: 'upload' },
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

registerUploadFilesCases('Vue', { describe, it, expect, vi }, mountUploadFiles)
