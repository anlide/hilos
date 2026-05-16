import { expect, type Locator, type Page } from '@playwright/test'

export const profileName = (page: Page) => page.locator('strong.fs-5')

export const adminUserSearch = (page: Page) => page.getByPlaceholder('Search users...')
export const hilosUserSearch = (page: Page) => page.getByPlaceholder('Search users...')

export const uniqueRealtimeName = (label: string) => {
  return `RT ${label} ${Date.now().toString(36)}-${Math.random().toString(36).slice(2, 8)}`
}

export const waitForConnected = async (page: Page) => {
  await expect(page.getByTestId('nav-offline')).toBeHidden({ timeout: 30000 })
}

export const openMainPage = async (page: Page) => {
  await page.goto('/')
  await waitForConnected(page)
  await expect(page.getByTestId('chat-input')).toBeEnabled({ timeout: 30000 })
  await expect(page.getByTestId('participants-header')).toBeVisible({ timeout: 30000 })
}

export const getCurrentProfileName = async (page: Page) => {
  await page.goto('/profile')
  await waitForConnected(page)
  await expect(page.getByRole('heading', { name: 'Profile' })).toBeVisible({ timeout: 10000 })
  await expect(profileName(page)).toHaveText(/^(?!User$)(?!User #).+$/, { timeout: 30000 })

  return (await profileName(page).textContent())?.trim() ?? ''
}

export const openAdminUsersPage = async (page: Page) => {
  await page.goto('/')
  await waitForConnected(page)
  await page.getByTestId('nav-admin').click()
  await expect(page.getByRole('heading', { name: 'Hilos' })).toBeVisible({ timeout: 10000 })
  await page.getByRole('link', { name: 'User management' }).click()

  await waitForConnected(page)
  await expect(page).toHaveURL('/hilos/admin_users')
  await expect(page.getByRole('heading', { name: 'Admin Users' })).toBeVisible({ timeout: 30000 })
  await expect(adminUserSearch(page)).toBeVisible({ timeout: 30000 })
}

export const openHilosUsersPage = async (page: Page) => {
  await page.goto('/')
  await waitForConnected(page)
  await page.getByTestId('nav-admin').click()
  await expect(page.getByRole('heading', { name: 'Hilos' })).toBeVisible({ timeout: 10000 })
  await page.getByRole('link', { name: 'Users' }).click()

  await waitForConnected(page)
  await expect(page).toHaveURL('/hilos/users')
  await expect(page.getByRole('heading', { name: 'Users' })).toBeVisible({ timeout: 30000 })
  await expect(hilosUserSearch(page)).toBeVisible({ timeout: 30000 })
}

export const userRow = (page: Page, userName: string) => page.getByRole('row').filter({ hasText: userName })

export const waitForSearchedUserRow = async (
  page: Page,
  searchInput: Locator,
  userName: string,
) => {
  await searchInput.fill(userName)
  const row = userRow(page, userName)
  await expect(row).toHaveCount(1, { timeout: 30000 })
  await expect(row).toBeVisible()

  return row
}

export const renameAdminUser = async (page: Page, currentName: string, nextName: string) => {
  const row = await waitForSearchedUserRow(page, adminUserSearch(page), currentName)

  await row.getByRole('button', { name: 'Edit' }).click()
  const modal = page.locator('[data-modal-name="admin-users-modal"]')
  await expect(modal).toBeVisible()

  await modal.locator('#user-name').fill(nextName)
  await modal.getByRole('button', { name: 'Save' }).click()
  await expect(modal).toBeHidden({ timeout: 30000 })
}
