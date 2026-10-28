import { expect, test as base } from '@playwright/test'
import {
  cancelI18nEditor,
  clickSubmit,
  countSentFrames,
  dismissToasts,
  expectDocumentUnchanged,
  expectI18nActionReply,
  expectI18nLocaleFormats,
  expectLanguagePageHead,
  expectPageReady,
  gotoPage,
  grantAdminToSelf,
  I18N_LOCALE_FIELDS,
  i18nLocaleRow,
  markDocument,
  PAGE_READY,
  PAGE_REFUSED,
  selectI18nLocaleFormats,
  shownByTestId,
  watchDeliveryFrames,
  watchHeight,
  watchI18nLivePages,
  watchLeft,
  watchTop,
} from '../../../../../framework/frontend/e2e/index.js'
import { clearLocale } from '../../../../../framework/frontend/scripts/i18n.mjs'
import {
  i18nFixtures,
  type I18nFixtureScope,
} from '../../../../../framework/frontend/scripts/i18nFixtures.mjs'

const test = base.extend<{ i18n: I18nFixtureScope }>({ i18n: i18nFixtures })
const LOCALES_PATH = '/hilos/i18n/languages/en/locales'
const ADD_TITLE = 'Add locale · English in United Kingdom'
const WINDOW = 'i18n-locale-window'
const SUBMIT = 'i18n-locale-submit'
const NOTICE = 'i18n-locale-notice'
const BRITISH_FORMATS = {
  date: 'DD/MM/YYYY',
  time: 'hh:mm:ss a',
  number: '1.000,00',
  phone: '+XX (X) XXXX XXXX',
  address: 'Street, House, Index, City',
  measurement: 'metric',
  collation: 'und',
} as const
const EDITED_FORMATS = {
  date: 'YYYY-MM-DD',
  time: 'HH:mm:ss',
  number: '1 000,00',
  phone: '+XX-XXXX-XXXX',
  address: 'Street, House, City, Index',
  measurement: 'imperial',
  collation: 'und',
} as const
test('locale.life', async ({ page, context, i18n }) => {
  // One admin grant covers the long lifecycle; its baseline group took 41.798s including hooks.
  test.slow()
  await i18n.ownLocale('en-GB')
  await i18n.language({ code: 'pt', nativeName: 'Português', enabled: true })
  await i18n.ownLocale('pt')
  const language = await i18n.language({
    code: 'qy',
    nativeName: 'Scope QY',
  })
  await i18n.locale({ languageId: language.id, enabled: true })

  const other = await context.newPage()
  i18n.defer(() => other.close())
  const frames = watchDeliveryFrames(page)
  i18n.defer(async () => frames.stop())
  await grantAdminToSelf(expect, page)

  await test.step('Read the default language locales and its shared owner head', async () => {
    await gotoPage(expect, page, LOCALES_PATH, PAGE_READY)
    await expectLanguagePageHead(expect, page, {
      code: 'en',
      nativeName: 'English',
      currentPage: 'hilos_i18n_language_locales',
      appName: 'Hilos Polls',
    })
    const row = i18nLocaleRow(page, 'en')
    const localesTable = page.locator('table')
    await expect(localesTable).toHaveAccessibleName('English')
    await expect(row.getByTestId('i18n-locales-country-en')).toHaveText(
      '— no country',
    )
    await expect(row.getByTestId('i18n-locales-code-en')).toHaveText('en')
    const enabled = row.getByTestId('i18n-locales-enabled-en')
    await expect(enabled).toBeChecked()
    await expect(enabled).toBeDisabled()
    await expect(enabled).toHaveAccessibleName('Enabled')
    await expect(row.getByTestId('i18n-locales-view-en')).toBeVisible()
    await expect(
      i18nLocaleRow(page, 'en-GB').getByTestId('i18n-locales-add-en-GB'),
    ).toBeVisible()

    const localesTab = page.getByTestId(
      'hilos-page-tab-hilos_i18n_language_locales',
    )
    await expect(localesTab).toHaveAttribute('aria-current', 'page')
    await expect(localesTab).toHaveAttribute(
      'href',
      '/hilos/i18n/languages/en/locales',
    )
    const overviewTab = page.getByTestId('hilos-page-tab-hilos_i18n_language')
    await expect(overviewTab).toHaveAttribute(
      'href',
      '/hilos/i18n/languages/en',
    )
    await overviewTab.click()
    await expectPageReady(expect, page)
    await expect(page).toHaveURL(/\/hilos\/i18n\/languages\/en$/)
    await expect(page.getByTestId('language-card-main')).toBeVisible()
    await page.getByTestId('hilos-page-tab-hilos_i18n_language_locales').click()
    await expectPageReady(expect, page)
    await expect(page).toHaveURL(/\/hilos\/i18n\/languages\/en\/locales$/)
    await expectLanguagePageHead(expect, page, {
      code: 'en',
      nativeName: 'English',
      currentPage: 'hilos_i18n_language_locales',
      appName: 'Hilos Polls',
    })
  })

  await test.step('Add and edit the known English–United Kingdom pair across all seven exact formats', async () => {
    const row = i18nLocaleRow(page, 'en-GB')
    await clickSubmit(expect, row.getByTestId('i18n-locales-add-en-GB'))
    await expect(page.getByTestId('i18n-locale-title')).toHaveText(ADD_TITLE)
    await expectI18nLocaleFormats(expect, page, BRITISH_FORMATS)
    await expect(page.getByTestId(SUBMIT)).toHaveText('Add')
    const beforeAdd = frames.sent.length
    await clickSubmit(expect, page.getByTestId(SUBMIT))
    await expect(page.getByTestId(WINDOW)).toHaveCount(0)
    expectI18nActionReply(expect, frames, beforeAdd, 'hilos_i18n_locale_add', {
      message: 'Locale en-GB added.',
    })
    await expect(page.getByTestId('hilos-toast-success')).toContainText(
      'Locale en-GB added.',
    )
    await expect(row.getByTestId('i18n-locales-code-en-GB')).toHaveText('en-GB')
    await expect(
      row.getByTestId('i18n-locales-enabled-en-GB'),
    ).not.toBeChecked()

    await dismissToasts(page)
    await clickSubmit(expect, row.getByTestId('i18n-locales-edit-en-GB'))
    await expectI18nLocaleFormats(expect, page, BRITISH_FORMATS)
    await selectI18nLocaleFormats(page, EDITED_FORMATS)
    const beforeEdit = frames.sent.length
    await clickSubmit(expect, page.getByTestId(SUBMIT))
    await expect(page.getByTestId(WINDOW)).toHaveCount(0)
    const editPayload = expectI18nActionReply(
      expect,
      frames,
      beforeEdit,
      'hilos_i18n_locale_update',
      { message: 'Locale en-GB saved.' },
    )
    expect(editPayload.formats).toEqual(EDITED_FORMATS)
    await expect(page.getByTestId('hilos-toast-success')).toContainText(
      'Locale en-GB saved.',
    )

    await dismissToasts(page)
    await clickSubmit(expect, row.getByTestId('i18n-locales-edit-en-GB'))
    await expectI18nLocaleFormats(expect, page, EDITED_FORMATS)
    await expect(page.getByTestId(SUBMIT)).toBeDisabled()
  })

  await test.step('Switch the known locale on, preserve the still-window draft, and read its frozen formats', async () => {
    const modal = page.getByTestId('modal').locator('.modal-content')
    const primary = page
      .getByTestId(/^i18n-locale-(submit|switch-on-confirm)$/)
      .filter({ visible: true })
    await expect(primary).toBeVisible()
    const geometry = [
      await watchTop(modal),
      await watchLeft(modal),
      await watchHeight(modal),
      await watchTop(primary),
      await watchHeight(primary),
      await watchTop(page.getByTestId('i18n-locale-title')),
    ]
    const date = page.getByTestId('i18n-locale-field-date')
    await date.selectOption('DD.MM.YYYY')
    await expect(page.getByTestId(SUBMIT)).toBeEnabled()
    await clickSubmit(expect, page.getByTestId('i18n-locale-switch-on'))
    await expect(page.getByTestId('i18n-locale-title')).toHaveText(
      'Switch on locale · English in United Kingdom',
    )
    await expect(page.getByTestId('i18n-locale-switch-on-note')).toBeVisible()
    await expect(
      page.getByTestId('i18n-locale-switch-on-unsaved'),
    ).toBeVisible()
    await expect(
      page.getByTestId('i18n-locale-switch-on-confirm'),
    ).toBeDisabled()
    await expect(primary).toBeVisible()
    for (const bookmark of geometry) await bookmark.unchanged()

    await clickSubmit(expect, page.getByTestId('i18n-locale-switch-on-back'))
    await expect(date).toHaveValue('DD.MM.YYYY')
    await expect(primary).toBeVisible()
    for (const bookmark of geometry) await bookmark.unchanged()
    await date.selectOption(EDITED_FORMATS.date)
    await expect(page.getByTestId(SUBMIT)).toBeDisabled()
    await clickSubmit(expect, page.getByTestId('i18n-locale-switch-on'))
    await expect(page.getByTestId('i18n-locale-switch-on-unsaved')).toHaveCount(
      0,
    )
    await expect(primary).toBeVisible()
    for (const bookmark of geometry) await bookmark.unchanged()
    await clickSubmit(expect, page.getByTestId('i18n-locale-switch-on-back'))
    await expect(date).toBeVisible()
    await expect(page.getByTestId(SUBMIT)).toBeDisabled()
    await expect(primary).toBeVisible()
    for (const bookmark of geometry) await bookmark.unchanged()

    await clickSubmit(expect, page.getByTestId('i18n-locale-switch-on'))
    const beforeSwitchOn = frames.sent.length
    await clickSubmit(expect, page.getByTestId('i18n-locale-switch-on-confirm'))
    await expect(page.getByTestId(WINDOW)).toHaveCount(0)
    expectI18nActionReply(
      expect,
      frames,
      beforeSwitchOn,
      'hilos_i18n_locale_switch_on',
      { message: 'Locale en-GB switched on.' },
    )
    await expect(page.getByTestId('hilos-toast-success')).toContainText(
      'Locale en-GB switched on.',
    )
    const row = i18nLocaleRow(page, 'en-GB')
    await expect(row.getByTestId('i18n-locales-enabled-en-GB')).toBeChecked()
    await expect(row.getByTestId('i18n-locales-view-en-GB')).toBeVisible()
    await expect(row.getByTestId('i18n-locales-edit-en-GB')).toHaveCount(0)

    await dismissToasts(page)
    await clickSubmit(expect, row.getByTestId('i18n-locales-view-en-GB'))
    await expectI18nLocaleFormats(expect, page, EDITED_FORMATS)
    for (const field of I18N_LOCALE_FIELDS)
      await expect(
        page.getByTestId('i18n-locale-field-' + field),
      ).toBeDisabled()
    await expect(page.getByTestId('i18n-locale-plate')).toContainText(
      'switched on',
    )
    await expect(page.getByTestId(SUBMIT)).toHaveCount(0)
    await expect(page.getByTestId('i18n-locale-close')).toBeVisible()
    await clickSubmit(expect, page.getByTestId('i18n-locale-close'))
    await expect(page.getByTestId(WINDOW)).toHaveCount(0)
  })

  await test.step('Switch off an ordinary country locale without a fallback warning and unlock its formats', async () => {
    await dismissToasts(page)
    const row = i18nLocaleRow(page, 'en-GB')
    await clickSubmit(expect, row.getByTestId('i18n-locales-view-en-GB'))
    await clickSubmit(expect, page.getByTestId('i18n-locale-switch-off'))
    await expect(
      page.getByTestId('i18n-locale-switch-off-warning'),
    ).toHaveCount(0)
    await expect(page.getByTestId('i18n-locale-switch-off-reason')).toHaveCount(
      0,
    )
    const beforeSwitchOff = frames.sent.length
    await clickSubmit(
      expect,
      page.getByTestId('i18n-locale-switch-off-confirm'),
    )
    await expect(page.getByTestId(WINDOW)).toHaveCount(0)
    expectI18nActionReply(
      expect,
      frames,
      beforeSwitchOff,
      'hilos_i18n_locale_switch_off',
      { message: 'Locale en-GB switched off.' },
    )
    await expect(
      row.getByTestId('i18n-locales-enabled-en-GB'),
    ).not.toBeChecked()
    await expect(row.getByTestId('i18n-locales-view-en-GB')).toHaveCount(0)
    await expect(row.getByTestId('i18n-locales-edit-en-GB')).toBeVisible()

    await dismissToasts(page)
    await clickSubmit(expect, row.getByTestId('i18n-locales-edit-en-GB'))
    for (const field of I18N_LOCALE_FIELDS)
      await expect(page.getByTestId('i18n-locale-field-' + field)).toBeEnabled()
    await expectI18nLocaleFormats(expect, page, EDITED_FORMATS)
    await clickSubmit(expect, page.getByTestId('i18n-locale-cancel'))
    await expect(page.getByTestId(WINDOW)).toHaveCount(0)
  })

  await test.step('Delete the Portuguese countryless locale from one of two subscribed tabs', async () => {
    await gotoPage(expect, page, '/hilos/i18n/languages/pt/locales', PAGE_READY)
    await gotoPage(
      expect,
      other,
      '/hilos/i18n/languages/pt/locales',
      PAGE_READY,
    )
    const tabs = [page, other]
    for (const tab of tabs)
      await expectLanguagePageHead(expect, tab, {
        code: 'pt',
        nativeName: 'Português',
        currentPage: 'hilos_i18n_language_locales',
        appName: 'Hilos Polls',
      })
    const live = await watchI18nLivePages(expect, tabs)
    await Promise.all(tabs.map(markDocument))

    const row = i18nLocaleRow(page, 'pt')
    await clickSubmit(expect, row.getByTestId('i18n-locales-add-pt'))
    for (const field of I18N_LOCALE_FIELDS)
      await expect(
        page.getByTestId('i18n-locale-field-' + field),
      ).not.toHaveValue('')
    const beforeAdd = frames.sent.length
    await clickSubmit(expect, page.getByTestId(SUBMIT))
    await expect(page.getByTestId(WINDOW)).toHaveCount(0)
    expectI18nActionReply(expect, frames, beforeAdd, 'hilos_i18n_locale_add', {
      message: 'Locale pt added.',
    })
    await expect(row.getByTestId('i18n-locales-enabled-pt')).not.toBeChecked()
    await dismissToasts(page)

    await clickSubmit(expect, row.getByTestId('i18n-locales-edit-pt'))
    await clickSubmit(expect, page.getByTestId('i18n-locale-switch-on'))
    const beforeSwitchOn = frames.sent.length
    await clickSubmit(expect, page.getByTestId('i18n-locale-switch-on-confirm'))
    await expect(page.getByTestId(WINDOW)).toHaveCount(0)
    expectI18nActionReply(
      expect,
      frames,
      beforeSwitchOn,
      'hilos_i18n_locale_switch_on',
      { message: 'Locale pt switched on.' },
    )
    for (const tab of tabs) {
      await expect(
        i18nLocaleRow(tab, 'pt').getByTestId('i18n-locales-enabled-pt'),
      ).toBeChecked()
      await expect(shownByTestId(tab, 'i18n-locales-view-pt')).toBeVisible()
      await expect(tab.getByTestId('i18n-locales-edit-pt')).toHaveCount(0)
    }

    await dismissToasts(page)
    await clickSubmit(
      expect,
      i18nLocaleRow(page, 'pt').getByTestId('i18n-locales-view-pt'),
    )
    await expect(page.getByTestId('i18n-locale-title')).toHaveText(
      'Locale · Português without a country',
    )
    for (const field of I18N_LOCALE_FIELDS)
      await expect(
        page.getByTestId('i18n-locale-field-' + field),
      ).toBeDisabled()
    await expect(page.getByTestId('i18n-locale-plate')).toContainText(
      'switched on',
    )
    await expect(page.getByTestId(SUBMIT)).toHaveCount(0)
    await expect(page.getByTestId('i18n-locale-close')).toBeVisible()
    await clickSubmit(expect, page.getByTestId('i18n-locale-switch-off'))
    await expect(page.getByTestId('i18n-locale-switch-off-warning')).toHaveText(
      'This language is switched on. While its locale without a country is off, formats fall back to the default language.',
    )
    await expect(page.getByTestId('i18n-locale-switch-off-reason')).toHaveCount(
      0,
    )
    const beforeSwitchOff = frames.sent.length
    await clickSubmit(
      expect,
      page.getByTestId('i18n-locale-switch-off-confirm'),
    )
    await expect(page.getByTestId(WINDOW)).toHaveCount(0)
    expectI18nActionReply(
      expect,
      frames,
      beforeSwitchOff,
      'hilos_i18n_locale_switch_off',
      { message: 'Locale pt switched off.' },
    )
    for (const tab of tabs) {
      await expect(
        shownByTestId(tab, 'i18n-locales-enabled-pt'),
      ).not.toBeChecked()
      await expect(shownByTestId(tab, 'i18n-locales-edit-pt')).toBeVisible()
      await expect(tab.getByTestId('i18n-locales-view-pt')).toHaveCount(0)
    }

    const languageCard = await context.newPage()
    i18n.defer(() => languageCard.close())
    await gotoPage(expect, languageCard, '/hilos/i18n/languages/pt', PAGE_READY)
    await expectLanguagePageHead(expect, languageCard, {
      code: 'pt',
      nativeName: 'Português',
      currentPage: 'hilos_i18n_language',
      appName: 'Hilos Polls',
    })
    await expect(languageCard.getByTestId('language-card-enabled')).toHaveText(
      'Enabled',
    )

    const trash = shownByTestId(page, 'i18n-locales-delete-pt')
    await trash.scrollIntoViewIfNeeded()
    await expect(trash).toBeVisible()
    await expect(trash).toBeEnabled()
    await expect(trash).toHaveAccessibleName('Delete locale pt')
    await trash.focus()
    await trash.press('Enter')
    const window = page.getByTestId('i18n-locale-delete-window')
    const modal = page.getByTestId('modal').filter({ has: window })
    await expect(modal).toBeFocused()
    await expect(modal).toHaveAccessibleName(
      'Delete locale · Português without a country',
    )
    await expect(window).toContainText('fall back to the default language')
    await modal.press('Escape')
    await expect(window).toHaveCount(0)
    await expect(trash).toBeFocused()
    await expect(row.getByTestId('i18n-locales-code-pt')).toHaveText('pt')

    await trash.press('Enter')
    await expect(modal).toBeFocused()
    const beforeDelete = frames.sent.length
    await clickSubmit(expect, page.getByTestId('i18n-locale-delete-confirm'))
    await expect(window).toHaveCount(0)
    expectI18nActionReply(
      expect,
      frames,
      beforeDelete,
      'hilos_i18n_locale_delete',
      { message: 'Locale pt deleted.' },
    )
    for (const tab of tabs) {
      await expect(shownByTestId(tab, 'i18n-locales-none-pt')).toHaveText(
        'No locale',
      )
      await expect(shownByTestId(tab, 'i18n-locales-add-pt')).toBeVisible()
      for (const control of ['code', 'enabled', 'edit', 'view', 'delete'])
        await expect(
          tab.getByTestId('i18n-locales-' + control + '-pt'),
        ).toHaveCount(0)
      await expectLanguagePageHead(expect, tab, {
        code: 'pt',
        nativeName: 'Português',
        currentPage: 'hilos_i18n_language_locales',
        appName: 'Hilos Polls',
      })
      await expect(tab).toHaveURL(/\/hilos\/i18n\/languages\/pt\/locales$/)
    }
    await expectDocumentUnchanged(expect, page)
    await expectDocumentUnchanged(expect, other)
    await live.unchanged()
  })

  await test.step('Switch off a countryless locale under a disabled parent without a warning', async () => {
    await gotoPage(expect, page, '/hilos/i18n/languages/qy/locales', PAGE_READY)
    await clickSubmit(
      expect,
      i18nLocaleRow(page, 'qy').getByTestId('i18n-locales-view-qy'),
    )
    for (const field of I18N_LOCALE_FIELDS)
      await expect(
        page.getByTestId('i18n-locale-field-' + field),
      ).toBeDisabled()
    await clickSubmit(expect, page.getByTestId('i18n-locale-switch-off'))
    await expect(
      page.getByTestId('i18n-locale-switch-off-warning'),
    ).toHaveCount(0)
    await expect(page.getByTestId('i18n-locale-switch-off-reason')).toHaveCount(
      0,
    )
    const beforeSwitchOff = frames.sent.length
    await clickSubmit(
      expect,
      page.getByTestId('i18n-locale-switch-off-confirm'),
    )
    await expect(page.getByTestId(WINDOW)).toHaveCount(0)
    expectI18nActionReply(
      expect,
      frames,
      beforeSwitchOff,
      'hilos_i18n_locale_switch_off',
      { message: 'Locale qy switched off.' },
    )
    await expect(
      i18nLocaleRow(page, 'qy').getByTestId('i18n-locales-enabled-qy'),
    ).not.toBeChecked()
    await gotoPage(expect, page, '/hilos/i18n/languages/qy', PAGE_READY)
    await expect(page.getByTestId('language-card-enabled')).toHaveText(
      'Disabled',
    )
  })

  // The fixture's finally remains the fallback on any earlier setup/body failure.
  await i18n.cleanup()
  await gotoPage(expect, page, '/hilos/i18n/languages/pt', PAGE_REFUSED)
  await expect(page.getByTestId('page-error')).toHaveAttribute(
    'data-error-code',
    '404',
  )
})

