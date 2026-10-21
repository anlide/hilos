import { expect, type Page } from '@playwright/test'

import { clickSubmit } from './session.js'

// Polls owns this photo walk: its Angular shell and profile publish immediately,
// without chat's name changes, model keys or moderation setup.

/** Draw a non-square JPEG with a narrow crop marker, or a different PNG. */
export async function picture(
  page: Page,
  mimeType: 'image/jpeg' | 'image/png',
): Promise<Buffer> {
  const dataUrl = await page.evaluate((type) => {
    const canvas = document.createElement('canvas')
    canvas.width = 480
    canvas.height = type === 'image/jpeg' ? 320 : 240
    const drawing = canvas.getContext('2d')
    if (!drawing) throw new Error('The browser has no 2D canvas')
    drawing.fillStyle = type === 'image/jpeg' ? '#bc3024' : '#18a050'
    drawing.fillRect(0, 0, 240, canvas.height)
    drawing.fillStyle = type === 'image/jpeg' ? '#246ac4' : '#9028c8'
    drawing.fillRect(240, 0, 240, canvas.height)
    if (type === 'image/jpeg') {
      drawing.fillStyle = '#f0d020'
      drawing.fillRect(200, 0, 20, canvas.height)
    }
    return canvas.toDataURL(type)
  }, mimeType)
  return Buffer.from(dataUrl.split(',')[1] ?? '', 'base64')
}

/** Add EXIF orientation 6 (90° clockwise) before the JPEG's first segment. */
export function rotatedJpeg(jpeg: Buffer): Buffer {
  const orientationSix = Buffer.from(
    'ffe1002245786966000049492a0008000000010012010300010000000600000000000000',
    'hex',
  )
  return Buffer.concat([jpeg.subarray(0, 2), orientationSix, jpeg.subarray(2)])
}

/** Open the real pick/current window and select a picture for cropping. */
export async function selectPhoto(
  page: Page,
  buffer: Buffer,
  mimeType: 'image/jpeg' | 'image/png',
): Promise<void> {
  await clickSubmit(page.getByTestId('profile-photo-open'))
  await page.getByTestId('profile-photo-input').setInputFiles({
    name: mimeType === 'image/png' ? 'photo.png' : 'photo.jpg',
    mimeType,
    buffer,
  })
  await expect(page.getByTestId('profile-photo-preview')).toBeVisible()
}

/** Decode the actual HTTP response bytes, sampling inside four equal regions. */
export async function decodePhoto(page: Page, bytes: Buffer) {
  return page.evaluate(async (data) => {
    const bitmap = await createImageBitmap(new Blob([new Uint8Array(data)]))
    try {
      const canvas = document.createElement('canvas')
      canvas.width = bitmap.width
      canvas.height = bitmap.height
      const drawing = canvas.getContext('2d')
      if (!drawing) throw new Error('The browser has no 2D canvas')
      drawing.drawImage(bitmap, 0, 0)
      const pixel = (x: number, y: number) =>
        Array.from(drawing.getImageData(x, y, 1, 1).data)
      return {
        width: bitmap.width,
        height: bitmap.height,
        vertical: [32, 96, 160, 224].map((y) => pixel(128, y)),
        horizontal: [32, 96, 160, 224].map((x) => pixel(x, 128)),
      }
    } finally {
      bitmap.close()
    }
  }, Array.from(bytes))
}
