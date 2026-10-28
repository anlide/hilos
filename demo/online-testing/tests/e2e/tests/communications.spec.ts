import { expect, test } from '@playwright/test'

import {
  createTestUser,
  COMMUNICATIONS_FRAME_PATH,
  communicationsFrameHtml,
  draftCommunicationsFrame,
  expectCommunicationsFrameConflict,
  expectCommunicationsFrameDiscard,
  expectCommunicationsFrameInitial,
  expectCommunicationsFrameLive,
  expectCommunicationsFramePreview,
  resetCommunicationsFrame,
  restoreCommunicationsFrame,
  saveCommunicationsFrame,
  submitCommunicationsFrame,
  watchDeliveryFrames,
  expectCommunicationsAccessibility,
  expectCommunicationsFrameEditor,
  expectCommunicationsChannelHead,
  shownByTestId,
  expectPageReady,
  gotoPage,
  grantAdminToSelf,
} from '../../../../../framework/frontend/e2e/index.js'
import { setAdminViewMode } from '../../../../../framework/frontend/scripts/adminViewMode.mjs'
import { waitForMailTo } from '../../../../../framework/frontend/scripts/standMailbox.mjs'

test('the communications section configures email, sends a test and records delivery', async ({
  page,
}) => {
  const { email } = await createTestUser(expect, page)
  await grantAdminToSelf(expect, page)

  await gotoPage(expect, page, '/hilos/communications')
  await expect(page.getByTestId('page-error')).toHaveCount(0)
  const emailToggle = shownByTestId(page, 'hilos-channel-enabled-email')
  await expect(emailToggle).toBeVisible()
  await expect(emailToggle).toBeChecked()

  await shownByTestId(page, 'hilos-channel-configure-email').click()
  await expectCommunicationsChannelHead(expect, page, 'configuration')
  expect(new URL(page.url()).pathname).toBe(
    '/hilos/communications/email/configuration',
  )
  await expect(page.getByTestId('hilos-viewport-table')).toBeVisible()
  await expect(page.getByTestId('hilos-channel-test')).toHaveCount(0)

  await page.getByTestId('hilos-page-tab-hilos_communications_channel').click()
  await expectPageReady(expect, page)
  await expectCommunicationsChannelHead(expect, page, 'overview')
  await expect(page.getByTestId('hilos-viewport-table')).toHaveCount(0)

  const testButton = page.getByTestId('hilos-channel-test')
  await testButton.scrollIntoViewIfNeeded()
  await expect(testButton).toBeVisible()
  await expect(testButton).toBeEnabled()
  await testButton.focus()
  await testButton.click()
  await expect(testButton).not.toHaveAttribute('aria-busy', 'true')

  const toast = page.getByTestId('hilos-toast-success')
  await expect(toast).toBeVisible()
  await expect(toast).toContainText('Test notification queued to')
  await waitForMailTo(email, 'Test notification: Email')

  await page
    .getByTestId('hilos-page-tab-hilos_communications_deliveries')
    .click()
  await expectPageReady(expect, page)
  await expectCommunicationsChannelHead(expect, page, 'deliveries')
  const deliveryRow = page.getByTestId(/^hilos-table-row-/).first()
  await expect(deliveryRow).toBeVisible()
  await expect(deliveryRow).toContainText('sent')
})

test('the letter frame editor has a dialog name and keyboard focus', async ({
  page,
}) => {
  await grantAdminToSelf(expect, page)
  await gotoPage(expect, page, '/hilos/communications/email/frame')
  await expectCommunicationsAccessibility(expect, page, 'frame', 'Enabled')
  const preview = page.getByTestId('hilos-channel-frame-preview')
  await expect(preview).toHaveAttribute('title', 'Letter frame preview')
  await expect(preview).toHaveAttribute('sandbox', '')
  await expect(preview).toHaveAttribute('srcdoc', /482913/)
  const edit = page.getByTestId('hilos-channel-frame-edit')
  await expect(edit).toHaveAccessibleName('Edit frame')
  await edit.focus()
  await edit.press('Enter')
  await expectCommunicationsFrameEditor(expect, page)
})

