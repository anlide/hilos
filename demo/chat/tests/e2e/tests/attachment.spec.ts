import { test, expect } from '@playwright/test'

import {
  clickSubmit,
  isSessionCookie,
  orphanSessionToken,
  SESSION_COOKIE_PREFIX,
  signUp,
  typeInto,
} from '../helpers/session'
import { modelKey } from '../helpers/model'
import { dictateModerationVerdict } from '../helpers/moderation'

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

// Attachment lifecycle and authorization e2e (HIL-1035): HIL-904 changed the
// session cookie name, and the attachment download handler did not read the new
// name, returning 401 on a valid session (fixed in HOTFIX cb3df308b). This test
// carries an image attachment from upload to download and verifies that:
// (1) the browser renders the image with its own session cookie,
// (2) fetching the URL with the browser context returns 200 and the exact bytes,
// (3) a request without a session cookie is refused with 401,
// (4) a request with an orphan session token is refused with 401.
test('a sent image opens from the feed, and its address refuses a request without a session', async ({
  page,
  context,
  request,
}) => {
  await signUp(page)

  const key = modelKey()
  const text = `attachment ${key}`
  const filename = `${key}.png`
  await dictateModerationVerdict(key, true, 'ok')

  await page.getByTestId('file-input').setInputFiles({
    name: filename,
    mimeType: ATTACHMENT_MIME,
    buffer: ATTACHMENT_PNG,
  })
  await expect(page.getByTestId('attachment-draft-name')).toHaveText(filename)
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
})
