import type { Page } from '@playwright/test'
import { drawPhotoFixture } from '../../../../../framework/frontend/e2e/index.js'

/** Draw opaque JPEG and half-transparent PNG photo fixtures. */
export async function picture(
  page: Page,
  mimeType: 'image/jpeg' | 'image/png',
): Promise<Buffer> {
  return drawPhotoFixture(page, {
    width: 480,
    height: mimeType === 'image/jpeg' ? 320 : 240,
    mimeType,
    rectangles:
      mimeType === 'image/jpeg'
        ? [
            { color: '#bc3024', x: 0, y: 0, width: 240, height: 320 },
            { color: '#246ac4', x: 240, y: 0, width: 240, height: 320 },
          ]
        : [
            {
              color: 'rgba(36, 106, 196, 0.5)',
              x: 240,
              y: 0,
              width: 240,
              height: 240,
            },
          ],
  })
}