test('letter frame cold entry, reload and history carry one complete saved preview', async ({
  page,
}) => {
  const wire = watchDeliveryFrames(page)
  try {
    await grantAdminToSelf(expect, page)
    let after = wire.packets.length
    await gotoPage(expect, page, COMMUNICATIONS_FRAME_PATH)
    await expectCommunicationsChannelHead(expect, page, 'frame')
    const original = await expectCommunicationsFrameInitial(
      expect,
      page,
      wire,
      after,
    )
    expect(original.source).toMatch(/^(default|project)$/)
    await expect(page.getByTestId('hilos-channel-frame-reset')).toBeDisabled()

    after = wire.packets.length
    await page.reload()
    await expectPageReady(expect, page)
    await expectCommunicationsChannelHead(expect, page, 'frame')
    expect(
      await expectCommunicationsFrameInitial(expect, page, wire, after),
    ).toEqual(original)

    await page
      .getByTestId('hilos-page-tab-hilos_communications_channel')
      .click()
    await expectPageReady(expect, page)
    await expectCommunicationsChannelHead(expect, page, 'overview')
    after = wire.packets.length
    await page.goBack()
    await expectPageReady(expect, page)
    await expectCommunicationsChannelHead(expect, page, 'frame')
    expect(
      await expectCommunicationsFrameInitial(expect, page, wire, after),
    ).toEqual(original)

    // This demo registers email only; the SMS frame address must still be refused.
    await gotoPage(expect, page, '/hilos/communications/sms/frame')
    await expect(page.getByTestId('hilos-page-state')).toHaveAttribute(
      'data-state',
      'error',
    )
    await expect(page.getByTestId('page-error')).toHaveAttribute(
      'data-error-code',
      '404',
    )
    await expect(page.getByTestId('hilos-channel-frame-preview')).toHaveCount(0)
  } finally {
    wire.stop()
  }
})

for (const [name, invalid, refusal] of [
  [
    'missing content',
    '<p>No slot</p>',
    'The frame must contain [[:content]] exactly once.',
  ],
  [
    'repeated content',
    '[[:content]][[:content]]',
    'The frame must contain [[:content]] exactly once.',
  ],
  [
    'unknown placeholder',
    '[[:content]][[:other]]',
    'The frame contains an unknown placeholder.',
  ],
] as const) {
  test(`letter frame refuses ${name} on the server and preserves the draft`, async ({
    page,
  }) => {
    const wire = watchDeliveryFrames(page)
    try {
      await grantAdminToSelf(expect, page)
      await gotoPage(expect, page, COMMUNICATIONS_FRAME_PATH)
      const original = await expectCommunicationsFrameInitial(
        expect,
        page,
        wire,
      )
      await page.getByTestId('hilos-channel-frame-edit').click()
      await expectCommunicationsFrameEditor(expect, page)
      await draftCommunicationsFrame(expect, page, invalid)
      await submitCommunicationsFrame(expect, page, wire, invalid, refusal)
      await expectCommunicationsFramePreview(expect, page, original)
      await page.getByTestId('modal-close').click()
      await page.getByTestId('modal-confirm-discard').click()
      await expect(page.getByTestId('modal')).toHaveCount(0)
      await page.getByTestId('hilos-channel-frame-edit').click()
      await expectCommunicationsFrameEditor(expect, page)
      await expect(page.getByTestId('hilos-channel-frame-html')).toHaveValue(
        String(original.html),
      )
    } finally {
      wire.stop()
    }
  })
}

