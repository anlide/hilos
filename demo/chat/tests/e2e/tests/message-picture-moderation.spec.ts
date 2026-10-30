import { createHash } from 'node:crypto'
import { writeFile } from 'node:fs/promises'
import { test, expect, type Page } from '@playwright/test'
import {
  clickSubmit,
  typeInto,
  createTestUserJoined,
  createTestAdmin,
  gotoPage,
  deliveryObject,
  readMonopolisticWorker,
  openSettingEdit,
  setCustomSetting,
  clearCustomSetting,
  openBell,
  unreadBadge,
  drawPhotoFixture,
  watchDeliveryFrames,
  openSignIn,
  login,
  expectSessionRotation,
  controlSocketReconnect,
  armSocketDrop,
  dropSocket,
  type SocketReconnectControl,
} from '../../../../../framework/frontend/e2e/index.js'

import {
  modelKey,
  readModelRequests,
  forgetModelKey,
} from '../../../../../framework/frontend/scripts/standModel.mjs'
import {
  observeModeration,
  observeChatSubmission,
} from '../../../../../framework/frontend/scripts/moderationObservation.mjs'
import { dictateGatewayBehavior } from '../../../../../framework/frontend/scripts/standGateway.mjs'
import { createStandCommandChannel } from '../../../../../framework/frontend/scripts/standCommand.mjs'
import {
  UPLOADS_WORKER_AGENT,
  withStoppedUploadWorker,
} from '../../../../../framework/frontend/scripts/stoppedUploadWorker.mjs'
import { dictateModerationVerdict } from '../helpers/moderation.js'
import { queuedPicture, pictureControls } from '../helpers/queuedPicture.js'
import { logout } from '../helpers/session.js'

// spec-owner: demo — chat consumes framework moderation for its message attachments

// ChatAgent opens a framework text check followed by one IMAGE check per picture.
// Each image call uses a JPEG copy and carries its file name in the prompt, so
// dictations for the key in both text and file names play in attachment order.
//
// Content refusals notify the author; unavailable and local unreadable do not.
// Recovery waits for the original re-send lockout through the control's state.

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
  await createTestUserJoined(expect, page)

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
  await expect(page.getByTestId('message-input')).toHaveValue(key)
  await expect(page.getByTestId('attachments-file')).toHaveAttribute(
    'data-state',
    'received',
  )
  await expect(page.getByTestId('attachments-file-name')).toHaveText(filename)

  await expect(unreadBadge(page)).toHaveText(/^1\b/)
  await openBell(page)
  const menu = page.getByTestId('hilos-notification-menu')
  await expect(menu).toContainText('Your message was not published')
  await expect(menu).toContainText(
    'Message rejected: attachment 1 (' +
      filename +
      ') looks like nudity or sexual content.',
  )
  await expect(menu.getByTestId(/^hilos-notification-item-/)).toHaveCount(1)
})

test('the second picture refusal releases both inputs and a new selection can publish', async ({
  page,
}, info) => {
  const key = modelKey()
  const first = `${key}-a.png`
  const second = `${key}-b.png`
  const events: Record<string, unknown>[] = []
  const record = (event: Record<string, unknown>) =>
    events.push({ at: Date.now(), ...event })
  const trace = await pictureControls(page, record)
  const failures: unknown[] = []
  try {
    await createTestUserJoined(expect, page)
    await dictateModerationVerdict(key, true, 'ok')
    await dictateModerationVerdict(key, true, 'ok')
    await dictateModerationVerdict(key, false, 'violence')
    await dictateGatewayBehavior('/model/api/generate', key, {})
    await dictateGatewayBehavior('/model/api/generate', key, {})
    await dictateGatewayBehavior('/model/api/generate', key, { delayMs: 1_600 })

    await attachPngs(page, [
      { name: first, buffer: PICTURE_PNG },
      { name: second, buffer: OTHER_PNG },
    ])
    const originals = structuredClone(trace.observer.summary())
    expect(originals).toHaveLength(2)
    const acceptKey = trace.self.acceptKey
    if (typeof acceptKey !== 'string') throw new Error('No author accept key')
    await typeInto(page.getByTestId('message-input'), key)
    await clickSubmit(expect, page.getByTestId('message-send'))
    await expect
      .poll(
        async () =>
          (await readModelRequests(key)).filter(
            ({ imageHashes }) => imageHashes.length > 0,
          ).length,
      )
      .toBe(2)
    const rejectedStage = await observeModeration(
      acceptKey,
      'chat_message_picture',
    )
    expect(rejectedStage.activeRequestId).toEqual(expect.any(String))
    record({
      kind: 'refused-a2-active',
      rejectedStage,
      originals,
      arrivals: await readModelRequests(key),
    })

    await expect(page.getByTestId('moderation-text')).toHaveText(
      'Message rejected: attachment 2 (' + second + ') looks violent.',
    )
    const rejectedAt = Date.now()
    await expect(
      page.getByTestId('event-text').filter({ hasText: key }),
    ).toHaveCount(0)
    await expect(page.getByTestId('message-input')).toHaveValue(key)
    const chips = page.getByTestId('attachments-file')
    await expect(chips).toHaveCount(2)
    for (const chip of await chips.all()) {
      await expect(chip).toHaveAttribute('data-state', 'received')
    }
    await expect(page.getByTestId('attachments-file-name')).toHaveText([
      first,
      second,
    ])

    await expect(unreadBadge(page)).toHaveText(/^1\b/)
    await openBell(page)
    const menu = page.getByTestId('hilos-notification-menu')
    await expect(menu).toContainText('Your message was not published')
    await expect(menu).toContainText(
      'Message rejected: attachment 2 (' + second + ') looks violent.',
    )
    await expect(menu.getByTestId(/^hilos-notification-item-/)).toHaveCount(1)
    await page.getByTestId('hilos-notification-toggle').click()

    await test.step('release permits real owner cancels and new SDK files before the old hold deadline', async () => {
      for (const original of originals) {
        const requestId = await trace.mutate(original.clientUploadId, 'cancel')
        await expect
          .poll(() => trace.reply(requestId))
          .toMatchObject({
            type: 'action_success',
            requestId,
            data: { action: 'hilos_upload_cancel' },
          })
        record({
          kind: 'released-cancel',
          clientUploadId: original.clientUploadId,
          requestId,
          reply: trace.reply(requestId),
          elapsedMs: Date.now() - rejectedAt,
        })
      }
      await expect(page.getByTestId('attachments-file')).toHaveCount(0)
      expect(Date.now() - rejectedAt).toBeLessThan(10_000)
    })
    const retryFiles = [
      { name: `${key}-retry-a.png`, buffer: OTHER_PNG },
      { name: `${key}-retry-b.png`, buffer: PICTURE_PNG },
    ]
    await attachPngs(page, retryFiles)
    const retryUploads = trace.observer.summary().slice(2)
    expect(retryUploads).toHaveLength(2)
    for (const [index, upload] of retryUploads.entries()) {
      expect(
        originals.map(({ clientUploadId }) => clientUploadId),
      ).not.toContain(upload.clientUploadId)
      expect(upload.sentSha256).toBe(
        createHash('sha256').update(retryFiles[index]!.buffer).digest('hex'),
      )
      expect(upload.states).toContainEqual(
        expect.objectContaining({ phase: 'complete' }),
      )
    }
    await dictateModerationVerdict(key, true, 'ok')
    await dictateModerationVerdict(key, true, 'ok')
    await dictateModerationVerdict(key, true, 'ok')
    await dictateGatewayBehavior('/model/api/generate', key, {})
    await dictateGatewayBehavior('/model/api/generate', key, {})
    await dictateGatewayBehavior('/model/api/generate', key, { delayMs: 1_600 })
    await expect(page.getByTestId('message-send')).toBeEnabled({
      timeout: 20_000,
    })
    await clickSubmit(expect, page.getByTestId('message-send'))
    await expect
      .poll(
        async () =>
          (await readModelRequests(key)).filter(
            ({ imageHashes }) => imageHashes.length > 0,
          ).length,
      )
      .toBe(4)
    const retryStage = await observeModeration(
      acceptKey,
      'chat_message_picture',
    )
    expect(retryStage.activeRequestId).toEqual(expect.any(String))
    expect(retryStage.checkRequestId).not.toBe(rejectedStage.checkRequestId)
    expect(retryStage.activeRequestId).not.toBe(rejectedStage.activeRequestId)
    record({
      kind: 'retry-a2-active',
      retryStage,
      retryUploads,
      arrivals: await readModelRequests(key),
    })
    const messages = page.getByTestId('event-text').filter({ hasText: key })
    await expect(messages).toHaveCount(1)
    await expect(page.getByTestId('moderation-text')).toHaveCount(0)
    await expect(page.getByTestId('message-input')).toHaveValue('')
    await expect(page.getByTestId('attachments-file')).toHaveCount(0)
    const event = page.getByTestId('event').filter({ has: messages })
    await expect(event.getByTestId('event-attachment-image')).toHaveCount(2)
    for (const [index, file] of retryFiles.entries()) {
      const attachment = event.getByTestId('event-attachment').nth(index)
      await expect(
        attachment.getByTestId('event-attachment-image'),
      ).toHaveAttribute('alt', file.name)
      const href = await attachment.getAttribute('href')
      if (!href || href.includes('variant='))
        throw new Error('No original download path')
      const response = await page.request.get(href)
      expect(response.status()).toBe(200)
      const bytes = await response.body()
      expect(bytes).toEqual(file.buffer)
      record({
        kind: 'retry-original-download',
        index,
        href,
        sha256: createHash('sha256').update(bytes).digest('hex'),
      })
    }
    expect(trace.observer.errors).toEqual([])
  } catch (error) {
    record({ kind: 'scenario-error', error: String(error) })
    failures.push(error)
  } finally {
    try {
      await forgetModelKey(key)
    } catch (error) {
      failures.push(error)
    }
    trace.observer.stop()
    const path = info.outputPath('picture-release-retry-proof.json')
    await writeFile(
      path,
      JSON.stringify(
        {
          events,
          errors: trace.observer.errors,
          uploads: trace.observer.summary(),
        },
        null,
        2,
      ),
    )
    await info.attach('picture-release-retry-proof', {
      path,
      contentType: 'application/json',
    })
  }
  if (failures.length === 1) throw failures[0]
  if (failures.length)
    throw new AggregateError(
      failures,
      'Picture retry scenario or cleanup failed',
    )
})

