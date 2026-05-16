import { test, expect } from '@playwright/test'
import {
  adminUserSearch,
  getCurrentProfileName,
  openAdminUsersPage,
  openMainPage,
  renameAdminUser,
  uniqueRealtimeName,
  waitForSearchedUserRow,
} from './realtime-helpers'

test.describe('Admin users realtime @realtime', () => {
  test.setTimeout(60000)

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

  test('updates presence and online session counts while the admin table is open', async ({ browser }) => {
    const adminContext = await browser.newContext()
    const userContext = await browser.newContext()
    const admin = await adminContext.newPage()
    const firstUserTab = await userContext.newPage()

    try {
      await openAdminUsersPage(admin)

      const userName = await getCurrentProfileName(firstUserTab)
      const row = await waitForSearchedUserRow(admin, adminUserSearch(admin), userName)
      await expect(row.locator('td').nth(3)).toHaveText('online', { timeout: 30000 })
      await expect(row.locator('td').nth(4)).toHaveText('1', { timeout: 30000 })

      const secondUserTab = await userContext.newPage()
      await openMainPage(secondUserTab)
      await expect(row).toHaveCount(1)
      await expect(row.locator('td').nth(4)).toHaveText('2', { timeout: 30000 })

      await secondUserTab.close()
      await expect(row.locator('td').nth(4)).toHaveText('1', { timeout: 30000 })
    } finally {
      await userContext.close()
      await adminContext.close()
    }
  })

  test('syncs table mutations between two admin sessions', async ({ browser }) => {
    const userContext = await browser.newContext()
    const firstAdminContext = await browser.newContext()
    const secondAdminContext = await browser.newContext()
    const userProfile = await userContext.newPage()
    const firstAdmin = await firstAdminContext.newPage()
    const secondAdmin = await secondAdminContext.newPage()
    const renamedUser = uniqueRealtimeName('admin users')

    try {
      const initialName = await getCurrentProfileName(userProfile)

      await openAdminUsersPage(firstAdmin)
      await openAdminUsersPage(secondAdmin)
      await waitForSearchedUserRow(firstAdmin, adminUserSearch(firstAdmin), initialName)
      await waitForSearchedUserRow(secondAdmin, adminUserSearch(secondAdmin), initialName)

      await renameAdminUser(firstAdmin, initialName, renamedUser)

      const syncedRow = await waitForSearchedUserRow(secondAdmin, adminUserSearch(secondAdmin), renamedUser)
      await expect(syncedRow.locator('td').nth(1)).toHaveText(renamedUser)
    } finally {
      await userContext.close()
      await firstAdminContext.close()
      await secondAdminContext.close()
    }
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
