import {
  countSentFrames,
  dismissToasts,
  i18nLocaleRow,
  watchI18nLivePages,
  clickSubmit,
  gotoPage,
  PAGE_READY,
  signUpAdmin,
} from '../../../../../framework/frontend/e2e/index.js'
import { expect, test, type Page } from '@playwright/test'

const LOCALES_PATH = '/hilos/i18n/languages/en/locales'
const ADD_TITLE = 'Add locale · English in United Kingdom'
const WINDOW = 'i18n-locale-window'
const SUBMIT = 'i18n-locale-submit'
const NOTICE = 'i18n-locale-notice'
const FIELDS = [
  'date',
  'time',
  'number',
  'phone',
  'address',
  'measurement',
  'collation',
] as const

/** Remove this shared pair through the UI, including a previous test's enabled locale. */
async function clearBritishLocale(page: Page): Promise<void> {
  await dismissToasts(page)
  const row = i18nLocaleRow(page, 'en-GB')
  await expect(row).toBeVisible()
  const view = row.getByTestId('i18n-locales-view-en-GB')
  if (await view.isVisible()) {
    await clickSubmit(expect, view)
    await clickSubmit(expect, page.getByTestId('i18n-locale-switch-off'))
    await clickSubmit(
      expect,
      page.getByTestId('i18n-locale-switch-off-confirm'),
    )
    await expect(page.getByTestId(WINDOW)).toHaveCount(0)
    await expect(
      row.getByTestId('i18n-locales-enabled-en-GB'),
    ).not.toBeChecked()
    await dismissToasts(page)
  }
  const remove = row.getByTestId('i18n-locales-delete-en-GB')
  if (await remove.isVisible()) {
    await clickSubmit(expect, remove)
    await clickSubmit(expect, page.getByTestId('i18n-locale-delete-confirm'))
    await expect(page.getByTestId('i18n-locale-delete-window')).toHaveCount(0)
  }
  await expect(row.getByTestId('i18n-locales-add-en-GB')).toBeVisible()
}

/** Open the same absent known pair in each already subscribed tab. */
async function openBritishAdd(page: Page): Promise<void> {
  await dismissToasts(page)
  await clickSubmit(
    expect,
    i18nLocaleRow(page, 'en-GB').getByTestId('i18n-locales-add-en-GB'),
  )
  await expect(page.getByTestId('i18n-locale-title')).toHaveText(ADD_TITLE)
  await expect(page.getByTestId('i18n-locale-field-date')).toHaveValue(
    'DD/MM/YYYY',
  )
  await expect(page.getByTestId(SUBMIT)).toHaveText('Add')
  await expect(page.getByTestId(SUBMIT)).toBeEnabled()
}

/** Wait for the tracked success, rather than just the table's incoming row. */
async function saveLocale(page: Page): Promise<void> {
  await clickSubmit(expect, page.getByTestId(SUBMIT))
  await expect(page.getByTestId(WINDOW)).toHaveCount(0)
}

test('adds a known locale and edits its formats from the language table', async ({
  page,
}) => {
  await signUpAdmin(expect, page)
  await gotoPage(expect, page, '/hilos/i18n/languages/en/locales', PAGE_READY)

  const britishRow = i18nLocaleRow(page, 'en-GB')
  const add = britishRow.getByTestId('i18n-locales-add-en-GB')
  await clickSubmit(expect, add)

  await expect(page.getByTestId('i18n-locale-title')).toHaveText(
    'Add locale · English in United Kingdom',
  )
  await expect(page.getByTestId('i18n-locale-field-date')).toHaveValue(
    'DD/MM/YYYY',
  )
  const submit = page.getByTestId('i18n-locale-submit')
  await clickSubmit(expect, submit)
  await expect(submit).toHaveCount(0)
  await expect(
    page
      .getByTestId('hilos-toast-success')
      .filter({ hasText: 'Locale en-GB added.' }),
  ).toBeVisible()
  await expect(britishRow.getByTestId('i18n-locales-code-en-GB')).toHaveText(
    'en-GB',
  )
  await expect(
    britishRow.getByTestId('i18n-locales-enabled-en-GB'),
  ).not.toBeChecked()

  const edit = britishRow.getByTestId('i18n-locales-edit-en-GB')
  await clickSubmit(expect, edit)
  await page.getByTestId('i18n-locale-field-date').selectOption('YYYY-MM-DD')
  const save = page.getByTestId('i18n-locale-submit')
  await clickSubmit(expect, save)
  await expect(save).toHaveCount(0)
  await expect(
    page
      .getByTestId('hilos-toast-success')
      .filter({ hasText: 'Locale en-GB saved.' }),
  ).toBeVisible()

  await clickSubmit(expect, edit)
  await expect(page.getByTestId('i18n-locale-field-date')).toHaveValue(
    'YYYY-MM-DD',
  )
})

