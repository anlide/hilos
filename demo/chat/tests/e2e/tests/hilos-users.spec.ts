import { test, expect, type Page } from '@playwright/test'

const hilosUserModal = (page: Page) => page.locator('[data-modal-name="hilos-user-modal"]')
const hilosUserSearch = (page: Page) => page.getByPlaceholder('Search users...')
const hilosUserRow = (page: Page, userName: string) => page.getByRole('row').filter({ hasText: userName })
const hilosUserNameValue = (page: Page) => page.locator('.form-control-plaintext').first()
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

const openHilosUsersPage = async (page: Page) => {
  await page.goto('/')
  await waitForConnected(page)
  await page.getByTestId('nav-admin').click()
  await expect(page.getByRole('heading', { name: 'Hilos' })).toBeVisible({ timeout: 10000 })
  await page.getByRole('link', { name: 'Users' }).click()

  await expect(page).toHaveURL('/hilos/users')
  await expect(page.getByRole('heading', { name: 'Users' })).toBeVisible({ timeout: 10000 })
  await expect(hilosUserSearch(page)).toBeVisible()
}

const waitForUserRowVisible = async (page: Page, userName: string) => {
  const row = hilosUserRow(page, userName)
  await expect(row).toBeVisible({ timeout: 15000 })

  return row
}

test.describe('Hilos users', () => {
  test('renames a Hilos user through the detail page modal', async ({ page }) => {
    const initialName = await getCurrentUserName(page)
    const renamedUser = uniqueUserName('hilos')

    await openHilosUsersPage(page)
    await hilosUserSearch(page).fill(initialName)
    const row = await waitForUserRowVisible(page, initialName)

    await row.getByRole('link', { name: 'Open' }).click()
    await expect(page).toHaveURL(/\/hilos\/users\/\d+/)

    await page.getByRole('button', { name: 'Rename' }).click()

    const modal = hilosUserModal(page)
    await expect(modal).toBeVisible()
    await expect(modal.getByText('Edit User')).toBeVisible()

    await page.locator('#hilos-user-name').fill(renamedUser)
    await modal.getByRole('button', { name: 'Save' }).click()

    await expect(modal).toBeHidden({ timeout: 15000 })
    await expect(hilosUserNameValue(page)).toHaveText(renamedUser, { timeout: 15000 })

    await page.getByRole('link', { name: 'Back to list' }).click()
    await expect(page).toHaveURL('/hilos/users')
    await hilosUserSearch(page).fill(renamedUser)
    await waitForUserRowVisible(page, renamedUser)
  })
})
