// HilosQrCode — a QR code drawn as SVG from the matrix the core computes
// (`qrMatrix`, HIL-494). The core owns the library; this owns nothing but the
// drawing: one path of dark squares over a light field, with the quiet zone of
// four modules a scanner needs around it. Light and dark are fixed rather than
// themed — a code inverted by a dark theme is a code most scanners refuse.
import { qrMatrix } from '@hilos/core'
import { useMemo } from 'react'

/** Props for {@link HilosQrCode}. */
export interface HilosQrCodeProps {
  /** The text the code carries, e.g. an `otpauth://` address. */
  text: string
  /** What the code is, for a reader who cannot see it. */
  label: string
  /** Extra classes for the drawing. */
  className?: string
}

/** Modules of light border every side needs for a scanner to find the code. */
const QUIET_ZONE = 4

/**
 * Draw the QR code of a text.
 *
 * @param props The component props.
 * @param props.text The text the code carries.
 * @param props.label What the code is, for a reader who cannot see it.
 * @param props.className Extra classes for the drawing.
 */
export function HilosQrCode({ text, label, className }: HilosQrCodeProps) {
  const { size, path } = useMemo(() => {
    const matrix = qrMatrix(text)
    const squares: string[] = []
    matrix.forEach((row, y) => {
      row.forEach((dark, x) => {
        if (dark) {
          squares.push(`M${x + QUIET_ZONE} ${y + QUIET_ZONE}h1v1h-1z`)
        }
      })
    })

    return { size: matrix.length + 2 * QUIET_ZONE, path: squares.join('') }
  }, [text])

  return (
    <svg
      viewBox={`0 0 ${size} ${size}`}
      role="img"
      aria-label={label}
      shapeRendering="crispEdges"
      className={`d-block mx-auto${className ? ` ${className}` : ''}`}
      width="176"
      height="176"
      data-id="qr-code"
    >
      <rect width={size} height={size} fill="#fff" />
      <path d={path} fill="#000" />
    </svg>
  )
}
