import { expect, test as base } from '@playwright/test'
import {
  clickSubmit,
  countSentFrames,
  dismissToasts,
  expectI18nPageHead,
  expectLanguagePageHead,
  expectPageReady,
  gotoPage,
  grantAdminToSelf,
  PAGE_READY,
  settleRemovedRow,
  shownByTestId,
  typeInto,
  watchI18nLivePages,
} from '../../../../../framework/frontend/e2e/index.js'
import {
  clearLocale,
  deleteLanguage,
} from '../../../../../framework/frontend/scripts/i18n.mjs'
import {
  i18nFixtures,
  type I18nFixtureScope,
} from '../../../../../framework/frontend/scripts/i18nFixtures.mjs'

const test = base.extend<{ i18n: I18nFixtureScope }>({ i18n: i18nFixtures })

test('language.life', async ({ page, i18n }) => {
  // One administrator walks both creation branches, navigation and the full edit/switch/delete cycle.
  test.slow()
  await i18n.ownLanguage('pt')
  await i18n.ownLanguage('cy')
  const addRequests = countSentFrames(page, 'hilos_i18n_language_add')
  const updates = countSentFrames(page, 'hilos_i18n_language_update')
  await grantAdminToSelf(expect, page)

  await test.step('Read the default card, its protected actions and count-tile navigation', async () => {
    await gotoPage(expect, page, '/hilos/i18n/languages/en', PAGE_READY)
    await expectLanguagePageHead(expect, page, {
      code: 'en',
      nativeName: 'English',
      currentPage: 'hilos_i18n_language',
      appName: 'BTC Tracker',
    })
    await expect(page.getByTestId('language-head-direction')).toHaveText(
      'Left to right',
    )
    await expect(page.getByTestId('language-head-default')).toHaveText(
      'Default language',
    )
    await expect(page.getByTestId('language-head-own')).toHaveCount(0)
    await expect(page.getByTestId('language-card-enabled')).toHaveText(
      'Language switched on',
    )
    await expect(page.getByTestId('language-card-frozen')).toHaveText(
      'Language enabled — frozen',
    )
    await expect(
      page.getByTestId('language-overview-countryless-state'),
    ).toHaveText('Switched on')
    for (const action of ['switch-off', 'delete']) {
      const control = page.getByTestId(`language-card-${action}`)
      const reason = page.getByTestId(`language-card-${action}-reason`)
      await expect(control).toBeDisabled()
      await expect(reason).toContainText('default language')
      await expect(control).toHaveAttribute(
        'aria-describedby',
        (await reason.getAttribute('id'))!,
      )
    }
    await expect(page.getByTestId('language-card-delete-reason')).toHaveText(
      'Cannot delete: this is the default language.',
    )
    await clickSubmit(expect, page.getByTestId('language-card-view'))
    await expect(page.getByTestId('i18n-language-edit-title')).toHaveText(
      'Language · English',
    )
    await expect(
      page.getByTestId('i18n-language-edit-native-name'),
    ).toHaveValue('English')
    for (const field of ['code', 'native-name', 'rtl'])
      await expect(
        page.getByTestId(`i18n-language-edit-${field}`),
      ).toBeDisabled()
    await expect(page.getByTestId('i18n-language-edit-plate')).toHaveAttribute(
      'data-kind',
      'on',
    )
    await expect(page.getByTestId('i18n-language-edit-plate')).toContainText(
      'switch it off, edit it and switch it back on',
    )
    await expect(page.getByTestId('i18n-language-edit-submit')).toHaveCount(0)
    await clickSubmit(expect, page.getByTestId('i18n-language-edit-close'))
    await expect(page.getByTestId('i18n-language-edit-window')).toHaveCount(0)
    for (const [tile, suffix, key] of [
      ['name', 'names', 'hilos_i18n_language_names'],
      ['locale', 'locales', 'hilos_i18n_language_locales'],
    ]) {
      const link = page.getByTestId(`language-overview-${tile}-count`)
      await expect(link).toHaveAttribute(
        'href',
        `/hilos/i18n/languages/en/${suffix}`,
      )
      await clickSubmit(expect, link)
      await expectPageReady(expect, page)
      await expect(page).toHaveURL(new RegExp(`/en/${suffix}$`))
      await expectLanguagePageHead(expect, page, {
        code: 'en',
        nativeName: 'English',
        currentPage: key!,
        appName: 'BTC Tracker',
      })
      await expect(page.getByRole('table')).toHaveAccessibleName('English')
      await page.reload()
      await expectPageReady(expect, page)
      await expectLanguagePageHead(expect, page, {
        code: 'en',
        nativeName: 'English',
        currentPage: key!,
        appName: 'BTC Tracker',
      })
      await page.goBack()
      await expectPageReady(expect, page)
      await expect(page).toHaveURL(/\/languages\/en$/)
      await expect(
        page.getByTestId('hilos-page-tab-hilos_i18n_language'),
      ).toHaveAttribute('aria-current', 'page')
    }
    expect(updates()).toBe(0)
  })

  await test.step('Search the language table and open its frozen default row', async () => {
    await gotoPage(expect, page, '/hilos/i18n', PAGE_READY)
    await clickSubmit(
      expect,
      page.getByTestId('hilos-page-tab-hilos_i18n_languages'),
    )
    await expectPageReady(expect, page)
    await expectI18nPageHead(expect, page, {
      currentPage: 'hilos_i18n_languages',
      appName: 'BTC Tracker',
      tabsLabel: 'Internationalization pages',
    })
    await expect(page.getByTestId('i18n-languages-catalog')).toContainText(
      /The system knows \d+ languages and \d+ countries on its own/,
    )
    await expect(
      page.getByTestId('i18n-languages-legend').locator('dt'),
    ).toHaveText([
      'Default language',
      'Custom language',
      'Frozen language',
      'Editable language',
      'Deletion unavailable',
    ])
    const search = page.getByTestId('hilos-table-search')
    await typeInto(search, 'en')
    const row = shownByTestId(page, /^hilos-table-row-/).filter({
      has: page.getByTestId('i18n-languages-link-en'),
    })
    await expect(row.getByTestId('i18n-languages-link-en')).toHaveAttribute(
      'href',
      '/hilos/i18n/languages/en',
    )
    await expect(row.getByTestId('i18n-languages-default-en')).toContainText(
      'Default language',
    )
    await expect(row.getByTestId('i18n-languages-enabled-en')).toBeChecked()
    await expect(row.getByTestId('i18n-languages-delete-en')).toBeDisabled()
    await expect(row.getByTestId('i18n-languages-edit-en')).toHaveCount(0)
    await clickSubmit(expect, row.getByTestId('i18n-languages-view-en'))
    await expect(
      page.getByTestId('i18n-language-edit-native-name'),
    ).toBeDisabled()
    await expect(page.getByTestId('i18n-language-edit-submit')).toHaveCount(0)
    await clickSubmit(expect, page.getByTestId('i18n-language-edit-close'))
    await expect(page.getByTestId('i18n-language-edit-window')).toHaveCount(0)
    await typeInto(search, 'zzzz-absent')
    await expect(
      page.locator('table').getByTestId('hilos-table-no-matches'),
    ).toContainText('Nothing found')
    await expect(row).toHaveCount(0)
    await expect(page.getByTestId('i18n-languages-legend')).toBeVisible()
    await typeInto(search, '')
  })

  await test.step('Reject incomplete and duplicate input, then add a known language switched off', async () => {
    await clickSubmit(expect, page.getByTestId('hilos-table-main-action'))
    const code = page.getByTestId('i18n-language-add-code')
    const name = page.getByTestId('i18n-language-add-native-name')
    const submit = page.getByTestId('i18n-language-add-submit')
    await typeInto(code, 'p')
    await expect(submit).toBeDisabled()
    await code.press('Enter')
    expect(addRequests()).toBe(0)
    await typeInto(code, 'en')
    await expect(name).toHaveValue('English')
    await clickSubmit(expect, submit)
    await expect(page.getByTestId('i18n-language-add-error')).toContainText(
      "Language 'en' already exists.",
    )
    await expect(submit).toBeEnabled()
    await expect(code).toHaveValue('en')
    await typeInto(code, 'pt')
    await expect(name).toHaveValue('Português')
    await expect(page.getByTestId('i18n-language-add-plate')).toHaveAttribute(
      'data-kind',
      'known',
    )
    await clickSubmit(expect, submit)
    await expect(submit).toHaveCount(0)
    await expect(page.getByTestId('hilos-toast-success')).toContainText(
      'Language Português (pt) added and switched off for now.',
    )
    await expect(
      shownByTestId(page, 'i18n-languages-enabled-pt'),
    ).not.toBeChecked()
  })

  await test.step('Cancel custom upkeep, then explicitly confirm the custom language', async () => {
    await dismissToasts(page)
    for (const confirm of [false, true]) {
      await clickSubmit(expect, page.getByTestId('hilos-table-main-action'))
      await typeInto(page.getByTestId('i18n-language-add-code'), 'cy')
      await expect(page.getByTestId('i18n-language-add-plate')).toHaveAttribute(
        'data-kind',
        'own',
      )
      const name = page.getByTestId('i18n-language-add-native-name')
      await expect(name).toHaveValue('')
      await typeInto(name, '   ')
      await expect(page.getByTestId('i18n-language-add-submit')).toBeDisabled()
      await typeInto(name, 'Cymraeg')
      await clickSubmit(expect, page.getByTestId('i18n-language-add-submit'))
      await expect(page.getByTestId('modal')).toHaveAccessibleName(
        'The system does not know this language',
      )
      await expect(page.getByTestId('i18n-language-confirm-code')).toHaveText(
        'cy',
      )
      const before = addRequests()
      await clickSubmit(
        expect,
        page.getByTestId(
          `i18n-language-confirm-${confirm ? 'submit' : 'cancel'}`,
        ),
      )
      await expect(page.getByTestId('i18n-language-confirm')).toHaveCount(0)
      if (!confirm) {
        expect(addRequests()).toBe(before)
        await expect(shownByTestId(page, 'i18n-languages-link-cy')).toHaveCount(
          0,
        )
      }
    }
    await expect(page.getByTestId('hilos-toast-success')).toContainText(
      'Custom language Cymraeg (cy) added and switched off for now.',
    )
    await expect(shownByTestId(page, 'i18n-languages-own-cy')).toBeVisible()
  })

  await test.step('Switch on without a locale, read frozen fields, switch off and edit both entry places', async () => {
    await gotoPage(expect, page, '/hilos/i18n/languages/pt', PAGE_READY)
    await clickSubmit(expect, page.getByTestId('language-card-switch-on'))
    await expect(page.getByTestId('language-card-switch-on-locale')).toHaveText(
      'not created',
    )
    await expect(
      page.getByTestId('language-card-switch-on-warning'),
    ).toBeVisible()
    await clickSubmit(
      expect,
      page.getByTestId('language-card-switch-on-confirm'),
    )
    await expect(
      page.getByTestId('language-card-switch-on-confirm'),
    ).toHaveCount(0)
    await expect(page.getByTestId('language-card-enabled')).toHaveText(
      'Language switched on',
    )
    await expect(page.getByTestId('language-card-frozen')).toBeVisible()
    await clickSubmit(expect, page.getByTestId('language-card-view'))
    await expect(
      page.getByTestId('i18n-language-edit-native-name'),
    ).toBeDisabled()
    await expect(page.getByTestId('i18n-language-edit-submit')).toHaveCount(0)
    await clickSubmit(expect, page.getByTestId('i18n-language-edit-close'))
    await expect(page.getByTestId('i18n-language-edit-window')).toHaveCount(0)
    await clickSubmit(expect, page.getByTestId('language-card-switch-off'))
    await clickSubmit(
      expect,
      page.getByTestId('language-card-switch-off-confirm'),
    )
    await expect(
      page.getByTestId('language-card-switch-off-confirm'),
    ).toHaveCount(0)
    await expect(page.getByTestId('language-card-enabled')).toHaveText(
      'Language switched off',
    )
    await expect(page.getByTestId('language-card-frozen')).toHaveCount(0)
    await clickSubmit(expect, page.getByTestId('language-card-edit'))
    await expect(page.getByTestId('i18n-language-edit-code')).toBeDisabled()
    await expect(page.getByTestId('i18n-language-edit-code')).toHaveValue('pt')
    await expect(page.getByTestId('i18n-language-edit-plate')).toHaveAttribute(
      'data-kind',
      'known',
    )
    const name = page.getByTestId('i18n-language-edit-native-name')
    await typeInto(name, '')
    await expect(page.getByTestId('i18n-language-edit-submit')).toBeDisabled()
    const before = updates()
    await name.press('Enter')
    expect(updates()).toBe(before)
    await typeInto(name, 'Português edited')
    await clickSubmit(expect, page.getByTestId('i18n-language-edit-submit'))
    await expect(page.getByTestId('i18n-language-edit-window')).toHaveCount(0)
    await expect(page.getByTestId('language-card-name')).toHaveText(
      'Português edited',
    )
    await gotoPage(expect, page, '/hilos/i18n/languages', PAGE_READY)
    await dismissToasts(page)
    await clickSubmit(expect, shownByTestId(page, 'i18n-languages-edit-pt'))
    await expect(name).toHaveValue('Português edited')
    await typeInto(name, 'Português')
    await clickSubmit(expect, page.getByTestId('i18n-language-edit-submit'))
    await expect(page.getByTestId('i18n-language-edit-window')).toHaveCount(0)
    const row = shownByTestId(page, /^hilos-table-row-/).filter({
      has: page.getByTestId('i18n-languages-link-pt'),
    })
    await expect(row).toContainText('Português')
  })

  await test.step('Switch on with an enabled countryless locale and explain why its language cannot be deleted', async () => {
    const row = shownByTestId(page, /^hilos-table-row-/).filter({
      has: page.getByTestId('i18n-languages-link-pt'),
    })
    const languageId = Number(
      (await row.getAttribute('data-id'))!.replace('hilos-table-row-', ''),
    )
    expect(languageId).toBeGreaterThan(0)
    const locale = await i18n.locale({ languageId, enabled: true })
    await gotoPage(expect, page, '/hilos/i18n/languages/pt', PAGE_READY)
    await clickSubmit(expect, page.getByTestId('language-card-switch-on'))
    await expect(page.getByTestId('language-card-switch-on-locale')).toHaveText(
      'switched on',
    )
    await expect(
      page.getByTestId('language-card-switch-on-warning'),
    ).toHaveCount(0)
    await clickSubmit(
      expect,
      page.getByTestId('language-card-switch-on-confirm'),
    )
    await expect(
      page.getByTestId('language-card-switch-on-confirm'),
    ).toHaveCount(0)
    await expect(page.getByTestId('language-card-enabled')).toHaveText(
      'Language switched on',
    )
    await clickSubmit(expect, page.getByTestId('language-card-switch-off'))
    await clickSubmit(
      expect,
      page.getByTestId('language-card-switch-off-confirm'),
    )
    await expect(
      page.getByTestId('language-card-switch-off-confirm'),
    ).toHaveCount(0)
    await expect(page.getByTestId('language-card-enabled')).toHaveText(
      'Language switched off',
    )
    await expect(page.getByTestId('language-card-delete')).toBeDisabled()
    await expect(page.getByTestId('language-card-delete-reason')).toContainText(
      'locales',
    )
    await gotoPage(expect, page, '/hilos/i18n/languages', PAGE_READY)
    await dismissToasts(page)
    const blocked = shownByTestId(page, /^hilos-table-row-/).filter({
      has: page.getByTestId('i18n-languages-delete-pt'),
    })
    const remove = blocked.getByTestId('i18n-languages-delete-pt')
    const reason = blocked.getByTestId('i18n-languages-delete-reason-pt')
    const why = blocked.getByTestId('hilos-action-reason')
    await expect(remove).toBeDisabled()
    await expect(remove).toHaveAccessibleName('Delete language Português')
    await expect(remove).toHaveAttribute(
      'aria-describedby',
      (await reason.getAttribute('id'))!,
    )
    await expect(remove).toHaveAccessibleDescription(
      'Cannot delete: the language has locales.',
    )
    await expect(reason).not.toHaveAttribute('aria-hidden')
    const ids = await page
      .getByTestId('i18n-languages-delete-reason-pt')
      .evaluateAll((nodes) => nodes.map((node) => node.id))
    expect(ids).toHaveLength(2)
    expect(new Set(ids).size).toBe(2)
    await expect(why).toHaveRole('button')
    await expect(why).toHaveAccessibleName('Why this action is unavailable')
    await expect(why).toHaveAttribute(
      'title',
      'Cannot delete: the language has locales.',
    )
    await expect(why.locator('i')).toHaveAttribute('aria-hidden', 'true')
    for (const gesture of ['click', 'Enter', 'Space']) {
      await why.scrollIntoViewIfNeeded()
      await why.focus()
      if (gesture === 'click') await why.click()
      else await why.press(gesture)
      const modal = page.getByTestId('modal')
      await expect(modal).toBeVisible()
      await expect(modal).toHaveAccessibleName('Action unavailable')
      await expect(modal).toBeFocused()
      await expect(page.getByTestId('hilos-action-reason-body')).toHaveText(
        'Cannot delete: the language has locales.',
      )
      await expect(page.getByTestId('i18n-language-delete-window')).toHaveCount(
        0,
      )
      await expect(
        page.getByTestId(/^hilos-toast-(success|info|error|warning)$/),
      ).toHaveCount(0)
      if (gesture === 'Enter') await modal.press('Escape')
      else
        await clickSubmit(expect, page.getByTestId('hilos-action-reason-close'))
      await expect(modal).toHaveCount(0)
      await expect(why).toBeFocused()
      await expect(remove).toBeDisabled()
    }
    await clearLocale(locale.code)
  })

  await test.step('Cancel then confirm deletion from the card and delete the custom language from the list', async () => {
    await gotoPage(expect, page, '/hilos/i18n/languages/pt', PAGE_READY)
    await clickSubmit(expect, page.getByTestId('language-card-delete'))
    await expect(page.getByTestId('i18n-language-delete-window')).toContainText(
      "Deleting it also removes the built-in catalog's country names written in it.",
    )
    await clickSubmit(expect, page.getByTestId('i18n-language-delete-cancel'))
    await expect(page.getByTestId('i18n-language-delete-window')).toHaveCount(0)
    await expect(page.getByTestId('language-card-name')).toHaveText('Português')
    await clickSubmit(expect, page.getByTestId('language-card-delete'))
    await clickSubmit(expect, page.getByTestId('i18n-language-delete-confirm'))
    await expect(page.getByTestId('i18n-language-delete-window')).toHaveCount(0)
    await expectPageReady(expect, page)
    await expect(page).toHaveURL(/\/hilos\/i18n\/languages$/)
    await expect(page.getByTestId('hilos-toast-success')).toContainText(
      'Language Português (pt) deleted.',
    )
    await dismissToasts(page)
    const row = shownByTestId(page, /^hilos-table-row-/).filter({
      has: page.getByTestId('i18n-languages-link-cy'),
    })
    const pinned = page.getByTestId((await row.getAttribute('data-id'))!)
    const remove = row.getByTestId('i18n-languages-delete-cy')
    await expect(remove).toHaveAccessibleName('Delete language Cymraeg')
    await clickSubmit(expect, remove)
    await clickSubmit(expect, page.getByTestId('i18n-language-delete-confirm'))
    await expect(page.getByTestId('i18n-language-delete-window')).toHaveCount(0)
    await expect(page.getByTestId('hilos-toast-success')).toContainText(
      'Language Cymraeg (cy) deleted.',
    )
    await settleRemovedRow(expect, page, 'i18n-languages-delete-cy')
    await expect(pinned.getByTestId('hilos-table-placeholder')).toBeVisible()
  })
})

