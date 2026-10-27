import { createHash } from 'node:crypto'
import { writeFile } from 'node:fs/promises'
import {
  test,
  expect,
  type BrowserContext,
  type TestInfo,
  type Page,
} from '@playwright/test'

import {
  login,
  armSocketDrop,
  controlSocketReconnect,
  countSentFrames,
  expectWelcomedSockets,
  nextClientPaint,
  readMonopolisticWorker,
  watchClientDocument,
  watchOpenedSockets,
  watchUploadFrames,
  dropFiles,
  dropSocket,
  isSessionCookie,
  SESSION_COOKIE_PREFIX,
  clickSubmit,
  typeInto,
  gotoPage,
} from '../../../../../framework/frontend/e2e/index.js'
import { logout, orphanSessionToken, signUp } from '../helpers/session.js'
import { modelKey } from '../../../../../framework/frontend/scripts/standModel.mjs'
import { dictateModerationVerdict } from '../helpers/moderation'
import { setAdmin } from '../../../../../framework/frontend/scripts/adminGrant.mjs'
import { chatUploadPool } from '../helpers/uploadPool.js'
import {
  UPLOADS_WORKER_AGENT,
  withStoppedUploadWorker,
} from '../../../../../framework/frontend/scripts/stoppedUploadWorker.mjs'
import {
  AUTH_ACTION_LOGIN,
  SIGN_OUT_ACTION,
  UPLOAD_ACTION_CANCEL,
} from '../../../../../framework/frontend/core/src/base/index.js'

// spec-owner: demo — files attached to chat's messages

const ATTACHMENT_MIME = 'image/png'
const SPEED_FILE_BYTES = 5_070_675
const READY_WITHIN_MS = 2_000

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

/** Generate the same printable ASCII lines for every upload speed measurement. */
function speedFile(): Buffer {
  const buffer = Buffer.alloc(SPEED_FILE_BYTES)
  let seed = 1527
  for (let offset = 0; offset < buffer.length; offset++) {
    seed = (Math.imul(seed, 1_664_525) + 1_013_904_223) >>> 0
    buffer[offset] =
      (offset + 1) % 80 === 0 || offset === buffer.length - 1
        ? 10
        : 32 + (seed % 95)
  }
  return buffer
}

/**
 * Pick one PNG through the composer's file input and wait for its chip to read
 * received.
 *
 * @param page The signed-in chat page.
 * @param filename Name the file is picked under.
 */
async function attachPng(page: Page, filename: string): Promise<void> {
  await page.getByTestId('attach-button-input').setInputFiles({
    name: filename,
    mimeType: ATTACHMENT_MIME,
    buffer: ATTACHMENT_PNG,
  })
  await expectReceived(page, filename)
}

/**
 * Wait for the composer's one chip to read received under its name.
 *
 * @param page The signed-in chat page.
 * @param filename Name the file was attached under.
 */
async function expectReceived(page: Page, filename: string): Promise<void> {
  await expect(page.getByTestId('attachments-file')).toHaveAttribute(
    'data-state',
    'received',
  )
  await expect(page.getByTestId('attachments-file-name')).toHaveText(filename)
}

