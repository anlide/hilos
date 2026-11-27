import { expect, test as base } from '../test.js'
import {
  gotoPage,
  grantAdminToSelf,
  PAGE_READY,
  readI18nRowId,
  shownByTestId,
  typeInto,
} from '../../../../../framework/frontend/e2e/index.js'
import {
  i18nFixtures,
  I18N_EXAMPLE_INSTALLATION,
  type I18nFixtureScope,
} from '../../../../../framework/frontend/scripts/i18nFixtures.mjs'

const test = base.extend<{ i18n: I18nFixtureScope }>({ i18n: i18nFixtures })

test('i18n example installation sets up mockups and cleans up completely', async ({
  page,
  i18n,
}) => {
  const installation = await i18n.exampleInstallation()
  await grantAdminToSelf(expect, page)

  // 1. In /hilos/i18n/languages: ru, de, pt are enabled, uk is disabled
  await gotoPage(expect, page, '/hilos/i18n/languages', PAGE_READY)
  await expect(shownByTestId(page, 'i18n-languages-link-ru')).toBeVisible()
  await expect(shownByTestId(page, 'i18n-languages-enabled-ru')).toBeChecked()
  await expect(shownByTestId(page, 'i18n-languages-link-de')).toBeVisible()
  await expect(shownByTestId(page, 'i18n-languages-enabled-de')).toBeChecked()
  await expect(shownByTestId(page, 'i18n-languages-link-pt')).toBeVisible()
  await expect(shownByTestId(page, 'i18n-languages-enabled-pt')).toBeChecked()
  await expect(shownByTestId(page, 'i18n-languages-link-uk')).toBeVisible()
  await expect(
    shownByTestId(page, 'i18n-languages-enabled-uk'),
  ).not.toBeChecked()

  // 2. In ru, de, and en details: variants and enabled states match the fixture constant
  for (const langCode of ['ru', 'de'] as const) {
    const langId = installation.languages[langCode].id
    await gotoPage(
      expect,
      page,
      `/hilos/i18n/language/${langId}/locales`,
      PAGE_READY,
    )
    const expectedLocales = I18N_EXAMPLE_INSTALLATION.locales.filter(
      (l) => l.code === langCode || l.code.startsWith(`${langCode}-`),
    )
    for (const loc of expectedLocales) {
      const toggle = shownByTestId(page, `i18n-locales-enabled-${loc.code}`)
      if (loc.enabled) {
        await expect(toggle).toBeChecked()
      } else {
        await expect(toggle).not.toBeChecked()
      }
    }
  }

  const enId = await readI18nRowId(expect, page, 'language', 'en')
  await gotoPage(
    expect,
    page,
    `/hilos/i18n/language/${enId}/locales`,
    PAGE_READY,
  )
  const enLocales = I18N_EXAMPLE_INSTALLATION.locales.filter((l) =>
    l.code.startsWith('en-'),
  )
  for (const loc of enLocales) {
    await expect(
      shownByTestId(page, `i18n-locales-enabled-${loc.code}`),
    ).toBeChecked()
  }

  // 3. In /hilos/i18n/countries: by and kz exist
  await gotoPage(expect, page, '/hilos/i18n/countries', PAGE_READY)
  await expect(shownByTestId(page, 'i18n-countries-link-by')).toBeVisible()
  await typeInto(page.getByTestId('hilos-table-search'), 'kz')
  await expect(shownByTestId(page, 'i18n-countries-link-kz')).toBeVisible()

  // 4. Cleanup fixture installation
  await i18n.cleanup()

  await gotoPage(expect, page, '/hilos/i18n/languages', PAGE_READY)
  for (const langCode of ['ru', 'de', 'pt', 'uk']) {
    await expect(
      page.getByTestId(`i18n-languages-link-${langCode}`),
    ).toHaveCount(0)
  }

  await gotoPage(
    expect,
    page,
    `/hilos/i18n/language/${enId}/locales`,
    PAGE_READY,
  )
  for (const loc of enLocales) {
    await expect(page.getByTestId(`i18n-locales-code-${loc.code}`)).toHaveCount(
      0,
    )
  }

  await gotoPage(expect, page, '/hilos/i18n/countries', PAGE_READY)
  await expect(page.getByTestId('i18n-countries-link-by')).toHaveCount(0)
  await typeInto(page.getByTestId('hilos-table-search'), 'kz')
  await expect(page.getByTestId('i18n-countries-link-kz')).toHaveCount(0)
})