test('letter frame saves an override and resets only after confirmation', async ({
  page,
}) => {
  const wire = watchDeliveryFrames(page)
  let original:
    Awaited<ReturnType<typeof expectCommunicationsFrameInitial>> | undefined
  try {
    await grantAdminToSelf(expect, page)
    await gotoPage(expect, page, COMMUNICATIONS_FRAME_PATH)
    original = await expectCommunicationsFrameInitial(expect, page, wire)
    const html = communicationsFrameHtml('frame-saved')
    await page.getByTestId('hilos-channel-frame-edit').click()
    await expectCommunicationsFrameEditor(expect, page)
    await expect(page.getByTestId('hilos-channel-frame-html')).toHaveValue(
      String(original.html),
    )
    await draftCommunicationsFrame(expect, page, html)
    await expectCommunicationsFramePreview(expect, page, original)
    await submitCommunicationsFrame(expect, page, wire, html)
    await expect(
      page.getByTestId('hilos-channel-frame-preview'),
    ).toHaveAttribute('srcdoc', /lang="en" dir="ltr"/)
    await expect(page.locator('#frame-saved')).toHaveCount(0)
    await resetCommunicationsFrame(expect, page, wire, original)
    await expectCommunicationsFramePreview(expect, page, original)
  } finally {
    try {
      if (original) {
        const cleanup = await page.context().newPage()
        const cleanupWire = watchDeliveryFrames(cleanup)
        try {
          await gotoPage(expect, cleanup, COMMUNICATIONS_FRAME_PATH)
          await restoreCommunicationsFrame(
            expect,
            cleanup,
            cleanupWire,
            original,
          )
        } finally {
          cleanupWire.stop()
          await cleanup.close()
        }
      }
    } finally {
      wire.stop()
    }
  }
})

test('letter frame dirty Escape and Close keep a named discard layer and return focus', async ({
  page,
}) => {
  const wire = watchDeliveryFrames(page)
  try {
    await grantAdminToSelf(expect, page)
    await gotoPage(expect, page, COMMUNICATIONS_FRAME_PATH)
    const original = await expectCommunicationsFrameInitial(expect, page, wire)
    const edit = page.getByTestId('hilos-channel-frame-edit')
    await edit.focus()
    await edit.press('Enter')
    await expectCommunicationsFrameEditor(expect, page)
    const draft = communicationsFrameHtml('frame-unsaved')
    await draftCommunicationsFrame(expect, page, draft)
    await page.getByTestId('hilos-channel-frame-html').press('Escape')
    await expectCommunicationsFrameDiscard(expect, page, draft)
    await page.getByTestId('modal-close').click()
    await expectCommunicationsFrameDiscard(expect, page, draft)
    await page.getByTestId('modal-close').click()
    await page.getByTestId('modal-confirm-discard').click()
    await expect(page.getByTestId('modal')).toHaveCount(0)
    await expect(edit).toBeFocused()
    await expectCommunicationsFramePreview(expect, page, original)
    await edit.press('Enter')
    await expectCommunicationsFrameEditor(expect, page)
    await expect(page.getByTestId('hilos-channel-frame-html')).toHaveValue(
      String(original.html),
    )
    await page.getByTestId('hilos-channel-frame-html').press('Escape')
    await expect(page.getByTestId('modal')).toHaveCount(0)
    await expect(edit).toBeFocused()
  } finally {
    wire.stop()
  }
})

test.describe('letter frame in admin view mode', () => {
  test.afterEach(() => setAdminViewMode(false))

  test('a viewer reads the visible synthetic frame but cannot save a draft', async ({
    page,
  }) => {
    await setAdminViewMode(true)
    const wire = watchDeliveryFrames(page)
    try {
      await gotoPage(expect, page, COMMUNICATIONS_FRAME_PATH)
      await expectCommunicationsChannelHead(expect, page, 'frame')
      await expect(page.getByTestId('view-mode-banner')).toBeVisible()
      const original = await expectCommunicationsFrameInitial(
        expect,
        page,
        wire,
      )
      await expect(
        page.getByTestId('hilos-channel-frame').getByTestId('hilos-hidden'),
      ).toHaveCount(0)
      await page.getByTestId('hilos-channel-frame-edit').click()
      await expectCommunicationsFrameEditor(expect, page)
      await expect(page.getByTestId('hilos-channel-frame-html')).toHaveValue(
        String(original.html),
      )
      await draftCommunicationsFrame(
        expect,
        page,
        communicationsFrameHtml('viewer-draft'),
      )
      await expect(page.getByTestId('hilos-channel-frame-save')).toBeDisabled()
      await expect(
        page.getByTestId('hilos-channel-frame-save'),
      ).toHaveAttribute(
        'aria-describedby',
        /(^| )hilos-view-mode-strip-text( |$)/,
      )
      await page.getByTestId('hilos-channel-frame-edit-cancel').click()
      await page.getByTestId('modal-confirm-discard').click()
      await expect(page.getByTestId('modal')).toHaveCount(0)
      await expectCommunicationsFramePreview(expect, page, original)
    } finally {
      wire.stop()
    }
  })
})

