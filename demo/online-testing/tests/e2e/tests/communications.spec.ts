import { expect, test } from '@playwright/test'

import { shownByTestId } from '../../../../../framework/frontend/e2e/index.js'
import { grantAdminToSelf } from '../helpers/adminGrant.js'
import { waitForMailTo } from '../helpers/mail.js'
import { gotoPage } from '../helpers/page.js'
import { signUp } from '../helpers/session.js'

test('the communications section displays email channel, sends a test notification and records delivery', async ({
  page,
}) => {
  const email = await signUp(page)
  await grantAdminToSelf(page)

  await gotoPage(page, '/hilos/communications')
  await expect(page.getByTestId('page-error')).toHaveCount(0)

  const emailToggle = shownByTestId(page, 'hilos-channel-enabled-email')
  await expect(emailToggle).toBeVisible()
  await expect(emailToggle).toBeChecked()

  await shownByTestId(page, 'hilos-channel-configure-email').click()
  await expect(page.getByTestId('hilos-admin-title')).toHaveText('Channel')
  expect(new URL(page.url()).pathname).toBe('/hilos/communications/email')
  await expect(page.getByTestId('page-error')).toHaveCount(0)
  await expect(page.getByTestId('hilos-viewport-table')).toBeVisible()

  const testButton = page.getByTestId('hilos-channel-test')
  await expect(testButton).toBeVisible()
  await expect(testButton).toBeEnabled()
  await testButton.click()

  const toast = page.getByTestId('hilos-toast-success')
  await expect(toast).toBeVisible()
  await expect(toast).toContainText('Test notification queued to')

  await waitForMailTo(email, 'Test notification: Email')

  await gotoPage(page, '/hilos/communications/email/deliveries')
  await expect(page.getByTestId('page-error')).toHaveCount(0)
  const deliveryRow = page.getByTestId(/^hilos-table-row-/).first()
  await expect(deliveryRow).toBeVisible()
  await expect(deliveryRow).toContainText('sent')
})
