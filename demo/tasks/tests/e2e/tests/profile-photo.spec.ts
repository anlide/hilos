import { test as base, expect } from '@playwright/test'

import {
  createTestUser,
  cleanupFixtures,
  type TestCleanup,
  watchClientDocument,
  watchUploadFrames,
  watchSocketFrames,
  type SocketFrame,
  clickSubmit,
  selectPhoto,
  gotoPage,
  PAGE_READY,
  grantAdminToSelf,
} from '../../../../../framework/frontend/e2e/index.js'
import { rotatedJpeg } from '../../../../../framework/frontend/scripts/profilePhoto.mjs'
import { setAdminViewMode } from '../../../../../framework/frontend/scripts/adminViewMode.mjs'
import {
  dictateModelAnswer,
  forgetModelKey,
  modelKey,
  readModelRequests,
} from '../../../../../framework/frontend/scripts/standModel.mjs'
import {
  nameForPhoto,
  picture,
  readPublicPicture,
} from '../helpers/profilePhoto.js'

const PHOTO_LIMIT_BYTES = 40 * 1024 * 1024
// Both JPEG and WebP are lossy. Interior samples tolerate compression, while
// the fixture's red, green, blue, yellow and cyan remain clearly distinct.
const COLOR_TOLERANCE = 12

const test = base.extend<{ cleanup: TestCleanup }>({ cleanup: cleanupFixtures })

