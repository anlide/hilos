// Vue input, event and lifecycle delivery for the shared support dialog cases.
import { describe, expect, it } from 'vitest'
import { createApp, h, nextTick, ref } from 'vue'

import {
  registerSupportModalCases,
  type WithSupportModal,
} from '../../../test-support/supportModalCases.js'
import HilosSupportModal from './HilosSupportModal.vue'

/** Own the app before mounting so cleanup also encloses initial activation. */
const withSupportModal: WithSupportModal = async (initialOpen, steps) => {
  const originalNodes = new Set(document.body.childNodes)
  const modalOpen = document.body.classList.contains('modal-open')
  const previousFocus = document.activeElement
  const host = document.body.appendChild(document.createElement('div'))
  const open = ref(initialOpen)
  const app = createApp({
    render: () =>
      h(HilosSupportModal, {
        modelValue: open.value,
        'onUpdate:modelValue': (value: boolean) => {
          open.value = value
        },
      }),
  })
  try {
    app.mount(host)
    await nextTick()
    await steps({
      root: document.body,
      async update(value) {
        open.value = value
        await nextTick()
      },
      async click(target) {
        target.click()
        await nextTick()
      },
    })
  } finally {
    try {
      app.unmount()
      await nextTick()
    } finally {
      for (const node of [...document.body.childNodes]) {
        if (!originalNodes.has(node)) node.remove()
      }
      document.body.classList.toggle('modal-open', modalOpen)
      if (previousFocus instanceof HTMLElement && previousFocus.isConnected) {
        previousFocus.focus()
      }
    }
  }
}

registerSupportModalCases('Vue', { describe, it, expect }, withSupportModal)
