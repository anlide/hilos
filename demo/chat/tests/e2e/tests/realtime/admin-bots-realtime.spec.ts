import { test, expect } from '@playwright/test'

test.describe('Admin bots realtime @realtime', () => {
  test.fixme('syncs bot table mutations between two admin sessions', async ({ browser }) => {
    const firstAdminContext = await browser.newContext()
    const secondAdminContext = await browser.newContext()
    const firstAdmin = await firstAdminContext.newPage()
    const secondAdmin = await secondAdminContext.newPage()

    await firstAdmin.goto('/hilos/admin_bots')
    await secondAdmin.goto('/hilos/admin_bots')

    await firstAdmin.getByRole('button', { name: /Add|Create/i }).click()
    await firstAdmin.locator('#bot-name').fill('Realtime Bot')
    await firstAdmin.getByRole('button', { name: 'Create' }).click()

    await expect(secondAdmin.getByText('Realtime Bot')).toBeVisible()
  })

  test.fixme('propagates bot activation changes to the home participants list', async ({ browser }) => {
    const adminContext = await browser.newContext()
    const userContext = await browser.newContext()
    const admin = await adminContext.newPage()
    const user = await userContext.newPage()

    await user.goto('/')
    await admin.goto('/hilos/admin_bots')

    await admin.getByRole('button', { name: 'Edit' }).first().click()
    await admin.locator('#bot-active').selectOption('true')
    await admin.getByRole('button', { name: 'Save' }).click()

    await expect(user.getByText(/Bots \(/)).toBeVisible()
  })

  test.fixme('publishes a bot response to all subscribed chat sessions', async ({ browser }) => {
    const senderContext = await browser.newContext()
    const observerContext = await browser.newContext()
    const sender = await senderContext.newPage()
    const observer = await observerContext.newPage()

    await sender.goto('/')
    await observer.goto('/')

    await sender.getByTestId('chat-input').fill('Ask the bot for a short reply')
    await sender.getByTestId('chat-send').click()

    await expect(observer.getByText(/bot/i)).toBeVisible()
  })
})
