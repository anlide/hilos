import { expect, test, type Page } from '@playwright/test'

import {
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
} from '../../../../../framework/frontend/e2e/index.js'
import { grantAdminToSelf } from '../helpers/adminGrant.js'
import { setAdminViewMode } from '../helpers/adminViewMode.js'
import { expectPageReady, gotoPage, PAGE_READY } from '../helpers/page.js'
import { clickSubmit, typeInto } from '../helpers/session.js'

test('draws no pager under a single-page declared table', async ({ page }) => {
  await grantAdminToSelf(page)
  await gotoPage(page, '/hilos/communications')

  await expect(page.getByTestId('hilos-table-count')).toBeVisible()
  await expect(page.getByTestId('hilos-table-prev')).toHaveCount(0)
  await expect(page.getByTestId('hilos-table-next')).toHaveCount(0)
})

// HIL-1050: the channel field's edit modal merges against the live row. Two
// tabs of one context on the sms channel and its `from` field, which no other
// spec reads (the sms helper catches a code by its recipient): a pristine open
// modal follows the other tab's save and says so, a typed one conflicts and
// locks Save, Keep mine sends the typed value to both tabs. The override is
// reset at the end so the field goes back to its env/default — through the
// reset's confirm dialog, the one way ↺ resets (HIL-1147).
test('an open channel-field edit follows the other tab, then conflicts and keeps mine', async ({
  page,
}) => {
  await grantAdminToSelf(page)
  const tabB = await page.context().newPage()
  await gotoPage(page, '/hilos/communications/sms/configuration')
  await gotoPage(tabB, '/hilos/communications/sms/configuration')
  await expect(page.getByTestId('conn-state')).toHaveText('connected')
  await expect(tabB.getByTestId('conn-state')).toHaveText('connected')
  const row = 'hilos-table-row-notifications.channel.sms.from'
  await expect(page.getByTestId(row)).toBeVisible()
  await expect(tabB.getByTestId(row)).toBeVisible()

  // Start from the env/default: an override a broken earlier attempt left
  // behind would make the first save a no-op. The reset control is live only
  // while an override stands, so its going dark is the reset having landed.
  const reset = shownByTestId(page, 'hilos-channel-field-reset-from')
  if (await reset.isEnabled()) {
    await reset.click()
    await page.getByTestId('hilos-channel-reset-confirm').click()
    await expect(reset).toBeDisabled()
  }

  // Tab A opens the modal and does not type; tab B saves a value.
  await shownByTestId(page, 'hilos-channel-field-edit-from').click()
  await expect(page.getByTestId('hilos-channel-edit-value')).toBeVisible()
  await saveChannelField(tabB, '+15550000001')
  await expect(tabB.getByTestId(row)).toContainText('+15550000001')

  // A pristine modal follows the live row and says so on its message line.
  await expect(page.getByTestId('hilos-channel-edit-value')).toHaveValue(
    '+15550000001',
  )
  await expect(page.getByTestId('hilos-channel-edit-notice')).toContainText(
    'Updated just now',
  )
  await expect(page.getByTestId('conflict-badge')).toHaveCount(0)
  await expect(page.getByTestId('hilos-channel-edit-save')).toBeDisabled()

  // Tab A types; tab B saves something else: a conflict, Save locked.
  await typeInto(page.getByTestId('hilos-channel-edit-value'), '+15550000002')
  await saveChannelField(tabB, '+15550000003')
  await expect(tabB.getByTestId(row)).toContainText('+15550000003')
  await expect(page.getByTestId('conflict-badge')).toBeVisible()
  await expect(page.getByTestId('hilos-channel-edit-notice')).toContainText(
    'Changed elsewhere to "+15550000003"',
  )
  await expect(page.getByTestId('conflict-merge')).toHaveCount(0)
  await expect(page.getByTestId('hilos-channel-edit-save')).toBeDisabled()

  // Keep mine unlocks Save; the typed value lands in both tabs.
  await page.getByTestId('conflict-accept-mine').click()
  await expect(page.getByTestId('conflict-badge')).toHaveCount(0)
  await clickSubmit(page.getByTestId('hilos-channel-edit-save'))
  await expect(page.getByTestId('hilos-channel-edit-value')).toHaveCount(0)
  await expect(page.getByTestId(row)).toContainText('+15550000002')
  await expect(tabB.getByTestId(row)).toContainText('+15550000002')

  // Back to the env/default, the same way.
  await expect(reset).toBeEnabled()
  await reset.click()
  await page.getByTestId('hilos-channel-reset-confirm').click()
  await expect(reset).toBeDisabled()
  await tabB.close()
})

/**
 * Save one value into the sms `from` field through its edit modal, and settle
 * when the modal closes.
 *
 * @param tab The tab on the sms channel page.
 * @param value The value to save.
 */