test('language.live-identity', async ({ page, context, i18n }) => {
  // Card/list writes, live disappearance and replacement cross several owner round trips.
  test.slow()
  const original = await i18n.language({ code: 'uk', nativeName: 'Українська' })
  const writes: Array<{
    languageId: number
    nativeName: string
    rtl: boolean
  }> = []
  let staleId: number | null = null
  await page.routeWebSocket(/.*/, (socket) => {
    const server = socket.connectToServer()
    socket.onMessage((message) => {
      if (typeof message !== 'string') {
        server.send(message)
        return
      }
      const frame = JSON.parse(message)
      if (
        frame.type === 'action' &&
        [
          'hilos_i18n_language_update',
          'hilos_i18n_language_row_update',
        ].includes(frame.action)
      ) {
        writes.push({ ...frame.data })
        if (staleId !== null) frame.data.languageId = staleId
        server.send(JSON.stringify(frame))
        return
      }
      server.send(message)
    })
  })
  await grantAdminToSelf(expect, page)
  const observer = await context.newPage()
  i18n.defer(() => observer.close())
  await gotoPage(expect, page, '/hilos/i18n/languages/uk', PAGE_READY)
  await gotoPage(expect, observer, '/hilos/i18n/languages/uk/names', PAGE_READY)
  const live = await watchI18nLivePages(expect, [observer])
  const name = page.getByTestId('i18n-language-edit-native-name')
  const save = page.getByTestId('i18n-language-edit-submit')

  await test.step('Save from the card and list; the subscribed Names head follows both names', async () => {
    const cardLive = await watchI18nLivePages(expect, [page])
    await clickSubmit(expect, page.getByTestId('language-card-edit'))
    await expect(page.getByTestId('i18n-language-edit-code')).toBeDisabled()
    await expect(page.getByTestId('i18n-language-edit-code')).toHaveValue('uk')
    await typeInto(name, 'Українська card')
    await clickSubmit(expect, save)
    await expect(page.getByTestId('i18n-language-edit-window')).toHaveCount(0)
    await expect(page.getByTestId('language-card-name')).toHaveText(
      'Українська card',
    )
    await expectLanguagePageHead(expect, page, {
      code: 'uk',
      nativeName: 'Українська card',
      currentPage: 'hilos_i18n_language',
      appName: 'BTC Tracker',
    })
    await expectLanguagePageHead(expect, observer, {
      code: 'uk',
      nativeName: 'Українська card',
      currentPage: 'hilos_i18n_language_names',
      appName: 'BTC Tracker',
    })
    await cardLive.unchanged()
    await gotoPage(expect, page, '/hilos/i18n/languages', PAGE_READY)
    const row = shownByTestId(page, /^hilos-table-row-/).filter({
      has: page.getByTestId('i18n-languages-link-uk'),
    })
    await expect(row).toContainText('Українська card')
    await dismissToasts(page)
    await clickSubmit(expect, row.getByTestId('i18n-languages-edit-uk'))
    await expect(name).toHaveValue('Українська card')
    await typeInto(name, 'Українська list')
    await clickSubmit(expect, save)
    await expect(page.getByTestId('i18n-language-edit-window')).toHaveCount(0)
    await expect(row).toContainText('Українська list')
    await expectLanguagePageHead(expect, observer, {
      code: 'uk',
      nativeName: 'Українська list',
      currentPage: 'hilos_i18n_language_names',
      appName: 'BTC Tracker',
    })
    expect(writes).toEqual([
      { languageId: original.id, nativeName: 'Українська card', rtl: false },
      { languageId: original.id, nativeName: 'Українська list', rtl: false },
    ])
    await live.unchanged()
  })

  await test.step('Observe deletion, recreate the same address and refuse an actual old-ID write', async () => {
    staleId = original.id
    await deleteLanguage('uk')
    await expect(observer.getByTestId('language-card-unavailable')).toHaveText(
      'Language details are no longer available.',
    )
    await expect(observer.getByTestId('hilos-page-head-title')).toHaveText(
      'Language',
    )
    await expect(observer.getByRole('table')).toHaveCount(0)
    await live.unchanged()
    const replacement = await i18n.language({
      code: 'uk',
      nativeName: 'Українська',
    })
    expect(replacement.id).not.toBe(original.id)
    await gotoPage(expect, page, '/hilos/i18n/languages/uk', PAGE_READY)
    await gotoPage(
      expect,
      observer,
      '/hilos/i18n/languages/uk/names',
      PAGE_READY,
    )
    await clickSubmit(expect, page.getByTestId('language-card-edit'))
    await typeInto(name, 'Must not reach replacement')
    await clickSubmit(expect, save)
    await expect(page.getByTestId('i18n-language-edit-error')).toContainText(
      `Unknown language: ${original.id}`,
    )
    await expect(save).toBeEnabled()
    await expect(name).toHaveValue('Must not reach replacement')
    await expect(page.getByTestId('hilos-page-head-title')).toHaveText(
      'Українська',
    )
    await expect(observer.getByTestId('hilos-page-head-title')).toHaveText(
      'Українська',
    )
    expect(writes).toHaveLength(3)
    expect(writes[2]).toEqual({
      languageId: replacement.id,
      nativeName: 'Must not reach replacement',
      rtl: false,
    })
    staleId = null
    await typeInto(name, 'Explicit replacement save')
    await clickSubmit(expect, save)
    await expect(page.getByTestId('i18n-language-edit-window')).toHaveCount(0)
    await expectLanguagePageHead(expect, observer, {
      code: 'uk',
      nativeName: 'Explicit replacement save',
      currentPage: 'hilos_i18n_language_names',
      appName: 'BTC Tracker',
    })
    await expect(page.getByTestId('hilos-page-head-title')).toHaveText(
      'Explicit replacement save',
    )
    expect(writes).toHaveLength(4)
    expect(writes[3]).toEqual({
      languageId: replacement.id,
      nativeName: 'Explicit replacement save',
      rtl: false,
    })
  })
})
