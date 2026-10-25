import { expect, test, type Locator, type Page } from '@playwright/test'
import {
  dismissToasts,
  expectLanguagePageHead,
  shownByTestId,
  watchI18nLivePages,
} from '../../../../../framework/frontend/e2e/index.js'
import { signUpAdmin } from '../helpers/adminGrant.js'
import { typeInto } from '../helpers/session.js'
import { expectPageReady, gotoPage, PAGE_READY } from '../helpers/page.js'

test('opens the default language card directly with read-only details and staged tabs', async ({
  page,
}) => {
  await signUpAdmin(page)
  await gotoPage(page, '/hilos/i18n/languages/en', PAGE_READY)

  await expectLanguagePageHead(expect, page, {
    code: 'en',
    nativeName: 'English',
    currentPage: 'hilos_i18n_language',
    appName: 'BTC Tracker',
  })
  await expect(page.getByTestId('language-head-direction')).toHaveText(
    'Left to right',
  )
  await expect(page.getByTestId('language-card-enabled')).toHaveText(
    'Language switched on',
  )
  await expect(page.getByTestId('language-card-delete-verdict')).toContainText(
    'default language',
  )
  await expect(page.getByTestId('language-card-frozen')).toHaveText(
    'Language enabled — frozen',
  )

  await expect(page.getByTestId('language-head-tile')).toHaveText('en')
  await expect(page.getByTestId('language-head-default')).toHaveText(
    'Default language',
  )
  await expect(page.getByTestId('language-head-own')).toHaveCount(0)
  // This head test does not own every English locale or name row on the shared stand.
  // The custom fixture below verifies exact Overview counts through its lifecycle.
  await expect(
    page.getByTestId('language-overview-locale-count'),
  ).toHaveAttribute('href', '/hilos/i18n/languages/en/locales')
  await expect(
    page.getByTestId('language-overview-name-count'),
  ).toHaveAttribute('href', '/hilos/i18n/languages/en/names')
  await expect(
    page.getByTestId('language-overview-countryless-state'),
  ).toHaveText('Switched on')
  await expect(
    page.getByTestId('language-card-main').getByRole('checkbox'),
  ).toBeDisabled()
  const tabs = page.getByTestId('hilos-page-tabs')
  await expect(tabs).toContainText('Overview')
  await expect(tabs).toContainText('Names')
  await expect(tabs).toContainText('Locales')
  // The names (HIL-1477) and locales (HIL-1476) pages are built in Vue: every tab is a link.
  await expect(tabs.locator('a')).toHaveCount(3)
  await expect(tabs.locator('a').first()).toHaveAttribute(
    'href',
    '/hilos/i18n/languages/en',
  )
  await expect(tabs.locator('a').first()).toHaveAttribute(
    'aria-current',
    'page',
  )
  await expect(
    page.getByTestId('hilos-page-tab-hilos_i18n_language_names'),
  ).toHaveAttribute('href', '/hilos/i18n/languages/en/names')
  await expect(
    page.getByTestId('hilos-page-tab-hilos_i18n_language_locales'),
  ).toHaveAttribute('href', '/hilos/i18n/languages/en/locales')
  await expect(
    page.getByTestId('language-card-main').locator('button, input'),
  ).toHaveCount(2)
  await expect(
    page.getByTestId('language-card-main').getByTestId('language-card-view'),
  ).toBeVisible()
  await expect(
    page.getByTestId('language-card-state').locator('button'),
  ).toHaveCount(2)
  const switchOff = page.getByTestId('language-card-switch-off')
  await expect(switchOff).toBeDisabled()
  await expect(
    page.getByTestId('language-card-switch-off-reason'),
  ).toContainText('default language')
  await expect(switchOff).toHaveAttribute(
    'aria-describedby',
    'language-card-switch-off-reason',
  )
  const deleteButton = page.getByTestId('language-card-delete')
  const deleteReason = page.getByTestId('language-card-delete-reason')
  await expect(deleteButton).toHaveRole('button')
  await expect(deleteButton).toBeVisible()
  await expect(deleteButton).toBeDisabled()
  await expect(deleteButton).toHaveText('Delete language')
  await expect(deleteReason).toBeVisible()
  await expect(deleteReason).toHaveText(
    'Cannot delete: this is the default language.',
  )
  await expect(deleteReason).toHaveAttribute(
    'id',
    'language-card-delete-reason',
  )
  await expect(deleteButton).toHaveAttribute(
    'aria-describedby',
    (await deleteReason.getAttribute('id'))!,
  )
  await expect(
    page.getByTestId('language-card-state').locator('input'),
  ).toHaveCount(0)

  await gotoPage(page, '/hilos/i18n/languages', PAGE_READY)
  await gotoPage(page, '/hilos/i18n', PAGE_READY)
})

/** Drive a new control through its visible, enabled and focused states.
 * @param button The stable control to submit.
 */
async function submit(button: Locator): Promise<void> {
  await button.scrollIntoViewIfNeeded()
  await expect(button).toBeVisible()
  await expect(button).toBeEnabled()
  await button.focus()
  await button.click()
}

