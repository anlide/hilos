import { test, expect } from '@playwright/test'

/**
 * File upload scenarios need deterministic test files and server-side upload
 * settings in the E2E seed. Keep these skipped until that fixture exists.
 */
test.describe('Chat file uploads', () => {
  test.fixme('uploads a text file through the picker and sends it with a message', async ({ page }) => {
    await page.goto('/')

    // TODO: add tests/e2e/fixtures/files/plain.txt and target the hidden file input.
    await page.getByLabel('Attach file').click()
    await expect(page.getByText(/Uploading .*plain.txt/)).toBeVisible()
    await expect(page.getByText('plain.txt')).toBeVisible()

    await page.getByTestId('chat-input').fill('Message with text attachment')
    await page.getByTestId('chat-send').click()

    await expect(page.getByText('Message with text attachment')).toBeVisible()
    await expect(page.getByRole('link', { name: /plain.txt/ })).toBeVisible()
  })

  test.fixme('uploads an image and exposes a downloadable attachment after publish', async ({ page }) => {
    await page.goto('/')

    // TODO: attach a small deterministic PNG fixture.
    await expect(page.getByText(/Uploading .*png/)).toBeVisible()
    await expect(page.getByText(/png/)).toBeVisible()

    await page.getByTestId('chat-input').fill('Image attachment check')
    await page.getByTestId('chat-send').click()

    await expect(page.getByRole('link', { name: /png/ })).toBeVisible()
  })

  test.fixme('removes an uploaded draft before message submit', async ({ page }) => {
    await page.goto('/')

    // TODO: create a draft and click the draft remove button.
    await expect(page.getByLabel('Remove attachment')).toBeVisible()
    await page.getByLabel('Remove attachment').click()

    await expect(page.getByLabel('Remove attachment')).toBeHidden()
    await expect(page.getByTestId('chat-send')).toBeDisabled()
  })

  test.fixme('supports drag and drop upload into the chat window', async ({ page }) => {
    await page.goto('/')

    // TODO: dispatch a DataTransfer with a small fixture file.
    await expect(page.getByText('Drop file to upload')).toBeVisible()
    await expect(page.getByText(/Uploading/)).toBeVisible()
  })

  test.fixme('supports paste upload from clipboard file data', async ({ page }) => {
    await page.goto('/')

    // TODO: synthesize a ClipboardEvent with a fixture file.
    await expect(page.getByText(/Uploading/)).toBeVisible()
  })

  test.fixme('rejects unsupported or oversized files with a visible client/server error', async ({ page }) => {
    await page.goto('/')

    // TODO: attach a fixture that is disallowed by test upload settings.
    await expect(page.getByText(/rejected|invalid|too large|unsupported/i)).toBeVisible()
    await expect(page.getByTestId('chat-send')).toBeDisabled()
  })

  test.fixme('recovers cleanly from an interrupted binary upload', async ({ page }) => {
    await page.goto('/')

    // TODO: interrupt the WebSocket after FILE_UPLOAD_INIT and assert retry/error UX.
    await expect(page.getByText(/Not connected|aborted|unavailable/i)).toBeVisible()
  })
})
