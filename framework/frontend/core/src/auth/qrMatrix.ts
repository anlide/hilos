// The QR code of an authenticator enrolment as plain data (HIL-494): which modules
// are dark, row by row, and nothing about how to draw them. The one module that
// imports the QR library — the seam the dependency rule asks a frontend library to
// sit behind (docs/agents/framework-development.md, Dependencies), so swapping it
// is an edit here and nowhere else. Each view draws the matrix itself, as SVG.
import qrcode from 'qrcode-generator'

/**
 * Error correction level M: about 15% of the code may be lost and still read.
 * The code is scanned off a screen, where nothing tears or smudges it, so the
 * denser levels would only grow the code.
 */
const ERROR_CORRECTION = 'M'

/** Let the library pick the smallest version that holds the text. */
const AUTOMATIC_VERSION = 0

/**
 * The dark modules of the QR code that carries a text, row by row — `true` is a
 * dark module. The matrix is square, and holds no quiet zone: the border around
 * it is the view's to draw.
 *
 * The text is carried as its UTF-8 bytes. The library turns a string into
 * bytes by keeping the low byte of every character, which is right for ASCII
 * and wrong for anything else, so the string it is given is already one
 * character per UTF-8 byte.
 *
 * @param text The text to encode, e.g. an `otpauth://` address.
 * @returns The modules, `matrix[row][column]`.
 */
export function qrMatrix(text: string): readonly (readonly boolean[])[] {
  const code = qrcode(AUTOMATIC_VERSION, ERROR_CORRECTION)
  code.addData(utf8AsByteString(text), 'Byte')
  code.make()
  const size = code.getModuleCount()
  const rows: boolean[][] = []
  for (let row = 0; row < size; row++) {
    const cells: boolean[] = []
    for (let column = 0; column < size; column++) {
      cells.push(code.isDark(row, column))
    }
    rows.push(cells)
  }

  return rows
}

/**
 * A text as the string of its UTF-8 bytes, one character per byte.
 *
 * @param text The text to encode.
 */
function utf8AsByteString(text: string): string {
  return Array.from(new TextEncoder().encode(text), (byte) =>
    String.fromCharCode(byte),
  ).join('')
}
