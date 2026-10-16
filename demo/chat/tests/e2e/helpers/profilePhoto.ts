import { expect, type Page } from '@playwright/test'

import { dictateModerationVerdict } from './moderation.js'
import { gotoPage } from './page.js'
import { PASSWORD, clickSubmit, signUp, typeInto } from './session.js'

// The profile photo walk on the chat stand: a real picture drawn in the browser,
// the person named after the stand model's key, and the crop window opened on a
// chosen picture. The photo check asks chat's moderator, whose verdict a spec
// dictates through chat's own moderation helper — so this is the demo's helper,
// not the shared toolbox's.

/**
 * Create a real, non-square picture in the browser without a binary fixture in the tree.
 *
 * @param page Any page of the stand; its canvas draws the picture.
 * @param mimeType The picture's type: an opaque JPEG or a half-transparent PNG.
 */
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
    if (type === 'image/jpeg') {
      drawing.fillStyle = '#bc3024'
      drawing.fillRect(0, 0, 240, 320)
      drawing.fillStyle = '#246ac4'
      drawing.fillRect(240, 0, 240, 320)
    } else {
      drawing.clearRect(0, 0, 480, 240)
      drawing.fillStyle = 'rgba(36, 106, 196, 0.5)'
      drawing.fillRect(240, 0, 240, 240)
    }
    return canvas.toDataURL(type)
  }, mimeType)
  return Buffer.from(dataUrl.split(',')[1] ?? '', 'base64')
}

/**
 * Put the stand model key into the person's current name, which the photo prompt carries.
 *
 * @param page Playwright page still anonymous in its browser context.
 * @param key The stand model key the name carries.
 * @returns The person's new name.
 */
export async function nameForPhoto(page: Page, key: string): Promise<string> {
  await signUp(page)
  await gotoPage(page, '/profile')
  const name = `Photo ${key}`
  await dictateModerationVerdict(key, true, 'ok')
  await clickSubmit(page.getByTestId('profile-edit'))
  await typeInto(page.getByTestId('step-up-password'), PASSWORD)
  await clickSubmit(page.getByTestId('profile-name-step-up-confirm'))
  await typeInto(page.getByTestId('profile-name-input'), name)
  await clickSubmit(page.getByTestId('profile-rename-save'))
  await expect(page.getByTestId('modal')).toBeHidden()
  await expect(page.getByTestId('profile-name')).toHaveText(name)
  return name
}

/**
 * Select a picture and wait until the crop controls replace the file picker.
 *
 * @param page The person's page on /profile.
 * @param buffer The picture's bytes.
 * @param mimeType The picture's type.
 */
export async function selectPhoto(
  page: Page,
  buffer: Buffer,
  mimeType: string,
): Promise<void> {
  await clickSubmit(page.getByTestId('profile-photo-open'))
  await page.getByTestId('profile-photo-input').setInputFiles({
    name: mimeType === 'image/png' ? 'photo.png' : 'photo.jpg',
    mimeType,
    buffer,
  })
  await expect(page.getByTestId('profile-photo-preview')).toBeVisible()
}
