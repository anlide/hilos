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

/**
 * Prepare Polls' photo subject with a key in the name carried by image prompts.
 *
 * @param expect The calling spec's assertions.
 * @param page The anonymous browser to bind to the prepared account.
 * @param key The test-owned stand model key.
 * @returns The prepared account with its published name and original identity.
 */
export async function nameForPhoto(expect: Expect, page: Page, key: string) {
  const person = await createTestUser(expect, page)
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
  return { ...person, name }
}

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
