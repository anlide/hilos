import { expect, test as base } from '../test.js'

import {
  borrowSetting,
  cleanupFixtures,
  clearCustomSetting,
  type TestCleanup,
  createTestUser,
  enableEmailChannel,
  ownNotificationSubject,
  COMMUNICATIONS_FRAME_PATH,
  communicationsFrameHtml,
  draftCommunicationsFrame,
  expectCommunicationsFrameConflict,
  expectCommunicationsFrameDiscard,
  expectCommunicationsFrameInitial,
  expectCommunicationsFrameLive,
  expectCommunicationsFramePreview,
  resetCommunicationsFrame,
  saveCommunicationsFrame,
  submitCommunicationsFrame,
  watchDeliveryFrames,
  expectCommunicationsAccessibility,
  expectCommunicationsChannelHead,
  expectCommunicationsFrameEditor,
  shownByTestId,
  expectPageReady,
  gotoPage,
  typeInto,
  grantAdminToSelf,
} from '../../../../../framework/frontend/e2e/index.js'
import { setAdminViewMode } from '../../../../../framework/frontend/scripts/adminViewMode.mjs'
import { waitForMailTo } from '../../../../../framework/frontend/scripts/standMailbox.mjs'
import { inspectNotification } from '../../../../../framework/frontend/scripts/notifications.mjs'

const test = base.extend<{ cleanup: TestCleanup }>({ cleanup: cleanupFixtures })
test.describe.configure({ mode: 'default' })

// spec-owner: demo — carries Polls' Angular browser journeys for these shared pages

test('email delivery preserves keyboard navigation and sends the administrator a test letter', async ({
  page,
  cleanup,
}) => {
  const { email, userId } = await createTestUser(expect, page)
  await grantAdminToSelf(expect, page)
  await borrowSetting(cleanup, 'notifications.channel.email.enabled')
  await gotoPage(expect, page, '/hilos/communications')
  await enableEmailChannel(page)
  await test.step('Communications exposes one named head and keyboard links between channel pages', async () => {
    await gotoPage(expect, page, '/hilos/communications/channel/email')
    await expectCommunicationsAccessibility(
      expect,
      page,
      'overview',
      'Enable Email',
    )
    await expect(page).toHaveURL(/\/hilos\/communications\/channel\/email$/)

    const configuration = page.getByTestId(
      'hilos-page-tab-hilos_communications_channel_configuration',
    )
    await configuration.focus()
    await expect(configuration).toBeFocused()
    await configuration.press('Enter')
    await expectPageReady(expect, page)
    await expectCommunicationsAccessibility(
      expect,
      page,
      'configuration',
      'Enable Email',
    )
    await expect(page).toHaveURL(
      /\/hilos\/communications\/channel\/email\/configuration$/,
    )

    const deliveries = page.getByTestId(
      'hilos-page-tab-hilos_communications_deliveries',
    )
    await deliveries.focus()
    await expect(deliveries).toBeFocused()
    await deliveries.press('Enter')
    await expectPageReady(expect, page)
    await expectCommunicationsAccessibility(
      expect,
      page,
      'deliveries',
      'Enable Email',
    )
    await expect(page).toHaveURL(
      /\/hilos\/communications\/channel\/email\/deliveries$/,
    )

    const overview = page.getByTestId(
      'hilos-page-tab-hilos_communications_channel',
    )
    await overview.focus()
    await expect(overview).toBeFocused()
    await overview.press('Enter')
    await expectPageReady(expect, page)
    await expectCommunicationsAccessibility(
      expect,
      page,
      'overview',
      'Enable Email',
    )
    await expect(page).toHaveURL(/\/hilos\/communications\/channel\/email$/)
  })
  await test.step('the communications section configures email, sends a test and records delivery', async () => {
    await gotoPage(expect, page, '/hilos/communications')
    await expect(page.getByTestId('page-error')).toHaveCount(0)
    const emailToggle = shownByTestId(page, 'hilos-channel-enabled-email')
    await expect(emailToggle).toBeVisible()
    await expect(emailToggle).toBeChecked()

    await shownByTestId(page, 'hilos-channel-configure-email').click()
    await expectCommunicationsChannelHead(expect, page, 'configuration')
    expect(new URL(page.url()).pathname).toBe(
      '/hilos/communications/channel/email/configuration',
    )
    await expect(page.getByTestId('hilos-viewport-table')).toBeVisible()
    await expect(page.getByTestId('hilos-channel-test')).toHaveCount(0)

    await page
      .getByTestId('hilos-page-tab-hilos_communications_channel')
      .click()
    await expectPageReady(expect, page)
    await expectCommunicationsChannelHead(expect, page, 'overview')
    await expect(page.getByTestId('hilos-viewport-table')).toHaveCount(0)

    ownNotificationSubject(cleanup, userId, 'Test notification: Email', expect)
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
    const mail = await waitForMailTo(email, 'Test notification: Email')
    expect(mail.subject).toBe('Test notification: Email')
    expect(mail.text.trim()).toBe('')
    const own = await inspectNotification(userId, 'Test notification: Email')
    expect(own.queuedChannels).toContain('email')
    const delivery = own.deliveries.find(({ channel }) => channel === 'email')
    expect(delivery).toBeDefined()

    await page
      .getByTestId('hilos-page-tab-hilos_communications_deliveries')
      .click()
    await expectPageReady(expect, page)
    await expectCommunicationsChannelHead(expect, page, 'deliveries')
    await typeInto(
      page.getByTestId('hilos-table-search'),
      'Test notification: Email',
    )
    const deliveryRow = page.getByTestId(
      `hilos-table-row-${delivery!.deliveryId}`,
    )
    await expect(deliveryRow).toBeVisible()
    await expect(deliveryRow).toContainText('sent')
  })
})

