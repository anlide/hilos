import { expect, test } from '@playwright/test'
import { downloadBytes } from '../../../../../framework/frontend/e2e/index.js'
import { gotoPage } from '../helpers/page.js'
import {
  clickSubmit,
  nameFromEmail,
  openSignIn,
  PASSWORD,
  register,
  typeInto,
  uniqueEmail,
} from '../helpers/session.js'

test('Your data confirms identity, prepares a copy and downloads it', async ({
  page,
}) => {
  const email = uniqueEmail()
  await gotoPage(page, '/')
  await openSignIn(page)
  await register(page, email)
  await expect(page.getByTestId('self-user')).toHaveText(nameFromEmail(email))
  await gotoPage(page, '/profile/data')
  await expect(page.getByTestId('profile-data-view')).toBeVisible()
  await expect(page.getByRole('heading', { level: 1 })).toHaveCount(1)
  await clickSubmit(page.getByTestId('data-export-prepare'))
  await typeInto(page.getByTestId('step-up-password'), PASSWORD)
  await clickSubmit(page.getByTestId('data-export-confirm'))
  await expect(page.getByTestId('data-export-step-up')).toHaveCount(0)
  await expect(page.getByTestId('data-export-ready')).toBeVisible()
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
})
