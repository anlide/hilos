import { test, expect } from '@playwright/test'

// Step-7.3.3 first-data-on-screen e2e: the client subscribes the page named
// by the URL, the backend answers page_response with the users-table
// snapshot, the normalizer ingests it into the page scope, and the read-only
// table renders. The session-bootstrapped current user is always part of the
// snapshot, so the table must show that exact name.
for (const path of ['/hilos/admin_users', '/hilos/users']) {
  test(`${path} renders the users table snapshot`, async ({ page }) => {
    await page.goto(path)
    await expect(page.getByTestId('self-user')).toHaveText(/^User\d{4}$/)

    const selfName = await page.getByTestId('self-user').textContent()
    await expect(
      page
        .getByTestId('users-table')
        .getByText(selfName ?? '', { exact: true })
        .first(),
    ).toBeVisible()
  })
}

// The main page is the chat itself — it hosts no users table.
test('the main page shows no users table', async ({ page }) => {
  await page.goto('/')
  await expect(page.getByTestId('self-user')).toHaveText(/^User\d{4}$/)
  await expect(page.getByTestId('users-table')).toHaveCount(0)
})