test('an unavailable picture returns its chip and a corrected retry publishes once', async ({
  page,
}) => {
  await createTestUserJoined(expect, page)

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
  await expect(page.getByTestId('message-input')).toHaveValue(key)
  await expect(page.getByTestId('attachments-file')).toHaveAttribute(
    'data-state',
    'received',
  )
  await expect(page.getByTestId('attachments-file-name')).toHaveText(filename)

  await openBell(page)
  await expect(page.getByTestId('hilos-notification-empty')).toBeVisible()
  await expect(page.getByTestId(/^hilos-notification-item-/)).toHaveCount(0)
  await expect(unreadBadge(page)).toBeHidden()
  await page.getByTestId('hilos-notification-toggle').click()

  const corrected = `a corrected picture message ${key}`
  await dictateModerationVerdict(key, true, 'ok')
  await dictateModerationVerdict(key, true, 'ok')
  await typeInto(page.getByTestId('message-input'), corrected)
  // Unavailable does not clear the original ten-second submit lockout.
  await expect(page.getByTestId('message-send')).toBeEnabled({
    timeout: 20_000,
  })
  await clickSubmit(expect, page.getByTestId('message-send'))
  await expect(page.getByTestId('moderation-text')).toHaveCount(0)
  await expect(page.getByTestId('message-input')).toHaveValue('')
  await expect(page.getByTestId('attachments-file')).toHaveCount(0)
  const messages = page.getByTestId('event-text').filter({ hasText: key })
  await expect(messages).toHaveCount(1)
  await expect(messages).toHaveText(corrected)
  const event = page.getByTestId('event').filter({ has: messages })
  await expect(event.getByTestId('event-attachment')).toHaveCount(1)
  await expect(event.getByTestId('event-attachment-image')).toHaveAttribute(
    'alt',
    filename,
  )
})

test('a truncated picture is refused as one that cannot be checked', async ({
  page,
}) => {
  await createTestUserJoined(expect, page)

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
  await expect(page.getByTestId('message-input')).toHaveValue(key)
  await expect(page.getByTestId('attachments-file')).toHaveAttribute(
    'data-state',
    'received',
  )
  await expect(page.getByTestId('attachments-file-name')).toHaveText(filename)

  await openBell(page)
  await expect(page.getByTestId('hilos-notification-empty')).toBeVisible()
  await expect(page.getByTestId(/^hilos-notification-item-/)).toHaveCount(0)
  await expect(unreadBadge(page)).toBeHidden()
})