test('opens a switched-on countryless locale only to view its formats', async ({
  page,
}) => {
  await signUpAdmin(expect, page)
  await gotoPage(expect, page, '/hilos/i18n/languages/en/locales', PAGE_READY)

  const view = i18nLocaleRow(page, 'en').getByTestId('i18n-locales-view-en')
  await clickSubmit(expect, view)

  await expect(page.getByTestId('i18n-locale-title')).toHaveText(
    'Locale · English without a country',
  )
  for (const field of [
    'date',
    'time',
    'number',
    'phone',
    'address',
    'measurement',
    'collation',
  ]) {
    await expect(page.getByTestId(`i18n-locale-field-${field}`)).toBeDisabled()
  }
  await expect(page.getByTestId('i18n-locale-plate')).toContainText(
    'switched on',
  )
  await expect(page.getByTestId('i18n-locale-submit')).toHaveCount(0)
  await expect(page.getByTestId('i18n-locale-close')).toBeVisible()
})

for (const draft of ['untouched', 'prefill', 'equal'] as const) {
  test(`accepts a concurrent locale into an ${draft} Add window without a conflict`, async ({
    page,
    context,
  }) => {
    await signUpAdmin(expect, page)
    await gotoPage(expect, page, LOCALES_PATH, PAGE_READY)
    await clearBritishLocale(page)
    const observer = await context.newPage()
    try {
      await gotoPage(expect, observer, LOCALES_PATH, PAGE_READY)
      const livePages = await watchI18nLivePages(expect, [page, observer])
      for (const tab of [page, observer]) await openBritishAdd(tab)
      const incoming = draft === 'prefill' ? 'DD/MM/YYYY' : 'YYYY-MM-DD'
      if (draft === 'equal') {
        await observer
          .getByTestId('i18n-locale-field-date')
          .selectOption(incoming)
      }
      await page.getByTestId('i18n-locale-field-date').selectOption(incoming)
      await saveLocale(page)

      await expect(observer.getByTestId(NOTICE)).toHaveText('Updated just now')
      await expect(observer.getByTestId('i18n-locale-field-date')).toHaveValue(
        incoming,
      )
      await expect(observer.getByTestId('i18n-locale-title')).toHaveText(
        ADD_TITLE,
      )
      await expect(observer.getByTestId('conflict-choices')).toHaveCount(0)
      await expect(observer.getByTestId(SUBMIT)).toHaveText('Save')
      await expect(observer.getByTestId(SUBMIT)).toBeDisabled()
      await expect(
        i18nLocaleRow(observer, 'en-GB').getByTestId('i18n-locales-edit-en-GB'),
      ).toBeVisible()
      await expect(observer.getByTestId('hilos-table-apply')).toHaveCount(0)
      await livePages.unchanged()
      await clickSubmit(expect, observer.getByTestId('i18n-locale-cancel'))
      await expect(observer.getByTestId(WINDOW)).toHaveCount(0)
    } finally {
      await observer.close()
      await gotoPage(expect, page, LOCALES_PATH, PAGE_READY)
      await clearBritishLocale(page)
    }
  })
}

for (const choice of ['mine', 'theirs'] as const) {
  test(`merges an independent format and resolves a concurrent Add date with ${choice}`, async ({
    page,
    context,
  }) => {
    await signUpAdmin(expect, page)
    await gotoPage(expect, page, LOCALES_PATH, PAGE_READY)
    await clearBritishLocale(page)
    const observer = await context.newPage()
    const adds = countSentFrames(observer, 'hilos_i18n_locale_add')
    const updates = countSentFrames(observer, 'hilos_i18n_locale_update')
    try {
      await gotoPage(expect, observer, LOCALES_PATH, PAGE_READY)
      const livePages = await watchI18nLivePages(expect, [page, observer])
      for (const tab of [page, observer]) await openBritishAdd(tab)
      await observer
        .getByTestId('i18n-locale-field-date')
        .selectOption('YYYY-MM-DD')
      await page
        .getByTestId('i18n-locale-field-date')
        .selectOption('DD.MM.YYYY')
      await page.getByTestId('i18n-locale-field-time').selectOption('HH:mm:ss')
      await saveLocale(page)

      await expect(observer.getByTestId(NOTICE)).toHaveText(
        'Date changed elsewhere to "DD.MM.YYYY"; your choice stays selected.',
      )
      await expect(observer.getByTestId('i18n-locale-field-date')).toHaveValue(
        'YYYY-MM-DD',
      )
      await expect(observer.getByTestId('i18n-locale-field-time')).toHaveValue(
        'HH:mm:ss',
      )
      await expect(observer.getByTestId('i18n-locale-title')).toContainText(
        ADD_TITLE,
      )
      await expect(observer.getByTestId(SUBMIT)).toHaveText('Save')
      await expect(observer.getByTestId(SUBMIT)).toBeDisabled()
      await expect(observer.getByTestId('conflict-choices')).toBeVisible()
      await expect(observer.getByTestId('conflict-merge')).toHaveCount(0)
      await observer.getByTestId('i18n-locale-field-date').press('Enter')
      await expect(observer.getByTestId(WINDOW)).toBeVisible()
      expect(adds()).toBe(0)
      expect(updates()).toBe(0)
      await clickSubmit(
        expect,
        observer.getByTestId(`conflict-accept-${choice}`),
      )
      await expect(observer.getByTestId('conflict-choices')).toHaveCount(0)
      await expect(observer.getByTestId('i18n-locale-title')).toHaveText(
        ADD_TITLE,
      )
      const date = choice === 'mine' ? 'YYYY-MM-DD' : 'DD.MM.YYYY'
      await expect(observer.getByTestId('i18n-locale-field-date')).toHaveValue(
        date,
      )
      await expect(observer.getByTestId('i18n-locale-field-time')).toHaveValue(
        'HH:mm:ss',
      )
      if (choice === 'mine') {
        await saveLocale(observer)
        expect(updates()).toBe(1)
      } else {
        await expect(observer.getByTestId(SUBMIT)).toBeDisabled()
        await clickSubmit(expect, observer.getByTestId('i18n-locale-cancel'))
        await expect(observer.getByTestId(WINDOW)).toHaveCount(0)
        expect(updates()).toBe(0)
      }
      expect(adds()).toBe(0)
      await dismissToasts(page)
      await clickSubmit(
        expect,
        i18nLocaleRow(page, 'en-GB').getByTestId('i18n-locales-edit-en-GB'),
      )
      await expect(page.getByTestId('i18n-locale-field-date')).toHaveValue(date)
      await expect(page.getByTestId('i18n-locale-field-time')).toHaveValue(
        'HH:mm:ss',
      )
      await expect(page.getByTestId(SUBMIT)).toBeDisabled()
      await clickSubmit(expect, page.getByTestId('i18n-locale-cancel'))
      await expect(page.getByTestId(WINDOW)).toHaveCount(0)
      await expect(observer.getByTestId('hilos-table-apply')).toHaveCount(0)
      await livePages.unchanged()
    } finally {
      await observer.close()
      await gotoPage(expect, page, LOCALES_PATH, PAGE_READY)
      await clearBritishLocale(page)
    }
  })
}