test('another person saves into a pristine frame editor, then resolves atomic conflicts both ways', async ({
  page,
  browser,
  baseURL,
  ignoreHTTPSErrors,
}) => {
  test.slow() // Two independent administrators and four real writes with keyboard input.
  const context = await browser.newContext({ baseURL, ignoreHTTPSErrors })
  const other = await context.newPage()
  const wire = watchDeliveryFrames(page)
  const otherWire = watchDeliveryFrames(other)
  let original:
    Awaited<ReturnType<typeof expectCommunicationsFrameInitial>> | undefined
  try {
    const observerId = await grantAdminToSelf(expect, page)
    const writerId = await grantAdminToSelf(expect, other)
    expect(writerId).not.toBe(observerId)
    await gotoPage(expect, page, COMMUNICATIONS_FRAME_PATH)
    await gotoPage(expect, other, COMMUNICATIONS_FRAME_PATH)
    original = await expectCommunicationsFrameInitial(expect, other, otherWire)
    expect(original.source).toMatch(/^(default|project)$/)
    await page.getByTestId('hilos-channel-frame-edit').click()
    await expectCommunicationsFrameEditor(expect, page)
    const first = communicationsFrameHtml('remote-first')
    await saveCommunicationsFrame(expect, other, otherWire, first)
    await expectCommunicationsFrameLive(expect, page, wire, first, 'settings')
    await expect(page.getByTestId('hilos-channel-frame-html')).toHaveValue(
      first,
    )
    await expect(page.getByTestId('hilos-channel-frame-notice')).toHaveText(
      'Updated just now',
    )
    await expect(page.getByTestId('hilos-channel-frame-save')).toBeDisabled()

    const mine = communicationsFrameHtml('observer-mine')
    const second = communicationsFrameHtml('remote-second')
    await draftCommunicationsFrame(expect, page, mine)
    await saveCommunicationsFrame(expect, other, otherWire, second)
    await expectCommunicationsFrameLive(expect, page, wire, second, 'settings')
    await expectCommunicationsFrameConflict(expect, page, mine, second)
    await page.getByTestId('conflict-accept-theirs').click()
    await expect(page.getByTestId('hilos-channel-frame-html')).toHaveValue(
      second,
    )
    await expect(page.getByTestId('conflict-badge')).toHaveCount(0)
    await expect(page.getByTestId('hilos-channel-frame-save')).toBeDisabled()

    const third = communicationsFrameHtml('remote-third')
    await draftCommunicationsFrame(expect, page, mine)
    await saveCommunicationsFrame(expect, other, otherWire, third)
    await expectCommunicationsFrameConflict(expect, page, mine, third)
    await page.getByTestId('conflict-accept-mine').click()
    await expect(page.getByTestId('conflict-badge')).toHaveCount(0)
    await submitCommunicationsFrame(expect, page, wire, mine)
    await expectCommunicationsFrameLive(
      expect,
      other,
      otherWire,
      mine,
      'settings',
    )
  } finally {
    try {
      if (original) {
        const cleanup = await context.newPage()
        const cleanupWire = watchDeliveryFrames(cleanup)
        try {
          await gotoPage(expect, cleanup, COMMUNICATIONS_FRAME_PATH)
          await restoreCommunicationsFrame(
            expect,
            cleanup,
            cleanupWire,
            original,
          )
        } finally {
          cleanupWire.stop()
          await cleanup.close()
        }
      }
    } finally {
      wire.stop()
      otherWire.stop()
      await context.close()
    }
  }
})

