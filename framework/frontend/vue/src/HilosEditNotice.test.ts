// Vue lifecycle for the shared EditNotice scenarios.
import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import { nextTick } from 'vue'

import {
  registerEditNoticeCases,
  type EditNoticeInput,
  type EditNoticeView,
} from '../../test-support/editNoticeCases.js'
import HilosEditNotice from './HilosEditNotice.vue'

/** Mount the real component, including its teleported details panel. */
async function mountEditNotice(
  input: EditNoticeInput,
): Promise<EditNoticeView> {
  const host = document.body.appendChild(document.createElement('div'))
  try {
    const wrapper = mount(HilosEditNotice, { attachTo: host, props: input })
    return {
      root: document.body,
      async update(patch) {
        await wrapper.setProps(patch)
      },
      async click(target) {
        target.click()
        await nextTick()
      },
      async flush() {
        await nextTick()
      },
      async unmount() {
        try {
          wrapper.unmount()
        } finally {
          host.remove()
        }
      },
    }
  } catch (error) {
    host.remove()
    throw error
  }
}

registerEditNoticeCases('Vue', { describe, expect, it }, mountEditNotice)