test.afterEach(() => setAdminViewMode(false))

test('a viewer follows channel tabs and drafts the visible frame without saving', async ({
  page,
  cleanup,
}) => {
  await setAdminViewMode(true)
  await test.step('channel tabs support direct entry, reload, and a viewer with masked fields', async () => {
    await gotoPage(
      expect,
      page,
      '/hilos/communications/channel/email/configuration',
    )
    await expectCommunicationsChannelHead(expect, page, 'configuration')
    await expect(page.getByTestId('view-mode-banner')).toBeVisible()
    await expect(page.getByTestId('hilos-viewport-table')).toBeVisible()
    await expect(page.getByTestId('hilos-hidden').first()).toBeVisible()
    await expect(page.getByTestId('hilos-channel-test')).toHaveCount(0)
    await page.reload()
    await expectPageReady(expect, page)
    await expectCommunicationsChannelHead(expect, page, 'configuration')
    expect(new URL(page.url()).pathname).toBe(
      '/hilos/communications/channel/email/configuration',
    )

    await page
      .getByTestId('hilos-page-tab-hilos_communications_channel')
      .click()
    await expectPageReady(expect, page)
    await expectCommunicationsChannelHead(expect, page, 'overview')
    await expect(page.getByTestId('hilos-viewport-table')).toHaveCount(0)
    await expect(page.getByTestId('channel-controls')).toBeVisible()
    await expect(page.getByTestId('hilos-channel-enabled')).toBeDisabled()
    await expect(page.getByTestId('hilos-channel-test')).toBeDisabled()

    await page
      .getByTestId('hilos-page-tab-hilos_communications_deliveries')
      .click()
    await expectPageReady(expect, page)
    await expectCommunicationsChannelHead(expect, page, 'deliveries')
    expect(new URL(page.url()).pathname).toBe(
      '/hilos/communications/channel/email/deliveries',
    )
    await expect(page.getByTestId('hilos-viewport-table')).toBeVisible()
    await gotoPage(
      expect,
      page,
      '/hilos/communications/channel/email/deliveries',
    )
    await expectCommunicationsChannelHead(expect, page, 'deliveries')
    await page.reload()
    await expectPageReady(expect, page)
    await expectCommunicationsChannelHead(expect, page, 'deliveries')
  })
  const wire = watchDeliveryFrames(page)
  cleanup.defer(() => wire.stop())
  await test.step('a viewer reads the visible synthetic frame but cannot save a draft', async () => {
    await gotoPage(expect, page, COMMUNICATIONS_FRAME_PATH)
    await expectCommunicationsChannelHead(expect, page, 'frame')
    await expect(page.getByTestId('view-mode-banner')).toBeVisible()
    const original = await expectCommunicationsFrameInitial(expect, page, wire)
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
    await expect(page.getByTestId('hilos-channel-frame-save')).toHaveAttribute(
      'aria-describedby',
      /(^| )hilos-view-mode-strip-text( |$)/,
    )
    await page.getByTestId('hilos-channel-frame-edit-cancel').click()
    await page.getByTestId('modal-confirm-discard').click()
    await expect(page.getByTestId('modal')).toHaveCount(0)
    await expectCommunicationsFramePreview(expect, page, original)
  })
})

