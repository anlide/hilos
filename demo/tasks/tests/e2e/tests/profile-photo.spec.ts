import { test, expect } from '@playwright/test'

import {
  watchClientDocument,
  watchUploadFrames,
  watchSocketFrames,
  type SocketFrame,
} from '../../../../../framework/frontend/e2e/index.js'
import { grantAdminToSelf } from '../helpers/adminGrant.js'
import { setAdminViewMode } from '../helpers/adminViewMode.js'
import { gotoPage, PAGE_READY } from '../helpers/page.js'
import {
  picture,
  readPublicPicture,
  rotatedJpeg,
  selectPhoto,
} from '../helpers/profilePhoto.js'
import { clickSubmit, signUp } from '../helpers/session.js'

const PHOTO_LIMIT_BYTES = 40 * 1024 * 1024
// Both JPEG and WebP are lossy. Interior samples tolerate compression, while
// the fixture's red, green, blue, yellow and cyan remain clearly distinct.
const COLOR_TOLERANCE = 12

test.afterEach(() => setAdminViewMode(false))

test('a real crop is public, follows both tabs and the admin card, and can be replaced and removed', async ({
  page,
  browser,
  playwright,
}) => {
  const uploads = watchUploadFrames(page, 'profile', () => {})
  const { baseURL, ignoreHTTPSErrors } = test.info().project.use
  const adminContext = await browser.newContext({ baseURL, ignoreHTTPSErrors })
  const viewerContext = await browser.newContext({ baseURL, ignoreHTTPSErrors })
  // This is deliberately separate from page.request, with no storageState.
  const publicRequest = await playwright.request.newContext({
    baseURL,
    ignoreHTTPSErrors,
  })
  const second = await page.context().newPage()
  const documents: Awaited<ReturnType<typeof watchClientDocument>>[] = []
  try {
    const { name, userId } = await signUp(page)
    const initials = name.charAt(0).toUpperCase()
    await gotoPage(page, '/profile', PAGE_READY)
    await gotoPage(second, '/profile', PAGE_READY)
    for (const tab of [page, second]) {
      await expect(tab.getByTestId('profile-name')).toHaveText(name)
      documents.push(await watchClientDocument(tab))
    }
    const avatars = [page, second].flatMap((tab) => [
      tab.getByTestId('nav-profile-name').getByTestId('hilos-avatar'),
      tab.getByTestId('profile-identity').getByTestId('hilos-avatar'),
    ])
    for (const avatar of avatars) await expect(avatar).toHaveText(initials)

    const admin = await adminContext.newPage()
    await grantAdminToSelf(admin)
    await gotoPage(admin, `/hilos/user/${userId}`, PAGE_READY)
    const adminCard = admin.getByTestId('hilos-user-detail')
    await expect(adminCard.getByTestId('hilos-user-name')).toHaveText(name)
    const adminAvatar = adminCard.getByTestId('hilos-avatar')
    await expect(adminAvatar).toHaveText(initials)
    documents.push(await watchClientDocument(admin))

    await clickSubmit(page.getByTestId('profile-photo-open'))
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
    // Move the picture down until its top edge clamps. After EXIF rotation,
    // the chosen 80×80 square lies entirely in the top red band. Without zoom
    // the bottom samples would be green; without movement all would be green.
    for (let move = 0; move < 64; move++) await preview.press('ArrowDown')
    expect(uploads.uploads.size).toBe(0)
    expect(uploads.sentBytes()).toBe(0)
    for (const avatar of avatars) {
      await expect(avatar.getByTestId('hilos-avatar-photo')).toHaveCount(0)
    }
    await clickSubmit(page.getByTestId('profile-photo-save'))
    await expect(page.getByTestId('modal')).toBeHidden()

    const firstPhoto = avatars[0].getByTestId('hilos-avatar-photo')
    await expect(firstPhoto).toBeVisible()
    const firstSrc = await firstPhoto.getAttribute('src')
    if (firstSrc === null) throw new Error('The published crop has no address')
    const firstUrl = new URL(firstSrc, page.url())
    expect(firstUrl.pathname).toBe('/_hilos/file')
    expect([...firstUrl.searchParams.keys()].sort()).toEqual([
      'id',
      'v',
      'variant',
    ])
    expect(firstUrl.searchParams.get('variant')).toBe('hilos_avatar')
    expect(firstUrl.searchParams.get('id')).toMatch(/^\d+$/)
    expect(firstUrl.searchParams.get('v')).toBeTruthy()
    for (const avatar of [...avatars, adminAvatar]) {
      await expect(avatar.getByTestId('hilos-avatar-photo')).toHaveAttribute(
        'src',
        firstSrc,
      )
    }
    expect((await publicRequest.storageState()).cookies).toEqual([])
    const firstResponse = await publicRequest.get(firstSrc)
    expect(firstResponse.status()).toBe(200)
    expect(firstResponse.headers()['content-type']).toContain('image/webp')
    const firstPicture = await readPublicPicture(
      page,
      await firstResponse.body(),
    )
    expect([firstPicture.width, firstPicture.height]).toEqual([256, 256])
    for (const color of firstPicture.colors) {
      for (const [channel, expected] of [188, 48, 36].entries()) {
        expect(Math.abs(color[channel] - expected)).toBeLessThanOrEqual(
          COLOR_TOLERANCE,
        )
      }
      expect(color[3]).toBe(255)
    }
    expect(uploads.summary()).toHaveLength(1)
    expect(uploads.sentBytes()).toBeGreaterThan(0)
    const firstUpload = uploads.summary()[0]
    expect(firstUpload.size).toBeLessThanOrEqual(512 * 1024)
    expect(firstUpload.sentBytes).toBe(firstUpload.size)

    await setAdminViewMode(true)
    const viewer = await viewerContext.newPage()
    const viewerFrames: SocketFrame[] = []
    watchSocketFrames(viewer, (frame) => viewerFrames.push(frame))
    await gotoPage(viewer, `/hilos/user/${userId}`, PAGE_READY)
    await expect(viewer.getByTestId('view-mode-banner')).toBeVisible()
    const viewerCard = viewer.getByTestId('hilos-user-detail')
    await expect(
      viewerCard.getByTestId('hilos-user-name').getByTestId('hilos-hidden'),
    ).toHaveText('Hidden')
    await expect(viewerCard.getByTestId('hilos-avatar-photo')).toHaveCount(0)
    // Look at the real masked photo field, not the administrator's shell avatar.
    expect(viewerFrames.map(({ raw }) => raw).join('\n')).toMatch(
      /"photo"\s*:\s*\{\s*"_hidden"\s*:\s*true\s*\}/,
    )
    documents.push(await watchClientDocument(viewer))

    const sentBeforeReplacement = uploads.sentBytes()
    await clickSubmit(page.getByTestId('profile-photo-open'))
    const replacement = await picture(page, 'image/png')
    const chooser = page.waitForEvent('filechooser')
    await clickSubmit(page.getByTestId('profile-photo-upload-another'))
    await (
      await chooser
    ).setFiles({
      name: 'replacement.png',
      mimeType: 'image/png',
      buffer: replacement,
    })
    await expect(preview).toBeVisible()
    await expect(zoom).toHaveValue('1')
    expect(uploads.uploads.size).toBe(1)
    expect(uploads.sentBytes()).toBe(sentBeforeReplacement)
    // Selecting another picture does not unbind the current published photo.
    for (const avatar of [...avatars, adminAvatar]) {
      await expect(avatar.getByTestId('hilos-avatar-photo')).toHaveAttribute(
        'src',
        firstSrc,
      )
    }
    await clickSubmit(page.getByTestId('profile-photo-save'))
    await expect(page.getByTestId('modal')).toBeHidden()
    await expect(firstPhoto).not.toHaveAttribute('src', firstSrc)
    const replacementSrc = await firstPhoto.getAttribute('src')
    if (replacementSrc === null)
      throw new Error('The replacement has no address')
    expect(new URL(replacementSrc, page.url()).searchParams.get('id')).not.toBe(
      firstUrl.searchParams.get('id'),
    )
    for (const avatar of [...avatars, adminAvatar]) {
      await expect(avatar.getByTestId('hilos-avatar-photo')).toHaveAttribute(
        'src',
        replacementSrc,
      )
    }
    expect((await publicRequest.storageState()).cookies).toEqual([])
    const replacementResponse = await publicRequest.get(replacementSrc)
    expect(replacementResponse.status()).toBe(200)
    expect(replacementResponse.headers()['content-type']).toContain(
      'image/webp',
    )
    const replacementPicture = await readPublicPicture(
      page,
      await replacementResponse.body(),
    )
    expect([replacementPicture.width, replacementPicture.height]).toEqual([
      256, 256,
    ])
    // The PNG's top and bottom bands survive the centered square, unlike the
    // previous crop. Samples stay far from the horizontal boundary at y=128.
    for (const [row, expected] of [
      [0, [228, 189, 40]],
      [2, [36, 184, 196]],
    ] as const) {
      for (const color of replacementPicture.colors.slice(
        row * 3,
        row * 3 + 3,
      )) {
        for (const [channel, value] of expected.entries()) {
          expect(Math.abs(color[channel] - value)).toBeLessThanOrEqual(
            COLOR_TOLERANCE,
          )
        }
        expect(color[3]).toBe(255)
      }
    }
    expect(uploads.uploads.size).toBe(2)
    expect(uploads.errors).toEqual([])
    await expect(viewerCard.getByTestId('hilos-avatar-photo')).toHaveCount(0)

    await clickSubmit(page.getByTestId('profile-photo-open'))
    await clickSubmit(page.getByTestId('profile-photo-remove'))
    await expect(page.getByTestId('modal')).toBeHidden()
    // The live admin browser table is also empty again: neither former file
    // remains the active photo, regardless of the file registry's retention.
    for (const avatar of [...avatars, adminAvatar]) {
      await expect(avatar.getByTestId('hilos-avatar-photo')).toHaveCount(0)
      await expect(avatar).toHaveText(initials)
    }
    await expect(viewerCard.getByTestId('hilos-avatar-photo')).toHaveCount(0)
    for (const { raw } of viewerFrames) {
      expect(raw ?? '').not.toContain(firstSrc)
      expect(raw ?? '').not.toContain(replacementSrc)
      expect(raw ?? '').not.toContain(name)
    }
    for (const document of documents) await document.unchanged()
  } finally {
    for (const document of documents) await document.dispose()
    await setAdminViewMode(false)
    await second.close()
    await publicRequest.dispose()
    await viewerContext.close()
    await adminContext.close()
  }
})

test('a picture over 40 MB is refused before any upload begins', async ({
  page,
}) => {
  const uploads = watchUploadFrames(page, 'oversize', () => {})
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
  for (const surface of ['nav-profile-name', 'profile-identity']) {
    await expect(
      page.getByTestId(surface).getByTestId('hilos-avatar-photo'),
    ).toHaveCount(0)
  }
  expect(uploads.connections().sockets).toBeGreaterThan(0)
  expect(uploads.uploads.size).toBe(0)
  expect(uploads.sentBytes()).toBe(0)
  expect(uploads.errors).toEqual([])
  await clickSubmit(page.getByTestId('profile-photo-cancel'))
  await expect(page.getByTestId('modal')).toBeHidden()
})
