import { expect, test } from '@playwright/test'

import { signUpAdmin } from '../helpers/adminGrant.js'
import { gotoPage } from '../helpers/page.js'

test('opens the empty Analytics overview from the admin dashboard and follows users', async ({
  page,
}) => {
  await signUpAdmin(page)
  await gotoPage(page, '/hilos')

  const lead =
    'What a browser session did, in order, and which sessions a person signed into.'
  const card = page.getByTestId('dashboard-card-hilos_analytics')
  await expect(card).toBeVisible()
  await expect(card).toContainText(lead)
  await card.click()

  await expect(page.getByTestId('hilos-admin-title')).toHaveText('Analytics')
  await expect(page.getByTestId('hilos-admin-page')).toContainText(lead)
  await expect(
    page.getByTestId('hilos-analytics-overview-empty'),
  ).toContainText(
    "Nothing here yet. A person's actions open from their page in the admin.",
  )
  await page.getByTestId('hilos-analytics-overview-open-users').click()
  await expect(page.getByTestId('hilos-admin-title')).toHaveText('Users')
})
