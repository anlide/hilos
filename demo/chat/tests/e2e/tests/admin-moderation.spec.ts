import { test, expect, type Page } from '@playwright/test'

const adminModeratorModal = (page: Page) => page.locator('[data-modal-name="admin-moderator-modal"]')
const adminModeratorDeleteModal = (page: Page) => page.locator('[data-modal-name="admin-moderator-delete-modal"]')
const promptPieceSearch = (page: Page) => page.getByPlaceholder('Search prompt pieces...')
const promptPieceRow = (page: Page, promptText: string) => page.getByRole('row').filter({ hasText: promptText })

const uniquePromptText = (label: string) => {
  return `E2E ${label} prompt ${Date.now()}-${Math.random().toString(36).slice(2, 8)}`
}

const openAdminModeratorPage = async (page: Page) => {
  await page.goto('/')

  await expect(page.getByTestId('nav-offline')).toBeHidden({ timeout: 30000 })
  await page.getByTestId('nav-admin').click()
  await expect(page.getByRole('heading', { name: 'Hilos' })).toBeVisible({ timeout: 10000 })
  await page.getByRole('link', { name: 'Moderator prompt pieces' }).click()

  await expect(page).toHaveURL('/hilos/admin_moderator')
  await expect(page.getByRole('heading', { name: 'Moderator prompt pieces' })).toBeVisible({ timeout: 10000 })
  await expect(promptPieceSearch(page)).toBeVisible()
  await expect(page.getByRole('row').filter({ hasText: 'Allow benign' })).toBeVisible({ timeout: 30000 })
}

const applyPendingChangesIfVisible = async (page: Page) => {
  const applyChangesButton = page.getByRole('button', { name: 'Apply changes' })
  if (await applyChangesButton.isVisible()) {
    await applyChangesButton.click()
  }
}

const waitForRowVisible = async (page: Page, row: ReturnType<typeof promptPieceRow>) => {
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

const waitForRowHidden = async (page: Page, row: ReturnType<typeof promptPieceRow>) => {
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

const createPromptPiece = async (page: Page, promptText: string) => {
  await page.getByRole('button', { name: 'Add' }).click()

  const modal = adminModeratorModal(page)
  await expect(modal).toBeVisible()
  await expect(modal.getByText('Create prompt piece')).toBeVisible()

  await page.locator('#piece-section').selectOption('message_rule')
  await page.locator('#piece-prompt').fill(promptText)
  await modal.getByRole('button', { name: 'Create' }).click()

  await expect(modal).toBeHidden()
  await promptPieceSearch(page).fill(promptText)
  await waitForRowVisible(page, promptPieceRow(page, promptText))
}

const deletePromptPiece = async (page: Page, promptText: string) => {
  await promptPieceSearch(page).fill(promptText)
  const row = promptPieceRow(page, promptText)
  await waitForRowVisible(page, row)

  await row.getByRole('button', { name: 'Delete' }).click()

  const modal = adminModeratorDeleteModal(page)
  await expect(modal).toBeVisible()
  await expect(modal.getByText(promptText)).toBeVisible()
  await modal.getByRole('button', { name: 'Delete' }).click()

  await expect(modal).toBeHidden({ timeout: 15000 })
  await waitForRowHidden(page, row)
}

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

  test('creates a message_rule prompt piece', async ({ page }) => {
    await openAdminModeratorPage(page)

    const promptText = uniquePromptText('create')
    await createPromptPiece(page, promptText)
    await expect(promptPieceRow(page, promptText)).toBeVisible()

    await deletePromptPiece(page, promptText)
  })

  test('edits an existing prompt piece and applies the mutation update', async ({ page }) => {
    await openAdminModeratorPage(page)

    const promptText = uniquePromptText('edit')
    const updatedPromptText = `${promptText} updated`
    await createPromptPiece(page, promptText)

    await promptPieceRow(page, promptText).getByRole('button', { name: 'Edit' }).click()

    const modal = adminModeratorModal(page)
    await expect(modal).toBeVisible()
    await expect(modal.getByText('Edit prompt piece')).toBeVisible()

    await page.locator('#piece-prompt').fill(updatedPromptText)
    await modal.getByRole('button', { name: 'Save' }).click()

    await expect(modal).toBeHidden()
    await promptPieceSearch(page).fill(updatedPromptText)
    await waitForRowVisible(page, promptPieceRow(page, updatedPromptText))
    await expect(promptPieceRow(page, updatedPromptText)).toBeVisible()

    await deletePromptPiece(page, updatedPromptText)
  })

  test('deletes a prompt piece after confirmation', async ({ page }) => {
    await openAdminModeratorPage(page)

    const promptText = uniquePromptText('delete')
    await createPromptPiece(page, promptText)
    await deletePromptPiece(page, promptText)

    await expect(promptPieceRow(page, promptText)).toBeHidden()
  })

  test.fixme('searches and sorts prompt pieces by section', async ({ page }) => {
    await page.goto('/hilos/admin_moderator')

    await page.getByPlaceholder('Search prompt pieces...').fill('message_rule')
    await page.getByRole('button', { name: /Section/ }).click()

    await expect(page.getByText('message_rule')).toBeVisible()
  })

})
