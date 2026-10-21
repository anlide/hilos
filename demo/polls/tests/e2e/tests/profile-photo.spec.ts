import { test, expect, type Page } from '@playwright/test'

import {
  countSentFrames,
  watchClientDocument,
} from '../../../../../framework/frontend/e2e/index.js'
import { grantAdminToSelf } from '../helpers/adminGrant.js'
import { setAdminViewMode } from '../helpers/adminViewMode.js'
import { gotoPage, PAGE_READY } from '../helpers/page.js'
import {
  decodePhoto,
  picture,
  rotatedJpeg,
  selectPhoto,
} from '../helpers/profilePhoto.js'
import { clickSubmit, signUp } from '../helpers/session.js'

// spec-owner: demo — polls' Angular photo wiring publishes without moderation.

const PHOTO_LIMIT_BYTES = 40 * 1024 * 1024
// Flat interior pixels survive the browser JPEG and server WebP encoders with
// small rounding differences. The colors and crop regions remain distinct.
const COLOR_TOLERANCE = 20

function avatarSurfaces(page: Page) {
  return ['nav-profile-name', 'profile-identity'].map((id) =>
    page.getByTestId(id).getByTestId('hilos-avatar'),
  )
}

function expectPixel(actual: number[], expected: number[]): void {
  expect(actual).toHaveLength(4)
  for (let channel = 0; channel < 3; channel++) {
    expect(Math.abs(actual[channel] - expected[channel])).toBeLessThanOrEqual(
      COLOR_TOLERANCE,
    )
  }
  expect(actual[3]).toBe(255)
}

