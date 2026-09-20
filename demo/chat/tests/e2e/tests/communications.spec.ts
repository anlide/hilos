import { expect, test } from '@playwright/test'

import { signUpAdmin } from '../helpers/adminGrant'
import { gotoPage } from '../helpers/page'

test('draws no pager under a single-page declared table', async ({ page }) => {
  await signUpAdmin(page)
  await gotoPage(page, '/hilos/communications')

  await expect(page.getByTestId('hilos-table-count')).toBeVisible()
  await expect(page.getByTestId('hilos-table-prev')).toHaveCount(0)
  await expect(page.getByTestId('hilos-table-next')).toHaveCount(0)
})
