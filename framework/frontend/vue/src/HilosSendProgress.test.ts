// Vue lifecycle for the shared SendProgress scenarios.
import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import { nextTick } from 'vue'

import {
  registerSendProgressCases,
  type SendProgressInput,
  type SendProgressView,
} from '../../test-support/sendProgressCases.js'
import HilosSendProgress from './HilosSendProgress.vue'

/** Mount the real component, including its teleported details panel. */
async function mountSendProgress(
  input: SendProgressInput,
): Promise<SendProgressView> {
  const host = document.body.appendChild(document.createElement('div'))
  try {
    let current = input
    const wrapper = mount(HilosSendProgress, {
      attachTo: host,
      props: {
        progress: current.progress,
        to: current.to,
        resendAt: current.resendAt,
        busy: current.busy,
        dataId: current.dataId,
        onSendAgain: () => current.onSendAgain(),
      },
    })
    return {
      root: document.body,
      async update(patch) {
        current = { ...current, ...patch }
        await wrapper.setProps({
          progress: current.progress,
          to: current.to,
          resendAt: current.resendAt,
          busy: current.busy,
          dataId: current.dataId,
        })
      },
      async click(target) {
        target.click()
        await nextTick()
      },
      async flush(change) {
        change?.()
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

registerSendProgressCases(
  'Vue',
  { describe, expect, it, vi },
  mountSendProgress,
)
