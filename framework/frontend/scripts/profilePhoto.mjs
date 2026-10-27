import { Buffer } from 'node:buffer'

/** Add EXIF orientation 6 (90° clockwise) before the JPEG's first segment.
 * @param {Buffer} jpeg The original JPEG bytes.
 * @returns {Buffer} The same JPEG with the orientation segment inserted.
 */
export function rotatedJpeg(jpeg) {
  const orientationSix = Buffer.from(
    'ffe1002245786966000049492a0008000000010012010300010000000600000000000000',
    'hex',
  )
  return Buffer.concat([jpeg.subarray(0, 2), orientationSix, jpeg.subarray(2)])
}
