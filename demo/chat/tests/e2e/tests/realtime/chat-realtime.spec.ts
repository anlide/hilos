import { test, expect } from '@playwright/test'
import { openMainPage, uniqueRealtimeName } from './realtime-helpers'

test.describe('Chat realtime @realtime', () => {
  test('broadcasts a message between two browser sessions', async ({ browser }) => {
    const senderContext = await browser.newContext()
    const receiverContext = await browser.newContext()
    const sender = await senderContext.newPage()
    const receiver = await receiverContext.newPage()
    const message = uniqueRealtimeName('main chat fanout')

    try {
      await openMainPage(sender)
      await openMainPage(receiver)

      await sender.getByTestId('chat-input').fill(message)
      await sender.getByTestId('chat-send').click()

      await expect(receiver.getByText(message)).toBeVisible({ timeout: 30000 })
      await expect(receiver.getByText(message)).toHaveCount(1)
    } finally {
      await senderContext.close()
      await receiverContext.close()
    }
  })

  test.fixme('updates participants when another user connects and disconnects', async ({ browser }) => {
    const watcherContext = await browser.newContext()
    const userContext = await browser.newContext()
    const watcher = await watcherContext.newPage()
    const user = await userContext.newPage()

    await watcher.goto('/')
    await expect(watcher.getByTestId('participants-header')).toBeVisible()

    await user.goto('/')
    await expect(watcher.getByTestId('online-user').first()).toBeVisible()

    await user.close()
    await expect(watcher.getByTestId('participants-header')).toBeVisible()
  })

  test.fixme('keeps two tabs of the same session in sync after a message send', async ({ browser }) => {
    const context = await browser.newContext()
    const firstTab = await context.newPage()
    const secondTab = await context.newPage()

    await firstTab.goto('/')
    await secondTab.goto('/')

    await firstTab.getByTestId('chat-input').fill('Same-session tab sync')
    await firstTab.getByTestId('chat-send').click()

    await expect(secondTab.getByText('Same-session tab sync')).toBeVisible()
  })
})