/** Create this scenario's own language through the existing custom-language modal.
 * @param page The writer's page.
 * @param code The language address.
 * @param nativeName The language's shown name.
 */
async function addLanguage(
  page: Page,
  code: string,
  nativeName: string,
): Promise<void> {
  await gotoPage(page, '/hilos/i18n/languages', PAGE_READY)
  await submit(page.getByTestId('hilos-table-main-action'))
  await typeInto(page.getByTestId('i18n-language-add-code'), code)
  await typeInto(page.getByTestId('i18n-language-add-native-name'), nativeName)
  await submit(page.getByTestId('i18n-language-add-submit'))
  await expect(page.getByTestId('i18n-language-confirm')).toBeVisible()
  await submit(page.getByTestId('i18n-language-confirm-submit'))
  await expect(page.getByTestId('i18n-language-confirm')).toHaveCount(0)
  await expect(shownByTestId(page, `i18n-languages-link-${code}`)).toHaveText(
    code,
  )
}

/** Remove this scenario's custom language through its existing browser controls.
 * @param page The writer, after both observers have closed.
 * @param code The custom language created by this scenario.
 */
async function removeLanguage(page: Page, code: string): Promise<void> {
  await gotoPage(page, `/hilos/i18n/languages/${code}/locales`, PAGE_READY)
  await expect(
    shownByTestId(page, `i18n-locales-country-${code}`),
  ).toBeVisible()
  await dismissToasts(page)
  const view = shownByTestId(page, `i18n-locales-view-${code}`)
  if (await view.isVisible()) {
    await submit(view)
    await submit(page.getByTestId('i18n-locale-switch-off'))
    await submit(page.getByTestId('i18n-locale-switch-off-confirm'))
    await expect(page.getByTestId('i18n-locale-window')).toHaveCount(0)
    await dismissToasts(page)
  }
  const remove = shownByTestId(page, `i18n-locales-delete-${code}`)
  if (await remove.isVisible()) {
    await submit(remove)
    await submit(page.getByTestId('i18n-locale-delete-confirm'))
    await expect(page.getByTestId('i18n-locale-delete-window')).toHaveCount(0)
  }
  await gotoPage(page, `/hilos/i18n/languages/${code}`, PAGE_READY)
  await dismissToasts(page)
  await submit(page.getByTestId('language-card-delete'))
  await submit(page.getByTestId('i18n-language-delete-confirm'))
  await expect(page.getByTestId('i18n-language-delete-window')).toHaveCount(0)
  await expectPageReady(page)
  await expect(page).toHaveURL(/\/hilos\/i18n\/languages$/)
}

/** Read the complete Vue Overview, including zero and the countryless locale.
 * @param page The subscribed Overview.
 * @param counts The exact names, total and enabled locale counts and countryless state.
 */
async function expectCounts(
  page: Page,
  counts: {
    names: number
    locales: number
    enabled: number
    countryless: string
  },
): Promise<void> {
  await expect(page.getByTestId('language-overview-name-count')).toHaveText(
    String(counts.names),
  )
  await expect(page.getByTestId('language-overview-locale-count')).toHaveText(
    String(counts.locales),
  )
  await expect(
    page.getByTestId('language-overview-enabled-locale-count'),
  ).toHaveText(String(counts.enabled))
  await expect(
    page.getByTestId('language-overview-countryless-state'),
  ).toHaveText(counts.countryless)
}

test('keeps code-based addresses, one live head and current tabs through tile links, Back and F5', async ({
  page,
}) => {
  await signUpAdmin(page)
  await gotoPage(page, '/hilos/i18n/languages/en', PAGE_READY)
  await expectLanguagePageHead(expect, page, {
    code: 'en',
    nativeName: 'English',
    currentPage: 'hilos_i18n_language',
    appName: 'BTC Tracker',
  })
  await submit(page.getByTestId('language-overview-name-count'))
  await expectPageReady(page)
  await expect(page).toHaveURL(/\/en\/names$/)
  await expectLanguagePageHead(expect, page, {
    code: 'en',
    nativeName: 'English',
    currentPage: 'hilos_i18n_language_names',
    appName: 'BTC Tracker',
  })
  await expect(page.getByRole('table')).toHaveAccessibleName('English')
  await page.goBack()
  await expectPageReady(page)
  await expectLanguagePageHead(expect, page, {
    code: 'en',
    nativeName: 'English',
    currentPage: 'hilos_i18n_language',
    appName: 'BTC Tracker',
  })
  await submit(page.getByTestId('language-overview-locale-count'))
  await expectPageReady(page)
  await expect(page).toHaveURL(/\/en\/locales$/)
  await expectLanguagePageHead(expect, page, {
    code: 'en',
    nativeName: 'English',
    currentPage: 'hilos_i18n_language_locales',
    appName: 'BTC Tracker',
  })
  await expect(page.getByRole('table')).toHaveAccessibleName('English')
  await page.reload()
  await expectPageReady(page)
  await expectLanguagePageHead(expect, page, {
    code: 'en',
    nativeName: 'English',
    currentPage: 'hilos_i18n_language_locales',
    appName: 'BTC Tracker',
  })
})

