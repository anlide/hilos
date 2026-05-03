import { test, expect } from '@playwright/test'

test.describe('Admin moderation prompt pieces', () => {
  test.fixme('opens moderation prompt pieces from the Hilos dashboard', async ({ page }) => {
    await page.goto('/hilos')

    await page.getByRole('link', { name: 'Moderator prompt pieces' }).click()

    await expect(page).toHaveURL('/hilos/admin_moderator')
    await expect(page.getByRole('heading', { name: 'Moderator prompt pieces' })).toBeVisible()
  })

  test.fixme('renders the prompt pieces table snapshot', async ({ page }) => {
    await page.goto('/hilos/admin_moderator')

    await expect(page.getByRole('columnheader', { name: 'ID' })).toBeVisible()
    await expect(page.getByRole('columnheader', { name: 'Section' })).toBeVisible()
    await expect(page.getByRole('columnheader', { name: 'Prompt' })).toBeVisible()
  })

  test.fixme('creates a message_rule prompt piece', async ({ page }) => {
    await page.goto('/hilos/admin_moderator')

    await page.getByRole('button', { name: /Add|Create/i }).click()
    await expect(page.getByRole('dialog', { name: 'Create prompt piece' })).toBeVisible()

    await page.locator('#piece-section').selectOption('message_rule')
    await page.locator('#piece-prompt').fill('Reject messages containing deterministic-e2e-token.')
    await page.getByRole('button', { name: 'Create' }).click()

    await expect(page.getByText('deterministic-e2e-token')).toBeVisible()
  })

  test.fixme('edits an existing prompt piece and applies the mutation update', async ({ page }) => {
    await page.goto('/hilos/admin_moderator')

    await page.getByRole('button', { name: 'Edit' }).first().click()
    await expect(page.getByRole('dialog', { name: 'Edit prompt piece' })).toBeVisible()

    await page.locator('#piece-prompt').fill('Updated prompt piece from E2E')
    await page.getByRole('button', { name: 'Save' }).click()

    await expect(page.getByText('Updated prompt piece from E2E')).toBeVisible()
  })

  test.fixme('deletes a prompt piece after confirmation', async ({ page }) => {
    await page.goto('/hilos/admin_moderator')

    const promptText = await page.getByRole('row').nth(1).getByRole('cell').nth(2).textContent()
    await page.getByRole('button', { name: 'Delete' }).first().click()
    await expect(page.getByRole('dialog', { name: /Delete/ })).toBeVisible()
    await page.getByRole('button', { name: 'Delete' }).last().click()

    if (promptText) {
      await expect(page.getByText(promptText)).toBeHidden()
    }
  })

  test.fixme('searches and sorts prompt pieces by section', async ({ page }) => {
    await page.goto('/hilos/admin_moderator')

    await page.getByPlaceholder('Search prompt pieces...').fill('message_rule')
    await page.getByRole('button', { name: /Section/ }).click()

    await expect(page.getByText('message_rule')).toBeVisible()
  })

})
