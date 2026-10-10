import { expect, test } from '@playwright/test'

import { signUpAdmin } from '../helpers/adminGrant.js'
import { gotoPage } from '../helpers/page.js'

test('opens Change Log from the admin dashboard with its overview and feed', async ({
  page,
}) => {
  await signUpAdmin(page)
  await gotoPage(page, '/hilos')

  const card = page.getByTestId('dashboard-card-hilos_change_log')
  await expect(card).toBeVisible()
  await expect(card).toContainText(
    'Who changed what in the tracked tables, and through which action.',
  )
  await card.click()

  await expect(page.getByTestId('hilos-admin-title')).toHaveText('Change Log')
  await expect(page.getByTestId('hilos-admin-page')).toContainText(
    'Who changed what in the tracked tables, and through which action.',
  )
  await expect(page.getByTestId('hilos-change-log-tile-journal')).toHaveText(
    /[1-9]/,
  )
  await expect(page.getByTestId('hilos-change-log-tile-tracked')).toHaveText(
    /\d+ of \d+/,
  )
  await expect(page.getByTestId('hilos-table-title')).toHaveText(
    'Recent actions',
  )
  await expect(
    page.locator('table [data-id^="hilos-table-row-"]').first(),
  ).toBeVisible()
})
