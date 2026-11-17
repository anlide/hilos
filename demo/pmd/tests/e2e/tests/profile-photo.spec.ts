import { test as base, expect } from '../test.js'
import {
  cleanupFixtures,
  type TestCleanup,
  clickSubmit,
  openAndSelectPhoto,
  selectPhoto,
  gotoPage,
  preparePhotoSubject,
} from '../../../../../framework/frontend/e2e/index.js'

import { rotatedJpeg } from '../../../../../framework/frontend/scripts/profilePhoto.mjs'
import {
  dictateModerationVerdict,
  modelKey,
  forgetModelKey,
} from '../../../../../framework/frontend/scripts/standModel.mjs'
import { picture } from '../helpers/profilePhoto.js'

const PHOTO_LIMIT_BYTES = 40 * 1024 * 1024

const test = base.extend<{ cleanup: TestCleanup }>({ cleanup: cleanupFixtures })

test('an EXIF-rotated photo reaches both tabs and guests, then can be removed', async ({
  page,
  request,
}) => {
  const key = modelKey()
  const { name } = await preparePhotoSubject(expect, page, key)
  const second = await page.context().newPage()
  try {
    await gotoPage(expect, second, '/profile')
    await expect(second.getByTestId('profile-name')).toHaveText(name)

    await dictateModerationVerdict(key, true, 'ok')
    await openAndSelectPhoto(
      expect,
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
    await clickSubmit(expect, page.getByTestId('profile-photo-save'))
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

    await clickSubmit(expect, page.getByTestId('profile-photo-open'))
    await clickSubmit(expect, page.getByTestId('profile-photo-remove'))
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

test('corrects an oversized and refused photo with an accepted transparent PNG', async ({
  page,
  cleanup,
}) => {
  const key = modelKey()
  cleanup.defer(() => forgetModelKey(key))
  await preparePhotoSubject(expect, page, key)
  await test.step('a picture over 40 MB is refused before an upload begins', async () => {
    await clickSubmit(expect, page.getByTestId('profile-photo-open'))
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

  await test.step('a photo rejected for nudity stays in the crop window and initials remain', async () => {
    await dictateModerationVerdict(key, false, 'nudity')
    await selectPhoto(
      expect,
      page,
      await picture(page, 'image/jpeg'),
      'image/jpeg',
    )
    await clickSubmit(expect, page.getByTestId('profile-photo-save'))
    await expect(page.getByTestId('profile-photo-error')).toHaveText(
      'This photo was not accepted: it looks like nudity or sexual content.',
    )
    await expect(page.getByTestId('profile-photo-preview')).toBeVisible()
    await expect(
      page.getByTestId('nav-profile').getByTestId('hilos-avatar-photo'),
    ).toHaveCount(0)
  })

  await test.step('a transparent PNG is accepted as a cropped photo', async () => {
    await dictateModerationVerdict(key, true, 'ok')
    await selectPhoto(
      expect,
      page,
      await picture(page, 'image/png'),
      'image/png',
    )
    await clickSubmit(expect, page.getByTestId('profile-photo-save'))
    await expect(page.getByTestId('modal')).toBeHidden()
    await expect(
      page.getByTestId('profile-identity').getByTestId('hilos-avatar-photo'),
    ).toBeVisible()
  })
})
