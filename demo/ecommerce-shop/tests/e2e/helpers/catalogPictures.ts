import type { Page } from '@playwright/test'

import { drawPhotoFixture } from '../../../../../framework/frontend/e2e/index.js'

/** Draw a two-color picture of the given type in the browser. */
export function drawCatalogPicture(
  page: Page,
  mimeType: 'image/jpeg' | 'image/png',
): Promise<Buffer> {
  return drawPhotoFixture(page, {
    width: 320,
    height: 240,
    mimeType,
    rectangles: [
      { color: '#bc3024', x: 0, y: 0, width: 160, height: 240 },
      { color: '#246ac4', x: 160, y: 0, width: 160, height: 240 },
    ],
  })
}
