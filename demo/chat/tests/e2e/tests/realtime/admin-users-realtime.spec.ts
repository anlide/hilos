import { test, expect } from '@playwright/test'

test.describe('Admin users realtime @realtime', () => {
  test.fixme('propagates an admin rename to the user profile and home participants list', async ({ browser }) => {
    const adminContext = await browser.newContext()
    const userContext = await browser.newContext()
    const admin = await adminContext.newPage()
    const userProfile = await userContext.newPage()
    const userHome = await userContext.newPage()

    await userProfile.goto('/profile')
    await userHome.goto('/')
    await admin.goto('/hilos/admin_users')

    await admin.getByRole('button', { name: 'Edit' }).first().click()
    await admin.locator('#user-name').fill('Admin Propagated User')
    await admin.getByRole('button', { name: 'Save' }).click()

    await expect(userProfile.getByText('Admin Propagated User')).toBeVisible()
    await expect(userHome.getByText('Admin Propagated User')).toBeVisible()
  })

  test.fixme('updates presence and online session counts while the admin table is open', async ({ browser }) => {
    const adminContext = await browser.newContext()
    const userContext = await browser.newContext()
    const admin = await adminContext.newPage()
    const user = await userContext.newPage()

    await admin.goto('/hilos/admin_users')
    await user.goto('/')

    await expect(admin.getByText('online')).toBeVisible()

    await user.close()
    await expect(admin.getByText(/offline|unstable/)).toBeVisible()
  })

  test.fixme('syncs table mutations between two admin sessions', async ({ browser }) => {
    const firstAdminContext = await browser.newContext()
    const secondAdminContext = await browser.newContext()
    const firstAdmin = await firstAdminContext.newPage()
    const secondAdmin = await secondAdminContext.newPage()

    await firstAdmin.goto('/hilos/admin_users')
    await secondAdmin.goto('/hilos/admin_users')

    await firstAdmin.getByRole('button', { name: 'Edit' }).first().click()
    await firstAdmin.locator('#user-name').fill('Admin Table Sync')
    await firstAdmin.getByRole('button', { name: 'Save' }).click()

    await expect(secondAdmin.getByText('Admin Table Sync')).toBeVisible()
  })

  test.fixme('handles concurrent admin edits to the same user row', async ({ browser }) => {
    const firstAdminContext = await browser.newContext()
    const secondAdminContext = await browser.newContext()
    const firstAdmin = await firstAdminContext.newPage()
    const secondAdmin = await secondAdminContext.newPage()

    await firstAdmin.goto('/hilos/admin_users')
    await secondAdmin.goto('/hilos/admin_users')

    await firstAdmin.getByRole('button', { name: 'Edit' }).first().click()
    await secondAdmin.getByRole('button', { name: 'Edit' }).first().click()

    await firstAdmin.locator('#user-name').fill('First Admin Edit')
    await secondAdmin.locator('#user-name').fill('Second Admin Edit')
    await firstAdmin.getByRole('button', { name: 'Save' }).click()

    await expect(secondAdmin.getByText(/conflict|updated|changed/i)).toBeVisible()
  })
})
