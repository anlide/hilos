import { test, expect } from '@playwright/test'

/**
 * Roadmap for replacing the current title-only home smoke with real chat
 * message coverage. Convert individual fixme cases to tests as fixtures and
 * selectors become stable.
 */
test.describe('Chat messages', () => {
  test.fixme('sends a text message and renders it in the current session', async ({ page }) => {
    await page.goto('/')

    await expect(page.getByTestId('chat-input')).toBeEnabled()
    await page.getByTestId('chat-input').fill('Hello from Playwright')
    await page.getByTestId('chat-send').click()

    await expect(page.getByText('Hello from Playwright')).toBeVisible()
  })

  test.fixme('persists sent messages across reload and a fresh page subscription', async ({ page }) => {
    await page.goto('/')

    await page.getByTestId('chat-input').fill('Reload persistence check')
    await page.getByTestId('chat-send').click()
    await expect(page.getByText('Reload persistence check')).toBeVisible()

    await page.reload()
    await expect(page.getByText('Reload persistence check')).toBeVisible()
  })

  test.fixme('keeps the send button disabled for empty and trim-empty messages', async ({ page }) => {
    await page.goto('/')

    await expect(page.getByTestId('chat-send')).toBeDisabled()
    await page.getByTestId('chat-input').fill('   ')
    await expect(page.getByTestId('chat-send')).toBeDisabled()
  })

  test.fixme('shows rate limiting after a successful send and recovers after the countdown', async ({ page }) => {
    await page.goto('/')

    await page.getByTestId('chat-input').fill('Rate limit check')
    await page.getByTestId('chat-send').click()

    await expect(page.getByText(/s$/)).toBeVisible()
    await expect(page.getByTestId('chat-send')).toBeDisabled()
    await expect(page.getByTestId('chat-send')).toBeEnabled({ timeout: 15000 })
  })

  test.fixme('shows outbound moderation progress and publishes an approved message', async ({ page }) => {
    await page.goto('/')

    await page.getByTestId('chat-input').fill('Moderation approval check')
    await page.getByTestId('chat-send').click()

    await expect(page.getByText('Moderating message...')).toBeVisible()
    await expect(page.getByText('Moderation approval check')).toBeVisible()
  })

  test.fixme('surfaces rejected and unavailable moderation states without losing the draft', async ({ page }) => {
    await page.goto('/')

    // Seed or force a deterministic moderation rejection before enabling this.
    await page.getByTestId('chat-input').fill('Moderation rejection check')
    await page.getByTestId('chat-send').click()

    await expect(page.getByText(/Message rejected|Moderation unavailable/)).toBeVisible()
    await expect(page.getByTestId('chat-input')).toHaveValue('Moderation rejection check')
  })

  test.fixme('renders participants and user detail links after chat subscription', async ({ page }) => {
    await page.goto('/')

    await expect(page.getByTestId('participants-header')).toBeVisible()
    await expect(page.getByTestId('online-users-label')).toBeVisible()
    await expect(page.getByTestId('online-user').first()).toBeVisible()
  })
})
