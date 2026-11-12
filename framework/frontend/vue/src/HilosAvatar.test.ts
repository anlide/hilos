// Vue input, event and lifecycle delivery for the shared avatar cases.
import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import { nextTick } from 'vue'

import {
  registerAvatarCases,
  type WithAvatar,
} from '../../test-support/avatarCases.js'
import HilosAvatar from './HilosAvatar.vue'

/** Keep the real component and host inside the case's cleanup boundary. */
const withAvatar: WithAvatar = async (input, steps) => {
  const host = document.body.appendChild(document.createElement('div'))
  try {
    const wrapper = mount(HilosAvatar, { attachTo: host, props: input })
    try {
      await steps({
        root: host,
        async update(patch) {
          await wrapper.setProps(patch)
        },
        async imageError(image) {
          image.dispatchEvent(new Event('error'))
          await nextTick()
        },
      })
    } finally {
      wrapper.unmount()
    }
  } finally {
    host.remove()
  }
}

registerAvatarCases('Vue', { describe, it, expect }, withAvatar)
