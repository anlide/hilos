import { test, expect, type Page } from '@playwright/test'

const adminBotsModal = (page: Page) => page.locator('[data-modal-name="admin-bots-modal"]')
const adminBotsDeleteModal = (page: Page) => page.locator('[data-modal-name="admin-bots-delete-modal"]')
const botSearch = (page: Page) => page.getByPlaceholder('Search bots...')
const botRow = (page: Page, botName: string) => page.getByRole('row').filter({ hasText: botName })

const uniqueBotName = (label: string) => {
  return `E2E Bot ${label} ${Date.now()}-${Math.random().toString(36).slice(2, 8)}`
}

const openAdminBotsPage = async (page: Page) => {
  await page.goto('/')

  await expect(page.getByTestId('nav-offline')).toBeHidden({ timeout: 30000 })
  await page.getByTestId('nav-admin').click()
  await expect(page.getByRole('heading', { name: 'Hilos' })).toBeVisible({ timeout: 10000 })
  await page.getByRole('link', { name: 'Bots' }).click()

  await expect(page).toHaveURL('/hilos/admin_bots')
  await expect(page.getByRole('heading', { name: 'Admin Bots' })).toBeVisible({ timeout: 10000 })
  await expect(botSearch(page)).toBeVisible()
  await expect(page.getByRole('row').filter({ hasText: 'Marcus' })).toBeVisible({ timeout: 30000 })
}

const applyPendingChangesIfVisible = async (page: Page) => {
  const applyChangesButton = page.getByRole('button', { name: 'Apply changes' })
  if (await applyChangesButton.isVisible()) {
    await applyChangesButton.click()
  }
}

const waitForRowVisible = async (page: Page, row: ReturnType<typeof botRow>) => {
  const deadline = Date.now() + 15000
  while (Date.now() < deadline) {
    await applyPendingChangesIfVisible(page)
    if (await row.isVisible()) {
      return
    }
    await page.waitForTimeout(250)
  }

  await expect(row).toBeVisible()
}

const waitForRowHidden = async (page: Page, row: ReturnType<typeof botRow>) => {
  const deadline = Date.now() + 15000
  while (Date.now() < deadline) {
    await applyPendingChangesIfVisible(page)
    if (!(await row.isVisible())) {
      return
    }
    await page.waitForTimeout(250)
  }

  await expect(row).toBeHidden()
}

const createBot = async (page: Page, botName: string) => {
  await page.getByRole('button', { name: 'Add' }).click()

  const modal = adminBotsModal(page)
  await expect(modal).toBeVisible()
  await expect(modal.getByText('Create Bot')).toBeVisible()

  await page.locator('#bot-name').fill(botName)
  await page.locator('#bot-description').fill('Created by Playwright CRUD coverage')
  await page.locator('#bot-style').fill('brief')
  await page.locator('#bot-active').selectOption('true')
  await modal.getByRole('button', { name: 'Create' }).click()

  await expect(modal).toBeHidden()
  await botSearch(page).fill(botName)
  await waitForRowVisible(page, botRow(page, botName))
}

const deleteBot = async (page: Page, botName: string) => {
  await botSearch(page).fill(botName)
  const row = botRow(page, botName)
  await waitForRowVisible(page, row)

  await row.getByRole('button', { name: 'Delete' }).click()

  const modal = adminBotsDeleteModal(page)
  await expect(modal).toBeVisible()
  await expect(modal.locator('.text-break.fw-medium')).toHaveText(botName)
  await modal.getByRole('button', { name: 'Delete' }).click()

  await expect(modal).toBeHidden({ timeout: 15000 })
  await waitForRowHidden(page, row)
}

test.describe('Admin bots', () => {
  test.fixme('opens bot management from the Hilos dashboard', async ({ page }) => {
    await page.goto('/hilos')

    await page.getByRole('link', { name: 'Bots' }).click()

    await expect(page).toHaveURL('/hilos/admin_bots')
    await expect(page.getByRole('heading', { name: 'Admin Bots' })).toBeVisible()
  })

  test.fixme('renders bot table snapshot with status and runtime columns', async ({ page }) => {
    await page.goto('/hilos/admin_bots')

    await expect(page.getByRole('columnheader', { name: 'Name' })).toBeVisible()
    await expect(page.getByRole('columnheader', { name: 'Description' })).toBeVisible()
    await expect(page.getByRole('columnheader', { name: 'Style' })).toBeVisible()
    await expect(page.getByRole('columnheader', { name: 'Status' })).toBeVisible()
    await expect(page.getByRole('columnheader', { name: 'Runtime' })).toBeVisible()
  })

  test('creates an active bot through the modal', async ({ page }) => {
    await openAdminBotsPage(page)

    const botName = uniqueBotName('create')
    await createBot(page, botName)

    const row = botRow(page, botName)
    await expect(row).toBeVisible()
    await expect(row.getByText(/^active$/)).toBeVisible()

    await deleteBot(page, botName)
  })

  test('edits bot metadata and active state', async ({ page }) => {
    await openAdminBotsPage(page)

    const botName = uniqueBotName('edit')
    const updatedBotName = `${botName} updated`
    await createBot(page, botName)

    await botRow(page, botName).getByRole('button', { name: 'Edit' }).click()

    const modal = adminBotsModal(page)
    await expect(modal).toBeVisible()
    await expect(modal.getByText('Edit Bot')).toBeVisible()

    await page.locator('#bot-name').fill(updatedBotName)
    await page.locator('#bot-description').fill('Updated by Playwright CRUD coverage')
    await page.locator('#bot-style').fill('concise')
    await page.locator('#bot-active').selectOption('false')
    await modal.getByRole('button', { name: 'Save' }).click()

    await expect(modal).toBeHidden()
    await botSearch(page).fill(updatedBotName)
    await waitForRowVisible(page, botRow(page, updatedBotName))

    const row = botRow(page, updatedBotName)
    await expect(row).toBeVisible()
    await expect(row.getByText(/^inactive$/)).toBeVisible()

    await deleteBot(page, updatedBotName)
  })

  test('deletes a bot after confirmation and applies the table mutation', async ({ page }) => {
    await openAdminBotsPage(page)

    const botName = uniqueBotName('delete')
    await createBot(page, botName)
    await deleteBot(page, botName)

    await expect(botRow(page, botName)).toBeHidden()
  })

  test.fixme('shows active bots in the participants list and bot profile route', async ({ page }) => {
    await page.goto('/')

    await expect(page.getByText(/Bots \(/)).toBeVisible()
    await page.getByText(/bot/i).first().click()
    await expect(page).toHaveURL(/\/bot\/\d+/)
  })

})
