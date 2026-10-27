import { expect, test } from '@playwright/test'

import {
  expectAnalyticsDashboardCard,
  expectAnalyticsOverview,
  gotoPage,
  signUpAdmin,
} from '../../../../../framework/frontend/e2e/index.js'

test('opens the empty Analytics overview from the admin dashboard and follows users', async ({
  page,
}) => {
  await signUpAdmin(expect, page)
  await gotoPage(expect, page, '/hilos')

  await expectAnalyticsDashboardCard(expect, page)
  await page.getByTestId('dashboard-card-hilos_analytics').click()

  await expectAnalyticsOverview(expect, page)
  await page.getByTestId('hilos-analytics-overview-open-users').click()
  await expect(page.getByTestId('hilos-admin-title')).toHaveText('Users')
})
