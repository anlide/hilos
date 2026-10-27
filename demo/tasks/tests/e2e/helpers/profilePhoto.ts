import type { Page } from '@playwright/test'
import {
  drawPhotoFixture,
  samplePhotoPixels,
} from '../../../../../framework/frontend/e2e/index.js'

/** Keep Tasks' three JPEG bands, replacement PNG bands and explicit encoding quality. */
export async function picture(
  page: Page,
  mimeType: 'image/jpeg' | 'image/png',
): Promise<Buffer> {
  return drawPhotoFixture(page, {
    width: 480,
    height: 320,
    mimeType,
    quality: 0.95,
    rectangles:
      mimeType === 'image/jpeg'
        ? ['#bc3024', '#249a50', '#246ac4'].map((color, index) => ({
            color,
            x: index * 160,
            y: 0,
            width: 160,
            height: 320,
          }))
        : [
            { color: '#e4bd28', x: 0, y: 0, width: 480, height: 160 },
            { color: '#24b8c4', x: 0, y: 160, width: 480, height: 160 },
          ],
  })
}

/** Keep Tasks' 3×3 interior grid and its width/height/colors caller shape. */
export async function readPublicPicture(page: Page, bytes: Buffer) {
  return samplePhotoPixels(
    page,
    bytes,
    [32, 128, 224].flatMap((y) => [32, 128, 224].map((x) => ({ x, y }))),
  )
}
