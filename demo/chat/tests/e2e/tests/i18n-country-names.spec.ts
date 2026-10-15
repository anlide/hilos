import { expect, test } from '@playwright/test'
import {
  shownByTestId,
  i18nNameRow,
} from '../../../../../framework/frontend/e2e/index.js'
import { signUpAdmin } from '../helpers/adminGrant.js'
import { gotoPage, PAGE_READY } from '../helpers/page.js'

test('opens the names of a reflowed country directly with the shared header, tabs, and the names table', async ({
  page,
}) => {
  let reading = false
  let actionRequests = 0
  page.on('websocket', (socket) => {
    socket.on('framesent', ({ payload }) => {
      if (reading && payload.toString().includes('"type":"action"')) {
        actionRequests += 1
      }
    })
  })
  await signUpAdmin(page)
  await gotoPage(page, '/hilos/i18n/countries/us/names', PAGE_READY)
  reading = true

  await expect(page.getByTestId('country-card-title')).toHaveText(
    'United States',
  )
  await expect(page.getByTestId('country-card-code-badge')).toHaveText('us')
  await expect(page.getByTestId('country-card-own')).toHaveCount(0)
  await expect(page.getByTestId('country-card-line')).toHaveText(
    'Currency $ USD · Default locale not chosen',
  )

  const tabs = page.getByTestId('country-card-tabs')
  await expect(tabs).toContainText('Main')
  await expect(tabs).toContainText('Names')
  await expect(tabs.locator('a')).toHaveCount(2)

  const namesTab = page.getByTestId('country-card-tab-hilos_i18n_country_names')
  await expect(namesTab).toHaveAttribute('aria-current', 'page')
  await expect(namesTab).toHaveAttribute(
    'href',
    '/hilos/i18n/countries/us/names',
  )

  const mainTab = page.getByTestId('country-card-tab-hilos_i18n_country')
  await expect(mainTab).toHaveAttribute('href', '/hilos/i18n/countries/us')

  const enRow = i18nNameRow(page, 'country', 'en')
  await expect(enRow).toContainText('English')
  await expect(enRow).toContainText('United States')
  await expect(page.getByTestId('i18n-names-none-en')).toHaveCount(0)

  const enKey = (await enRow.getAttribute('data-id'))!.replace(
    /^hilos-table-(row|card)-/,
    '',
  )
  const section = page.getByTestId('country-names')
  const inlineForms = section.locator(
    'input, select, textarea, form, [contenteditable="true"]',
  )
  await expect(inlineForms).toHaveCount(0)
  await expect(
    shownByTestId(section, 'i18n-country-name-edit-en'),
  ).toBeEnabled()
  await expect(
    shownByTestId(section, 'i18n-country-name-edit-en'),
  ).toHaveAccessibleName('Edit name · United States in English')
  await expect(section.getByTestId('i18n-country-name-add-en')).toHaveCount(0)
  await expect(shownByTestId(section, 'i18n-names-delete-en')).toBeEnabled()
  await expect(
    shownByTestId(section, 'i18n-names-delete-en'),
  ).toHaveAccessibleName('Delete country name in English')

  // Every country locale of English may offer an inherited correction, including
  // en-QZ left by the country deletion scenario. A fresh stand has none.
  const expand = i18nNameRow(page, 'country', 'en').getByTestId(
    /^hilos-table-expand-/,
  )
  const hasCorrections = (await expand.count()) > 0
  let correctionTexts: string[] = []
  if (hasCorrections) {
    await expect(expand).toHaveAttribute('aria-expanded', 'false')
    await expand.scrollIntoViewIfNeeded()
    await expect(expand).toBeVisible()
    await expect(expand).toBeEnabled()
    await expand.focus()
    await expand.click()
    await expect(expand).toHaveAttribute('aria-expanded', 'true')
    const detail = shownByTestId(section, `hilos-table-row-detail-${enKey}`)
    await expect(detail).toBeVisible()
    const corrections = shownByTestId(detail, /^i18n-names-correction-/)
    expect(await corrections.count()).toBeGreaterThan(0)
    for (const correction of await corrections.all()) {
      const id = await correction.getAttribute('data-id')
      expect(id).toMatch(/^i18n-names-correction-en-[A-Z]{2}$/)
      const localeCode = id!.slice('i18n-names-correction-'.length)
      await expect(correction).toContainText(localeCode)
      await expect(
        correction.getByTestId(`i18n-names-inherit-${localeCode}`),
      ).toHaveText('as in “English”')
      await expect(
        correction.getByTestId(`i18n-country-name-locale-add-${localeCode}`),
      ).toBeEnabled()
    }
    await expect(inlineForms).toHaveCount(0)
    correctionTexts = await corrections.allTextContents()

    await expand.click()
    await expect(expand).toHaveAttribute('aria-expanded', 'false')
    await expect(
      page.getByTestId(`hilos-table-row-detail-${enKey}`),
    ).toHaveCount(0)
  }

  await gotoPage(page, '/hilos/i18n/countries/us/names', PAGE_READY)
  await expect(enRow).toContainText('English')
  await expect(enRow).toContainText('United States')
  await expect(page.getByTestId('i18n-names-none-en')).toHaveCount(0)
  await expect(inlineForms).toHaveCount(0)
  if (hasCorrections) {
    await expand.scrollIntoViewIfNeeded()
    await expect(expand).toBeVisible()
    await expect(expand).toBeEnabled()
    await expand.focus()
    await expand.click()
    await expect(expand).toHaveAttribute('aria-expanded', 'true')
    expect(
      await shownByTestId(section, /^i18n-names-correction-/).allTextContents(),
    ).toEqual(correctionTexts)
    await expect(inlineForms).toHaveCount(0)
  } else {
    await expect(
      i18nNameRow(page, 'country', 'en').getByTestId(/^hilos-table-expand-/),
    ).toHaveCount(0)
    await expect(page.getByTestId('i18n-names-correction-en-QZ')).toHaveCount(0)
  }
  expect(actionRequests).toBe(0)
})