test('locale.protected-defaults', async ({ page, i18n }) => {
  // Three protected paths share one grant; their baseline group took 15.061s including hooks.
  test.slow()
  const language = await i18n.language({
    code: 'ql',
    nativeName: 'Protected QL',
  })
  const country = await i18n.country({
    code: 'qq',
    currencySymbol: '¤',
    currencyCode: 'XQQ',
  })
  await i18n.locale({ languageId: language.id, enabled: true })
  await i18n.locale({ languageId: language.id, countryId: country.id })
  const frames = watchDeliveryFrames(page)
  i18n.defer(async () => frames.stop())
  const switchOff = countSentFrames(page, 'hilos_i18n_locale_switch_off')
  await grantAdminToSelf(expect, page)

  await test.step('Explain the default countryless lock and reject real Enter without a switch-off request', async () => {
    await gotoPage(expect, page, LOCALES_PATH, PAGE_READY)
    await clickSubmit(
      expect,
      i18nLocaleRow(page, 'en').getByTestId('i18n-locales-view-en'),
    )
    await expect(page.getByTestId('i18n-locale-title')).toHaveText(
      'Locale · English without a country',
    )
    for (const field of I18N_LOCALE_FIELDS)
      await expect(
        page.getByTestId(`i18n-locale-field-${field}`),
      ).toBeDisabled()
    await expect(page.getByTestId('i18n-locale-plate')).toContainText(
      'switched on',
    )
    await expect(page.getByTestId(SUBMIT)).toHaveCount(0)
    await expect(page.getByTestId('i18n-locale-close')).toBeVisible()
    await clickSubmit(expect, page.getByTestId('i18n-locale-switch-off'))
    await expect(page.getByTestId('i18n-locale-switch-off-reason')).toHaveText(
      'The default language needs its locale without a country: it cannot be switched off.',
    )
    await expect(
      page.getByTestId('i18n-locale-switch-off-warning'),
    ).toHaveCount(0)
    const confirm = page.getByTestId('i18n-locale-switch-off-confirm')
    await expect(confirm).toBeVisible()
    await expect(confirm).toBeDisabled()
    await confirm.press('Enter')
    await clickSubmit(expect, page.getByTestId('i18n-locale-switch-off-back'))
    await expect(page.getByTestId('i18n-locale-title')).toHaveText(
      'Locale · English without a country',
    )
    await expect(page.getByTestId('i18n-locale-field-date')).toBeDisabled()
    await expect(shownByTestId(page, 'i18n-locales-enabled-en')).toBeChecked()
    expect(switchOff()).toBe(0)
    await expect(page.getByTestId('hilos-toast-error')).toHaveCount(0)
    await clickSubmit(expect, page.getByTestId('i18n-locale-close'))
    await expect(page.getByTestId(WINDOW)).toHaveCount(0)
  })

  await test.step('Keep switched-on deletion refusal in its reserved room and permit an explicit second request', async () => {
    await gotoPage(expect, page, '/hilos/i18n/languages/ql/locales', PAGE_READY)
    await clickSubmit(expect, shownByTestId(page, 'i18n-locales-delete-ql'))
    const window = page.getByTestId('i18n-locale-delete-window')
    const room = await watchHeight(window)
    const confirm = page.getByTestId('i18n-locale-delete-confirm')
    const reason =
      "Locale 'ql' is switched on: switch it off before deleting it."
    for (const attempt of [1, 2]) {
      const before = frames.sent.length
      await clickSubmit(expect, confirm)
      await expect(page.getByTestId('i18n-locale-delete-error')).toHaveText(
        reason,
      )
      await expect(window).toBeVisible()
      await expect(confirm).toBeEnabled()
      await expect(async () => {
        expectI18nActionReply(
          expect,
          frames,
          before,
          'hilos_i18n_locale_delete',
          { reason },
        )
      }).toPass()
      await room.unchanged()
      await expect(
        shownByTestId(page, 'i18n-locales-enabled-ql'),
        `Locale stays enabled after refusal ${attempt}`,
      ).toBeChecked()
    }
    await clickSubmit(expect, page.getByTestId('i18n-locale-delete-cancel'))
    await expect(window).toHaveCount(0)
  })

  await test.step('Unlock an ordinary countryless locale without an enabled-language warning', async () => {
    await dismissToasts(page)
    await clickSubmit(expect, shownByTestId(page, 'i18n-locales-view-ql'))
    await clickSubmit(expect, page.getByTestId('i18n-locale-switch-off'))
    await expect(page.getByTestId('i18n-locale-title')).toHaveText(
      'Switch off locale · Protected QL without a country',
    )
    await expect(
      page.getByTestId('i18n-locale-switch-off-warning'),
    ).toHaveCount(0)
    await expect(page.getByTestId('i18n-locale-switch-off-reason')).toHaveCount(
      0,
    )
    await clickSubmit(
      expect,
      page.getByTestId('i18n-locale-switch-off-confirm'),
    )
    await expect(page.getByTestId(WINDOW)).toHaveCount(0)
    await expect(page.getByTestId('hilos-toast-success')).toContainText(
      'Locale ql switched off.',
    )
    await expect(
      shownByTestId(page, 'i18n-locales-enabled-ql'),
    ).not.toBeChecked()
    await expect(page.getByTestId('i18n-locales-view-ql')).toHaveCount(0)
    await dismissToasts(page)
    await clickSubmit(expect, shownByTestId(page, 'i18n-locales-edit-ql'))
    await expect(page.getByTestId('i18n-locale-title')).toHaveText(
      'Edit locale · Protected QL without a country',
    )
    for (const field of I18N_LOCALE_FIELDS)
      await expect(page.getByTestId(`i18n-locale-field-${field}`)).toBeEnabled()
    await clickSubmit(expect, page.getByTestId('i18n-locale-cancel'))
    await expect(page.getByTestId(WINDOW)).toHaveCount(0)
  })

  await test.step('Select a country default through UI and preserve that pointer after a real deletion refusal while the country is off', async () => {
    await gotoPage(expect, page, '/hilos/i18n/countries/qq', PAGE_READY)
    await expect(page.getByTestId('country-card-enabled')).toHaveText(
      'Disabled',
    )
    await clickSubmit(expect, page.getByTestId('country-card-edit'))
    await page
      .getByTestId('i18n-country-edit-default-locale')
      .selectOption({ label: 'ql-QQ — switched off' })
    await clickSubmit(expect, page.getByTestId('i18n-country-save'))
    await expect(page.getByTestId('i18n-country-window')).toHaveCount(0)
    await expect(page.getByTestId('country-card-default-locale')).toHaveText(
      'ql-QQ',
    )
    await gotoPage(expect, page, '/hilos/i18n/languages/ql/locales', PAGE_READY)
    await clickSubmit(expect, shownByTestId(page, 'i18n-locales-delete-ql-QQ'))
    const before = frames.sent.length
    const confirm = page.getByTestId('i18n-locale-delete-confirm')
    await clickSubmit(expect, confirm)
    const reason =
      "Locale 'ql-QQ' is the country's default: choose another locale for the country first."
    await expect(page.getByTestId('i18n-locale-delete-error')).toHaveText(
      reason,
    )
    await expect(page.getByTestId('i18n-locale-delete-window')).toBeVisible()
    await expect(confirm).toBeEnabled()
    expectI18nActionReply(expect, frames, before, 'hilos_i18n_locale_delete', {
      reason,
    })
    await expect(shownByTestId(page, 'i18n-locales-code-ql-QQ')).toHaveText(
      'ql-QQ',
    )
    await expect(
      shownByTestId(page, 'i18n-locales-enabled-ql-QQ'),
    ).not.toBeChecked()
    await clickSubmit(expect, page.getByTestId('i18n-locale-delete-cancel'))
    await expect(page.getByTestId('i18n-locale-delete-window')).toHaveCount(0)
    await gotoPage(expect, page, '/hilos/i18n/countries/qq', PAGE_READY)
    await expect(page.getByTestId('country-card-default-locale')).toHaveText(
      'ql-QQ',
    )
    await expect(page.getByTestId('country-card-enabled')).toHaveText(
      'Disabled',
    )
  })
})

