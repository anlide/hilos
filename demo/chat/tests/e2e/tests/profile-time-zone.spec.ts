import { expect, test } from '@playwright/test'

import { forwardSessionTimeZone } from '../../../../../framework/frontend/e2e/index.js'
import { gotoPage } from '../helpers/page.js'
import { signUp } from '../helpers/session.js'

test.use({ timezoneId: 'Europe/Warsaw' })

test('displays automatic browser time zone without action button before Theme', async ({
  page,
}) => {
  await signUp(page)
  await gotoPage(page, '/profile')
  await expect(page.getByTestId('profile-name')).toBeVisible()

  const row = page.getByTestId('profile-time-zone-row')
  await expect(row).toBeVisible()
  await expect(page.getByTestId('profile-time-zone')).toHaveText(
    'Europe/Warsaw · automatic',
  )
  await expect(row.locator('button')).toHaveCount(0)

  const rowIndices = await page
    .getByTestId('profile-detail')
    .locator('> div')
    .evaluateAll((rows) => {
      const timeZoneIndex = rows.findIndex((element) =>
        element.querySelector('[data-id="profile-time-zone"]'),
      )
      const themeIndex = rows.findIndex((element) =>
        element.querySelector('[data-id="profile-theme"]'),
      )
      return { timeZoneIndex, themeIndex }
    })
  expect(rowIndices.timeZoneIndex).toBeGreaterThan(-1)
  expect(rowIndices.themeIndex).toBe(rowIndices.timeZoneIndex + 1)
})

test('renders delivered own time zone and updates reactively on session restate without reload', async ({
  page,
}) => {
  let fullLoads = 0
  page.on('load', () => {
    fullLoads += 1
  })

  const socket = await forwardSessionTimeZone(page, 'Asia/Tokyo')
  try {
    await signUp(page)
    await gotoPage(page, '/profile')
    await expect(page.getByTestId('profile-name')).toBeVisible()

    const timeZone = page.getByTestId('profile-time-zone')
    await expect(timeZone).toHaveText('Asia/Tokyo')

    const sockets = socket.sockets()
    const loadsAfterNavigation = fullLoads

    socket.replay('Europe/Warsaw')
    await expect(timeZone).toHaveText('Europe/Warsaw')

    socket.replay(null)
    await expect(timeZone).toHaveText('Europe/Warsaw · automatic')

    expect(socket.sockets()).toBe(sockets)
    expect(fullLoads).toBe(loadsAfterNavigation)
  } finally {
    socket.dispose()
  }
})