test('another person resets the frame under pristine and dirty editors without deleting the carrier', async ({
  page,
  browser,
  baseURL,
  ignoreHTTPSErrors,
}) => {
  test.slow() // Two accounts, three resets and three writes exercise successive merge baselines.
  const context = await browser.newContext({ baseURL, ignoreHTTPSErrors })
  const other = await context.newPage()
  const wire = watchDeliveryFrames(page)
  const otherWire = watchDeliveryFrames(other)
  let original:
    Awaited<ReturnType<typeof expectCommunicationsFrameInitial>> | undefined
  try {
    const observerId = await grantAdminToSelf(expect, page)
    const writerId = await grantAdminToSelf(expect, other)
    expect(writerId).not.toBe(observerId)
    await gotoPage(expect, other, COMMUNICATIONS_FRAME_PATH)
    original = await expectCommunicationsFrameInitial(expect, other, otherWire)
    await saveCommunicationsFrame(
      expect,
      other,
      otherWire,
      communicationsFrameHtml('reset-seed'),
    )
    await gotoPage(expect, page, COMMUNICATIONS_FRAME_PATH)
    await page.getByTestId('hilos-channel-frame-edit').click()
    await expectCommunicationsFrameEditor(expect, page)
    await resetCommunicationsFrame(expect, other, otherWire, original)
    await expectCommunicationsFrameLive(
      expect,
      page,
      wire,
      original.html,
      original.source,
    )
    await expect(page.getByTestId('hilos-channel-frame-html')).toHaveValue(
      String(original.html),
    )
    await expect(page.getByTestId('hilos-channel-frame-notice')).toHaveText(
      'Updated just now',
    )
    await expect(page.getByTestId('hilos-channel-frame-save')).toBeDisabled()

    const remote = communicationsFrameHtml('reset-second')
    const mine = communicationsFrameHtml('reset-mine')
    await saveCommunicationsFrame(expect, other, otherWire, remote)
    await expect(page.getByTestId('hilos-channel-frame-html')).toHaveValue(
      remote,
    )
    await draftCommunicationsFrame(expect, page, mine)
    await resetCommunicationsFrame(expect, other, otherWire, original)
    await expectCommunicationsFrameLive(
      expect,
      page,
      wire,
      original.html,
      original.source,
    )
    await expectCommunicationsFrameConflict(expect, page, mine, original.html)
    await page.getByTestId('conflict-accept-mine').click()
    await submitCommunicationsFrame(expect, page, wire, mine)
    await expectCommunicationsFrameLive(
      expect,
      other,
      otherWire,
      mine,
      'settings',
    )

    await page.getByTestId('hilos-channel-frame-edit').click()
    await expectCommunicationsFrameEditor(expect, page)
    const discarded = communicationsFrameHtml('reset-take-theirs')
    await draftCommunicationsFrame(expect, page, discarded)
    await resetCommunicationsFrame(expect, other, otherWire, original)
    await expectCommunicationsFrameConflict(
      expect,
      page,
      discarded,
      original.html,
    )
    await page.getByTestId('conflict-accept-theirs').click()
    await expect(page.getByTestId('hilos-channel-frame-html')).toHaveValue(
      String(original.html),
    )
    await expect(page.getByTestId('conflict-badge')).toHaveCount(0)
    await expect(page.getByTestId('hilos-channel-frame-save')).toHaveText(
      'Save',
    )
    await expect(page.getByTestId('hilos-channel-frame-save')).toBeDisabled()
    await page.getByTestId('hilos-channel-frame-html').press('Escape')
    await expect(page.getByTestId('modal')).toHaveCount(0)
  } finally {
    try {
      if (original) {
        const cleanup = await context.newPage()
        const cleanupWire = watchDeliveryFrames(cleanup)
        try {
          await gotoPage(expect, cleanup, COMMUNICATIONS_FRAME_PATH)
          await restoreCommunicationsFrame(
            expect,
            cleanup,
            cleanupWire,
            original,
          )
        } finally {
          cleanupWire.stop()
          await cleanup.close()
        }
      }
    } finally {
      wire.stop()
      otherWire.stop()
      await context.close()
    }
  }
})
