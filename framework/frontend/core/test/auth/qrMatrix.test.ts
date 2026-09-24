// Covers the QR matrix of an authenticator enrolment (HIL-494): a square code of
// a real QR size, the three finder patterns in their corners, the same text
// giving the same code, and a longer text needing a larger one.
import { describe, expect, it } from 'vitest'
import { qrMatrix } from '../../src/auth/qrMatrix.js'

const URI =
  'otpauth://totp/Hilos:ada%40b.com?secret=JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP&issuer=Hilos&algorithm=SHA1&digits=6&period=30'

/**
 * Whether the 7×7 finder pattern stands at a corner: a dark ring, a light ring,
 * and a dark 3×3 heart.
 *
 * @param matrix The code.
 * @param top The row the pattern starts on.
 * @param left The column it starts on.
 */
function hasFinder(
  matrix: readonly (readonly boolean[])[],
  top: number,
  left: number,
): boolean {
  for (let row = 0; row < 7; row++) {
    for (let column = 0; column < 7; column++) {
      const ring = Math.max(Math.abs(row - 3), Math.abs(column - 3))
      const dark = ring !== 2
      if (matrix[top + row]?.[left + column] !== dark) {
        return false
      }
    }
  }

  return true
}

describe('qrMatrix', () => {
  it('draws a square code of a QR size, a finder in three corners', () => {
    const matrix = qrMatrix(URI)
    const size = matrix.length

    expect((size - 17) % 4).toBe(0)
    expect(matrix.every((row) => row.length === size)).toBe(true)
    expect(hasFinder(matrix, 0, 0)).toBe(true)
    expect(hasFinder(matrix, 0, size - 7)).toBe(true)
    expect(hasFinder(matrix, size - 7, 0)).toBe(true)
    expect(hasFinder(matrix, size - 7, size - 7)).toBe(false)
  })

  it('gives the same code for the same text, and a larger one for a longer text', () => {
    expect(qrMatrix(URI)).toStrictEqual(qrMatrix(URI))
    expect(qrMatrix(URI + URI).length).toBeGreaterThan(qrMatrix(URI).length)
  })

  it('carries text that is not ASCII as its UTF-8 bytes', () => {
    // The two texts are as long in UTF-16 units. The library alone keeps one
    // byte of each unit and would draw them the same size; as UTF-8 the key is
    // four bytes against the letters' two, and needs the larger code.
    const ascii = qrMatrix('a'.repeat(80))
    const wide = qrMatrix('\u{1F511}'.repeat(40))

    expect(wide.length).toBeGreaterThan(ascii.length)
  })
})
