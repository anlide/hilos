// Covers the QR code drawing (HIL-494): an image with a name, a light field with
// its quiet zone, and the dark modules of the core's matrix in one path.
import { qrMatrix } from '@hilos/core'
import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'

import HilosQrCode from './HilosQrCode.vue'

describe('HilosQrCode', () => {
  it('draws the matrix with a quiet zone, named for a reader who cannot see it', () => {
    const text = 'otpauth://totp/Hilos:ada%40b.com?secret=JBSWY3DP&issuer=Hilos'
    const wrapper = mount(HilosQrCode, {
      props: { text, label: 'QR code for your authenticator app' },
    })
    const svg = wrapper.find('[data-id="qr-code"]')
    const size = qrMatrix(text).length + 8

    expect(svg.attributes('role')).toBe('img')
    expect(svg.attributes('aria-label')).toBe(
      'QR code for your authenticator app',
    )
    expect(svg.attributes('viewBox')).toBe(`0 0 ${size} ${size}`)
    // The top-left finder starts at the edge of the quiet zone.
    expect(wrapper.find('path').attributes('d')).toMatch(/^M4 4h1v1h-1z/)
  })
})
