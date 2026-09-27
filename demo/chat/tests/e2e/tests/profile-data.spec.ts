import { expect, test } from '@playwright/test'
import { downloadBytes } from '../../../../../framework/frontend/e2e/index.js'
import { gotoPage } from '../helpers/page.js'
import { clickSubmit, PASSWORD, signUp, typeInto } from '../helpers/session.js'

test('Your data prepares a copy across tabs, downloads it and announces readiness', async ({
  context,
  page,
}) => {
  await signUp(page)
  await gotoPage(page, '/profile')
  await expect(page.getByTestId('profile-data-summary')).toHaveText(
    'No copy yet',
  )
  await clickSubmit(page.getByTestId('profile-data-open'))
  await expect(page.getByTestId('profile-data-view')).toBeVisible()
  await expect(page.getByRole('heading', { level: 1 })).toHaveCount(1)
  await expect(page.getByTestId('hilos-breadcrumb-hilos_profile')).toBeVisible()
  const other = await context.newPage()
  try {
    await gotoPage(other, '/profile/data')
    await expect(other.getByTestId('data-export-prepare')).toBeVisible()
    await clickSubmit(page.getByTestId('data-export-prepare'))
    await expect(page.getByTestId('data-export-step-up')).toBeVisible()
    await typeInto(page.getByTestId('step-up-password'), PASSWORD)
    await clickSubmit(page.getByTestId('data-export-confirm'))
    await expect(page.getByTestId('data-export-step-up')).toHaveCount(0)
    await expect(page.getByTestId('data-export-ready')).toBeVisible()
    await expect(other.getByTestId('data-export-ready')).toBeVisible()
    const { filename, bytes } = await downloadBytes(
      page,
      page.getByTestId('data-export-download'),
    )
    expect(filename).toMatch(/^your-data-\d{4}-\d{2}-\d{2}\.zip$/)
    expect(bytes.subarray(0, 2).toString()).toBe('PK')
    await clickSubmit(page.getByTestId('hilos-notification-toggle'))
    await expect(page.getByTestId('hilos-notification-menu')).toContainText(
      'Your copy of your data is ready',
    )
    await gotoPage(page, '/profile')
    await expect(page.getByTestId('profile-data-summary')).toContainText(
      'Copy ready until',
    )
  } finally {
    await other.close()
  }
})

test('the deletion explanation links to Your data without scheduling deletion', async ({
  page,
}) => {
  await signUp(page)
  await gotoPage(page, '/profile')
  await clickSubmit(page.getByTestId('account-deletion-open'))
  await typeInto(page.getByTestId('step-up-password'), PASSWORD)
  await clickSubmit(page.getByTestId('account-deletion-confirm'))
  await expect(page.getByTestId('account-deletion-data-link')).toBeVisible()
  await clickSubmit(page.getByTestId('account-deletion-data-link').locator('a'))
  await expect(page.getByTestId('account-deletion-modal')).toHaveCount(0)
  await expect(page.getByTestId('profile-data-view')).toBeVisible()
  await expect(page).toHaveURL(/\/profile\/data$/)
  await gotoPage(page, '/profile')
  await expect(page.getByTestId('account-deletion-open')).toBeVisible()
  await expect(page.getByTestId('account-deletion-scheduled')).toHaveCount(0)
})
