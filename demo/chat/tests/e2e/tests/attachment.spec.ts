import { test, expect, type Page } from '@playwright/test'

import {
  armSocketDrop,
  dropSocket,
} from '../../../../../framework/frontend/e2e/index.js'
import { gotoPage } from '../helpers/page'
import {
  clickSubmit,
  isSessionCookie,
  login,
  logout,
  orphanSessionToken,
  SESSION_COOKIE_PREFIX,
  signUp,
  typeInto,
} from '../helpers/session'
import { modelKey } from '../../../../../framework/frontend/scripts/standModel.mjs'
import { dictateModerationVerdict } from '../helpers/moderation'

// spec-owner: demo — files attached to chat's messages

const ATTACHMENT_MIME = 'image/png'

/**
 * A valid 1x1 PNG image (70 bytes). A real image is required because the test
 * verifies that the browser actually renders it (naturalWidth > 0), which fails
 * on malformed image data.
 */
const ATTACHMENT_PNG = Buffer.from(
  'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==',
  'base64',
)

/** Bytes of a zip archive's head: content that is neither a picture, a PDF nor text. */
const ZIP_BYTES = Buffer.from('504b0304140000000800', 'hex')

/**
 * Pick one PNG through the composer's file input and wait for its chip.
 *
 * @param page The signed-in chat page.
 * @param filename Name the file is picked under.
 */
async function attachPng(page: Page, filename: string): Promise<void> {
  await page.getByTestId('file-input').setInputFiles({
    name: filename,
    mimeType: ATTACHMENT_MIME,
    buffer: ATTACHMENT_PNG,
  })
  await expect(page.getByTestId('attachment-draft-name')).toHaveText(filename)
}

// Attachment lifecycle and authorization e2e (HIL-1035, HIL-144): the file
// travels through the framework uploads client and is served by the files
// library at /_hilos/file. This test carries an image attachment from upload to
// download and verifies that:
// (1) the feed shows the chat_thumb copy and the browser renders it,
// (2) fetching the original with the browser context returns 200 and the exact bytes,
// (3) a request without a session cookie is refused with 401,
// (4) a request with an orphan session token is refused with 401,
// (5) a guest - a session naming nobody - gets the original: the chat lets any
//     session read a file attached to a message.
test('a sent image opens from the feed, and its address refuses a request without a session', async ({
  page,
  context,
  request,
  browser,
}) => {
  await signUp(page)

  const key = modelKey()
  const text = `attachment ${key}`
  const filename = `${key}.png`
  await dictateModerationVerdict(key, true, 'ok')

  await attachPng(page, filename)
  await expect(page.getByTestId('upload-error')).toHaveCount(0)

  await typeInto(page.getByTestId('message-input'), text)
  await clickSubmit(page.getByTestId('message-send'))

  const event = page
    .getByTestId('event')
    .filter({ has: page.getByTestId('event-text').filter({ hasText: text }) })
  const attachment = event.getByTestId('event-attachment')
  await expect(attachment).toHaveCount(1)

  await event.scrollIntoViewIfNeeded()
  const image = attachment.getByTestId('event-attachment-image')
  expect(await image.getAttribute('src')).toContain('variant=chat_thumb')
  await expect
    .poll(() =>
      image.evaluate((element) => {
        const img = element as HTMLImageElement
        return img.complete && img.naturalWidth > 0
      }),
    )
    .toBe(true)

  const href = await attachment.getAttribute('href')
  if (href === null) {
    throw new Error('the attachment link carries no href')
  }

  const own = await page.request.get(href)
  expect(own.status()).toBe(200)
  expect(await own.body()).toEqual(ATTACHMENT_PNG)

  expect((await request.get(href)).status()).toBe(401)

  const session = (await context.cookies()).find((cookie) =>
    isSessionCookie(cookie.name),
  )
  if (session === undefined) {
    throw new Error(`the stand issued no ${SESSION_COOKIE_PREFIX}* cookie`)
  }

  const forged = await request.get(href, {
    headers: { Cookie: `${session.name}=${orphanSessionToken()}` },
  })
  expect(forged.status()).toBe(401)

  const guest = await browser.newContext()
  try {
    const guestPage = await guest.newPage()
    await gotoPage(guestPage, '/')
    await expect(guestPage.getByTestId('conn-state')).toHaveText('connected')
    const asGuest = await guestPage.request.get(href)
    expect(asGuest.status()).toBe(200)
    expect(await asGuest.body()).toEqual(ATTACHMENT_PNG)
  } finally {
    await guest.close()
  }
})

// The server reads the type from the content (HIL-144): a file that calls itself
// a picture but holds a zip archive is refused with the server's sentence once its
// bytes arrive, and leaves no chip.
test('a file of a type the chat does not take is refused with the server sentence', async ({
  page,
}) => {
  await signUp(page)

  await page.getByTestId('file-input').setInputFiles({
    name: `${modelKey()}.png`,
    mimeType: ATTACHMENT_MIME,
    buffer: ZIP_BYTES,
  })

  await expect(page.getByTestId('upload-error')).toHaveText(
    'File content does not match an allowed type',
  )
  await expect(page.getByTestId('attachment-draft')).toHaveCount(0)
})

// From the acceptance of HIL-139: the browser client sends a ready file again
// under the same id after the socket drops, so the chip survives the drop and
// the file still rides the message.
test('a ready file survives a dropped connection and is sent with the message', async ({
  page,
}) => {
  await armSocketDrop(page)
  await signUp(page)

  const key = modelKey()
  const text = `after drop ${key}`
  const filename = `${key}.png`
  await dictateModerationVerdict(key, true, 'ok')
  await attachPng(page, filename)

  const reconnected = page.waitForEvent('websocket')
  await dropSocket(page)
  await reconnected
  await expect(page.getByTestId('conn-state')).toHaveText('connected')
  await expect(page.getByTestId('attachment-draft-name')).toHaveText(filename)
  await expect(page.getByTestId('upload-progress')).toHaveCount(0)

  await typeInto(page.getByTestId('message-input'), text)
  await clickSubmit(page.getByTestId('message-send'))

  const event = page
    .getByTestId('event')
    .filter({ has: page.getByTestId('event-text').filter({ hasText: text }) })
  await expect(event.getByTestId('event-attachment')).toHaveCount(1)
  await expect(page.getByTestId('attachment-draft')).toHaveCount(0)
})

// From the acceptance of HIL-139: the client drops its list when the person
// behind it changes, so signing out and back in starts with no chips.
test('signing out drops the files waiting for a message', async ({ page }) => {
  const user = await signUp(page)
  await attachPng(page, `${modelKey()}.png`)

  await logout(page)
  await expect(page.getByTestId('attachment-draft')).toHaveCount(0)
  await page.getByTestId('message-signin').click()
  await login(page, user.email)

  await expect(page.getByTestId('self-user')).toHaveText(user.name)
  await expect(page.getByTestId('attachment-draft')).toHaveCount(0)
})
