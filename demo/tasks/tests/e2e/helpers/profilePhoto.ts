import { expect, type Page } from '@playwright/test'

// Fixtures for the tasks/React photo walk: no moderator or model is configured
// here. The selected bands make the actual crop observable in the public bytes.

/** Draw a non-square JPEG with three vertical bands, or a two-band replacement PNG. */
export async function picture(
  page: Page,
  mimeType: 'image/jpeg' | 'image/png',
): Promise<Buffer> {
  const dataUrl = await page.evaluate((type) => {
    const canvas = document.createElement('canvas')
    canvas.width = 480
    canvas.height = 320
    const drawing = canvas.getContext('2d')
    if (!drawing) throw new Error('The browser has no 2D canvas')
    if (type === 'image/jpeg') {
      for (const [index, color] of [
        '#bc3024',
        '#249a50',
        '#246ac4',
      ].entries()) {
        drawing.fillStyle = color
        drawing.fillRect(index * 160, 0, 160, 320)
      }
    } else {
      drawing.fillStyle = '#e4bd28'
      drawing.fillRect(0, 0, 480, 160)
      drawing.fillStyle = '#24b8c4'
      drawing.fillRect(0, 160, 480, 160)
    }
    return canvas.toDataURL(type, 0.95)
  }, mimeType)
  return Buffer.from(dataUrl.split(',')[1] ?? '', 'base64')
}

/** Add EXIF orientation 6: the JPEG's vertical red/green/blue bands become horizontal. */
export function rotatedJpeg(jpeg: Buffer): Buffer {
  const orientationSix = Buffer.from(
    'ffe1002245786966000049492a0008000000010012010300010000000600000000000000',
    'hex',
  )
  return Buffer.concat([jpeg.subarray(0, 2), orientationSix, jpeg.subarray(2)])
}

/** Select the tasks fixture in the already open photo window and await the crop state. */
export async function selectPhoto(
  page: Page,
  buffer: Buffer,
  mimeType: 'image/jpeg' | 'image/png',
): Promise<void> {
  await page.getByTestId('profile-photo-input').setInputFiles({
    name: mimeType === 'image/png' ? 'photo.png' : 'photo.jpg',
    mimeType,
    buffer,
  })
  await expect(page.getByTestId('profile-photo-preview')).toBeVisible()
  await expect(page.getByTestId('profile-photo-save')).toBeEnabled()
}

/** Decode actual downloaded WebP bytes and sample a 3×3 grid away from band edges. */
export async function readPublicPicture(page: Page, bytes: Buffer) {
  return page.evaluate(async (base64) => {
    const blob = await (await fetch(`data:image/webp;base64,${base64}`)).blob()
    const bitmap = await createImageBitmap(blob)
    try {
      const canvas = document.createElement('canvas')
      canvas.width = bitmap.width
      canvas.height = bitmap.height
      const drawing = canvas.getContext('2d')
      if (!drawing) throw new Error('The browser has no 2D canvas')
      drawing.drawImage(bitmap, 0, 0)
      const colors = []
      for (const y of [32, 128, 224]) {
        for (const x of [32, 128, 224]) {
          colors.push([...drawing.getImageData(x, y, 1, 1).data])
        }
      }
      return { width: bitmap.width, height: bitmap.height, colors }
    } finally {
      bitmap.close()
    }
  }, bytes.toString('base64'))
}
