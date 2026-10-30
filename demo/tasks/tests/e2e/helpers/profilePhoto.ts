import type { Expect, Page } from '@playwright/test'
import {
  PASSWORD,
  clickSubmit,
  createTestUser,
  drawPhotoFixture,
  gotoPage,
  samplePhotoPixels,
  typeInto,
} from '../../../../../framework/frontend/e2e/index.js'
import { dictateModelAnswer } from '../../../../../framework/frontend/scripts/standModel.mjs'

/** Prepare Tasks' photo subject with a real approved rename carrying its model key. */
export async function nameForPhoto(expect: Expect, page: Page, key: string) {
  const user = await createTestUser(expect, page)
  await gotoPage(expect, page, '/profile')
  const name = `Photo ${key}`
  await dictateModelAnswer(key, JSON.stringify({ allow: true, reason: 'ok' }))
  await clickSubmit(expect, page.getByTestId('profile-edit'))
  await typeInto(page.getByTestId('step-up-password'), PASSWORD)
  await clickSubmit(expect, page.getByTestId('profile-name-step-up-confirm'))
  await typeInto(page.getByTestId('profile-name-input'), name)
  await clickSubmit(expect, page.getByTestId('profile-rename-save'))
  await expect(page.getByTestId('modal')).toBeHidden()
  await expect(page.getByTestId('profile-name')).toHaveText(name)
  return { ...user, name }
}

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