// FIXME(F-042, 2026-10-27): a 5 MB upload is ready later than the two-second cap · свой · попытка 1 · HIL-1820
test(
  'a 5 MB file is ready on the composer within two seconds',
  { tag: '@quarantine:F-042' },
  async ({ page }) => {
    const file = {
      name: 'upload-speed.txt',
      mimeType: 'text/plain',
      buffer: speedFile(),
    }
    await signUp(expect, page)

    const t0 = Date.now()
    await page.getByTestId('attach-button-input').setInputFiles(file)
    await expect(page.getByTestId('attachments-file')).toHaveAttribute(
      'data-state',
      'received',
      { timeout: 30_000 },
    )
    const elapsed = Date.now() - t0
    test
      .info()
      .annotations.push({ type: 'upload-ms', description: String(elapsed) })
    expect(
      elapsed,
      `The file was ready after ${elapsed} ms`,
    ).toBeLessThanOrEqual(READY_WITHIN_MS)
  },
)

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
  await signUp(expect, page)

  const key = modelKey()
  const text = `attachment ${key}`
  const filename = `${key}.png`
  await dictateModerationVerdict(key, true, 'ok')
  // The picture request finds the key in the file name. Without its own
  // dictation the model answers 503 and the spec sees moderation unavailable.
  await dictateModerationVerdict(key, true, 'ok')

  await attachPng(page, filename)
  await expect(page.getByTestId('attachments-error')).toHaveCount(0)

  await typeInto(page.getByTestId('message-input'), text)
  await clickSubmit(expect, page.getByTestId('message-send'))

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
    await gotoPage(expect, guestPage, '/')
    await expect(guestPage.getByTestId('conn-state')).toHaveText('connected')
    const asGuest = await guestPage.request.get(href)
    expect(asGuest.status()).toBe(200)
    expect(await asGuest.body()).toEqual(ATTACHMENT_PNG)
  } finally {
    await guest.close()
  }
})

// The third door of the upload field (HIL-140): a picture dropped on the
// composer goes the way a picked one does — a received chip, then the message
// carries it into the feed.
test('a picture dropped on the composer is sent with the message', async ({
  page,
}) => {
  await signUp(expect, page)

  const key = modelKey()
  const text = `dropped ${key}`
  const filename = `${key}.png`
  await dictateModerationVerdict(key, true, 'ok')
  // The picture request finds the key in the file name. Without its own
  // dictation the model answers 503 and the spec sees moderation unavailable.
  await dictateModerationVerdict(key, true, 'ok')

  await dropFiles(page.getByTestId('composer-upload'), [
    { name: filename, mimeType: ATTACHMENT_MIME, buffer: ATTACHMENT_PNG },
  ])
  await expectReceived(page, filename)

  await typeInto(page.getByTestId('message-input'), text)
  await clickSubmit(expect, page.getByTestId('message-send'))

  const event = page
    .getByTestId('event')
    .filter({ has: page.getByTestId('event-text').filter({ hasText: text }) })
  await expect(event.getByTestId('event-attachment')).toHaveCount(1)
  await expect(page.getByTestId('attachments-file')).toHaveCount(0)
})

// The server reads the type from the content (HIL-144): a file that calls itself
// a picture but holds a zip archive is refused with the server's sentence once its
// bytes arrive, and stays on the composer as a refused chip.
test('a file of a type the chat does not take is refused with the server sentence', async ({
  page,
}) => {
  await signUp(expect, page)

  await page.getByTestId('attach-button-input').setInputFiles({
    name: `${modelKey()}.png`,
    mimeType: ATTACHMENT_MIME,
    buffer: ZIP_BYTES,
  })

  await expect(page.getByTestId('attachments-error')).toHaveText(
    'File content does not match an allowed type',
  )
  await expect(page.getByTestId('attachments-file')).toHaveCount(1)
  await expect(page.getByTestId('attachments-file')).toHaveAttribute(
    'data-state',
    'failed',
  )
})

// From the acceptance of HIL-139: the browser client sends a ready file again
// under the same id after the socket drops, so the chip survives the drop and
// the file still rides the message.
test('a ready file survives a dropped connection and is sent with the message', async ({
  page,
}) => {
  await armSocketDrop(page)
  await signUp(expect, page)

  const key = modelKey()
  const text = `after drop ${key}`
  const filename = `${key}.png`
  await dictateModerationVerdict(key, true, 'ok')
  // The picture request finds the key in the file name. Without its own
  // dictation the model answers 503 and the spec sees moderation unavailable.
  await dictateModerationVerdict(key, true, 'ok')
  await attachPng(page, filename)

  const reconnected = page.waitForEvent('websocket')
  await dropSocket(page)
  await reconnected
  await expect(page.getByTestId('conn-state')).toHaveText('connected')
  await expectReceived(page, filename)

  await typeInto(page.getByTestId('message-input'), text)
  await clickSubmit(expect, page.getByTestId('message-send'))

  const event = page
    .getByTestId('event')
    .filter({ has: page.getByTestId('event-text').filter({ hasText: text }) })
  await expect(event.getByTestId('event-attachment')).toHaveCount(1)
  await expect(page.getByTestId('attachments-file')).toHaveCount(0)
})

