import type { Page } from '@playwright/test'
import {
  drawPhotoFixture,
  samplePhotoPixels,
} from '../../../../../framework/frontend/e2e/index.js'

/** Keep Polls' narrow yellow JPEG crop marker and its separate PNG colors and height. */
export async function picture(
  page: Page,
  mimeType: 'image/jpeg' | 'image/png',
): Promise<Buffer> {
  const height = mimeType === 'image/jpeg' ? 320 : 240
  return drawPhotoFixture(page, {
    width: 480,
    height,
    mimeType,
    rectangles: [
      {
        color: mimeType === 'image/jpeg' ? '#bc3024' : '#18a050',
        x: 0,
        y: 0,
        width: 240,
        height,
      },
      {
        color: mimeType === 'image/jpeg' ? '#246ac4' : '#9028c8',
        x: 240,
        y: 0,
        width: 240,
        height,
      },
      ...(mimeType === 'image/jpeg'
        ? [{ color: '#f0d020', x: 200, y: 0, width: 20, height }]
        : []),
    ],
  })
}

/** Keep Polls' four vertical/horizontal sample coordinates and its caller shape. */
export async function decodePhoto(page: Page, bytes: Buffer) {
  const samples = await samplePhotoPixels(page, bytes, [
    ...[32, 96, 160, 224].map((y) => ({ x: 128, y })),
    ...[32, 96, 160, 224].map((x) => ({ x, y: 128 })),
  ])
  return {
    width: samples.width,
    height: samples.height,
    vertical: samples.colors.slice(0, 4),
    horizontal: samples.colors.slice(4),
  }
}
