import { test, expect } from '@playwright/test'

test.describe('Admin users', () => {
  test.fixme('opens user management from the Hilos dashboard', async ({ page }) => {
    await page.goto('/hilos')

    await page.getByRole('link', { name: 'User management' }).click()

    await expect(page).toHaveURL('/hilos/admin_users')
    await expect(page.getByRole('heading', { name: 'Admin Users' })).toBeVisible()
  })

  test.fixme('renders the initial users table snapshot with presence columns', async ({ page }) => {
    await page.goto('/hilos/admin_users')

    await expect(page.getByRole('columnheader', { name: 'ID' })).toBeVisible()
    await expect(page.getByRole('columnheader', { name: 'Name' })).toBeVisible()
    await expect(page.getByRole('columnheader', { name: 'Last Activity' })).toBeVisible()
    await expect(page.getByRole('columnheader', { name: 'Presence' })).toBeVisible()
    await expect(page.getByRole('columnheader', { name: 'Online Sessions' })).toBeVisible()
  })

  test.fixme('searches and sorts users without losing row actions', async ({ page }) => {
    await page.goto('/hilos/admin_users')

    await page.getByPlaceholder('Search users...').fill('User')
    await page.getByRole('button', { name: /Name/ }).click()

    await expect(page.getByRole('button', { name: 'Edit' }).first()).toBeVisible()
  })

  test.fixme('edits a user name and applies the table mutation update', async ({ page }) => {
    await page.goto('/hilos/admin_users')

    await page.getByRole('button', { name: 'Edit' }).first().click()
    await expect(page.getByRole('dialog', { name: 'Edit User' })).toBeVisible()

    await page.locator('#user-name').fill('Admin Edited User')
    await page.getByRole('button', { name: 'Save' }).click()

    await expect(page.getByText('Admin Edited User')).toBeVisible()
  })

  test.fixme('prevents saving invalid admin user names', async ({ page }) => {
    await page.goto('/hilos/admin_users')

    await page.getByRole('button', { name: 'Edit' }).first().click()
    await page.locator('#user-name').fill('A')

    await expect(page.getByRole('button', { name: 'Save' })).toBeDisabled()
  })

  test.fixme('shows the guest placeholder when admin users are unavailable to the client', async ({ page }) => {
    // TODO: run with an unauthenticated/forbidden session fixture once auth exists.
    await page.goto('/hilos/admin_users')

    await expect(page.getByText('Access denied for guests.')).toBeVisible()
  })
})
