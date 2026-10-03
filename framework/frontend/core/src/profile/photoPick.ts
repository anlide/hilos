import {
  HILOS_PHOTO_JPEG_QUALITY,
  HILOS_PHOTO_OUTPUT_SIDE,
  HILOS_PHOTO_PICK_MAX_BYTES,
  type HilosPhotoSourceSquare,
} from './photoCrop.js'

/** What stopped the browser from opening or drawing a selected picture. */
export type HilosPhotoPickFailure = 'tooLarge' | 'unreadable'

/** A selection failure the photo flow can turn into its agreed copy. */
export class HilosPhotoPickError extends Error {
  constructor(readonly failure: HilosPhotoPickFailure) {
    super(failure)
    this.name = 'HilosPhotoPickError'
  }
}

const ACCEPTED_TYPES = new Set(['image/jpeg', 'image/png', 'image/webp'])
const JPEG_TYPE = 'image/jpeg'

/**
 * Decode a chosen picture with its EXIF orientation applied by the browser.
 *
 * @param file JPEG, PNG or WebP selected by the person.
 * @throws HilosPhotoPickError When the file is too large or cannot be decoded.
 */
export async function openHilosPhoto(file: File): Promise<ImageBitmap> {
  if (file.size > HILOS_PHOTO_PICK_MAX_BYTES) {
    throw new HilosPhotoPickError('tooLarge')
  }
  if (!ACCEPTED_TYPES.has(file.type)) {
    throw new HilosPhotoPickError('unreadable')
  }

  try {
    return await createImageBitmap(file, { imageOrientation: 'from-image' })
  } catch {
    throw new HilosPhotoPickError('unreadable')
  }
}

/**
 * Draw the same source square the exported JPEG will use into a preview canvas.
 *
 * @param canvas Canvas with the preview's pixel dimensions.
 * @param bitmap Decoded, oriented picture.
 * @param square Square source region in bitmap pixels.
 * @throws HilosPhotoPickError When the canvas cannot draw.
 */
export function drawHilosPhotoPreview(
  canvas: HTMLCanvasElement,
  bitmap: ImageBitmap,
  square: HilosPhotoSourceSquare,
): void {
  const context = canvas.getContext('2d')
  if (context === null) throw new HilosPhotoPickError('unreadable')
  context.fillStyle = '#ffffff'
  context.fillRect(0, 0, canvas.width, canvas.height)
  context.drawImage(
    bitmap,
    square.sx,
    square.sy,
    square.side,
    square.side,
    0,
    0,
    canvas.width,
    canvas.height,
  )
}

/**
 * Encode the cropped square as a metadata-free JPEG on white.
 *
 * @param bitmap Decoded, oriented picture.
 * @param square Square source region in bitmap pixels.
 * @throws HilosPhotoPickError When the canvas cannot encode.
 */
export function renderHilosPhotoSquare(
  bitmap: ImageBitmap,
  square: HilosPhotoSourceSquare,
): Promise<Blob> {
  const canvas = document.createElement('canvas')
  canvas.width = HILOS_PHOTO_OUTPUT_SIDE
  canvas.height = HILOS_PHOTO_OUTPUT_SIDE
  drawHilosPhotoPreview(canvas, bitmap, square)

  return new Promise<Blob>((resolve, reject) => {
    canvas.toBlob(
      (blob) => {
        if (blob === null) reject(new HilosPhotoPickError('unreadable'))
        else resolve(blob)
      },
      JPEG_TYPE,
      HILOS_PHOTO_JPEG_QUALITY,
    )
  })
}
