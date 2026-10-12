import { test, expect, type Page } from '@playwright/test'

import {
  clearCustomSetting,
  setCustomSetting,
  shownByTestId,
} from '../../../../../framework/frontend/e2e/index.js'
import { signUpAdmin } from '../helpers/adminGrant.js'
import { setAdminViewMode } from '../helpers/adminViewMode.js'
import { expectPageReady, gotoPage } from '../helpers/page.js'
import { signUpPerson } from '../helpers/session.js'

const SWITCHING_KEY = 'theme.switching_enabled'
const DEFAULT_KEY = 'theme.default'
const PICK_KEY = 'hilos.theme.pick'

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
    await shownByTestId(
      viewer,
      `appearance-setting-edit-${SWITCHING_KEY}`,
    ).click()
    const dialog = viewer.getByRole('dialog', {
      name: 'Edit · Theme switching',
    })
    await expect(dialog).toBeVisible()
    const switching = viewer.getByTestId('appearance-switching-input')
    await expect(switching).toBeChecked()
    await expect(switching).toBeEnabled()
    await switching.uncheck()
    await expect(viewer.getByTestId('appearance-switching-save')).toBeDisabled()
    await switching.check()
    await viewer.getByTestId('appearance-switching-cancel').click()
    await expect(dialog).toBeHidden()
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

test('Appearance switching restores guest and account Dark choices without reload', async ({
  browser,
}) => {
  const adminContext = await browser.newContext({ colorScheme: 'light' })
  const guestContext = await browser.newContext({ colorScheme: 'light' })
  const personContext = await browser.newContext({ colorScheme: 'light' })
  const admin = await adminContext.newPage()
  try {
    await signUpAdmin(admin)
    await gotoPage(admin, '/hilos/appearance')
    await expect(currentValue(admin, SWITCHING_KEY)).toHaveText('On')
    await expect(currentValue(admin, DEFAULT_KEY)).toHaveText('As the system')

    const guest = await guestContext.newPage()
    const person = await personContext.newPage()
    let guestLoads = 0
    let personLoads = 0
    guest.on('load', () => {
      guestLoads += 1
    })
    person.on('load', () => {
      personLoads += 1
    })
    for (const visitor of [guest, person]) {
      await gotoPage(visitor, '/')
      await visitor.evaluate((key) => {
        localStorage.setItem(key, 'dark')
      }, PICK_KEY)
      await visitor.reload()
      await expectPageReady(visitor)
    }
    // Registration carries the browser's existing Dark pick into the account.
    await signUpPerson(person)
    for (const visitor of [guest, person]) {
      await expect(visitor.locator('html')).toHaveAttribute(
        'data-bs-theme',
        'dark',
      )
      await expect(visitor.getByTestId('nav-theme')).toBeVisible()
    }
    const guestLoadsBeforeChange = guestLoads
    const personLoadsBeforeChange = personLoads

    for (const enabled of [false, true]) {
      await shownByTestId(
        admin,
        `appearance-setting-edit-${SWITCHING_KEY}`,
      ).click()
      const dialog = admin.getByRole('dialog', {
        name: 'Edit · Theme switching',
      })
      await expect(dialog).toBeVisible()
      const switching = admin.getByTestId('appearance-switching-input')
      await expect(switching).toBeChecked({ checked: !enabled })
      const save = admin.getByTestId('appearance-switching-save')
      await expect(save).toBeDisabled()
      await switching.setChecked(enabled)
      await save.scrollIntoViewIfNeeded()
      await expect(save).toBeVisible()
      await expect(save).toBeEnabled()
      await save.focus()
      await save.click()
      await expect(dialog).toBeHidden()
      await expect(
        admin.getByTestId('hilos-toasts').getByText('Theme switching saved.'),
      ).toBeVisible()
      await expect(currentValue(admin, SWITCHING_KEY)).toHaveText(
        enabled ? 'On' : 'Off',
      )

      for (const visitor of [guest, person]) {
        await expect(visitor.locator('html')).toHaveAttribute(
          'data-bs-theme',
          enabled ? 'dark' : 'light',
        )
        if (enabled) {
          await expect(visitor.getByTestId('nav-theme')).toBeVisible()
        } else {
          await expect(visitor.getByTestId('nav-theme')).toHaveCount(0)
        }
        expect(
          await visitor.evaluate((key) => localStorage.getItem(key), PICK_KEY),
        ).toBe('dark')
      }
      expect(guestLoads).toBe(guestLoadsBeforeChange)
      expect(personLoads).toBe(personLoadsBeforeChange)
    }
  } finally {
    try {
      await gotoPage(admin, '/hilos/settings')
      const search = admin.getByTestId('hilos-table-search')
      await search.fill('')
      await search.pressSequentially(SWITCHING_KEY, { delay: 10 })
      await expect(
        admin.getByTestId(`hilos-table-row-${SWITCHING_KEY}`),
      ).toBeVisible()
      await clearCustomSetting(admin, SWITCHING_KEY)
    } finally {
      await Promise.all([
        adminContext.close(),
        guestContext.close(),
        personContext.close(),
      ])
    }
  }
})