test('an EXIF crop is public, replaces the photo in both tabs and removes back to initials', async ({
  page,
  request,
}) => {
  // Registration, two publications and removal across two live Angular pages.
  test.slow()
  const uploads = countSentFrames(page, 'hilos_upload_init')
  const person = await signUp(page)
  await gotoPage(page, '/profile', PAGE_READY)
  const second = await page.context().newPage()
  try {
    await gotoPage(second, '/profile', PAGE_READY)
    await expect(second.getByTestId('profile-name')).toHaveText(person.name)
    const documents = await Promise.all(
      [page, second].map((tab) => watchClientDocument(tab)),
    )
    try {
      await selectPhoto(
        page,
        rotatedJpeg(await picture(page, 'image/jpeg')),
        'image/jpeg',
      )
      const zoom = page.getByTestId('profile-photo-zoom')
      await expect(zoom).toHaveValue('1')
      await zoom.focus()
      await zoom.press('End')
      await expect(zoom).toHaveValue('4')
      const preview = page.getByTestId('profile-photo-preview')
      await preview.focus()
      for (let step = 0; step < 4; step++) await preview.press('ArrowDown')
      expect(uploads()).toBe(0)
      for (const tab of [page, second]) {
        for (const avatar of avatarSurfaces(tab)) {
          await expect(avatar.getByTestId('hilos-avatar-photo')).toHaveCount(0)
        }
      }
      await clickSubmit(page.getByTestId('profile-photo-save'))
      await expect(page.getByTestId('modal')).toBeHidden()
      await expect(
        page.getByTestId('profile-identity').getByTestId('hilos-avatar-photo'),
      ).toBeVisible()
      const src = await page
        .getByTestId('profile-identity')
        .getByTestId('hilos-avatar-photo')
        .getAttribute('src')
      if (src === null) throw new Error('The published photo has no address')
      expect(new URL(src, page.url()).pathname).toBe('/_hilos/file')
      expect(new URL(src, page.url()).searchParams.get('variant')).toBe(
        'hilos_avatar',
      )
      for (const tab of [page, second]) {
        for (const avatar of avatarSurfaces(tab)) {
          await expect(avatar.getByTestId('hilos-avatar-photo')).toBeVisible()
          await expect(
            avatar.getByTestId('hilos-avatar-photo'),
          ).toHaveAttribute('src', src)
        }
      }

      // Playwright's request fixture has its own cookie jar, separate from the
      // signed-in browser context. Decode this anonymous HTTP response itself.
      expect((await request.storageState()).cookies).toEqual([])
      const publicPhoto = await request.get(src)
      expect(publicPhoto.status()).toBe(200)
      expect(publicPhoto.headers()['content-type']).toContain('image/webp')
      const firstBytes = await publicPhoto.body()
      expect(firstBytes.toString('ascii', 0, 4)).toBe('RIFF')
      expect(firstBytes.toString('ascii', 8, 12)).toBe('WEBP')
      const decoded = await decodePhoto(page, firstBytes)
      expect([decoded.width, decoded.height]).toEqual([256, 256])
      // EXIF makes the bands horizontal. Zoom 4 selects an 80px square;
      // four downward keys move its source from y=200 to y=180. The yellow
      // source stripe y=200..220 must land in the second output quarter.
      const cropColors = [
        [188, 48, 36],
        [240, 208, 32],
        [188, 48, 36],
        [36, 106, 196],
      ]
      decoded.vertical.forEach((pixel, index) =>
        expectPixel(pixel, cropColors[index]),
      )
      await test.info().attach('anonymous-exif-crop', {
        body: firstBytes,
        contentType: 'image/webp',
      })

      await selectPhoto(page, await picture(page, 'image/png'), 'image/png')
      // Merely picking the replacement must preserve the published photo.
      for (const tab of [page, second]) {
        for (const avatar of avatarSurfaces(tab)) {
          await expect(
            avatar.getByTestId('hilos-avatar-photo'),
          ).toHaveAttribute('src', src)
        }
      }
      expect(uploads()).toBe(1)
      await clickSubmit(page.getByTestId('profile-photo-save'))
      await expect(page.getByTestId('modal')).toBeHidden()
      const profilePhoto = page
        .getByTestId('profile-identity')
        .getByTestId('hilos-avatar-photo')
      await expect(profilePhoto).not.toHaveAttribute('src', src)
      const replacement = await profilePhoto.getAttribute('src')
      if (replacement === null)
        throw new Error('The replacement has no address')
      for (const tab of [page, second]) {
        for (const avatar of avatarSurfaces(tab)) {
          await expect(avatar.getByTestId('hilos-avatar-photo')).toBeVisible()
          await expect(
            avatar.getByTestId('hilos-avatar-photo'),
          ).toHaveAttribute('src', replacement)
        }
      }
      expect((await request.storageState()).cookies).toEqual([])
      const replacementPhoto = await request.get(replacement)
      expect(replacementPhoto.status()).toBe(200)
      expect(replacementPhoto.headers()['content-type']).toContain('image/webp')
      const replacementBytes = await replacementPhoto.body()
      expect(replacementBytes.equals(firstBytes)).toBe(false)
      const replaced = await decodePhoto(page, replacementBytes)
      expect([replaced.width, replaced.height]).toEqual([256, 256])
      replaced.horizontal.forEach((pixel, index) =>
        expectPixel(pixel, index < 2 ? [24, 160, 80] : [144, 40, 200]),
      )
      await test.info().attach('anonymous-replacement', {
        body: replacementBytes,
        contentType: 'image/webp',
      })

      await clickSubmit(page.getByTestId('profile-photo-open'))
      await clickSubmit(page.getByTestId('profile-photo-remove'))
      await expect(page.getByTestId('modal')).toBeHidden()
      for (const tab of [page, second]) {
        for (const avatar of avatarSurfaces(tab)) {
          await expect(avatar.getByTestId('hilos-avatar-photo')).toHaveCount(0)
          await expect(avatar).toHaveText('E')
        }
        await expect(tab.getByTestId('profile-name')).toHaveText(person.name)
      }
      expect(uploads()).toBe(2)
      for (const document of documents) await document.unchanged()
    } finally {
      for (const document of documents) await document.dispose()
    }
  } finally {
    await second.close()
  }
})

test('a picture over 40 MiB is refused locally before upload', async ({
  page,
}) => {
  const uploads = countSentFrames(page, 'hilos_upload_init')
  await signUp(page)
  await gotoPage(page, '/profile', PAGE_READY)
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
  await expect(page.getByTestId('profile-photo-save')).toHaveCount(0)
  await clickSubmit(page.getByTestId('profile-photo-cancel'))
  await expect(page.getByTestId('modal')).toBeHidden()
  expect(uploads()).toBe(0)
  for (const avatar of avatarSurfaces(page)) {
    await expect(avatar.getByTestId('hilos-avatar-photo')).toHaveCount(0)
    await expect(avatar).toHaveText('E')
  }
})

