// Turning a byte count into a readable figure with its unit. A core/format helper
// that carries the loop and the units only — every "not measured" answer stays
// with the screen that owns it.
//
// Never throws, and carries every branch of the loop verbatim from the copies it
// replaces: byte figures print unrounded, figures above a terabyte stop at TB,
// and there is no guard against a negative number or NaN.

/** Byte unit suffixes in ascending orders of magnitude. */
const BYTE_UNITS = ['B', 'KB', 'MB', 'GB', 'TB']

/**
 * Format a byte figure in the largest unit that leaves a readable number.
 *
 * @param bytes The figure, in bytes.
 */
export function formatBytes(bytes: number): string {
  let size = bytes
  let unit = 0
  while (size >= 1024 && unit < BYTE_UNITS.length - 1) {
    size /= 1024
    unit += 1
  }

  return `${unit === 0 ? size : size.toFixed(1)} ${BYTE_UNITS[unit]}`
}