test('locale.live-occupation', async ({ page, context, i18n }) => {
  // Four socket episodes share two pages; their baseline group took 50.727s including hooks.
  test.slow()
  await i18n.ownLocale('en-GB')
  const writerFrames = watchDeliveryFrames(page)
  i18n.defer(async () => writerFrames.stop())
  await grantAdminToSelf(expect, page)
  const observer = await context.newPage()
  i18n.defer(() => observer.close())
  const observerFrames = watchDeliveryFrames(observer)
  i18n.defer(async () => observerFrames.stop())
  const adds = countSentFrames(observer, 'hilos_i18n_locale_add')
  const updates = countSentFrames(observer, 'hilos_i18n_locale_update')
  const actions = countSentFrames(observer, '"type":"action"')
  await gotoPage(expect, page, LOCALES_PATH, PAGE_READY)
  await gotoPage(expect, observer, LOCALES_PATH, PAGE_READY)
  const live = await watchI18nLivePages(expect, [page, observer])
  await Promise.all([page, observer].map(markDocument))

  for (const episode of ['no conflict', 'mine', 'theirs', 'freeze'] as const) {
    await test.step(`Receive occupation in an open Add: ${episode}`, async () => {
      if (episode !== 'no conflict') {
        await clearLocale('en-GB')
        for (const tab of [page, observer])
          await expect(
            i18nLocaleRow(tab, 'en-GB').getByTestId('i18n-locales-add-en-GB'),
          ).toBeVisible()
      }
      await dismissToasts(page)
      await dismissToasts(observer)
      for (const tab of [page, observer]) {
        await clickSubmit(
          expect,
          i18nLocaleRow(tab, 'en-GB').getByTestId('i18n-locales-add-en-GB'),
        )
        await expect(tab.getByTestId('i18n-locale-title')).toHaveText(ADD_TITLE)
        await expectI18nLocaleFormats(expect, tab, BRITISH_FORMATS)
        await expect(tab.getByTestId(SUBMIT)).toHaveText('Add')
        await expect(tab.getByTestId(SUBMIT)).toBeEnabled()
      }
      const beforeObserver = observerFrames.sent.length
      const updatesBefore = updates()
      const actionsBefore = actions()
      if (episode !== 'no conflict')
        await observer
          .getByTestId('i18n-locale-field-date')
          .selectOption('YYYY-MM-DD')
      const draft = await Promise.all(
        I18N_LOCALE_FIELDS.map((field) =>
          observer.getByTestId(`i18n-locale-field-${field}`).inputValue(),
        ),
      )
      const submit = observer.getByTestId(SUBMIT)
      const geometry = [
        await watchHeight(submit),
        await watchHeight(observer.getByTestId('i18n-locale-notice-slot')),
      ]
      if (episode !== 'freeze')
        await page
          .getByTestId('i18n-locale-field-date')
          .selectOption(episode === 'no conflict' ? 'YYYY-MM-DD' : 'DD.MM.YYYY')
      if (episode === 'mine' || episode === 'theirs')
        await page
          .getByTestId('i18n-locale-field-time')
          .selectOption('HH:mm:ss')
      const beforeWriter = writerFrames.sent.length
      await clickSubmit(expect, page.getByTestId(SUBMIT))
      await expect(page.getByTestId(WINDOW)).toHaveCount(0)
      const request = expectI18nActionReply(
        expect,
        writerFrames,
        beforeWriter,
        'hilos_i18n_locale_add',
        { message: 'Locale en-GB added.' },
      )
      expect(request.formats).toEqual({
        ...BRITISH_FORMATS,
        ...(episode === 'freeze'
          ? {}
          : { date: episode === 'no conflict' ? 'YYYY-MM-DD' : 'DD.MM.YYYY' }),
        ...(episode === 'mine' || episode === 'theirs'
          ? { time: 'HH:mm:ss' }
          : {}),
      })
      await expect(observer.getByTestId(SUBMIT)).toHaveText('Save')
      await expect(observer.getByTestId('i18n-locale-title')).toHaveText(
        ADD_TITLE +
          (episode === 'mine' || episode === 'theirs' ? 'conflict' : ''),
      )
      await expect(
        i18nLocaleRow(observer, 'en-GB').getByTestId('i18n-locales-edit-en-GB'),
      ).toBeVisible()

      if (episode === 'no conflict') {
        await expect(observer.getByTestId(NOTICE)).toHaveText(
          'Updated just now',
        )
        await expectI18nLocaleFormats(expect, observer, {
          ...BRITISH_FORMATS,
          date: 'YYYY-MM-DD',
        })
        await expect(observer.getByTestId('conflict-choices')).toHaveCount(0)
        await expect(observer.getByTestId(SUBMIT)).toBeDisabled()
        for (const bookmark of geometry) await bookmark.unchanged()
      } else if (episode === 'mine' || episode === 'theirs') {
        await expect(observer.getByTestId(NOTICE)).toHaveText(
          'Date changed elsewhere to "DD.MM.YYYY"; your choice stays selected.',
        )
        await expect(
          observer.getByTestId('i18n-locale-field-date'),
        ).toHaveValue('YYYY-MM-DD')
        await expect(
          observer.getByTestId('i18n-locale-field-time'),
        ).toHaveValue('HH:mm:ss')
        await expect(observer.getByTestId(SUBMIT)).toBeDisabled()
        await expect(observer.getByTestId('conflict-choices')).toBeVisible()
        await expect(observer.getByTestId('conflict-badge')).toBeVisible()
        await expect(observer.getByTestId('conflict-merge')).toHaveCount(0)
        for (const bookmark of geometry) await bookmark.unchanged()
        await observer.getByTestId('i18n-locale-field-date').press('Enter')
        await expect(observer.getByTestId(WINDOW)).toBeVisible()
        expect(actions()).toBe(actionsBefore)
        await clickSubmit(
          expect,
          observer.getByTestId(`conflict-accept-${episode}`),
        )
        await expect(observer.getByTestId('conflict-choices')).toHaveCount(0)
        await expect(observer.getByTestId('conflict-badge')).toHaveCount(0)
        await expect(observer.getByTestId('i18n-locale-title')).toHaveText(
          ADD_TITLE,
        )
        for (const bookmark of geometry) await bookmark.unchanged()
        const date = episode === 'mine' ? 'YYYY-MM-DD' : 'DD.MM.YYYY'
        await expectI18nLocaleFormats(expect, observer, {
          ...BRITISH_FORMATS,
          date,
          time: 'HH:mm:ss',
        })
        expect(updates()).toBe(updatesBefore)
        if (episode === 'mine') {
          await clickSubmit(expect, observer.getByTestId(SUBMIT))
          await expect(observer.getByTestId(WINDOW)).toHaveCount(0)
          const sent = expectI18nActionReply(
            expect,
            observerFrames,
            beforeObserver,
            'hilos_i18n_locale_update',
            { message: 'Locale en-GB saved.' },
          )
          expect(sent).toMatchObject({
            languageId: request.languageId,
            countryId: request.countryId,
            localeId: expect.any(Number),
            formats: { ...BRITISH_FORMATS, date, time: 'HH:mm:ss' },
          })
          expect(sent.localeId).toBeGreaterThan(0)
          expect(updates()).toBe(updatesBefore + 1)
        } else {
          await expect(observer.getByTestId(SUBMIT)).toBeDisabled()
          expect(actions()).toBe(actionsBefore)
        }
        await dismissToasts(page)
        await clickSubmit(
          expect,
          i18nLocaleRow(page, 'en-GB').getByTestId('i18n-locales-edit-en-GB'),
        )
        await expectI18nLocaleFormats(expect, page, {
          ...BRITISH_FORMATS,
          date,
          time: 'HH:mm:ss',
        })
        await expect(page.getByTestId(SUBMIT)).toBeDisabled()
        await clickSubmit(expect, page.getByTestId('i18n-locale-cancel'))
        await expect(page.getByTestId(WINDOW)).toHaveCount(0)
      } else {
        await expect(observer.getByTestId(SUBMIT)).toBeEnabled()
        await dismissToasts(page)
        await clickSubmit(
          expect,
          i18nLocaleRow(page, 'en-GB').getByTestId('i18n-locales-edit-en-GB'),
        )
        await clickSubmit(expect, page.getByTestId('i18n-locale-switch-on'))
        const beforeSwitch = writerFrames.sent.length
        await clickSubmit(
          expect,
          page.getByTestId('i18n-locale-switch-on-confirm'),
        )
        await expect(page.getByTestId(WINDOW)).toHaveCount(0)
        expectI18nActionReply(
          expect,
          writerFrames,
          beforeSwitch,
          'hilos_i18n_locale_switch_on',
          { message: 'Locale en-GB switched on.' },
        )
        await expect(observer.getByTestId(NOTICE)).toHaveText(
          'Switched on elsewhere just now.',
        )
        await expect(observer.getByTestId('i18n-locale-plate')).toContainText(
          'switched on',
        )
        await expect(observer.getByTestId(SUBMIT)).toBeDisabled()
        // Deliberately bypass hit testing to prove the disabled submit's own mutation guard.
        await observer.getByTestId(SUBMIT).dispatchEvent('click')
        await observer.getByTestId('i18n-locale-field-date').press('Enter')
        await expect(observer.getByTestId(WINDOW)).toBeVisible()
        await expect(observer.getByTestId('i18n-locale-title')).toHaveText(
          ADD_TITLE,
        )
        await expect(observer.getByTestId(SUBMIT)).toHaveText('Save')
        await expect(observer.getByTestId(SUBMIT)).toBeDisabled()
        for (const bookmark of geometry) await bookmark.unchanged()
        for (const [index, field] of I18N_LOCALE_FIELDS.entries())
          await expect(
            observer.getByTestId(`i18n-locale-field-${field}`),
          ).toHaveValue(draft[index]!)
        await expect(observer.getByTestId('hilos-action-error')).toHaveCount(0)
        await expect(
          i18nLocaleRow(observer, 'en-GB').getByTestId(
            'i18n-locales-enabled-en-GB',
          ),
        ).toBeChecked()
        expect(actions()).toBe(actionsBefore)
      }
      if (await observer.getByTestId(WINDOW).isVisible()) {
        await cancelI18nEditor(expect, observer, WINDOW, 'i18n-locale-cancel')
      }
      expect(adds()).toBe(0)
      expect(updates()).toBe(updatesBefore + (episode === 'mine' ? 1 : 0))
      expect(actions()).toBe(actionsBefore + (episode === 'mine' ? 1 : 0))
      for (const tab of [page, observer])
        await expect(tab.getByTestId('hilos-table-apply')).toHaveCount(0)
      await live.unchanged()
      for (const tab of [page, observer])
        await expectDocumentUnchanged(expect, tab)
    })
  }
})
