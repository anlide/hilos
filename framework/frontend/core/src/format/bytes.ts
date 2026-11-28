// Turning a byte count into a readable figure with its unit. A core/format helper
// that carries the loop and the units only — every "not measured" answer stays
// with the screen that owns it.
//
// The figure is written by formatNumber() in the separators of the tab's region:
// «1.5 MB» reads «1,5 MB» in a region with a decimal comma. Never throws: byte
// figures print without a fixed fraction, figures above a terabyte stop at TB,
// and there is no guard against a negative number or NaN.

import { formatNumber } from './number.js'

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

  const figure =
    unit === 0
      ? formatNumber(size)
      : formatNumber(size, {
          minimumFractionDigits: 1,
          maximumFractionDigits: 1,
        })

  return `${figure} ${BYTE_UNITS[unit]}`
}
