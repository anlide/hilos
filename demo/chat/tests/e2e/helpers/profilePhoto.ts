import { expect, type Page } from '@playwright/test'
import {
  PASSWORD,
  clickSubmit,
  typeInto,
  gotoPage,
  drawPhotoFixture,
} from '../../../../../framework/frontend/e2e/index.js'

import { dictateModerationVerdict } from './moderation.js'
import { signUp } from './session.js'

/** Keep Chat's opaque JPEG and half-transparent PNG recipe for its moderation walk. */
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

/**
 * Put the stand model key into the person's current name, which the photo prompt carries.
 *
 * @param page Playwright page still anonymous in its browser context.
 * @param key The stand model key the name carries.
 * @returns The person's new name.
 */
export async function nameForPhoto(page: Page, key: string): Promise<string> {
  await signUp(expect, page)
  await gotoPage(expect, page, '/profile')
  const name = `Photo ${key}`
  await dictateModerationVerdict(key, true, 'ok')
  await clickSubmit(expect, page.getByTestId('profile-edit'))
  await typeInto(page.getByTestId('step-up-password'), PASSWORD)
  await clickSubmit(expect, page.getByTestId('profile-name-step-up-confirm'))
  await typeInto(page.getByTestId('profile-name-input'), name)
  await clickSubmit(expect, page.getByTestId('profile-rename-save'))
  await expect(page.getByTestId('modal')).toBeHidden()
  await expect(page.getByTestId('profile-name')).toHaveText(name)
  return name
}
