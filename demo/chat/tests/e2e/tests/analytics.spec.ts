import { expect, test } from '@playwright/test'

import {
  expectAnalyticsDashboardCard,
  expectAnalyticsOverview,
} from '../../../../../framework/frontend/e2e/index.js'
import { signUpAdmin } from '../helpers/adminGrant.js'
import { gotoPage } from '../helpers/page.js'

test('opens the empty Analytics overview from the admin dashboard and follows users', async ({
  page,
}) => {
  await signUpAdmin(page)
  await gotoPage(page, '/hilos')

  await expectAnalyticsDashboardCard(expect, page)
  await page.getByTestId('dashboard-card-hilos_analytics').click()

  await expectAnalyticsOverview(expect, page)
  await page.getByTestId('hilos-analytics-overview-open-users').click()
  await expect(page.getByTestId('hilos-admin-title')).toHaveText('Users')
})
