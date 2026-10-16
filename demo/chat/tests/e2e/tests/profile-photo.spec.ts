import { test, expect } from '@playwright/test'

import { modelKey } from '../../../../../framework/frontend/scripts/standModel.mjs'
import { dictateModerationVerdict } from '../helpers/moderation.js'
import { gotoPage } from '../helpers/page.js'
import { nameForPhoto, picture, selectPhoto } from '../helpers/profilePhoto.js'
import { clickSubmit, signUp } from '../helpers/session.js'

const PHOTO_LIMIT_BYTES = 40 * 1024 * 1024

/** Add EXIF orientation 6 (90° clockwise) before the JPEG's first segment. */
function rotatedJpeg(jpeg: Buffer): Buffer {
  const orientationSix = Buffer.from(
    'ffe1002245786966000049492a0008000000010012010300010000000600000000000000',
    'hex',
  )
  return Buffer.concat([jpeg.subarray(0, 2), orientationSix, jpeg.subarray(2)])
}

test('an EXIF-rotated photo reaches both tabs and guests, then can be removed', async ({
  page,
  request,
}) => {
  const key = modelKey()
  const name = await nameForPhoto(page, key)
  const second = await page.context().newPage()
  try {
    await gotoPage(second, '/profile')
    await expect(second.getByTestId('profile-name')).toHaveText(name)

    await dictateModerationVerdict(key, true, 'ok')
    await selectPhoto(
      page,
      rotatedJpeg(await picture(page, 'image/jpeg')),
      'image/jpeg',
    )
    const zoom = page.getByTestId('profile-photo-zoom')
    await zoom.focus()
    await zoom.press('End')
    await expect(zoom).toHaveValue('4')
    await page.getByTestId('profile-photo-preview').focus()
    await page.getByTestId('profile-photo-preview').press('ArrowRight')
    await clickSubmit(page.getByTestId('profile-photo-save'))
    await expect(page.getByTestId('modal')).toBeHidden()

    const navPhoto = page
      .getByTestId('nav-profile')
      .getByTestId('hilos-avatar-photo')
    const profilePhoto = page
      .getByTestId('profile-identity')
      .getByTestId('hilos-avatar-photo')
    await expect(navPhoto).toBeVisible()
    await expect(profilePhoto).toBeVisible()
    await expect(
      second.getByTestId('nav-profile').getByTestId('hilos-avatar-photo'),
    ).toBeVisible()
    await expect(
      second.getByTestId('profile-identity').getByTestId('hilos-avatar-photo'),
    ).toBeVisible()
    const src = await navPhoto.getAttribute('src')
    expect(src).toContain('variant=hilos_avatar')
    if (src === null) throw new Error('The published photo has no address')
    await expect.poll(async () => (await request.get(src)).status()).toBe(200)
    const publicPhoto = await request.get(src)
    expect(publicPhoto.headers()['content-type']).toContain('image/webp')

    await clickSubmit(page.getByTestId('profile-photo-open'))
    await clickSubmit(page.getByTestId('profile-photo-remove'))
    await expect(page.getByTestId('modal')).toBeHidden()
    await expect(navPhoto).toHaveCount(0)
    await expect(profilePhoto).toHaveCount(0)
    await expect(
      second.getByTestId('nav-profile').getByTestId('hilos-avatar-photo'),
    ).toHaveCount(0)
    await expect(
      second.getByTestId('profile-identity').getByTestId('hilos-avatar-photo'),
    ).toHaveCount(0)
    await expect(page.getByTestId('profile-identity')).toContainText(
      name.charAt(0),
    )
  } finally {
    await second.close()
  }
})

test('a photo rejected for nudity stays in the crop window and initials remain', async ({
  page,
}) => {
  const key = modelKey()
  await nameForPhoto(page, key)
  await dictateModerationVerdict(key, false, 'nudity')
  await selectPhoto(page, await picture(page, 'image/jpeg'), 'image/jpeg')
  await clickSubmit(page.getByTestId('profile-photo-save'))
  await expect(page.getByTestId('profile-photo-error')).toHaveText(
    'This photo was not accepted: it looks like nudity or sexual content.',
  )
  await expect(page.getByTestId('profile-photo-preview')).toBeVisible()
  await expect(
    page.getByTestId('nav-profile').getByTestId('hilos-avatar-photo'),
  ).toHaveCount(0)
})

test('a transparent PNG is accepted as a cropped photo', async ({ page }) => {
  const key = modelKey()
  await nameForPhoto(page, key)
  await dictateModerationVerdict(key, true, 'ok')
  await selectPhoto(page, await picture(page, 'image/png'), 'image/png')
  await clickSubmit(page.getByTestId('profile-photo-save'))
  await expect(page.getByTestId('modal')).toBeHidden()
  await expect(
    page.getByTestId('profile-identity').getByTestId('hilos-avatar-photo'),
  ).toBeVisible()
})

test('a picture over 40 MB is refused before an upload begins', async ({
  page,
}) => {
  await signUp(page)
  await gotoPage(page, '/profile')
  await clickSubmit(page.getByTestId('profile-photo-open'))
  await page.getByTestId('profile-photo-input').setInputFiles({
    name: 'too-large.png',
    mimeType: 'image/png',
    buffer: Buffer.alloc(PHOTO_LIMIT_BYTES + 1),
  })
  await expect(page.getByTestId('profile-photo-error')).toHaveText(
    'This picture is larger than 40 MB.',
  )
  await expect(page.getByTestId('profile-photo-preview')).toHaveCount(0)
  await expect(
    page.getByTestId('nav-profile').getByTestId('hilos-avatar-photo'),
  ).toHaveCount(0)
})
