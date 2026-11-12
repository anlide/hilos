// Shared SVG drawing contract; core owns the QR encoding algorithm.
import { qrMatrix } from '@hilos/core/base'
import type * as Vitest from 'vitest'

type QrCodeTestRunner = Pick<typeof Vitest, 'describe' | 'it' | 'expect'>

/** Content and accessible name handed to the real QR component. */
export interface QrCodeInput {
  text: string
  label: string
}

/** Own the component and host even if mounting or a case fails. */
export type WithQrCode = (
  input: QrCodeInput,
  steps: (root: HTMLElement) => void,
) => Promise<void>

/** Register the named image, core matrix and four-module quiet-zone checks. */
export function registerQrCodeCases(
  sdk: string,
  runner: QrCodeTestRunner,
  withQrCode: WithQrCode,
): void {
  const { describe, it, expect } = runner

  describe(`HilosQrCode on ${sdk}`, () => {
    it('draws the matrix with a quiet zone, named for a reader who cannot see it', async () => {
      const text =
        'otpauth://totp/Hilos:ada%40b.com?secret=JBSWY3DP&issuer=Hilos'
      await withQrCode(
        { text, label: 'QR code for your authenticator app' },
        (root) => {
          const svg = root.querySelector('[data-id="qr-code"]')!
          const matrix = qrMatrix(text)
          const size = matrix.length + 8

          expect(svg.localName).toBe('svg')
          expect(svg.namespaceURI).toBe('http://www.w3.org/2000/svg')
          expect(svg.getAttribute('role')).toBe('img')
          expect(svg.getAttribute('aria-label')).toBe(
            'QR code for your authenticator app',
          )
          expect(svg.getAttribute('viewBox')).toBe(`0 0 ${size} ${size}`)

          // The field and module contrast are QR data, independent of page theme.
          expect(svg.querySelectorAll('rect')).toHaveLength(1)
          const field = svg.querySelector('rect')!
          expect(field.getAttribute('width')).toBe(String(size))
          expect(field.getAttribute('height')).toBe(String(size))
          expect(field.getAttribute('fill')).toBe('#fff')
          expect(svg.querySelectorAll('path')).toHaveLength(1)
          const path = svg.querySelector('path')!
          expect(path.getAttribute('fill')).toBe('#000')
          const drawing = path.getAttribute('d')!
          // The top-left finder starts at the edge of the quiet zone.
          expect(drawing).toMatch(/^M4 4h1v1h-1z/)

          // Read the drawn unit squares back into cells, rather than re-encoding QR.
          const squares = [...drawing.matchAll(/M(\d+) (\d+)h1v1h-1z/g)]
          expect(squares.map(([command]) => command).join('')).toBe(drawing)
          expect(squares).toHaveLength(matrix.flat().filter(Boolean).length)
          const coordinates = new Set(squares.map(([, x, y]) => `${x} ${y}`))
          const drawnMatrix = matrix.map((row, y) =>
            row.map((_, x) => coordinates.has(`${x + 4} ${y + 4}`)),
          )
          expect(drawnMatrix).toEqual(matrix)
        },
      )
    })
  })
}
