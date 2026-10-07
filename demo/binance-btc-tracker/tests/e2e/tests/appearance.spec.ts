import { test, expect, type Page } from '@playwright/test'

import {
  clearCustomSetting,
  setCustomSetting,
  shownByTestId,
} from '../../../../../framework/frontend/e2e/index.js'
import { signUpAdmin } from '../helpers/adminGrant'
import { setAdminViewMode } from '../helpers/adminViewMode'
import { gotoPage } from '../helpers/page'

const SWITCHING_KEY = 'theme.switching_enabled'
const DEFAULT_KEY = 'theme.default'

/** Values on the fixed Appearance table remain in view without paging or search. */
function currentValue(page: Page, key: string) {
  return shownByTestId(page, `appearance-setting-value-${key}`)
}

test.afterEach(() => setAdminViewMode(false))

test('Appearance card, typed settings and live value remain visible to a viewer', async ({
  page,
  browser,
}) => {
  await signUpAdmin(page)
  await gotoPage(page, '/hilos')
  await expect(
    page.getByTestId('dashboard-card-hilos_appearance'),
  ).toBeVisible()
  await page.getByTestId('dashboard-card-hilos_appearance').click()
  await expect(page.getByTestId('hilos-admin-title')).toHaveText('Appearance')
  expect(new URL(page.url()).pathname).toBe('/hilos/appearance')
  await expect(currentValue(page, SWITCHING_KEY)).toHaveText('On')
  await expect(currentValue(page, DEFAULT_KEY)).toHaveText('As the system')

  await setAdminViewMode(true)
  const viewerContext = await browser.newContext()
  try {
    const viewer = await viewerContext.newPage()
    let viewerLoads = 0
    viewer.on('load', () => {
      viewerLoads += 1
    })
    await gotoPage(viewer, '/hilos/appearance')
    await expect(viewer.getByTestId('hilos-admin-title')).toHaveText(
      'Appearance',
    )
    await expect(currentValue(viewer, SWITCHING_KEY)).toHaveText('On')
    await expect(currentValue(viewer, DEFAULT_KEY)).toHaveText('As the system')
    await expect(
      shownByTestId(viewer, `appearance-setting-default-${DEFAULT_KEY}`),
    ).toHaveText('As the system')
    await expect(
      viewer.getByTestId(`hilos-settings-edit-${DEFAULT_KEY}`),
    ).toHaveCount(0)
    const loadsBeforeChange = viewerLoads

    await gotoPage(page, '/hilos/settings')
    const search = page.getByTestId('hilos-table-search')
    await search.fill('')
    await search.pressSequentially(DEFAULT_KEY, { delay: 10 })
    await expect(
      page.getByTestId(`hilos-table-row-${DEFAULT_KEY}`),
    ).toBeVisible()
    await setCustomSetting(page, DEFAULT_KEY, 'dark')
    await expect(currentValue(viewer, DEFAULT_KEY)).toHaveText('Dark')
    expect(viewerLoads).toBe(loadsBeforeChange)

    await clearCustomSetting(page, DEFAULT_KEY)
    await expect(currentValue(viewer, DEFAULT_KEY)).toHaveText('As the system')
  } finally {
    await viewerContext.close()
  }
})
