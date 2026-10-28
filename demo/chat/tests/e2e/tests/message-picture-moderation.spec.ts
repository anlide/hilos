import { test, expect, type Page } from '@playwright/test'
import {
  clickSubmit,
  typeInto,
  createTestUser,
} from '../../../../../framework/frontend/e2e/index.js'

import { modelKey } from '../../../../../framework/frontend/scripts/standModel.mjs'
import { dictateModerationVerdict } from '../helpers/moderation'

// spec-owner: demo — the pictures a chat message carries pass the moderator

// Pictures on a chat message (HIL-1313). The moderator asks the stand's model
// once for the text and once more for each picture, and dictations for one key
// play in that order. The picture prompt carries the file name, so the text
// and the name hold the same key.
//
// Send is not asserted: submitting starts the re-send lockout, and the refusal
// does not clear it (message-moderation.spec.ts).

const PNG_MIME = 'image/png'

/** A valid 1x1 PNG, the same bytes attachment.spec.ts sends. */
const PICTURE_PNG = Buffer.from(
  'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==',
  'base64',
)

/**
 * Another valid 1x1 PNG, the framework upload fixture. The same bytes twice
 * are refused as a duplicate upload, so the second picture has to differ.
 */
const OTHER_PNG = Buffer.from(
  'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==',
  'base64',
)

/** Signature and IHDR of PICTURE_PNG, with the image body cut off. */
const TRUNCATED_PNG = PICTURE_PNG.subarray(0, 33)

/**
 * Pick PNG files through the composer's file input and wait for every chip to
 * read received.
 *
 * @param page The signed-in chat page.
 * @param files Name and bytes of each file, in attach order.
 */
async function attachPngs(
  page: Page,
  files: readonly { name: string; buffer: Buffer }[],
): Promise<void> {
  await page.getByTestId('attach-button-input').setInputFiles(
    files.map((file) => ({
      name: file.name,
      mimeType: PNG_MIME,
      buffer: file.buffer,
    })),
  )
  const chips = page.getByTestId('attachments-file')
  await expect(chips).toHaveCount(files.length)
  for (const chip of await chips.all()) {
    await expect(chip).toHaveAttribute('data-state', 'received')
  }
  await expect(page.getByTestId('attachments-file-name')).toHaveText(
    files.map((file) => file.name),
  )
}

test('a picture refused for nudity stays out of the room and on its chip', async ({
  page,
}) => {
  await createTestUser(expect, page)

  const key = modelKey()
  const filename = `${key}.png`
  await dictateModerationVerdict(key, true, 'ok')
  await dictateModerationVerdict(key, false, 'nudity')

  await attachPngs(page, [{ name: filename, buffer: PICTURE_PNG }])
  await typeInto(page.getByTestId('message-input'), key)
  await clickSubmit(expect, page.getByTestId('message-send'))

  await expect(page.getByTestId('moderation-text')).toHaveText(
    'Message rejected: attachment 1 (' +
      filename +
      ') looks like nudity or sexual content.',
  )
  await expect(
    page.getByTestId('event-text').filter({ hasText: key }),
  ).toHaveCount(0)
  await expect(page.getByTestId('attachments-file')).toHaveAttribute(
    'data-state',
    'received',
  )
  await expect(page.getByTestId('attachments-file-name')).toHaveText(filename)
})

test('the second picture refused for violence names that attachment', async ({
  page,
}) => {
  await createTestUser(expect, page)

  const key = modelKey()
  const first = `${key}-a.png`
  const second = `${key}-b.png`
  await dictateModerationVerdict(key, true, 'ok')
  await dictateModerationVerdict(key, true, 'ok')
  await dictateModerationVerdict(key, false, 'violence')

  await attachPngs(page, [
    { name: first, buffer: PICTURE_PNG },
    { name: second, buffer: OTHER_PNG },
  ])
  await typeInto(page.getByTestId('message-input'), key)
  await clickSubmit(expect, page.getByTestId('message-send'))

  await expect(page.getByTestId('moderation-text')).toHaveText(
    'Message rejected: attachment 2 (' + second + ') looks violent.',
  )
  await expect(
    page.getByTestId('event-text').filter({ hasText: key }),
  ).toHaveCount(0)
})

test('a picture the model does not answer comes back as moderation unavailable', async ({
  page,
}) => {
  await createTestUser(expect, page)

  const key = modelKey()
  const filename = `${key}.png`
  // The text is allowed. The picture call finds the key in the file name and
  // the queue is empty, so the model answers 503.
  await dictateModerationVerdict(key, true, 'ok')

  await attachPngs(page, [{ name: filename, buffer: PICTURE_PNG }])
  await typeInto(page.getByTestId('message-input'), key)
  await clickSubmit(expect, page.getByTestId('message-send'))

  await expect(page.getByTestId('moderation-text')).toHaveText(
    'Moderation is unavailable right now. Your message is still here.',
  )
  await expect(
    page.getByTestId('event-text').filter({ hasText: key }),
  ).toHaveCount(0)
})

test('a truncated picture is refused as one that cannot be checked', async ({
  page,
}) => {
  await createTestUser(expect, page)

  const key = modelKey()
  const filename = `${key}.png`
  await dictateModerationVerdict(key, true, 'ok')

  await attachPngs(page, [{ name: filename, buffer: TRUNCATED_PNG }])
  await typeInto(page.getByTestId('message-input'), key)
  await clickSubmit(expect, page.getByTestId('message-send'))

  await expect(page.getByTestId('moderation-text')).toHaveText(
    'Message rejected: attachment 1 (' +
      filename +
      ') cannot be checked. Send pictures as JPEG, PNG, WebP or GIF.',
  )
  await expect(
    page.getByTestId('event-text').filter({ hasText: key }),
  ).toHaveCount(0)
})
