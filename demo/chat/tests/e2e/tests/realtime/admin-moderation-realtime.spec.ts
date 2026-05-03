import { test, expect } from '@playwright/test'

test.describe('Admin moderation realtime @realtime', () => {
  test.fixme('syncs prompt piece table mutations between two admin sessions', async ({ browser }) => {
    const firstAdminContext = await browser.newContext()
    const secondAdminContext = await browser.newContext()
    const firstAdmin = await firstAdminContext.newPage()
    const secondAdmin = await secondAdminContext.newPage()

    await firstAdmin.goto('/hilos/admin_moderator')
    await secondAdmin.goto('/hilos/admin_moderator')

    await firstAdmin.getByRole('button', { name: /Add|Create/i }).click()
    await firstAdmin.locator('#piece-section').selectOption('message_rule')
    await firstAdmin.locator('#piece-prompt').fill('Realtime prompt piece')
    await firstAdmin.getByRole('button', { name: 'Create' }).click()

    await expect(secondAdmin.getByText('Realtime prompt piece')).toBeVisible()
  })

  test.fixme('applies a new message_rule to a concurrently open chat session', async ({ browser }) => {
    const adminContext = await browser.newContext()
    const userContext = await browser.newContext()
    const admin = await adminContext.newPage()
    const user = await userContext.newPage()

    await user.goto('/')
    await admin.goto('/hilos/admin_moderator')

    await admin.getByRole('button', { name: /Add|Create/i }).click()
    await admin.locator('#piece-section').selectOption('message_rule')
    await admin.locator('#piece-prompt').fill('Reject messages containing deterministic-e2e-token.')
    await admin.getByRole('button', { name: 'Create' }).click()

    await user.getByTestId('chat-input').fill('deterministic-e2e-token')
    await user.getByTestId('chat-send').click()

    await expect(user.getByText(/Message rejected/)).toBeVisible()
  })
})