// From the acceptance of HIL-139: the client drops its list when the person
// behind it changes, so signing out and back in starts with no chips.
test('signing out drops the files waiting for a message', async ({ page }) => {
  const user = await signUp(expect, page)
  await attachPng(page, `${modelKey()}.png`)

  await logout(expect, page)
  await expect(page.getByTestId('attachments-file')).toHaveCount(0)
  await page.getByTestId('message-signin').click()
  await login(expect, page, user.email)

  await expect(page.getByTestId('self-user')).toHaveText(user.name)
  await expect(page.getByTestId('attachments-file')).toHaveCount(0)
})

test('identifier input keeps its prefix and drops only keys pressed during reconnect', async ({
  page,
}, info) => {
  const reconnect = await controlSocketReconnect(page)
  const sockets = watchOpenedSockets(page)
  try {
    const user = await signUp(expect, page)
    await attachPng(page, `${modelKey()}.png`)
    await logout(expect, page)
    await expect(page.getByTestId('attachments-file')).toHaveCount(0)
    await page.getByTestId('message-signin').click()
    const field = page.getByTestId('auth-identifier')
    const journal = await field.evaluateHandle((node) => {
      const field = node as HTMLInputElement
      const events: {
        kind: string
        key: string | null
        value: string
        blocked: boolean
        same: boolean
        state: string | null
        focus: string | null
        selection: [number | null, number | null]
      }[] = []
      const record = (kind: string, key: string | null = null): void => {
        events.push({
          kind,
          key,
          value: field.value,
          blocked: field.matches(':disabled'),
          same: document.querySelector('[data-id="auth-identifier"]') === field,
          state:
            document
              .querySelector('[data-id="conn-state"]')
              ?.getAttribute('title') ?? null,
          focus: document.activeElement?.getAttribute('data-id') ?? null,
          selection: [field.selectionStart, field.selectionEnd],
        })
      }
      const input = (event: Event): void => {
        if (event.type === 'input' && event.target !== field) return
        record(event.type, event instanceof KeyboardEvent ? event.key : null)
      }
      document.addEventListener('keydown', input, true)
      document.addEventListener('input', input, true)
      const observer = new MutationObserver(() => record('dom'))
      observer.observe(document.documentElement, {
        subtree: true,
        childList: true,
        attributes: true,
        attributeFilter: ['disabled', 'title'],
      })
      record('start')
      return {
        events,
        stop(): void {
          observer.disconnect()
          document.removeEventListener('keydown', input, true)
          document.removeEventListener('input', input, true)
        },
      }
    })
    const documentWatch = await watchClientDocument(page)
    try {
      const prefix = user.email.slice(0, 3)
      const blockedKeys = user.email.slice(3, 7)
      const tail = user.email.slice(7)
      await typeInto(field, prefix)
      await expect(field).toHaveValue(prefix)
      const before = sockets.length
      await reconnect.drop()
      await expect(field).toBeDisabled()
      await field.pressSequentially(blockedKeys, { delay: 10 })
      await expect(field).toHaveValue(prefix)
      await reconnect.release()
      await expectWelcomedSockets(expect, sockets, before + 1)
      await expect(field).toBeEnabled()
      await expect(field).toHaveValue(prefix)
      // The first gesture after the known drop resumes at the end. Playwright's
      // sequential typing focuses an unfocused field with its caret at zero.
      await field.press('End')
      await field.pressSequentially(tail, { delay: 10 })
      await expect(field).toHaveValue(prefix + tail)
      const events = await journal.evaluate((journal) => journal.events)
      await info.attach('identifier-reconnect-events', {
        body: JSON.stringify({
          requested: user.email,
          actual: prefix + tail,
          events,
        }),
        contentType: 'application/json',
      })
      expect(events.every(({ same }) => same)).toBe(true)
      expect(
        events.filter(({ kind, blocked }) => kind === 'input' && blocked),
      ).toEqual([])
      expect(
        events
          .filter(({ kind, blocked }) => kind === 'keydown' && blocked)
          .map(({ key }) => key),
      ).toEqual([...blockedKeys])
      // An explicit new gesture after the observed drop: sign in to the same
      // account. The helper neither retries nor repairs a mismatched identifier.
      await login(expect, page, user.email)
      await expect(page.getByTestId('self-user-id')).toHaveText(
        String(user.userId),
      )
      await expect(page.getByTestId('attachments-file')).toHaveCount(0)
      await documentWatch.unchanged()
    } finally {
      await journal.evaluate((journal) => journal.stop())
      await journal.dispose()
      await documentWatch.dispose()
    }
  } finally {
    await reconnect.dispose()
  }
})