test('a real crop is public, follows both tabs and the admin card, and can be replaced and removed', async ({
  page,
  browser,
  playwright,
  cleanup,
}) => {
  const key = modelKey()
  cleanup.defer(() => forgetModelKey(key))
  const uploads = watchUploadFrames(page, 'profile', () => {})
  const { baseURL, ignoreHTTPSErrors } = test.info().project.use
  const adminContext = await browser.newContext({ baseURL, ignoreHTTPSErrors })
  cleanup.defer(() => adminContext.close())
  const viewerContext = await browser.newContext({ baseURL, ignoreHTTPSErrors })
  cleanup.defer(() => viewerContext.close())
  // This is deliberately separate from page.request, with no storageState.
  const publicRequest = await playwright.request.newContext({
    baseURL,
    ignoreHTTPSErrors,
  })
  cleanup.defer(() => publicRequest.dispose())
  const second = await page.context().newPage()
  cleanup.defer(() => second.close())
  cleanup.defer(() => setAdminViewMode(false))
  const documents: Awaited<ReturnType<typeof watchClientDocument>>[] = []
  const { name, userId } = await nameForPhoto(expect, page, key)
  expect(await readModelRequests(key)).toEqual([
    { ordinal: 1, key, imageHashes: [] },
  ])
  const initials = 'PM'
  await gotoPage(expect, page, '/profile', PAGE_READY)
  await gotoPage(expect, second, '/profile', PAGE_READY)
  for (const tab of [page, second]) {
    await expect(tab.getByTestId('profile-name')).toHaveText(name)
    const document = await watchClientDocument(tab)
    cleanup.defer(() => document.dispose())
    documents.push(document)
  }
  const avatars = [page, second].flatMap((tab) => [
    tab.getByTestId('nav-profile-name').getByTestId('hilos-avatar'),
    tab.getByTestId('profile-identity').getByTestId('hilos-avatar'),
  ])
  for (const avatar of avatars) await expect(avatar).toHaveText(initials)

  const admin = await adminContext.newPage()
  await grantAdminToSelf(expect, admin)
  await gotoPage(expect, admin, `/hilos/user/${userId}`, PAGE_READY)
  const adminCard = admin.getByTestId('hilos-user-detail')
  await expect(adminCard.getByTestId('hilos-user-name')).toHaveText(name)
  const adminAvatar = adminCard.getByTestId('hilos-avatar')
  await expect(adminAvatar).toHaveText(initials)
  const adminDocument = await watchClientDocument(admin)
  cleanup.defer(() => adminDocument.dispose())
  documents.push(adminDocument)

  await clickSubmit(expect, page.getByTestId('profile-photo-open'))
  await selectPhoto(
    expect,
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
  await dictateModelAnswer(key, JSON.stringify({ allow: true, reason: 'ok' }))
  await clickSubmit(expect, page.getByTestId('profile-photo-save'))
  await expect(page.getByTestId('modal')).toBeHidden()
  const approved = await readModelRequests(key)
  expect(approved).toHaveLength(2)
  expect(approved[1].imageHashes).toHaveLength(1)

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
  const firstPicture = await readPublicPicture(page, await firstResponse.body())
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
  await gotoPage(expect, viewer, `/hilos/user/${userId}`, PAGE_READY)
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
  const viewerDocument = await watchClientDocument(viewer)
  cleanup.defer(() => viewerDocument.dispose())
  documents.push(viewerDocument)

  const sentBeforeReplacement = uploads.sentBytes()
  await clickSubmit(expect, page.getByTestId('profile-photo-open'))
  const replacement = await picture(page, 'image/png')
  const chooser = page.waitForEvent('filechooser')
  await clickSubmit(expect, page.getByTestId('profile-photo-upload-another'))
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
  await test.step('A nudity refusal keeps the old photo and the selected crop', async () => {
    await dictateModelAnswer(
      key,
      JSON.stringify({ allow: false, reason: 'nudity' }),
    )
    await clickSubmit(expect, page.getByTestId('profile-photo-save'))
    await expect(page.getByTestId('profile-photo-error')).toHaveText(
      'This photo was not accepted: it looks like nudity or sexual content.',
    )
    await expect(page.getByTestId('modal')).toBeVisible()
    await expect(preview).toBeVisible()
    await expect(zoom).toHaveValue('1')
    await expect(page.getByTestId('profile-photo-save')).toBeEnabled()
    for (const avatar of [...avatars, adminAvatar]) {
      await expect(avatar.getByTestId('hilos-avatar-photo')).toHaveAttribute(
        'src',
        firstSrc,
      )
    }
    const refused = await readModelRequests(key)
    expect(refused).toHaveLength(3)
    expect(refused[2].imageHashes).toHaveLength(1)
  })
  await dictateModelAnswer(key, JSON.stringify({ allow: true, reason: 'ok' }))
  await clickSubmit(expect, page.getByTestId('profile-photo-save'))
  await expect(page.getByTestId('modal')).toBeHidden()
  const replaced = await readModelRequests(key)
  expect(replaced).toHaveLength(4)
  expect(replaced[3].imageHashes).toEqual(replaced[2].imageHashes)
  await expect(firstPhoto).not.toHaveAttribute('src', firstSrc)
  const replacementSrc = await firstPhoto.getAttribute('src')
  if (replacementSrc === null) throw new Error('The replacement has no address')
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
  expect(replacementResponse.headers()['content-type']).toContain('image/webp')
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
    for (const color of replacementPicture.colors.slice(row * 3, row * 3 + 3)) {
      for (const [channel, value] of expected.entries()) {
        expect(Math.abs(color[channel] - value)).toBeLessThanOrEqual(
          COLOR_TOLERANCE,
        )
      }
      expect(color[3]).toBe(255)
    }
  }
  expect(uploads.uploads.size).toBe(3)
  expect(uploads.errors).toEqual([])
  await expect(viewerCard.getByTestId('hilos-avatar-photo')).toHaveCount(0)

  await clickSubmit(expect, page.getByTestId('profile-photo-open'))
  await clickSubmit(expect, page.getByTestId('profile-photo-remove'))
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
})

test('a picture over 40 MB is refused before any upload begins', async ({
  page,
}) => {
  const uploads = watchUploadFrames(page, 'oversize', () => {})
  await createTestUser(expect, page)
  await gotoPage(expect, page, '/profile', PAGE_READY)
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
  await clickSubmit(expect, page.getByTestId('profile-photo-cancel'))
  await expect(page.getByTestId('modal')).toBeHidden()
})