test.describe('a replacement queued before upload hold admission', () => {
  // A controlled process pause must prove its causal checkpoints on one attempt.
  test.describe.configure({ retries: 0 })

  test('an accepted replacement cannot refresh the pending original picture snapshot', async ({
    browser,
  }, info) => {
    const timeoutKey = 'moderation.check_timeout_sec'
    const timeoutSec = 4
    const keys = { message: modelKey(), a: modelKey(), b: modelKey() }
    const events: Record<string, unknown>[] = []
    const record = (event: Record<string, unknown>) => {
      events.push({ at: Date.now(), ...event })
    }
    const authorContext = await browser.newContext()
    const adminContext = await browser.newContext()
    const author = await authorContext.newPage()
    const admin = await adminContext.newPage()
    const replacement = { name: `${keys.b}.png`, buffer: OTHER_PNG }
    const trace = await queuedPicture(author, replacement, record)
    let borrowed: { custom: boolean; value: string } | undefined
    const failures: unknown[] = []
    try {
      await createTestUserJoined(expect, author)
      await createTestAdmin(expect, admin)
      const worker = await readMonopolisticWorker(
        admin,
        UPLOADS_WORKER_AGENT,
        (page) => gotoPage(expect, page, '/hilos/daemon/standalone/workers'),
        async (predicate) => {
          await expect.poll(predicate).toBe(true)
        },
      )
      await gotoPage(expect, admin, '/hilos/settings')
      await typeInto(admin.getByTestId('hilos-table-search'), timeoutKey)
      await openSettingEdit(admin, timeoutKey)
      borrowed = {
        custom: await admin
          .getByTestId('hilos-settings-edit-custom')
          .isChecked(),
        value: await admin
          .getByTestId('hilos-settings-edit-value')
          .inputValue(),
      }
      await admin.getByTestId('hilos-settings-edit-cancel').click()
      await setCustomSetting(admin, timeoutKey, String(timeoutSec))
      record({
        kind: 'borrowed-timeout',
        seconds: timeoutSec,
        original: borrowed,
      })

      await dictateModerationVerdict(keys.message, true, 'ok')
      await dictateModerationVerdict(keys.a, true, 'ok')
      await dictateGatewayBehavior('/model/api/generate', keys.a, {
        delayMs: 1_000,
      })
      // Announce B for request capture without dictating a verdict for it.
      await dictateGatewayBehavior('/model/api/generate', keys.b, {})
      await attachPngs(author, [{ name: `${keys.a}.png`, buffer: PICTURE_PNG }])
      expect(trace.observer.errors).toEqual([])
      expect(trace.observer.summary()).toHaveLength(1)
      const original = trace.observer.summary()[0]!
      expect(original.accepted).toBe(true)
      expect(original.sentSha256).toBe(
        createHash('sha256').update(PICTURE_PNG).digest('hex'),
      )
      expect(original.states).toContainEqual(
        expect.objectContaining({ phase: 'complete' }),
      )
      const connection = trace.connections.find(
        ({ socket }) => socket === original.socket,
      )!
      expect(connection.closed).toBe(false)
      expect(connection.acceptKey).toEqual(expect.any(String))
      const acceptKey = connection.acceptKey!
      record({ kind: 'original-complete', connection, original })
      await typeInto(author.getByTestId('message-input'), keys.message)

      let checkRequestId: string | null = null
      await withStoppedUploadWorker(worker, record, async (held) => {
        trace.arm()
        await clickSubmit(expect, author.getByTestId('message-send'))
        await expect.poll(() => trace.admitted()).toBe(true)
        await expect
          .poll(async () => {
            const observation = await observeModeration(
              acceptKey,
              'chat_message',
            )
            record({ kind: 'moderator-fence', observation })
            checkRequestId = observation.checkRequestId
            return observation
          })
          .toEqual({
            checkRequestId: expect.any(String),
            activeRequestId: null,
            waitingForUploadHold: true,
          })
        await held.assertStopped()
        const arrivals = await Promise.all(
          Object.values(keys).map(readModelRequests),
        )
        record({ kind: 'held-model-arrivals', keys, arrivals, checkRequestId })
        expect(arrivals).toEqual([[], [], []])
        expect(trace.queued()?.accepted).toBeNull()
        expect(trace.observer.errors).toEqual([])
        await held.assertStopped()
      })

      await expect
        .poll(() =>
          trace.observer
            .summary()[1]
            ?.states.some(({ phase }) => phase === 'complete'),
        )
        .toBe(true)
      expect(trace.observer.errors).toEqual([])
      expect(trace.observer.summary()).toHaveLength(2)
      const replaced = trace.observer.summary()[1]!
      expect(replaced).toMatchObject({
        socket: original.socket,
        clientUploadId: original.clientUploadId,
        target: original.target,
        filename: replacement.name,
        accepted: true,
        demands: [OTHER_PNG.length],
        sentBytes: OTHER_PNG.length,
        sentSha256: createHash('sha256').update(OTHER_PNG).digest('hex'),
      })
      expect(replaced.requestId).not.toBe(original.requestId)
      expect(replaced.sentSha256).not.toBe(original.sentSha256)
      expect(Math.max(...replaced.grants)).toBe(OTHER_PNG.length)
      const complete = events.find(
        (event) =>
          event.kind === 'incarnation-state' &&
          event.initRequestId === replaced.requestId &&
          event.phase === 'complete',
      )!
      // The deadline starts after the message is handled; this earlier local
      // timestamp gives a stricter bound and rules out expiry before replacement.
      expect(Number(complete.at) - trace.queued()!.sentAt).toBeLessThan(
        timeoutSec * 1_000,
      )
      record({
        kind: 'replacement-complete-before-deadline',
        checkRequestId,
        originalSubmitAt: trace.queued()!.sentAt,
        replaced,
        observation: await observeModeration(acceptKey, 'chat_message'),
      })
      await expect(author.getByTestId('moderation-text')).toHaveText(
        'Moderation is unavailable right now. Your message is still here.',
      )
      await expect(author.getByTestId('message-input')).toHaveValue(
        keys.message,
      )
      await expect(
        author.getByTestId('event-text').filter({ hasText: keys.message }),
      ).toHaveCount(0)
      await expect(
        author.getByTestId('event').filter({
          has: author.getByRole('img', { name: replacement.name, exact: true }),
        }),
      ).toHaveCount(0)
      await openBell(author)
      await expect(author.getByTestId('hilos-notification-empty')).toBeVisible()
      await expect(author.getByTestId(/^hilos-notification-item-/)).toHaveCount(
        0,
      )
      await expect(unreadBadge(author)).toBeHidden()
      const terminal = await observeModeration(acceptKey, 'chat_message')
      expect(terminal).toEqual({
        checkRequestId: null,
        activeRequestId: null,
        waitingForUploadHold: false,
      })
      const arrivals = await Promise.all(
        Object.values(keys).map(readModelRequests),
      )
      // Keep every delivered event window, including an event that could have
      // disappeared before the terminal DOM assertion.
      const eventDeliveries = events.flatMap((event) => {
        if (event.kind !== 'received-frame') return []
        const data = deliveryObject(deliveryObject(event.wire).data)
        const lists = deliveryObject(deliveryObject(data.payload).lists)
        return lists.mainEvents === undefined ? [] : [lists.mainEvents]
      })
      expect(JSON.stringify(eventDeliveries)).not.toContain(keys.message)
      expect(JSON.stringify(eventDeliveries)).not.toContain(keys.b)
      record({
        kind: 'terminal-unavailable',
        terminal,
        keys,
        arrivals,
        eventDeliveries,
        submissions: trace.submissions(),
      })
      expect(arrivals).toEqual([[], [], []])
      expect(trace.submissions()).toBe(1)
      expect(connection.closed).toBe(false)
      expect(trace.connections.filter(({ closed }) => !closed)).toEqual([
        connection,
      ])
      expect(trace.observer.errors).toEqual([])
    } catch (error) {
      record({ kind: 'scenario-error', error: String(error) })
      failures.push(error)
    } finally {
      const cleanupStart = failures.length
      if (borrowed) {
        try {
          await gotoPage(expect, admin, '/hilos/settings')
          await typeInto(admin.getByTestId('hilos-table-search'), timeoutKey)
          if (borrowed.custom)
            await setCustomSetting(admin, timeoutKey, borrowed.value)
          else await clearCustomSetting(admin, timeoutKey)
        } catch (error) {
          failures.push(error)
        }
      }
      const cleaned = await Promise.allSettled([
        ...Object.values(keys).map(forgetModelKey),
        authorContext.close(),
        adminContext.close(),
      ])
      for (const result of cleaned)
        if (result.status === 'rejected') failures.push(result.reason)
      record({
        kind: 'cleanup',
        errors: failures.slice(cleanupStart).map(String),
      })
      const path = info.outputPath('queued-picture-proof.json')
      await writeFile(
        path,
        JSON.stringify(
          {
            events,
            errors: trace.observer.errors,
            uploads: trace.observer.summary(),
          },
          null,
          2,
        ),
      )
      await info.attach('queued-picture-proof', {
        path,
        contentType: 'application/json',
      })
    }
    if (failures.length === 1) throw failures[0]
    if (failures.length)
      throw new AggregateError(
        failures,
        'Queued picture scenario or cleanup failed',
      )
  })
})

