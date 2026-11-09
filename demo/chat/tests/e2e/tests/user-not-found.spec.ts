import { test, expect } from '../test.js'
import {
  gotoPage,
  PAGE_REFUSED,
} from '../../../../../framework/frontend/e2e/index.js'

// spec-owner: demo — a missing user is chat's own /user page.

// Page subscription error e2e: subscribing a page whose guard rejects the
// request renders the SDK's full-page ErrorPage in place of the page, over the
// live socket with no document reload. A non-existent user trips the chat user
// page's DB_EXISTS 404 guard; "Back to home" navigates in place and clears it.

test('a missing user shows 404 and returning home clears the error in place', async ({
  page,
}) => {
  let fullLoads = 0
  page.on('load', () => {
    fullLoads += 1
  })

  await test.step('shows the 404 error page for a non-existent user', async () => {
    await gotoPage(expect, page, '/user/999999', PAGE_REFUSED)
    await expect(page.getByTestId('conn-state')).toHaveText('connected')

    const error = page.getByTestId('page-error')
    await expect(error).toBeVisible()
    await expect(error).toHaveAttribute('data-error-code', '404')
    await expect(error).toContainText('404')
    await expect(error).toContainText('Page Not Found')
  })

  await test.step('clears the error page when navigating home in place', async () => {
    await gotoPage(expect, page, '/user/999999', PAGE_REFUSED)
    await expect(page.getByTestId('page-error')).toBeVisible()
    const loadsAfterColdLoad = fullLoads

    // "Back to home" is an in-place navigation (no document reload): the page
    // subscription changes, which clears the error and renders the main page.
    await page.getByTestId('page-error-home').click()
    await expect(page).toHaveURL(/\/$/)
    await expect(page.getByTestId('page-error')).toHaveCount(0)
    expect(fullLoads).toBe(loadsAfterColdLoad)
  })
})
