import { test, expect } from '@playwright/test'

// Page subscription error e2e: subscribing a page whose guard rejects the
// request renders the SDK's full-page ErrorPage in place of the page, over the
// live socket with no document reload. A non-existent user trips the chat user
// page's DB_EXISTS 404 guard; "Back to home" navigates in place and clears it.

test('shows the 404 error page for a non-existent user', async ({ page }) => {
  await page.goto('/user/999999')
  await expect(page.getByTestId('conn-state')).toHaveText('connected')

  const error = page.getByTestId('page-error')
  await expect(error).toBeVisible()
  await expect(error).toHaveAttribute('data-error-code', '404')
  await expect(error).toContainText('404')
  await expect(error).toContainText('Page Not Found')
})

test('clears the error page when navigating home in place', async ({ page }) => {
  let fullLoads = 0
  page.on('load', () => {
    fullLoads += 1
  })

  await page.goto('/user/999999')
  await expect(page.getByTestId('page-error')).toBeVisible()
  const loadsAfterColdLoad = fullLoads

  // "Back to home" is an in-place navigation (no document reload): the page
  // subscription changes, which clears the error and renders the main page.
  await page.getByTestId('page-error-home').click()
  await expect(page).toHaveURL(/\/$/)
  await expect(page.getByTestId('page-error')).toHaveCount(0)
  expect(fullLoads).toBe(loadsAfterColdLoad)
})