test.describe('the whole picture group through final owner handover', () => {
  test.describe.configure({ retries: 0 })

  test('A1 stays held across A2, Chat restart and final owner handover', async ({
    browser,
  }, info) => {
    const keys = {
      message: modelKey(),
      a1: modelKey(),
      a2: modelKey(),
      b: modelKey(),
    }
    const files = [
      { name: `${keys.a1}.png`, buffer: PICTURE_PNG },
      { name: `${keys.a2}.png`, buffer: OTHER_PNG },
    ]
    const events: Record<string, unknown>[] = []
    const record = (event: Record<string, unknown>) => {
      events.push({ at: Date.now(), ...event })
    }
    const authorContext = await browser.newContext()
    const adminContext = await browser.newContext()
    const author = await authorContext.newPage()
    const admin = await adminContext.newPage()
    const trace = await pictureControls(author, record)
    const failures: unknown[] = []
    try {
      await createTestUserJoined(expect, author)
      await createTestAdmin(expect, admin)
      const worker = await readMonopolisticWorker(
        admin,
        UPLOADS_WORKER_AGENT,
        (page) => gotoPage(expect, page, '/hilos/daemon/standalone/workers'),
        async (predicate) => {
          await expect.poll(predicate).toBe(true)
        },
      )
      const jpeg = await drawPhotoFixture(author, {
        width: 2,
        height: 2,
        mimeType: 'image/jpeg',
        rectangles: [{ color: '#0000ff', x: 0, y: 0, width: 2, height: 2 }],
      })
      // Announce the text key first: TEXT_WITH_FILES also names both pictures.
      await dictateModerationVerdict(keys.message, true, 'ok')
      await dictateModerationVerdict(keys.a1, true, 'ok')
      await dictateModerationVerdict(keys.a2, true, 'ok')
      await dictateGatewayBehavior('/model/api/generate', keys.b, {})
      await dictateGatewayBehavior('/model/api/generate', keys.a1, {
        delayMs: 1_600,
      })
      await dictateGatewayBehavior('/model/api/generate', keys.a2, {
        delayMs: 4_000,
      })
      await attachPngs(author, files)
      const originals = structuredClone(trace.observer.summary())
      expect(originals).toHaveLength(2)
      expect(new Set(originals.map(({ socket }) => socket)).size).toBe(1)
      for (const [index, original] of originals.entries()) {
        expect(original.accepted).toBe(true)
        expect(original.sentSha256).toBe(
          createHash('sha256').update(files[index]!.buffer).digest('hex'),
        )
        expect(original.states).toContainEqual(
          expect.objectContaining({ phase: 'complete' }),
        )
      }
      expect(trace.observer.errors).toEqual([])
      const acceptKey = trace.self.acceptKey
      expect(typeof acceptKey).toBe('string')
      if (typeof acceptKey !== 'string') throw new Error('No author accept key')
      record({ kind: 'group-complete', acceptKey, originals })
      await typeInto(author.getByTestId('message-input'), keys.message)
      await clickSubmit(expect, author.getByTestId('message-send'))

      const activePicture = async (key: string) => {
        await expect
          .poll(
            async () =>
              (await readModelRequests(key)).filter(
                ({ imageHashes }) => imageHashes.length > 0,
              ).length,
          )
          .toBe(1)
        const arrivals = await readModelRequests(key)
        const observation = await observeModeration(
          acceptKey,
          'chat_message_picture',
        )
        expect(observation.checkRequestId).toEqual(expect.any(String))
        expect(observation.activeRequestId).toEqual(expect.any(String))
        expect(observation.waitingForUploadHold).toBe(false)
        const submission = await observeChatSubmission(acceptKey)
        expect(submission.phase).toBe('checking')
        expect(submission.activeCheckRequestIds).toContain(
          observation.checkRequestId,
        )
        record({
          kind: 'active-picture',
          key,
          arrivals,
          observation,
          submission,
        })
        return observation
      }
      const firstStage = await activePicture(keys.a1)
      const secondStage = await activePicture(keys.a2)
      expect(secondStage.checkRequestId).not.toBe(firstStage.checkRequestId)
      expect(secondStage.activeRequestId).not.toBe(firstStage.activeRequestId)
      expect(
        (await observeChatSubmission(acceptKey)).activeCheckRequestIds,
      ).not.toContain(firstStage.checkRequestId)

      await test.step('daemon runs Chat stop/start hooks while A2 is still active', async () => {
        const command = createStandCommandChannel({ timeoutMs: 5_000 })
        const reply = await command('agent:restart', {
          agentType: 'chat',
          agentIndex: null,
          nodeId: null,
        })
        expect(reply).toEqual({ agentId: 'chat', nodeId: 'standalone' })
        expect(
          await observeModeration(acceptKey, 'chat_message_picture'),
        ).toEqual(secondStage)
        const submission = await observeChatSubmission(acceptKey)
        expect(submission.phase).toBe('checking')
        expect(submission.activeCheckRequestIds).toEqual([
          secondStage.checkRequestId,
        ])
        record({
          kind: 'chat-hook-restarted-with-active-a2',
          reply,
          secondStage,
          submission,
        })
      })

      await test.step('owner refuses replacement, cancel and a registered cross-target on both COMPLETE inputs', async () => {
        for (const original of originals) {
          for (const mutation of ['init', 'cancel', 'cross-target'] as const) {
            const requestId = await trace.mutate(
              original.clientUploadId,
              mutation === 'cancel' ? 'cancel' : 'init',
              {
                name: `${keys.b}.${mutation === 'cross-target' ? 'jpg' : 'png'}`,
                buffer: mutation === 'cross-target' ? jpeg : OTHER_PNG,
                target:
                  mutation === 'cross-target'
                    ? 'hilos_profile_photo'
                    : original.target,
                mimeType: mutation === 'cross-target' ? 'image/jpeg' : PNG_MIME,
              },
            )
            await expect
              .poll(() => trace.reply(requestId))
              .toMatchObject({
                type: 'action_error',
                requestId,
                data: {
                  action:
                    mutation === 'cancel'
                      ? 'hilos_upload_cancel'
                      : 'hilos_upload_init',
                  reason: 'This file is being checked; wait for the result',
                },
              })
            record({
              kind: 'held-mutation-refused',
              mutation,
              clientUploadId: original.clientUploadId,
              requestId,
              reply: trace.reply(requestId),
            })
          }
        }
        const stillActive = await observeModeration(
          acceptKey,
          'chat_message_picture',
        )
        expect(stillActive).toEqual(secondStage)
        expect(trace.observer.errors).toEqual([])
        const afterMutations = trace.observer.summary()
        expect(afterMutations.slice(0, 2)).toEqual(originals)
        expect(afterMutations.slice(2)).toHaveLength(4)
        expect(
          afterMutations
            .slice(2)
            .every(
              ({ accepted, sentBytes, states }) =>
                accepted === false && sentBytes === 0 && states.length === 0,
            ),
        ).toBe(true)
        await expect(author.getByTestId('attachments-file-name')).toHaveText(
          files.map(({ name }) => name),
        )
        record({
          kind: 'group-survived-next-stage',
          firstStage,
          secondStage,
          stillActive,
          uploads: afterMutations,
        })
      })

      let queuedCancel: string | undefined
      await withStoppedUploadWorker(worker, record, async (held) => {
        // Fail if A2 finished before STOP; a later empty check is no substitute.
        const activeWhileStopped = await observeModeration(
          acceptKey,
          'chat_message_picture',
        )
        expect(activeWhileStopped).toEqual(secondStage)
        record({
          kind: 'final-image-active-while-stopped',
          observation: activeWhileStopped,
        })
        await held.assertStopped()
        await expect
          .poll(async () => {
            await held.assertStopped()
            const submission = await observeChatSubmission(acceptKey)
            record({ kind: 'handover-fifo-observation', submission })
            return submission
          })
          .toEqual({ phase: 'checking', activeCheckRequestIds: [] })
        expect(
          await observeModeration(acceptKey, 'chat_message_picture'),
        ).toEqual({
          checkRequestId: null,
          activeRequestId: null,
          waitingForUploadHold: false,
        })
        await held.assertStopped()
        // Chat's approve/publish were routed before the observation reply.
        // This cancel is deliberately behind publication, not a proof that it
        // was blocked before publication (the earlier six refusals prove that).
        queuedCancel = await trace.mutate(
          originals[0]!.clientUploadId,
          'cancel',
        )
        expect(trace.reply(queuedCancel)).toBeUndefined()
        record({ kind: 'cancel-queued-after-publish', requestId: queuedCancel })
        await held.assertStopped()
      })
      await expect
        .poll(() => trace.reply(queuedCancel!))
        .toMatchObject({
          type: 'action_success',
          requestId: queuedCancel,
          data: { action: 'hilos_upload_cancel' },
        })
      const messages = author
        .getByTestId('event-text')
        .filter({ hasText: keys.message })
      await expect(messages).toHaveCount(1)
      await expect(author.getByTestId('message-input')).toHaveValue('')
      await expect(author.getByTestId('attachments-file')).toHaveCount(0)
      const event = author.getByTestId('event').filter({ has: messages })
      const attachments = event.getByTestId('event-attachment')
      await expect(attachments).toHaveCount(2)
      for (const [index, file] of files.entries()) {
        const attachment = attachments.nth(index)
        await expect(
          attachment.getByTestId('event-attachment-image'),
        ).toHaveAttribute('alt', file.name)
        const href = await attachment.getAttribute('href')
        if (!href) throw new Error('Published original has no download path')
        expect(href).toContain('/_hilos/file?')
        expect(href).not.toContain('variant=')
        const response = await author.request.get(href)
        expect(response.status()).toBe(200)
        const bytes = await response.body()
        expect(bytes).toEqual(file.buffer)
        record({
          kind: 'original-download',
          index,
          filename: file.name,
          href,
          bytes: bytes.length,
          sha256: createHash('sha256').update(bytes).digest('hex'),
          expectedSha256: createHash('sha256')
            .update(file.buffer)
            .digest('hex'),
        })
      }
      const arrivals = await Promise.all(
        Object.values(keys).map(readModelRequests),
      )
      expect(arrivals[0]).toEqual([
        { ordinal: 1, key: keys.message, imageHashes: [] },
      ])
      for (const index of [1, 2]) {
        expect(arrivals[index]).toHaveLength(1)
        expect(arrivals[index]![0]!.imageHashes).toHaveLength(1)
      }
      expect(arrivals[1]![0]!.imageHashes).not.toEqual(
        arrivals[2]![0]!.imageHashes,
      )
      expect(arrivals[3]).toEqual([])
      await openBell(author)
      await expect(author.getByTestId('hilos-notification-empty')).toBeVisible()
      expect(trace.observer.errors).toEqual([])
      record({
        kind: 'published-original-group',
        arrivals,
        keys,
        cancelReply: trace.reply(queuedCancel!),
      })
    } catch (error) {
      record({ kind: 'scenario-error', error: String(error) })
      failures.push(error)
    } finally {
      const cleanupStart = failures.length
      const cleaned = await Promise.allSettled([
        ...Object.values(keys).map(forgetModelKey),
        authorContext.close(),
        adminContext.close(),
      ])
      for (const result of cleaned)
        if (result.status === 'rejected') failures.push(result.reason)
      trace.observer.stop()
      record({
        kind: 'cleanup',
        errors: failures.slice(cleanupStart).map(String),
      })
      const path = info.outputPath('picture-group-handover-proof.json')
      await writeFile(
        path,
        JSON.stringify(
          {
            events,
            errors: trace.observer.errors,
            uploads: trace.observer.summary(),
          },
          null,
          2,
        ),
      )
      await info.attach('picture-group-handover-proof', {
        path,
        contentType: 'application/json',
      })
    }
    if (failures.length === 1) throw failures[0]
    if (failures.length)
      throw new AggregateError(
        failures,
        'Picture group scenario or cleanup failed',
      )
  })

  test('uploads stop/start invalidates the old verdict before a same-id replacement completes', async ({
    page,
  }, info) => {
    const keys = {
      message: modelKey(),
      a1: modelKey(),
      a2: modelKey(),
      b: modelKey(),
      retry: modelKey(),
      retryImage: modelKey(),
    }
    const events: Record<string, unknown>[] = []
    const record = (event: Record<string, unknown>) => {
      events.push({ at: Date.now(), ...event })
    }
    const trace = await pictureControls(page, record)
    const failures: unknown[] = []
    let replacementId: string | undefined
    try {
      await createTestUserJoined(expect, page)
      await dictateModerationVerdict(keys.message, true, 'ok')
      await dictateModerationVerdict(keys.a1, true, 'ok')
      await dictateModerationVerdict(keys.a2, true, 'ok')
      await dictateGatewayBehavior('/model/api/generate', keys.a2, {
        delayMs: 2_000,
      })
      await dictateGatewayBehavior('/model/api/generate', keys.b, {})
      await attachPngs(page, [
        { name: `${keys.a1}.png`, buffer: PICTURE_PNG },
        { name: `${keys.a2}.png`, buffer: OTHER_PNG },
      ])
      const originals = structuredClone(trace.observer.summary())
      expect(originals).toHaveLength(2)
      const acceptKey = trace.self.acceptKey
      if (typeof acceptKey !== 'string') throw new Error('No author accept key')
      await typeInto(page.getByTestId('message-input'), keys.message)
      await clickSubmit(expect, page.getByTestId('message-send'))
      await expect
        .poll(
          async () =>
            (await readModelRequests(keys.a2)).filter(
              ({ imageHashes }) => imageHashes.length > 0,
            ).length,
        )
        .toBe(1)
      const active = await observeModeration(acceptKey, 'chat_message_picture')
      expect(active.activeRequestId).toEqual(expect.any(String))
      expect(active.waitingForUploadHold).toBe(false)
      record({
        kind: 'uploads-restart-with-active-a2',
        originals,
        active,
        arrivals: await readModelRequests(keys.a2),
      })
      const command = createStandCommandChannel({ timeoutMs: 5_000 })
      const reply = await command('agent:restart', {
        agentType: 'hilos_uploads',
        agentIndex: null,
        nodeId: null,
      })
      expect(reply).toEqual({ agentId: 'hilos_uploads', nodeId: 'standalone' })
      await expect(page.getByTestId('moderation-text')).toHaveText(
        'Moderation is unavailable right now. Your message is still here.',
      )
      await expect(page.getByTestId('message-input')).toHaveValue(keys.message)
      await expect(page.getByTestId('attachments-file')).toHaveCount(0)
      for (const original of originals) {
        expect(
          trace.observer.attempts.find(
            ({ requestId }) => requestId === original.requestId,
          )?.states,
        ).toContainEqual(expect.objectContaining({ phase: null }))
      }
      expect(await observeChatSubmission(acceptKey)).toEqual({
        phase: 'unavailable',
        activeCheckRequestIds: [],
      })
      expect(
        await observeModeration(acceptKey, 'chat_message_picture'),
      ).toEqual({
        checkRequestId: null,
        activeRequestId: null,
        waitingForUploadHold: false,
      })
      record({ kind: 'uploads-hooks-cleared-original-chain', reply })

      replacementId = originals[0]!.clientUploadId
      const requestId = await trace.mutate(replacementId, 'init', {
        name: `${keys.b}.png`,
        buffer: OTHER_PNG,
        target: 'chat_attachment',
      })
      await expect
        .poll(() => trace.reply(requestId))
        .toMatchObject({
          type: 'action_success',
          requestId,
          data: { action: 'hilos_upload_init' },
        })
      await trace.finishReplacement(expect, replacementId, OTHER_PNG)
      const replacement = trace.observer.summary().at(-1)!
      expect(replacement).toMatchObject({
        clientUploadId: originals[0]!.clientUploadId,
        socket: originals[0]!.socket,
        accepted: true,
        sentBytes: OTHER_PNG.length,
        sentSha256: createHash('sha256').update(OTHER_PNG).digest('hex'),
      })
      expect(replacement.requestId).not.toBe(originals[0]!.requestId)
      expect(replacement.sentSha256).not.toBe(originals[0]!.sentSha256)
      expect(trace.observer.errors).toEqual([])
      record({
        kind: 'replacement-complete-after-uploads-restart',
        replacement,
      })
      await openBell(page)
      await expect(page.getByTestId('hilos-notification-empty')).toBeVisible()
      await expect(unreadBadge(page)).toBeHidden()
      await page.getByTestId('hilos-notification-toggle').click()

      // A real new selection and the original submit lockout carry us beyond
      // the old delayed allow. No manual check/connection restore is involved.
      const retryFile = { name: `${keys.retryImage}.png`, buffer: PICTURE_PNG }
      await attachPngs(page, [retryFile])
      await dictateModerationVerdict(keys.retry, true, 'ok')
      await dictateModerationVerdict(keys.retryImage, true, 'ok')
      await typeInto(page.getByTestId('message-input'), keys.retry)
      await expect(page.getByTestId('message-send')).toBeEnabled({
        timeout: 20_000,
      })
      await clickSubmit(expect, page.getByTestId('message-send'))
      const messages = page
        .getByTestId('event-text')
        .filter({ hasText: keys.retry })
      await expect(messages).toHaveCount(1)
      await expect(
        page.getByTestId('event-text').filter({ hasText: keys.message }),
      ).toHaveCount(0)
      await expect(
        page.getByTestId('event').filter({
          has: page.getByRole('img', { name: `${keys.b}.png`, exact: true }),
        }),
      ).toHaveCount(0)
      const attachment = page
        .getByTestId('event')
        .filter({ has: messages })
        .getByTestId('event-attachment')
      await expect(attachment).toHaveCount(1)
      await expect(
        attachment.getByTestId('event-attachment-image'),
      ).toHaveAttribute('alt', retryFile.name)
      const href = await attachment.getAttribute('href')
      if (!href || href.includes('variant='))
        throw new Error('No original download path')
      const response = await page.request.get(href)
      expect(response.status()).toBe(200)
      expect(await response.body()).toEqual(PICTURE_PNG)
      const arrivals = await Promise.all(
        Object.values(keys).map(readModelRequests),
      )
      expect(arrivals[3]).toEqual([])
      expect(await observeChatSubmission(acceptKey)).toEqual({
        phase: '',
        activeCheckRequestIds: [],
      })
      const eventDeliveries = events.flatMap((event) => {
        if (event.kind !== 'incarnation-received-frame') return []
        const data = deliveryObject(deliveryObject(event.wire).data)
        const lists = deliveryObject(deliveryObject(data.payload).lists)
        return lists.mainEvents === undefined ? [] : [lists.mainEvents]
      })
      expect(JSON.stringify(eventDeliveries)).not.toContain(keys.message)
      expect(JSON.stringify(eventDeliveries)).not.toContain(keys.b)
      expect(trace.observer.errors).toEqual([])
      record({
        kind: 'only-new-submission-published',
        keys,
        arrivals,
        eventDeliveries,
        href,
        sha256: createHash('sha256')
          .update(await response.body())
          .digest('hex'),
      })
    } catch (error) {
      record({ kind: 'scenario-error', error: String(error) })
      failures.push(error)
    } finally {
      const cleanupStart = failures.length
      if (replacementId) {
        try {
          const requestId = await trace.mutate(replacementId, 'cancel')
          await expect
            .poll(() => trace.reply(requestId))
            .toMatchObject({
              type: 'action_success',
              requestId,
              data: { action: 'hilos_upload_cancel' },
            })
        } catch (error) {
          failures.push(error)
        }
      }
      const cleaned = await Promise.allSettled(
        Object.values(keys).map(forgetModelKey),
      )
      for (const result of cleaned)
        if (result.status === 'rejected') failures.push(result.reason)
      trace.observer.stop()
      record({
        kind: 'cleanup',
        errors: failures.slice(cleanupStart).map(String),
      })
      const path = info.outputPath('picture-uploads-restart-proof.json')
      await writeFile(
        path,
        JSON.stringify(
          {
            events,
            errors: trace.observer.errors,
            uploads: trace.observer.summary(),
          },
          null,
          2,
        ),
      )
      await info.attach('picture-uploads-restart-proof', {
        path,
        contentType: 'application/json',
      })
    }
    if (failures.length === 1) throw failures[0]
    if (failures.length)
      throw new AggregateError(
        failures,
        'Uploads restart scenario or cleanup failed',
      )
  })
})

