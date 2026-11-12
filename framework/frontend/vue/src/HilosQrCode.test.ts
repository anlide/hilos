// Vue mounting and cleanup for the shared QR drawing contract.
import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'

import {
  registerQrCodeCases,
  type WithQrCode,
} from '../../test-support/qrCodeCases.js'
import HilosQrCode from './HilosQrCode.vue'

/** Keep the real SVG and its host inside the case's cleanup boundary. */
const withQrCode: WithQrCode = async (input, steps) => {
  const host = document.body.appendChild(document.createElement('div'))
  try {
    const wrapper = mount(HilosQrCode, { attachTo: host, props: input })
    try {
      steps(host)
    } finally {
      wrapper.unmount()
    }
  } finally {
    host.remove()
  }
}

registerQrCodeCases('Vue', { describe, it, expect }, withQrCode)
