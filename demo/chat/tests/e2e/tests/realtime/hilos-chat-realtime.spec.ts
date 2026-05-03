import { test, expect } from '@playwright/test'

test.describe('hilos-chat realtime @realtime', () => {
  test.fixme('keeps hilos users list and hilos user detail in sync after admin user update', async ({ browser }) => {
    const adminContext = await browser.newContext()
    const hilosContext = await browser.newContext()
    const admin = await adminContext.newPage()
    const hilosUsers = await hilosContext.newPage()
    const hilosUserDetail = await hilosContext.newPage()

    await admin.goto('/hilos/admin_users')
    await hilosUsers.goto('/hilos/users')
    await hilosUserDetail.goto('/hilos/users/1')

    await admin.getByRole('button', { name: 'Edit' }).first().click()
    await admin.locator('#user-name').fill('Hilos Projection Sync')
    await admin.getByRole('button', { name: 'Save' }).click()

    await expect(hilosUsers.getByText('Hilos Projection Sync')).toBeVisible()
    await expect(hilosUserDetail.getByText('Hilos Projection Sync')).toBeVisible()
  })

  test.fixme('syncs hilos detail edits back to admin users and profile pages', async ({ browser }) => {
    const hilosContext = await browser.newContext()
    const adminContext = await browser.newContext()
    const userContext = await browser.newContext()
    const hilosUserDetail = await hilosContext.newPage()
    const adminUsers = await adminContext.newPage()
    const profile = await userContext.newPage()

    await hilosUserDetail.goto('/hilos/users/1')
    await adminUsers.goto('/hilos/admin_users')
    await profile.goto('/profile')

    await hilosUserDetail.getByRole('button', { name: 'Edit' }).click()
    await hilosUserDetail.locator('#hilos-user-name').fill('Hilos Detail Sync')
    await hilosUserDetail.getByRole('button', { name: 'Save' }).click()

    await expect(adminUsers.getByText('Hilos Detail Sync')).toBeVisible()
    await expect(profile.getByText('Hilos Detail Sync')).toBeVisible()
  })

  test.fixme('updates Hilos logs overview while another session performs logged actions', async ({ browser }) => {
    const logsContext = await browser.newContext()
    const actorContext = await browser.newContext()
    const logs = await logsContext.newPage()
    const actor = await actorContext.newPage()

    await logs.goto('/hilos/logs')
    await actor.goto('/')
    await actor.getByTestId('chat-input').fill('Log projection check')
    await actor.getByTestId('chat-send').click()

    await expect(logs.getByText(/Log projection check|events|messages/i)).toBeVisible()
  })
})
