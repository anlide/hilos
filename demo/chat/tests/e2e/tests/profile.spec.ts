import { test, expect, type Page } from '@playwright/test'

const profileModal = (page: Page) => page.locator('[data-modal-name="change-username-modal"]')
const profileName = (page: Page) => page.locator('strong.fs-5')
const usernameInput = (page: Page) => page.locator('#username-input')

const isRecord = (value: unknown): value is Record<string, unknown> => {
  return typeof value === 'object' && value !== null && !Array.isArray(value)
}

const isRenameActionMessage = (message: string): boolean => {
  return parseRenameActionName(message) !== null
}

const parseRenameActionName = (message: string): string | null => {
  try {
    const parsed = JSON.parse(message)
    if (
      !isRecord(parsed) ||
      parsed.type !== 'action' ||
      parsed.action !== 'rename' ||
      !isRecord(parsed.data) ||
      typeof parsed.data.newName !== 'string'
    ) {
      return null
    }
    return parsed.data.newName
  } catch {
    return null
  }
}

const openProfileModal = async (page: Page) => {
  await page.goto('/profile')

  await expect(page.getByRole('heading', { name: 'Profile' })).toBeVisible()
  await waitForProfileReady(page)
  await page.getByRole('button', { name: 'Edit' }).click()

  const modal = profileModal(page)
  await expect(modal).toBeVisible()
  await expect(usernameInput(page)).toBeVisible()

  return modal
}

const waitForProfileReady = async (page: Page) => {
  await expect(page.getByTestId('nav-offline')).toBeHidden({ timeout: 15000 })
  await expect(profileName(page)).toHaveText(/^User\d{4}$/, { timeout: 15000 })
}

const setRawUsernameInputValue = async (page: Page, value: string) => {
  await usernameInput(page).evaluate((element, nextValue) => {
    const input = element as HTMLInputElement
    input.value = nextValue
    input.dispatchEvent(new Event('input', { bubbles: true }))
  }, value)
}

const rejectNextRename = async (page: Page, reason: string) => {
  await page.routeWebSocket(
    (url) => url.protocol === 'ws:' || url.protocol === 'wss:',
    (ws) => {
      const server = ws.connectToServer()

      ws.onMessage((message) => {
        const text = typeof message === 'string' ? message : message.toString()
        if (isRenameActionMessage(text)) {
          ws.send(JSON.stringify({
            type: 'rename_fail',
            outcome: 'fail',
            data: { reason },
          }))

          return
        }

        server.send(message)
      })
    }
  )
}

const allowNextRename = async (page: Page) => {
  await page.routeWebSocket(
    (url) => url.protocol === 'ws:' || url.protocol === 'wss:',
    (ws) => {
      const server = ws.connectToServer()

      ws.onMessage((message) => {
        const text = typeof message === 'string' ? message : message.toString()
        const newName = parseRenameActionName(text)
        if (newName !== null) {
          ws.send(JSON.stringify({
            type: 'subscription_page_profile',
            data: {
              tables: {
                selfConnection: {
                  rows: [{
                    rowKey: `profile-test-${Date.now()}`,
                    sources: {
                      connections: { userId: 0 },
                      users: { id: 0, name: newName },
                    },
                  }],
                },
              },
            },
          }))
          ws.send(JSON.stringify({
            type: 'rename_success',
            outcome: 'success',
          }))

          return
        }

        server.send(message)
      })
    }
  )
}

const uniqueProfileName = () => {
  return `PW-${Date.now().toString(36).slice(-5)}-${Math.random().toString(36).slice(2, 5)}`
}

test.describe('Profile', () => {
  test('renames the current user from the profile modal', async ({ page }) => {
    await allowNextRename(page)
    const modal = await openProfileModal(page)
    const newName = uniqueProfileName()

    await usernameInput(page).fill(newName)
    await modal.getByRole('button', { name: 'Save' }).click()

    await expect(modal).toBeHidden()
    await expect(profileName(page)).toHaveText(newName, { timeout: 15000 })
  })

  test('keeps save disabled for names outside the user length contract', async ({ page }) => {
    const modal = await openProfileModal(page)
    const saveButton = modal.getByRole('button', { name: 'Save' })

    await usernameInput(page).fill('A')
    await expect(saveButton).toBeDisabled()

    await setRawUsernameInputValue(page, 'x'.repeat(65))
    await expect(saveButton).toBeDisabled()

    await usernameInput(page).fill('x'.repeat(64))
    await expect(saveButton).toBeEnabled()
  })

  test('shows rename failures in the modal without losing the entered name', async ({ page }) => {
    const reason = 'Rejected by profile test'
    await rejectNextRename(page, reason)

    const modal = await openProfileModal(page)
    await usernameInput(page).fill('Rejected Name')
    await modal.getByRole('button', { name: 'Save' }).click()

    await expect(modal.getByRole('alert')).toHaveText(reason)
    await expect(usernameInput(page)).toHaveValue('Rejected Name')
    await expect(modal).toBeVisible()
  })

  test('cancels a dirty rename form without changing the displayed user name', async ({ page }) => {
    await page.goto('/profile')

    await expect(page.getByRole('heading', { name: 'Profile' })).toBeVisible()
    await waitForProfileReady(page)
    const currentName = await profileName(page).textContent()

    await page.getByRole('button', { name: 'Edit' }).click()
    const modal = profileModal(page)
    await expect(modal).toBeVisible()
    await usernameInput(page).fill('Discarded Name')
    await modal.getByRole('button', { name: 'Cancel' }).click()

    await expect(modal).toBeHidden()
    await expect(profileName(page)).toHaveText(currentName ?? 'User')
  })
})