test('locale lifecycle updates its subscribed Overview and leaves the other language head and counts in place', async ({
  page,
  context,
}) => {
  // Custom-language setup, four locale writes and cleanup exercise three live pages.
  test.slow()
  await signUpAdmin(page)
  const own = await context.newPage()
  const foreign = await context.newPage()
  const created: string[] = []
  try {
    await addLanguage(page, 'qx', 'Scope QX')
    created.push('qx')
    await addLanguage(page, 'qy', 'Scope QY')
    created.push('qy')
    await gotoPage(page, '/hilos/i18n/languages/qx/locales', PAGE_READY)
    await gotoPage(own, '/hilos/i18n/languages/qx', PAGE_READY)
    await gotoPage(foreign, '/hilos/i18n/languages/qy', PAGE_READY)
    await expectLanguagePageHead(expect, own, {
      code: 'qx',
      nativeName: 'Scope QX',
      currentPage: 'hilos_i18n_language',
      appName: 'BTC Tracker',
    })
    await expectLanguagePageHead(expect, foreign, {
      code: 'qy',
      nativeName: 'Scope QY',
      currentPage: 'hilos_i18n_language',
      appName: 'BTC Tracker',
    })
    await expectCounts(own, {
      names: 0,
      locales: 0,
      enabled: 0,
      countryless: 'Not created',
    })
    await expectCounts(foreign, {
      names: 0,
      locales: 0,
      enabled: 0,
      countryless: 'Not created',
    })
    await expect(own.getByTestId('language-overview-locales')).toContainText(
      'None created.',
    )
    const live = await watchI18nLivePages(expect, [page, own, foreign])
    const tile = foreign.getByTestId('language-head-tile')
    const head = foreign.getByTestId('hilos-page-head-title')
    const main = foreign.getByTestId('language-card-main')
    await expect(tile).toHaveText('qy')
    await expect(main).toContainText('Scope QY')
    await expect(foreign.getByTestId('language-head-own')).toHaveText(
      'Custom language',
    )
    await expect(foreign.getByTestId('language-card-editable')).toBeVisible()
    await expect(foreign.getByTestId('language-card-enabled')).toHaveText(
      'Language switched off',
    )

    await submit(shownByTestId(page, 'i18n-locales-add-qx'))
    for (const field of [
      'date',
      'time',
      'number',
      'phone',
      'address',
      'measurement',
      'collation',
    ]) {
      const select = page.getByTestId(`i18n-locale-field-${field}`)
      const value = await select
        .locator('option:not([disabled])')
        .first()
        .getAttribute('value')
      expect(value).not.toBeNull()
      await select.selectOption(value!)
    }
    await submit(page.getByTestId('i18n-locale-submit'))
    await expect(page.getByTestId('i18n-locale-window')).toHaveCount(0)
    await expectCounts(own, {
      names: 0,
      locales: 1,
      enabled: 0,
      countryless: 'Switched off',
    })
    await dismissToasts(page)
    await submit(shownByTestId(page, 'i18n-locales-edit-qx'))
    await submit(page.getByTestId('i18n-locale-switch-on'))
    await submit(page.getByTestId('i18n-locale-switch-on-confirm'))
    await expect(page.getByTestId('i18n-locale-window')).toHaveCount(0)
    await expectCounts(own, {
      names: 0,
      locales: 1,
      enabled: 1,
      countryless: 'Switched on',
    })
    await expectCounts(foreign, {
      names: 0,
      locales: 0,
      enabled: 0,
      countryless: 'Not created',
    })
    await expect(head).toHaveText('Scope QY')
    await dismissToasts(page)
    await submit(shownByTestId(page, 'i18n-locales-view-qx'))
    await submit(page.getByTestId('i18n-locale-switch-off'))
    await submit(page.getByTestId('i18n-locale-switch-off-confirm'))
    await expect(page.getByTestId('i18n-locale-window')).toHaveCount(0)
    await expectCounts(own, {
      names: 0,
      locales: 1,
      enabled: 0,
      countryless: 'Switched off',
    })
    await dismissToasts(page)
    await submit(shownByTestId(page, 'i18n-locales-delete-qx'))
    await submit(page.getByTestId('i18n-locale-delete-confirm'))
    await expect(page.getByTestId('i18n-locale-delete-window')).toHaveCount(0)
    await expectCounts(own, {
      names: 0,
      locales: 0,
      enabled: 0,
      countryless: 'Not created',
    })
    await expectCounts(foreign, {
      names: 0,
      locales: 0,
      enabled: 0,
      countryless: 'Not created',
    })
    await expect(tile).toHaveText('qy')
    await expect(head).toHaveText('Scope QY')
    await expect(main).toContainText('Scope QY')
    await expect(foreign).toHaveURL(/\/hilos\/i18n\/languages\/qy$/)
    await expectLanguagePageHead(expect, foreign, {
      code: 'qy',
      nativeName: 'Scope QY',
      currentPage: 'hilos_i18n_language',
      appName: 'BTC Tracker',
    })
    await live.unchanged()
  } finally {
    await own.close()
    await foreign.close()
    for (const code of created) await removeLanguage(page, code)
  }
})