// The anonymous identity precedes completion of the logout's socket rotation.
// Hold the real next connection so readiness cannot pass on the old identity.
test('signing out waits for rotation before typing the original identifier', async ({
  page,
}, info) => {
  const reconnect = await controlSocketReconnect(page)
  const sockets = watchOpenedSockets(page)
  const actions = countSentFrames(page, '"type":"action"')
  const logouts = countSentFrames(page, `"action":"${SIGN_OUT_ACTION}"`)
  const cancels = countSentFrames(page, `"action":"${UPLOAD_ACTION_CANCEL}"`)
  const logins = countSentFrames(page, `"action":"${AUTH_ACTION_LOGIN}"`)
  let settled = false
  let signingOut: Promise<void> | undefined
  try {
    const user = await signUp(expect, page)
    await attachPng(page, `${modelKey()}.png`)
    const document = await watchClientDocument(page)
    try {
      const before = sockets.length
      const beforeActions = actions()
      const beforeLogouts = logouts()
      const beforeCancels = cancels()
      const beforeLogins = logins()
      await reconnect.hold()
      signingOut = logout(expect, page).then(() => {
        settled = true
      })
      await expect(page.getByTestId('nav-profile')).toHaveCount(0)
      await expect(page.getByTestId('conn-state')).toHaveAttribute(
        'title',
        /^(connecting|reconnecting)\b/,
      )
      await expect(page.getByTestId('page-interaction-scope')).toHaveJSProperty(
        'disabled',
        true,
      )
      await nextClientPaint(page)
      await info.attach('logout-rotation-held', {
        body: JSON.stringify({
          settled,
          sockets: sockets.map(({ welcomed }) => welcomed),
          state: await page.getByTestId('conn-state').textContent(),
        }),
        contentType: 'application/json',
      })
      expect(settled, 'logout must wait for its new server welcome').toBe(false)
      // Clearing the waiting file also cancels its upload once.
      expect(actions() - beforeActions).toBe(2)
      expect(logouts() - beforeLogouts).toBe(1)
      expect(cancels() - beforeCancels).toBe(1)
      expect(logins() - beforeLogins).toBe(0)
      await expect(page.getByTestId('attachments-file')).toHaveCount(0)

      await reconnect.release()
      await signingOut
      await expectWelcomedSockets(expect, sockets, before + 1)
      await page.getByTestId('message-signin').click()
      await login(expect, page, user.email)
      await expect(page.getByTestId('self-user')).toHaveText(user.name)
      await expect(page.getByTestId('self-user-id')).toHaveText(
        String(user.userId),
      )
      await expect(page.getByTestId('attachments-file')).toHaveCount(0)
      expect(logins() - beforeLogins).toBe(1)
      await document.unchanged()
    } finally {
      await document.dispose()
    }
  } finally {
    await reconnect.dispose()
    await signingOut
  }
})