test('letter frame editor preserves keyboard focus through named discard layers', async ({
  page,
  cleanup,
}) => {
  const wire = watchDeliveryFrames(page)
  cleanup.defer(() => wire.stop())
  await grantAdminToSelf(expect, page)
  await gotoPage(expect, page, COMMUNICATIONS_FRAME_PATH)
  const original = await expectCommunicationsFrameInitial(expect, page, wire)
  const edit = page.getByTestId('hilos-channel-frame-edit')
  await test.step('the letter frame editor has a dialog name and keyboard focus', async () => {
    await expectCommunicationsAccessibility(expect, page, 'frame', 'Enabled')
    const preview = page.getByTestId('hilos-channel-frame-preview')
    await expect(preview).toHaveAttribute('title', 'Letter frame preview')
    await expect(preview).toHaveAttribute('sandbox', '')
    await expect(preview).toHaveAttribute('srcdoc', /482913/)
    await expect(edit).toHaveAccessibleName('Edit frame')
    await edit.focus()
    await edit.press('Enter')
    await expectCommunicationsFrameEditor(expect, page)
  })
  await test.step('letter frame dirty Escape and Close keep a named discard layer and return focus', async () => {
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
  })
})

test('letter frame cold entry, reload and history carry one complete saved preview', async ({
  page,
  cleanup,
}) => {
  const wire = watchDeliveryFrames(page)
  try {
    await borrowSetting(cleanup, 'notifications.channel.email.frame')
    await grantAdminToSelf(expect, page)
    await gotoPage(expect, page, '/hilos/settings')
    await typeInto(
      page.getByTestId('hilos-table-search'),
      'notifications.channel.email.frame',
    )
    await clearCustomSetting(page, 'notifications.channel.email.frame')
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
    await gotoPage(expect, page, '/hilos/communications/channel/sms/frame')
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

test('letter frame refuses three invalid drafts and reopens the saved original', async ({
  page,
  cleanup,
}) => {
  const wire = watchDeliveryFrames(page)
  cleanup.defer(() => wire.stop())
  await borrowSetting(cleanup, 'notifications.channel.email.frame')
  await grantAdminToSelf(expect, page)
  await gotoPage(expect, page, COMMUNICATIONS_FRAME_PATH)
  const original = await expectCommunicationsFrameInitial(expect, page, wire)
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
    await test.step(`letter frame refuses ${name} on the server and preserves the draft`, async () => {
      await page.getByTestId('hilos-channel-frame-edit').click()
      await expectCommunicationsFrameEditor(expect, page)
      await expect(page.getByTestId('hilos-channel-frame-html')).toHaveValue(
        String(original.html),
      )
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
      await page.getByTestId('hilos-channel-frame-html').press('Escape')
      await expect(page.getByTestId('modal')).toHaveCount(0)
    })
  }
})

test('letter frame saves an override and resets only after confirmation', async ({
  page,
  cleanup,
}) => {
  const wire = watchDeliveryFrames(page)
  cleanup.defer(() => wire.stop())
  await grantAdminToSelf(expect, page)
  await borrowSetting(cleanup, 'notifications.channel.email.frame')
  await gotoPage(expect, page, '/hilos/settings')
  await typeInto(
    page.getByTestId('hilos-table-search'),
    'notifications.channel.email.frame',
  )
  await clearCustomSetting(page, 'notifications.channel.email.frame')
  await gotoPage(expect, page, COMMUNICATIONS_FRAME_PATH)
  const original = await expectCommunicationsFrameInitial(expect, page, wire)
  const html = communicationsFrameHtml('frame-saved')
  await page.getByTestId('hilos-channel-frame-edit').click()
  await expectCommunicationsFrameEditor(expect, page)
  await expect(page.getByTestId('hilos-channel-frame-html')).toHaveValue(
    String(original.html),
  )
  await draftCommunicationsFrame(expect, page, html)
  await expectCommunicationsFramePreview(expect, page, original)
  await submitCommunicationsFrame(expect, page, wire, html)
  await expect(page.getByTestId('hilos-channel-frame-preview')).toHaveAttribute(
    'srcdoc',
    /lang="en" dir="ltr"/,
  )
  await expect(page.locator('#frame-saved')).toHaveCount(0)
  await resetCommunicationsFrame(expect, page, wire, original)
  await expectCommunicationsFramePreview(expect, page, original)
})

test('another person saves into a pristine frame editor, then resolves atomic conflicts both ways', async ({
  page,
  cleanup,
  browser,
  baseURL,
  ignoreHTTPSErrors,
}) => {
  test.slow() // Two independent administrators and four real writes with keyboard input.
  const context = await browser.newContext({ baseURL, ignoreHTTPSErrors })
  cleanup.defer(() => context.close())
  const other = await context.newPage()
  const wire = watchDeliveryFrames(page)
  cleanup.defer(() => wire.stop())
  const otherWire = watchDeliveryFrames(other)
  cleanup.defer(() => otherWire.stop())
  const observerId = await grantAdminToSelf(expect, page)
  const writerId = await grantAdminToSelf(expect, other)
  expect(writerId).not.toBe(observerId)
  await borrowSetting(cleanup, 'notifications.channel.email.frame')
  await gotoPage(expect, other, '/hilos/settings')
  await typeInto(
    other.getByTestId('hilos-table-search'),
    'notifications.channel.email.frame',
  )
  await clearCustomSetting(other, 'notifications.channel.email.frame')
  await gotoPage(expect, page, COMMUNICATIONS_FRAME_PATH)
  await gotoPage(expect, other, COMMUNICATIONS_FRAME_PATH)
  const original = await expectCommunicationsFrameInitial(
    expect,
    other,
    otherWire,
  )
  expect(original.source).toMatch(/^(default|project)$/)
  await page.getByTestId('hilos-channel-frame-edit').click()
  await expectCommunicationsFrameEditor(expect, page)
  const first = communicationsFrameHtml('remote-first')
  await saveCommunicationsFrame(expect, other, otherWire, first)
  await expectCommunicationsFrameLive(expect, page, wire, first, 'settings')
  await expect(page.getByTestId('hilos-channel-frame-html')).toHaveValue(first)
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
  await expect(page.getByTestId('hilos-channel-frame-html')).toHaveValue(second)
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
})

test('another person resets the frame under pristine and dirty editors without deleting the carrier', async ({
  page,
  cleanup,
  browser,
  baseURL,
  ignoreHTTPSErrors,
}) => {
  test.slow() // Two accounts, three resets and three writes exercise successive merge baselines.
  const context = await browser.newContext({ baseURL, ignoreHTTPSErrors })
  cleanup.defer(() => context.close())
  const other = await context.newPage()
  const wire = watchDeliveryFrames(page)
  cleanup.defer(() => wire.stop())
  const otherWire = watchDeliveryFrames(other)
  cleanup.defer(() => otherWire.stop())
  const observerId = await grantAdminToSelf(expect, page)
  const writerId = await grantAdminToSelf(expect, other)
  expect(writerId).not.toBe(observerId)
  await borrowSetting(cleanup, 'notifications.channel.email.frame')
  await gotoPage(expect, other, '/hilos/settings')
  await typeInto(
    other.getByTestId('hilos-table-search'),
    'notifications.channel.email.frame',
  )
  await clearCustomSetting(other, 'notifications.channel.email.frame')
  await gotoPage(expect, other, COMMUNICATIONS_FRAME_PATH)
  const original = await expectCommunicationsFrameInitial(
    expect,
    other,
    otherWire,
  )
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
  await expect(page.getByTestId('hilos-channel-frame-html')).toHaveValue(remote)
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
  await expect(page.getByTestId('hilos-channel-frame-save')).toHaveText('Save')
  await expect(page.getByTestId('hilos-channel-frame-save')).toBeDisabled()
  await page.getByTestId('hilos-channel-frame-html').press('Escape')
  await expect(page.getByTestId('modal')).toHaveCount(0)
})