test('the admin card follows userPhoto while the viewer receives its personal photo hidden', async ({
  page,
  browser,
  baseURL,
}) => {
  // One registration, a separate admin session and live card updates.
  test.slow()
  const person = await signUp(page)
  await gotoPage(page, '/profile', PAGE_READY)
  const adminContext = await browser.newContext({
    baseURL,
    ignoreHTTPSErrors: true,
  })
  const viewerContext = await browser.newContext({
    baseURL,
    ignoreHTTPSErrors: true,
  })
  try {
    const admin = await adminContext.newPage()
    await grantAdminToSelf(admin)
    await gotoPage(admin, `/hilos/user/${person.userId}`, PAGE_READY)
    await expect(admin.getByTestId('hilos-user-name')).toHaveText(person.name)
    const avatar = admin
      .getByTestId('hilos-user-detail')
      .getByTestId('hilos-avatar')
    await expect(avatar).toHaveText('E')
    const document = await watchClientDocument(admin)
    try {
      await selectPhoto(page, await picture(page, 'image/jpeg'), 'image/jpeg')
      await clickSubmit(page.getByTestId('profile-photo-save'))
      await expect(page.getByTestId('modal')).toBeHidden()
      const photo = avatar.getByTestId('hilos-avatar-photo')
      await expect(photo).toBeVisible()
      const src = await photo.getAttribute('src')
      if (src === null) throw new Error('The admin card has no photo address')
      await expect(
        page.getByTestId('profile-identity').getByTestId('hilos-avatar-photo'),
      ).toHaveAttribute('src', src)

      await setAdminViewMode(true)
      const viewer = await viewerContext.newPage()
      const frames: string[] = []
      viewer.on('websocket', (socket) => {
        socket.on('framereceived', ({ payload }) => {
          frames.push(JSON.stringify(JSON.parse(payload.toString())))
        })
      })
      await gotoPage(viewer, `/hilos/user/${person.userId}`, PAGE_READY)
      await expect(viewer.getByTestId('view-mode-banner')).toBeVisible()
      await expect(
        viewer.getByTestId('hilos-user-name').getByTestId('hilos-hidden'),
      ).toHaveText('Hidden')
      const viewerAvatar = viewer
        .getByTestId('hilos-user-detail')
        .getByTestId('hilos-avatar')
      await expect(viewerAvatar.getByTestId('hilos-avatar-photo')).toHaveCount(
        0,
      )
      // An absent image alone could mean broken wiring. Require the actual
      // personal-field mask in the delivered userPhoto browser table as well.
      await expect
        .poll(() =>
          frames.some(
            (frame) =>
              frame.includes('"userPhoto"') &&
              frame.includes('"photo":{"_hidden":true}'),
          ),
        )
        .toBe(true)
      for (const frame of frames) expect(frame).not.toContain('/_hilos/file?')

      await selectPhoto(page, await picture(page, 'image/png'), 'image/png')
      await clickSubmit(page.getByTestId('profile-photo-save'))
      await expect(page.getByTestId('modal')).toBeHidden()
      await expect(photo).not.toHaveAttribute('src', src)
      const replacement = await page
        .getByTestId('profile-identity')
        .getByTestId('hilos-avatar-photo')
        .getAttribute('src')
      if (replacement === null)
        throw new Error('The replacement has no address')
      await expect(photo).toHaveAttribute('src', replacement)
      await expect(photo).toBeVisible()
      await expect(viewerAvatar.getByTestId('hilos-avatar-photo')).toHaveCount(
        0,
      )
      for (const frame of frames) expect(frame).not.toContain('/_hilos/file?')

      await clickSubmit(page.getByTestId('profile-photo-open'))
      await clickSubmit(page.getByTestId('profile-photo-remove'))
      await expect(page.getByTestId('modal')).toBeHidden()
      await expect(photo).toHaveCount(0)
      await expect(avatar).toHaveText('E')
      await document.unchanged()
      await test.info().attach('viewer-photo-frames', {
        body: frames.join('\n'),
        contentType: 'application/x-ndjson',
      })
    } finally {
      await document.dispose()
    }
  } finally {
    await setAdminViewMode(false)
    await viewerContext.close()
    await adminContext.close()
  }
})