async function saveChannelField(tab: Page, value: string): Promise<void> {
  await shownByTestId(tab, 'hilos-channel-field-edit-from').click()
  await typeInto(tab.getByTestId('hilos-channel-edit-value'), value)
  await clickSubmit(tab.getByTestId('hilos-channel-edit-save'))
  await expect(tab.getByTestId('hilos-channel-edit-value')).toHaveCount(0)
}

test.describe('the communications section in the admin view mode', () => {
  test.afterEach(() => setAdminViewMode(false))

  test('a guest sees the channel switch but cannot use it or send a test', async ({
    page,
  }) => {
    await setAdminViewMode(true)
    await gotoPage(page, '/hilos/communications', PAGE_READY)
    await expect(page.getByTestId('page-error')).toHaveCount(0)

    const enabled = shownByTestId(page, 'hilos-channel-enabled-sms')
    await expect(enabled).toBeVisible()
    await expect(enabled).toBeDisabled()
    await expect(
      shownByTestId(page, 'hilos-table-row-sms').getByTestId('hilos-hidden'),
    ).toHaveCount(0)

    await shownByTestId(page, 'hilos-channel-configure-sms').click()
    await expectCommunicationsChannelHead(
      expect,
      page,
      'configuration',
      'sms',
      'Sms',
    )
    expect(new URL(page.url()).pathname).toBe(
      '/hilos/communications/sms/configuration',
    )

    await page
      .getByTestId('hilos-page-tab-hilos_communications_channel')
      .click()
    await expectPageReady(page)
    await expectCommunicationsChannelHead(
      expect,
      page,
      'overview',
      'sms',
      'Sms',
    )
    await expect(page.getByTestId('hilos-viewport-table')).toHaveCount(0)

    const send = page.getByTestId('hilos-channel-test')
    await expect(send).toBeDisabled()
    await expect(send).toHaveAttribute(
      'aria-describedby',
      /(^| )hilos-view-mode-strip-text( |$)/,
    )
    await expect(page.getByTestId('page-error')).toHaveCount(0)
  })
})

test('the letter frame editor has a dialog name and keyboard focus', async ({
  page,
}) => {
  await grantAdminToSelf(page)
  await gotoPage(page, '/hilos/communications/email/frame')
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
    await grantAdminToSelf(page)
    let after = wire.packets.length
    await gotoPage(page, COMMUNICATIONS_FRAME_PATH)
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
    await expectPageReady(page)
    await expectCommunicationsChannelHead(expect, page, 'frame')
    expect(
      await expectCommunicationsFrameInitial(expect, page, wire, after),
    ).toEqual(original)

    await page
      .getByTestId('hilos-page-tab-hilos_communications_channel')
      .click()
    await expectPageReady(page)
    await expectCommunicationsChannelHead(expect, page, 'overview')
    after = wire.packets.length
    await page.goBack()
    await expectPageReady(page)
    await expectCommunicationsChannelHead(expect, page, 'frame')
    expect(
      await expectCommunicationsFrameInitial(expect, page, wire, after),
    ).toEqual(original)

    await gotoPage(page, '/hilos/communications/sms')
    await expectCommunicationsChannelHead(
      expect,
      page,
      'overview',
      'sms',
      'Sms',
    )
    await gotoPage(page, '/hilos/communications/sms/frame')
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
      await grantAdminToSelf(page)
      await gotoPage(page, COMMUNICATIONS_FRAME_PATH)
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
    await grantAdminToSelf(page)
    await gotoPage(page, COMMUNICATIONS_FRAME_PATH)
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
          await gotoPage(cleanup, COMMUNICATIONS_FRAME_PATH)
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
    await grantAdminToSelf(page)
    await gotoPage(page, COMMUNICATIONS_FRAME_PATH)
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
      await gotoPage(page, COMMUNICATIONS_FRAME_PATH)
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
    const observerId = await grantAdminToSelf(page)
    const writerId = await grantAdminToSelf(other)
    expect(writerId).not.toBe(observerId)
    await gotoPage(page, COMMUNICATIONS_FRAME_PATH)
    await gotoPage(other, COMMUNICATIONS_FRAME_PATH)
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
          await gotoPage(cleanup, COMMUNICATIONS_FRAME_PATH)
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
    const observerId = await grantAdminToSelf(page)
    const writerId = await grantAdminToSelf(other)
    expect(writerId).not.toBe(observerId)
    await gotoPage(other, COMMUNICATIONS_FRAME_PATH)
    original = await expectCommunicationsFrameInitial(expect, other, otherWire)
    await saveCommunicationsFrame(
      expect,
      other,
      otherWire,
      communicationsFrameHtml('reset-seed'),
    )
    await gotoPage(page, COMMUNICATIONS_FRAME_PATH)
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
          await gotoPage(cleanup, COMMUNICATIONS_FRAME_PATH)
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
