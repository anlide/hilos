import { expect, test } from '@playwright/test'

import { signUpAdmin } from '../helpers/adminGrant'
import { gotoPage, PAGE_READY } from '../helpers/page'

// The tracker stand enables the framework Daemon section. Its standalone
// workers page waits for the node picture and opens a worker's agent panel.
test('shows the standalone node and its workers', async ({ page }) => {
  await signUpAdmin(page)
  await gotoPage(page, '/hilos/daemon/standalone/workers', PAGE_READY)

  await expect(page.getByTestId('hilos-daemon-node-id')).toHaveText(
    'standalone',
  )
  await expect(page.getByTestId('hilos-daemon-node-solo')).toBeVisible()
  await expect(page.getByTestId('hilos-daemon-node-diagram')).toHaveCount(0)

  const expand = page.locator('[data-id^="hilos-table-expand-"]').first()
  await expect(expand).toBeVisible()
  await expand.click()
  await expect(
    page.locator('[data-id^="hilos-table-row-detail-"]').first(),
  ).toBeVisible()
})

test('finds the section node agent on the standalone agents page', async ({
  page,
}) => {
  await signUpAdmin(page)
  await gotoPage(page, '/hilos/daemon/standalone/agents', PAGE_READY)

  await expect(page.getByTestId('hilos-daemon-node-id')).toHaveText(
    'standalone',
  )
  await expect(page.getByTestId('hilos-daemon-node-solo')).toBeVisible()
  const rows = page.locator('[data-id^="hilos-table-row-"]')
  await expect.poll(() => rows.count()).toBeGreaterThan(1)

  const search = page.getByTestId('hilos-table-search')
  await search.fill('')
  await search.pressSequentially('hilos_daemon_node', { delay: 10 })
  await expect(
    page.locator('[data-id^="hilos-table-row-hilos_daemon_node"]'),
  ).toBeVisible()
  await expect(rows).toHaveCount(1)
})