const SMALL_POOL_BYTES = 131_072
const DEFAULT_POOL_BYTES = 2_147_483_648

/** Distinct printable content accepted by chat's content sniff and duplicate check. */
function poolFile(seed: number, size: number) {
  const buffer = Buffer.alloc(size)
  for (let offset = 0; offset < size; offset++) {
    buffer[offset] =
      (offset + 1) % 80 === 0 ? 10 : 32 + ((offset * 17 + seed) % 95)
  }
  return { name: `${modelKey()}.txt`, mimeType: 'text/plain', buffer }
}

/** Read the live uploads holder after its registration/settings source is ready. */
async function uploadWorker(page: Page) {
  return readMonopolisticWorker(
    page,
    UPLOADS_WORKER_AGENT,
    (page) => gotoPage(expect, page, '/hilos/daemon/standalone/workers'),
    async (predicate) => {
      await expect.poll(predicate).toBe(true)
    },
  )
}

/** Check bytes at the observed SDK boundary and the server's final state. */
function expectWholeUpload(
  trace: ReturnType<typeof watchUploadFrames>,
  file: ReturnType<typeof poolFile>,
) {
  expect(trace.errors).toEqual([])
  expect(trace.uploads.size).toBe(1)
  const upload = [...trace.uploads.values()][0]!
  expect(upload.filename).toBe(file.name)
  expect(upload.size).toBe(file.buffer.length)
  expect(upload.requestIds).toHaveLength(1)
  expect(upload.requestIds[0]).toMatch(/^\d+$/)
  expect(upload.demands).toEqual([file.buffer.length])
  expect(Buffer.concat(upload.chunks)).toEqual(file.buffer)
  expect(Math.max(...upload.grants)).toBe(file.buffer.length)
  expect(new Set(upload.grants).size).toBeGreaterThan(1)
  expect(upload.states).toContainEqual(
    expect.objectContaining({
      phase: 'complete',
      receivedBytes: file.buffer.length,
      declaredSize: file.buffer.length,
    }),
  )
}

/** Publish under the actual author and compare the product download's complete body. */
async function publishPoolFile(
  page: Page,
  author: string,
  file: ReturnType<typeof poolFile>,
  record: (event: Record<string, unknown>) => void,
) {
  const key = file.name.slice(0, -4)
  const text = `pool attachment ${key}`
  await dictateModerationVerdict(key, true, 'ok')
  await typeInto(page.getByTestId('message-input'), text)
  await clickSubmit(expect, page.getByTestId('message-send'))
  await expect(page.getByTestId('attachments-file')).toHaveCount(0)
  const event = page
    .getByTestId('event')
    .filter({ has: page.getByTestId('event-text').filter({ hasText: text }) })
  await expect(event.getByTestId('event-author')).toHaveText(author)
  const attachment = event.getByTestId('event-attachment')
  await expect(attachment).toHaveAttribute('download', file.name)
  const href = await attachment.getAttribute('href')
  expect(href).toContain('/_hilos/file?')
  if (!href) throw new Error('The published attachment has no product download')
  const response = await page.request.get(href)
  expect(response.status()).toBe(200)
  const body = await response.body()
  expect(body).toEqual(file.buffer)
  record({
    kind: 'published-download',
    author,
    text,
    filename: file.name,
    href,
    status: response.status(),
    bytes: body.length,
    sha256: createHash('sha256').update(body).digest('hex'),
    expectedSha256: createHash('sha256').update(file.buffer).digest('hex'),
  })
}

