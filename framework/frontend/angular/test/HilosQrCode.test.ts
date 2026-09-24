// The Angular peer of vue/src/HilosQrCode.test.ts (HIL-494): an image with a
// name, a light field with its quiet zone, and the core's matrix in one path.
import { TestBed } from '@angular/core/testing'
import { qrMatrix } from '@hilos/core'
import { describe, expect, it } from 'vitest'

import { HilosQrCode } from '../src/HilosQrCode.js'

describe('HilosQrCode', () => {
  it('draws the matrix with a quiet zone, named for a reader who cannot see it', () => {
    const text = 'otpauth://totp/Hilos:ada%40b.com?secret=JBSWY3DP&issuer=Hilos'
    const fixture = TestBed.createComponent(HilosQrCode)
    fixture.componentRef.setInput('text', text)
    fixture.componentRef.setInput('label', 'QR code for your authenticator app')
    fixture.detectChanges()
    const svg = (fixture.nativeElement as HTMLElement).querySelector(
      '[data-id="qr-code"]',
    )
    const size = qrMatrix(text).length + 8

    expect(svg?.getAttribute('role')).toBe('img')
    expect(svg?.getAttribute('aria-label')).toBe(
      'QR code for your authenticator app',
    )
    expect(svg?.getAttribute('viewBox')).toBe(`0 0 ${size} ${size}`)
    expect(svg?.querySelector('path')?.getAttribute('d')).toMatch(
      /^M4 4h1v1h-1z/,
    )
  })
})
