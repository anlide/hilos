import { test, expect, type Page } from '@playwright/test'

const adminUsersModal = (page: Page) => page.locator('[data-modal-name="admin-users-modal"]')
const adminUserSearch = (page: Page) => page.getByPlaceholder('Search users...')
const adminUserRow = (page: Page, userName: string) => page.getByRole('row').filter({ hasText: userName })
const profileName = (page: Page) => page.locator('strong.fs-5')

const uniqueUserName = (label: string) => {
  return `E2E ${label} ${Date.now().toString(36)}-${Math.random().toString(36).slice(2, 8)}`
}

const waitForConnected = async (page: Page) => {
  await expect(page.getByTestId('nav-offline')).toBeHidden({ timeout: 30000 })
}

const getCurrentUserName = async (page: Page) => {
  await page.goto('/profile')
  await waitForConnected(page)
  await expect(page.getByRole('heading', { name: 'Profile' })).toBeVisible({ timeout: 10000 })
  await expect(profileName(page)).toHaveText(/^User\d{4}$/, { timeout: 15000 })

  return (await profileName(page).textContent()) ?? ''
}

const openAdminUsersPage = async (page: Page) => {
  await page.goto('/')
  await waitForConnected(page)
  await page.getByTestId('nav-admin').click()
  await expect(page.getByRole('heading', { name: 'Hilos' })).toBeVisible({ timeout: 10000 })
  await page.getByRole('link', { name: 'User management' }).click()

  await expect(page).toHaveURL('/hilos/admin_users')
  await expect(page.getByRole('heading', { name: 'Admin Users' })).toBeVisible({ timeout: 10000 })
  await expect(adminUserSearch(page)).toBeVisible()
}

const applyPendingChangesIfVisible = async (page: Page) => {
  const applyChangesButton = page.getByRole('button', { name: 'Apply changes' })
  if (await applyChangesButton.isVisible()) {
    await applyChangesButton.click()
  }
}

const waitForUserRowVisible = async (page: Page, userName: string) => {
  const row = adminUserRow(page, userName)
  await expect(row).toBeVisible({ timeout: 15000 })

  return row
}

const waitForRenamedUserVisible = async (page: Page, userName: string) => {
  const deadline = Date.now() + 15000

  while (Date.now() < deadline) {
    await applyPendingChangesIfVisible(page)
    await adminUserSearch(page).fill(userName)

    const row = adminUserRow(page, userName)
    if (await row.isVisible()) {
      return
    }

    await page.waitForTimeout(250)
  }

  await adminUserSearch(page).fill(userName)
  await waitForUserRowVisible(page, userName)
}

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

  test('edits a user name and applies the table mutation update', async ({ page }) => {
    const initialName = await getCurrentUserName(page)
    const renamedUser = uniqueUserName('admin')

    await openAdminUsersPage(page)
    await adminUserSearch(page).fill(initialName)
    const row = await waitForUserRowVisible(page, initialName)

    await row.getByRole('button', { name: 'Edit' }).click()

    const modal = adminUsersModal(page)
    await expect(modal).toBeVisible()
    await expect(modal.getByText('Edit User')).toBeVisible()

    await page.locator('#user-name').fill(renamedUser)
    await modal.getByRole('button', { name: 'Save' }).click()

    await expect(modal).toBeHidden()
    await waitForRenamedUserVisible(page, renamedUser)

    await openAdminUsersPage(page)
    await waitForRenamedUserVisible(page, renamedUser)
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