/** Cleanup failure is a failed test, with the complete attempt attached even then. */
async function cleanupPoolProof(
  contexts: BrowserContext[],
  pool: ReturnType<typeof chatUploadPool>,
  original: number | undefined,
  traces: ReturnType<typeof watchUploadFrames>[],
  events: Record<string, unknown>[],
  info: TestInfo,
) {
  const failures: unknown[] = []
  const closed = await Promise.allSettled(
    contexts.map((context) => context.close()),
  )
  for (const result of closed)
    if (result.status === 'rejected') failures.push(result.reason)
  try {
    if (original !== undefined) {
      await pool.limit(original)
      await expect
        .poll(() => pool.inspect())
        .toEqual({
          limitBytes: original,
          usedBytes: 0,
          waitingUploads: 0,
          pendingPeerReservations: 0,
        })
    }
  } catch (error) {
    failures.push(error)
  }
  events.push({ kind: 'cleanup', errors: failures.map(String) })
  const proofPath = info.outputPath('upload-pool-proof.json')
  await writeFile(
    proofPath,
    JSON.stringify(
      {
        retry: info.retry,
        events,
        participants: traces.map((trace) => ({
          connections: trace.connections(),
          errors: trace.errors,
          uploads: trace.summary(),
        })),
      },
      null,
      2,
    ),
  )
  await info.attach('upload-pool-proof', {
    path: proofPath,
    contentType: 'application/json',
  })

  if (failures.length)
    throw new AggregateError(failures, 'Upload pool proof cleanup failed')
}