test('preserves the Add draft and sends no Save or Enter action after the locale is switched on elsewhere', async ({
  page,
  context,
}) => {
  await signUpAdmin(expect, page)
  await gotoPage(expect, page, LOCALES_PATH, PAGE_READY)
  await clearBritishLocale(page)
  const observer = await context.newPage()
  const actions = countSentFrames(observer, '"type":"action"')
  try {
    await gotoPage(expect, observer, LOCALES_PATH, PAGE_READY)
    const livePages = await watchI18nLivePages(expect, [page, observer])
    for (const tab of [page, observer]) await openBritishAdd(tab)
    await observer
      .getByTestId('i18n-locale-field-date')
      .selectOption('YYYY-MM-DD')
    const draft = await Promise.all(
      FIELDS.map((field) =>
        observer.getByTestId(`i18n-locale-field-${field}`).inputValue(),
      ),
    )
    await saveLocale(page)
    await expect(observer.getByTestId(SUBMIT)).toHaveText('Save')
    await expect(observer.getByTestId(SUBMIT)).toBeEnabled()

    await dismissToasts(page)
    await clickSubmit(
      expect,
      i18nLocaleRow(page, 'en-GB').getByTestId('i18n-locales-edit-en-GB'),
    )
    await clickSubmit(expect, page.getByTestId('i18n-locale-switch-on'))
    await clickSubmit(expect, page.getByTestId('i18n-locale-switch-on-confirm'))
    await expect(page.getByTestId(WINDOW)).toHaveCount(0)
    await expect(observer.getByTestId(NOTICE)).toHaveText(
      'Switched on elsewhere just now.',
    )
    await expect(observer.getByTestId('i18n-locale-plate')).toContainText(
      'switched on',
    )
    await expect(observer.getByTestId('i18n-locale-title')).toHaveText(
      ADD_TITLE,
    )
    const submit = observer.getByTestId(SUBMIT)
    await expect(submit).toHaveText('Save')
    await expect(submit).toBeDisabled()
    // Dispatch bypasses the disabled button's normal hit testing; the submit guard must still hold.
    await submit.dispatchEvent('click')
    await observer.getByTestId('i18n-locale-field-date').press('Enter')
    await expect(observer.getByTestId(WINDOW)).toBeVisible()
    await expect(submit).toBeDisabled()
    for (const [index, field] of FIELDS.entries()) {
      await expect(
        observer.getByTestId(`i18n-locale-field-${field}`),
      ).toHaveValue(draft[index])
    }
    await expect(observer.getByTestId('hilos-action-error')).toHaveCount(0)
    await expect(
      i18nLocaleRow(observer, 'en-GB').getByTestId(
        'i18n-locales-enabled-en-GB',
      ),
    ).toBeChecked()
    await expect(observer.getByTestId('hilos-table-apply')).toHaveCount(0)
    await livePages.unchanged()
    expect(actions()).toBe(0)
    await clickSubmit(expect, observer.getByTestId('i18n-locale-cancel'))
    await clickSubmit(expect, observer.getByTestId('modal-confirm-discard'))
    await expect(observer.getByTestId(WINDOW)).toHaveCount(0)
    expect(actions()).toBe(0)
  } finally {
    await observer.close()
    await gotoPage(expect, page, LOCALES_PATH, PAGE_READY)
    await clearBritishLocale(page)
  }
})