test.describe('an active picture loses its submitting connection or person', () => {
  test.describe.configure({ retries: 0 })

  for (const transition of ['close', 'sign-out'] as const) {
    test(`${transition} discards the old attempt before a new person's picture publishes`, async ({
      browser,
    }, info) => {
      const delayMs = 3_500
      const keys = {
        message: modelKey(),
        first: modelKey(),
        last: modelKey(),
        witness: modelKey(),
        witnessImage: modelKey(),
      }
      const events: Record<string, unknown>[] = []
      const record = (event: Record<string, unknown>) => {
        events.push({ at: Date.now(), ...event })
      }
      const authorContext = await browser.newContext()
      const observerContext = await browser.newContext()
      const author = await authorContext.newPage()
      const observer = await observerContext.newPage()
      const authorFrames = watchDeliveryFrames(author)
      const observerFrames = watchDeliveryFrames(observer)
      const trace = await pictureControls(author, record)
      const witnessTrace = await pictureControls(observer, record)
      let closeControl: SocketReconnectControl | undefined
      const failures: unknown[] = []
      try {
        if (transition === 'close') await armSocketDrop(author)
        const oldPerson = await createTestUserJoined(expect, author)
        const newPerson = await createTestUserJoined(expect, observer)
        expect(newPerson.userId).not.toBe(oldPerson.userId)
        const originalFiles = [
          { name: `${keys.first}.png`, buffer: PICTURE_PNG },
          { name: `${keys.last}.png`, buffer: OTHER_PNG },
        ]
        await dictateModerationVerdict(keys.message, true, 'ok')
        await dictateModerationVerdict(keys.first, true, 'ok')
        await dictateModerationVerdict(keys.last, true, 'ok')
        await dictateGatewayBehavior('/model/api/generate', keys.last, {
          delayMs,
        })
        await attachPngs(author, originalFiles)
        const originals = structuredClone(trace.observer.summary())
        expect(originals).toHaveLength(2)
        expect(new Set(originals.map(({ socket }) => socket)).size).toBe(1)
        const submitSocket = originals[0]!.socket
        const acceptKey = trace.self.acceptKey
        if (typeof acceptKey !== 'string')
          throw new Error('No author accept key')
        for (const [index, upload] of originals.entries()) {
          expect(upload.accepted).toBe(true)
          expect(upload.sentBytes).toBe(originalFiles[index]!.buffer.length)
          expect(upload.sentSha256).toBe(
            createHash('sha256')
              .update(originalFiles[index]!.buffer)
              .digest('hex'),
          )
          expect(upload.states).toContainEqual(
            expect.objectContaining({ phase: 'complete' }),
          )
        }
        expect(
          authorFrames.connections[submitSocket - 1]!.closed,
        ).toBeUndefined()
        await typeInto(author.getByTestId('message-input'), keys.message)
        await clickSubmit(expect, author.getByTestId('message-send'))
        await expect
          .poll(async () => (await readModelRequests(keys.last)).length)
          .toBe(1)
        const arrivals = await readModelRequests(keys.last)
        const oldArrivalObservedAt = Date.now()
        expect(arrivals[0]!.imageHashes).toHaveLength(1)
        const active = await observeModeration(
          acceptKey,
          'chat_message_picture',
        )
        expect(active.checkRequestId).toEqual(expect.any(String))
        expect(active.activeRequestId).toEqual(expect.any(String))
        expect(active.waitingForUploadHold).toBe(false)
        const admitted = await observeChatSubmission(acceptKey)
        expect(admitted).toEqual({
          phase: 'checking',
          activeCheckRequestIds: [active.checkRequestId],
        })
        await expect(author.getByTestId('moderation-text')).toBeVisible()
        await expect(author.getByTestId('message-input')).toHaveValue(
          keys.message,
        )
        record({
          kind: 'lifecycle-active-image',
          transition,
          acceptKey,
          submitSocket,
          oldPerson,
          newPerson,
          originals,
          active,
          admitted,
          arrivals,
          oldArrivalObservedAt,
          delayMs,
        })

        await test.step('end the original attempt through the real lifecycle door', async () => {
          if (transition === 'close') {
            // Keep original binary sends native and observable. Routing only
            // future reconnects avoids Playwright's proxy hiding those bytes.
            closeControl = await controlSocketReconnect(author)
            await closeControl.hold()
            await dropSocket(author)
          } else {
            // Chat first binds this live socket to null, then hands over its
            // rotation ticket. The reconnect is part of the real sign-out.
            await logout(expect, author)
            const identities = authorFrames.packets.flatMap(({ frames }) =>
              frames.filter(
                ({ socket, wire }) =>
                  socket === submitSocket &&
                  deliveryObject(wire).type === 'handshake_response',
              ),
            )
            const identity = deliveryObject(identities.at(-1)?.wire)
            const data = deliveryObject(identity.data)
            expect(
              deliveryObject(
                deliveryObject(deliveryObject(identities[0]?.wire).data)
                  .entities,
              ).currentUser,
            ).toMatchObject({ id: oldPerson.userId })
            expect(deliveryObject(data.entities).currentUser).toBeNull()
            record({ kind: 'old-socket-became-guest', submitSocket, identity })
            await expect(author.getByTestId('self-user')).toHaveCount(0)
            await expect(author.getByTestId('message-input')).toHaveValue('')
            await expect(author.getByTestId('attachments-file')).toHaveCount(0)
          }
          await expect
            .poll(() => authorFrames.connections[submitSocket - 1]!.closed)
            .toEqual(expect.any(Number))
          await expect
            .poll(async () => {
              try {
                return await observeChatSubmission(acceptKey)
              } catch (error) {
                // Missing connection is a checked refusal, never an empty
                // observation. Transport and schema failures escape unchanged.
                if (
                  !(error instanceof Error) ||
                  error.message !==
                    'test:chat:submission-observe failed: No such Chat connection'
                )
                  throw error
                record({
                  kind: 'closed-connection-refused',
                  reason: error.message,
                })
                return 'closed'
              }
            })
            .toBe('closed')
          for (const kind of ['chat_message', 'chat_message_picture']) {
            const terminal = await observeModeration(acceptKey, kind)
            expect(terminal).toEqual({
              checkRequestId: null,
              activeRequestId: null,
              waitingForUploadHold: false,
            })
            record({
              kind: 'lifecycle-cleanup-observed',
              moderationKind: kind,
              terminal,
            })
          }
        })

        if (transition === 'sign-out') {
          await openSignIn(expect, author, 'message-signin')
          await expectSessionRotation(expect, author, () =>
            login(expect, author, newPerson.email),
          )
          await expect(author.getByTestId('self-user-id')).toHaveText(
            String(newPerson.userId),
          )
          await expect(author.getByTestId('message-input')).toHaveValue('')
          await expect(author.getByTestId('attachments-file')).toHaveCount(0)
          await expect(author.getByTestId('moderation-text')).toHaveCount(0)
          expect(trace.self.acceptKey).not.toBe(acceptKey)
          record({
            kind: 'new-person-composer',
            self: structuredClone(trace.self),
          })
        }

        await test.step('a fresh SDK picture crosses the old response window and publishes once', async () => {
          // This successful provider round trip starts AFTER cleanup and has
          // the same delay as the old IMAGE. Its completion, rather than a
          // sleep or instantaneous DOM read, fences the old response window.
          const witness = transition === 'close' ? observer : author
          const witnessControl = transition === 'close' ? witnessTrace : trace
          const witnessFile = {
            name: `${keys.witnessImage}.png`,
            buffer: PICTURE_PNG,
          }
          await dictateModerationVerdict(keys.witness, true, 'ok')
          await dictateModerationVerdict(keys.witnessImage, true, 'ok')
          await dictateGatewayBehavior(
            '/model/api/generate',
            keys.witnessImage,
            {
              delayMs,
            },
          )
          await attachPngs(witness, [witnessFile])
          const fresh = structuredClone(
            witnessControl.observer.summary().at(-1)!,
          )
          expect(
            originals.map(({ clientUploadId }) => clientUploadId),
          ).not.toContain(fresh.clientUploadId)
          expect(fresh.accepted).toBe(true)
          expect(fresh.sentSha256).toBe(
            createHash('sha256').update(PICTURE_PNG).digest('hex'),
          )
          expect(fresh.states).toContainEqual(
            expect.objectContaining({ phase: 'complete' }),
          )
          await typeInto(witness.getByTestId('message-input'), keys.witness)
          await clickSubmit(expect, witness.getByTestId('message-send'))
          await expect
            .poll(
              async () => (await readModelRequests(keys.witnessImage)).length,
            )
            .toBe(1)
          const witnessAcceptKey = witnessControl.self.acceptKey
          if (typeof witnessAcceptKey !== 'string')
            throw new Error('No witness accept key')
          const witnessActive = await observeModeration(
            witnessAcceptKey,
            'chat_message_picture',
          )
          expect(witnessActive.activeRequestId).toEqual(expect.any(String))
          expect(witnessActive.activeRequestId).not.toBe(active.activeRequestId)
          record({ kind: 'witness-image-active', witnessActive, fresh })
          const messages = observer
            .getByTestId('event-text')
            .filter({ hasText: keys.witness })
          await expect(messages).toHaveCount(1)
          await expect(messages).toHaveText(keys.witness)
          expect(Date.now() - oldArrivalObservedAt).toBeGreaterThanOrEqual(
            delayMs,
          )
          expect(await observeChatSubmission(witnessAcceptKey)).toEqual({
            phase: '',
            activeCheckRequestIds: [],
          })
          expect(
            await observeModeration(acceptKey, 'chat_message_picture'),
          ).toEqual({
            checkRequestId: null,
            activeRequestId: null,
            waitingForUploadHold: false,
          })
          const attachment = observer
            .getByTestId('event')
            .filter({ has: messages })
            .getByTestId('event-attachment')
          await expect(attachment).toHaveCount(1)
          await expect(
            attachment.getByTestId('event-attachment-image'),
          ).toHaveAttribute('alt', witnessFile.name)
          const href = await attachment.getAttribute('href')
          if (!href || href.includes('variant='))
            throw new Error('No original download path')
          const response = await observer.request.get(href)
          expect(response.status()).toBe(200)
          const bytes = await response.body()
          expect(bytes).toEqual(PICTURE_PNG)
          const eventDeliveries = observerFrames.packets.flatMap(({ frames }) =>
            frames.flatMap(({ wire }) => {
              const data = deliveryObject(deliveryObject(wire).data)
              const lists = deliveryObject(deliveryObject(data.payload).lists)
              return lists.mainEvents === undefined ? [] : [lists.mainEvents]
            }),
          )
          expect(eventDeliveries.length).toBeGreaterThan(0)
          const delivered = JSON.stringify(observerFrames.packets)
          for (const oldKey of [keys.message, keys.first, keys.last]) {
            expect(delivered).not.toContain(oldKey)
            await expect(
              observer.getByTestId('event-text').filter({ hasText: oldKey }),
            ).toHaveCount(0)
          }
          expect(delivered).toContain(keys.witness)
          for (const page of transition === 'close'
            ? [observer]
            : [author, observer]) {
            await expect(page.getByTestId('message-input')).toHaveValue('')
            await expect(page.getByTestId('attachments-file')).toHaveCount(0)
            await expect(page.getByTestId('moderation-text')).toHaveCount(0)
            await openBell(page)
            if (transition === 'close') {
              await expect(
                page.getByTestId('hilos-notification-empty'),
              ).toBeVisible()
              await expect(
                page.getByTestId(/^hilos-notification-item-/),
              ).toHaveCount(0)
              await expect(unreadBadge(page)).toBeHidden()
            } else {
              // A real password login creates exactly this security notice.
              // No moderation outcome may be inherited from the old person.
              const items = page.getByTestId(/^hilos-notification-item-/)
              await expect(items).toHaveCount(1)
              await expect(items).toContainText('New sign-in to your account')
              await expect(items).not.toContainText(
                'Your message was not published',
              )
              await expect(unreadBadge(page)).toHaveText(/^1\b/)
            }
          }
          record({
            kind: 'only-witness-published-after-old-response-window',
            keys,
            eventDeliveries,
            href,
            sha256: createHash('sha256').update(bytes).digest('hex'),
            arrivals: await Promise.all(
              Object.values(keys).map(readModelRequests),
            ),
          })
        })
        expect(trace.observer.errors).toEqual([])
        expect(witnessTrace.observer.errors).toEqual([])
        expect(
          [...authorFrames.packets, ...observerFrames.packets]
            .flatMap(({ frames }) => frames)
            .filter(({ failure }) => failure !== undefined),
        ).toEqual([])
      } catch (error) {
        record({ kind: 'scenario-error', error: String(error) })
        failures.push(error)
      } finally {
        const cleanupStart = failures.length
        const cleaned = await Promise.allSettled([
          ...Object.values(keys).map(forgetModelKey),
          authorContext.close(),
          observerContext.close(),
        ])
        for (const result of cleaned)
          if (result.status === 'rejected') failures.push(result.reason)
        try {
          await closeControl?.dispose()
        } catch (error) {
          failures.push(error)
        }
        trace.observer.stop()
        witnessTrace.observer.stop()
        authorFrames.stop()
        observerFrames.stop()
        record({
          kind: 'cleanup',
          errors: failures.slice(cleanupStart).map(String),
        })
        const path = info.outputPath(`picture-${transition}-proof.json`)
        await writeFile(
          path,
          JSON.stringify(
            {
              events,
              authorFrames,
              observerFrames,
              errors: [
                ...trace.observer.errors,
                ...witnessTrace.observer.errors,
              ],
              uploads: trace.observer.summary(),
              witnessUploads: witnessTrace.observer.summary(),
            },
            null,
            2,
          ),
        )
        await info.attach(`picture-${transition}-proof`, {
          path,
          contentType: 'application/json',
        })
      }
      if (failures.length === 1) throw failures[0]
      if (failures.length)
        throw new AggregateError(
          failures,
          'Picture lifecycle scenario or cleanup failed',
        )
    })
  }
})