test.describe('the shared browser upload pool', () => {
  // A controlled process pause is judged on its first attempt, as in daemon restart.
  test.describe.configure({ retries: 0 })

  test('two people resume files larger than one pool and download every original byte', async ({
    browser,
  }, info) => {
    const contexts: BrowserContext[] = []
    const events: Record<string, unknown>[] = []
    const record = (event: Record<string, unknown>) => {
      events.push({ at: Date.now(), ...event })
    }
    const pool = chatUploadPool(record)
    const traces: ReturnType<typeof watchUploadFrames>[] = []
    let original: number | undefined
    try {
      const first = await browser.newContext()
      contexts.push(first)
      const second = await browser.newContext()
      contexts.push(second)
      const a = await first.newPage()
      const b = await second.newPage()
      const ta = watchUploadFrames(a, 'first-person', record)
      traces.push(ta)
      const tb = watchUploadFrames(b, 'second-person', record)
      traces.push(tb)
      const ua = await signUp(expect, a)
      const ub = await signUp(expect, b)
      expect(ua.userId).not.toBe(ub.userId)
      record({ kind: 'people', people: [ua, ub] })
      await setAdmin(ua.userId, true)
      const admin = await first.newPage()
      const worker = await uploadWorker(admin)
      // A nonzero initial value can only come from the ready settings reporter.
      await expect
        .poll(async () => (await pool.inspect()).limitBytes)
        .toBe(DEFAULT_POOL_BYTES)
      original = (await pool.inspect()).limitBytes
      expect(await pool.inspect()).toEqual({
        limitBytes: original,
        usedBytes: 0,
        waitingUploads: 0,
        pendingPeerReservations: 0,
      })
      const initialConnections = [ta.connections(), tb.connections()]
      record({
        kind: 'connections-before-pool',
        participants: initialConnections,
      })
      await pool.limit(0)
      const fa = poolFile(11, SMALL_POOL_BYTES * 3 + 17)
      const fb = poolFile(29, SMALL_POOL_BYTES * 4 + 31)
      await a.getByTestId('attach-button-input').setInputFiles(fa)
      await b.getByTestId('attach-button-input').setInputFiles(fb)
      await expect
        .poll(() => [ta.summary()[0]?.demands, tb.summary()[0]?.demands])
        .toEqual([[fa.buffer.length], [fb.buffer.length]])
      await expect
        .poll(() => pool.inspect())
        .toEqual({
          limitBytes: 0,
          usedBytes: 0,
          waitingUploads: 2,
          pendingPeerReservations: 0,
        })
      expect(ta.sentBytes() + tb.sentBytes()).toBe(0)
      await withStoppedUploadWorker(worker, record, async (held) => {
        await pool.limit(SMALL_POOL_BYTES)
        await expect
          .poll(() => ta.sentBytes() + tb.sentBytes())
          .toBe(SMALL_POOL_BYTES)
        // Four new control roundtrips, with the worker still T on both sides.
        for (let roundtrip = 1; roundtrip <= 4; roundtrip++) {
          await held.assertStopped()
          expect(await pool.inspect()).toEqual({
            limitBytes: SMALL_POOL_BYTES,
            usedBytes: SMALL_POOL_BYTES,
            waitingUploads: 2,
            pendingPeerReservations: 0,
          })
          expect(ta.grantedBytes() + tb.grantedBytes()).toBe(SMALL_POOL_BYTES)
          expect(ta.sentBytes() + tb.sentBytes()).toBe(SMALL_POOL_BYTES)
          for (const [page, trace] of [
            [a, ta],
            [b, tb],
          ] as const) {
            await expect(page.getByTestId('conn-state')).toHaveText('connected')
            await expect(
              page.getByTestId('attachments-file'),
            ).not.toHaveAttribute('data-state', 'received')
            expect(
              trace
                .summary()[0]
                ?.states.some((state) => state.phase === 'complete'),
            ).toBe(false)
            expect(trace.errors).toEqual([])
          }
          record({ kind: 'full-pool-control-roundtrip', roundtrip })
          await held.assertStopped()
        }
      })
      await expect
        .poll(async () => {
          await pool.inspect()
          return [ta, tb].every((trace) =>
            trace
              .summary()[0]
              ?.states.some((state) => state.phase === 'complete'),
          )
        })
        .toBe(true)
      await expectReceived(a, fa.name)
      await expectReceived(b, fb.name)
      expectWholeUpload(ta, fa)
      expectWholeUpload(tb, fb)
      await expect
        .poll(() => pool.inspect())
        .toEqual({
          limitBytes: SMALL_POOL_BYTES,
          usedBytes: 0,
          waitingUploads: 0,
          pendingPeerReservations: 0,
        })
      await publishPoolFile(a, ua.name, fa, record)
      await publishPoolFile(b, ub.name, fb, record)
      expect([ta.connections(), tb.connections()]).toEqual(initialConnections)
    } finally {
      await cleanupPoolProof(contexts, pool, original, traces, events, info)
    }
  })

  test('disconnect releases unused credit while accepted bytes remain until worker resume', async ({
    browser,
  }, info) => {
    const contexts: BrowserContext[] = []
    const events: Record<string, unknown>[] = []
    const record = (event: Record<string, unknown>) => {
      events.push({ at: Date.now(), ...event })
    }
    const pool = chatUploadPool(record)
    const traces: ReturnType<typeof watchUploadFrames>[] = []
    let original: number | undefined
    try {
      const accepted = await browser.newContext()
      contexts.push(accepted)
      const unused = await browser.newContext()
      contexts.push(unused)
      const waiting = await browser.newContext()
      contexts.push(waiting)
      const a = await accepted.newPage()
      const b = await unused.newPage()
      const c = await waiting.newPage()
      const ta = watchUploadFrames(a, 'accepted-debt', record)
      traces.push(ta)
      const tb = watchUploadFrames(b, 'unused-credit', record)
      traces.push(tb)
      const tc = watchUploadFrames(c, 'waiting-person', record)
      traces.push(tc)
      const unusedCredits = await tb.holdCredits()
      const ua = await signUp(expect, a)
      const ub = await signUp(expect, b)
      const uc = await signUp(expect, c)
      expect(new Set([ua.userId, ub.userId, uc.userId]).size).toBe(3)
      record({ kind: 'people', people: [ua, ub, uc] })
      await setAdmin(uc.userId, true)
      const admin = await waiting.newPage()
      const worker = await uploadWorker(admin)
      await expect
        .poll(async () => (await pool.inspect()).limitBytes)
        .toBe(DEFAULT_POOL_BYTES)
      original = (await pool.inspect()).limitBytes
      await pool.limit(0)
      const fa = poolFile(37, SMALL_POOL_BYTES / 2)
      const fb = poolFile(43, SMALL_POOL_BYTES * 3 + 7)
      const fc = poolFile(61, SMALL_POOL_BYTES * 3 + 23)
      for (const [page, file, trace] of [
        [a, fa, ta],
        [b, fb, tb],
        [c, fc, tc],
      ] as const) {
        await page.getByTestId('attach-button-input').setInputFiles(file)
        await expect
          .poll(() => trace.summary()[0]?.demands)
          .toEqual([file.buffer.length])
      }
      await expect
        .poll(() => pool.inspect())
        .toEqual({
          limitBytes: 0,
          usedBytes: 0,
          waitingUploads: 3,
          pendingPeerReservations: 0,
        })
      await withStoppedUploadWorker(worker, record, async (held) => {
        await pool.limit(SMALL_POOL_BYTES)
        await expect.poll(() => ta.sentBytes()).toBe(fa.buffer.length)
        const unusedId = [...tb.uploads.keys()][0]!
        await expect
          .poll(() => unusedCredits.credits)
          .toEqual([
            { clientUploadId: unusedId, sendUpToBytes: SMALL_POOL_BYTES / 2 },
          ])
        expect(unusedCredits.errors).toEqual([])
        expect(tb.grantedBytes()).toBe(SMALL_POOL_BYTES / 2)
        expect(tb.sentBytes()).toBe(0)
        expect(tc.grantedBytes()).toBe(0)
        expect(await pool.inspect()).toEqual({
          limitBytes: SMALL_POOL_BYTES,
          usedBytes: SMALL_POOL_BYTES,
          waitingUploads: 2,
          pendingPeerReservations: 0,
        })
        await held.assertStopped()
        await accepted.close()
        // EOF cannot free the 65536 bytes already delivered to the held worker.
        for (let roundtrip = 1; roundtrip <= 2; roundtrip++) {
          expect(await pool.inspect()).toEqual({
            limitBytes: SMALL_POOL_BYTES,
            usedBytes: SMALL_POOL_BYTES,
            waitingUploads: 2,
            pendingPeerReservations: 0,
          })
          expect(tc.grantedBytes()).toBe(0)
          await held.assertStopped()
          record({
            kind: 'accepted-eof-debt-retained',
            roundtrip,
            acceptedBytes: ta.sentBytes(),
            unusedBytes: unusedCredits.credits[0]!.sendUpToBytes,
          })
        }
        await unused.close()
        record({ kind: 'unused-context-closed' })
        await expect.poll(() => tc.sentBytes()).toBe(SMALL_POOL_BYTES / 2)
        expect(tc.grantedBytes()).toBe(SMALL_POOL_BYTES / 2)
        expect(tb.sentBytes()).toBe(0)
        expect(await pool.inspect()).toEqual({
          limitBytes: SMALL_POOL_BYTES,
          usedBytes: SMALL_POOL_BYTES,
          waitingUploads: 1,
          pendingPeerReservations: 0,
        })
        await expect(c.getByTestId('conn-state')).toHaveText('connected')
        await expect(c.getByTestId('attachments-file')).not.toHaveAttribute(
          'data-state',
          'received',
        )
        await held.assertStopped()
        record({
          kind: 'unused-reassigned-with-accepted-debt',
          acceptedBytes: ta.sentBytes(),
          successorBytes: tc.sentBytes(),
        })
      })
      await expectReceived(c, fc.name)
      expectWholeUpload(tc, fc)
      for (const trace of traces) expect(trace.errors).toEqual([])
      await expect
        .poll(() => pool.inspect())
        .toEqual({
          limitBytes: SMALL_POOL_BYTES,
          usedBytes: 0,
          waitingUploads: 0,
          pendingPeerReservations: 0,
        })
      await clickSubmit(expect, c.getByTestId('attachments-file-remove'))
      await expect(c.getByTestId('attachments-file')).toHaveCount(0)
      expect(tc.summary()[0]?.canceled).toBe(1)
    } finally {
      await cleanupPoolProof(contexts, pool, original, traces, events, info)
    }
  })
})
