import { test, expect } from '@playwright/test'

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

  test.fixme('creates an active bot through the modal', async ({ page }) => {
    await page.goto('/hilos/admin_bots')

    await page.getByRole('button', { name: /Add|Create/i }).click()
    await expect(page.getByRole('dialog', { name: 'Create Bot' })).toBeVisible()

    await page.locator('#bot-name').fill('E2E Bot')
    await page.locator('#bot-description').fill('Created by Playwright skeleton')
    await page.locator('#bot-style').fill('brief')
    await page.locator('#bot-active').selectOption('true')
    await page.getByRole('button', { name: 'Create' }).click()

    await expect(page.getByText('E2E Bot')).toBeVisible()
  })

  test.fixme('edits bot metadata and active state', async ({ page }) => {
    await page.goto('/hilos/admin_bots')

    await page.getByRole('button', { name: 'Edit' }).first().click()
    await expect(page.getByRole('dialog', { name: 'Edit Bot' })).toBeVisible()

    await page.locator('#bot-name').fill('Edited E2E Bot')
    await page.locator('#bot-active').selectOption('false')
    await page.getByRole('button', { name: 'Save' }).click()

    await expect(page.getByText('Edited E2E Bot')).toBeVisible()
    await expect(page.getByText('inactive')).toBeVisible()
  })

  test.fixme('deletes a bot after confirmation and applies the table mutation', async ({ page }) => {
    await page.goto('/hilos/admin_bots')

    const botName = await page.getByRole('row').nth(1).getByRole('cell').nth(1).textContent()
    await page.getByRole('button', { name: 'Delete' }).first().click()
    await expect(page.getByRole('dialog', { name: /Delete/ })).toBeVisible()
    await page.getByRole('button', { name: 'Delete' }).last().click()

    if (botName) {
      await expect(page.getByText(botName)).toBeHidden()
    }
  })

  test.fixme('shows active bots in the participants list and bot profile route', async ({ page }) => {
    await page.goto('/')

    await expect(page.getByText(/Bots \(/)).toBeVisible()
    await page.getByText(/bot/i).first().click()
    await expect(page).toHaveURL(/\/bot\/\d+/)
  })

})
