import { expect, test } from '@playwright/test'

import {
  expectCommunicationsChannelHead,
  shownByTestId,
} from '../../../../../framework/frontend/e2e/index.js'
import { setAdminViewMode } from '../helpers/adminViewMode.js'
import { grantAdminToSelf } from '../helpers/adminGrant.js'
import { waitForMailTo } from '../helpers/mail.js'
import { expectPageReady, gotoPage } from '../helpers/page.js'
import { signUp } from '../helpers/session.js'

test('the communications section configures email, sends a test and records delivery', async ({
  page,
}) => {
  const { email } = await signUp(page)
  await grantAdminToSelf(page)

  await gotoPage(page, '/hilos/communications')
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
  await expectPageReady(page)
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
  await expectPageReady(page)
  await expectCommunicationsChannelHead(expect, page, 'deliveries')
  const deliveryRow = page.getByTestId(/^hilos-table-row-/).first()
  await expect(deliveryRow).toBeVisible()
  await expect(deliveryRow).toContainText('sent')
})

test.afterEach(() => setAdminViewMode(false))

test('channel tabs support direct entry, reload, and a viewer with masked fields', async ({
  page,
}) => {
  await setAdminViewMode(true)
  await gotoPage(page, '/hilos/communications/email/configuration')
  await expectCommunicationsChannelHead(expect, page, 'configuration')
  await expect(page.getByTestId('view-mode-banner')).toBeVisible()
  await expect(page.getByTestId('hilos-viewport-table')).toBeVisible()
  await expect(page.getByTestId('hilos-hidden').first()).toBeVisible()
  await expect(page.getByTestId('hilos-channel-test')).toHaveCount(0)
  await page.reload()
  await expectPageReady(page)
  await expectCommunicationsChannelHead(expect, page, 'configuration')
  expect(new URL(page.url()).pathname).toBe(
    '/hilos/communications/email/configuration',
  )

  await page.getByTestId('hilos-page-tab-hilos_communications_channel').click()
  await expectPageReady(page)
  await expectCommunicationsChannelHead(expect, page, 'overview')
  await expect(page.getByTestId('hilos-viewport-table')).toHaveCount(0)
  await expect(page.getByTestId('channel-controls')).toBeVisible()
  await expect(page.getByTestId('hilos-channel-enabled')).toBeDisabled()
  await expect(page.getByTestId('hilos-channel-test')).toBeDisabled()

  await page
    .getByTestId('hilos-page-tab-hilos_communications_deliveries')
    .click()
  await expectPageReady(page)
  await expectCommunicationsChannelHead(expect, page, 'deliveries')
  expect(new URL(page.url()).pathname).toBe(
    '/hilos/communications/email/deliveries',
  )
  await expect(page.getByTestId('hilos-viewport-table')).toBeVisible()
  await gotoPage(page, '/hilos/communications/email/deliveries')
  await expectCommunicationsChannelHead(expect, page, 'deliveries')
  await page.reload()
  await expectPageReady(page)
  await expectCommunicationsChannelHead(expect, page, 'deliveries')
})
