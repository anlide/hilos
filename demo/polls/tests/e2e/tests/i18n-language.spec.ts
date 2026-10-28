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
  // One administrator owns the known/custom add, navigation, edit and delete journey.
  test.slow()
  await i18n.ownLanguage('pt')
  await i18n.ownLanguage('cy')
  const addRequests = countSentFrames(page, 'hilos_i18n_language_add')
  const updates = countSentFrames(page, 'hilos_i18n_language_update')
  await grantAdminToSelf(expect, page)

  await test.step('Read the protected default card and visit Names and Locales', async () => {
    await gotoPage(expect, page, '/hilos/i18n/languages/en', PAGE_READY)
    await expectLanguagePageHead(expect, page, {
      code: 'en',
      nativeName: 'English',
      currentPage: 'hilos_i18n_language',
      appName: 'Hilos Polls',
    })
    await expect(page.getByTestId('language-head-default')).toHaveText(
      'Default language',
    )
    await expect(page.getByTestId('language-head-direction')).toHaveText(
      'Left to right',
    )
    await expect(page.getByTestId('language-card-direction')).toHaveText(
      'Left to right',
    )
    await expect(page.getByTestId('language-card-enabled')).toHaveText(
      'Enabled',
    )
    await expect(page.getByTestId('language-card-frozen')).toHaveText(
      'Language enabled — frozen',
    )
    const switchOff = page.getByTestId('language-card-switch-off')
    const switchOffReason = page.getByTestId('language-card-switch-off-reason')
    await expect(switchOff).toBeDisabled()
    await expect(switchOff).toHaveAttribute(
      'aria-describedby',
      (await switchOffReason.getAttribute('id'))!,
    )
    await expect(switchOffReason).toContainText('default language')
    const remove = page.getByTestId('language-card-delete')
    const removeReason = page.getByTestId('language-card-delete-reason')
    await expect(remove).toBeDisabled()
    await expect(removeReason).toHaveText(
      'Cannot delete: this is the default language.',
    )
    await expect(remove).toHaveAttribute(
      'aria-describedby',
      (await removeReason.getAttribute('id'))!,
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

    for (const [tab, path, key] of [
      [
        'hilos-page-tab-hilos_i18n_language_names',
        '/names',
        'hilos_i18n_language_names',
      ],
      [
        'hilos-page-tab-hilos_i18n_language_locales',
        '/locales',
        'hilos_i18n_language_locales',
      ],
    ]) {
      await clickSubmit(expect, page.getByTestId(tab!))
      await expect(page).toHaveURL(new RegExp(`/languages/en${path}$`))
      await expectPageReady(expect, page)
      await expectLanguagePageHead(expect, page, {
        code: 'en',
        nativeName: 'English',
        currentPage: key!,
        appName: 'Hilos Polls',
      })
      await page.reload()
      await expectPageReady(expect, page)
      await page.goBack()
      await expectPageReady(expect, page)
      await expect(page).toHaveURL(/\/languages\/en$/)
    }
  })

  await test.step('Search the list and read the default row without relying on table paint', async () => {
    await gotoPage(expect, page, '/hilos/i18n', PAGE_READY)
    await clickSubmit(
      expect,
      page.getByTestId('hilos-page-tab-hilos_i18n_languages'),
    )
    await expectPageReady(expect, page)
    await expectI18nPageHead(expect, page, {
      currentPage: 'hilos_i18n_languages',
      appName: 'Hilos Polls',
    })
    await expect(page.getByTestId('i18n-languages-catalog')).toContainText(
      /The system knows \d+ languages and \d+ countries on its own/,
    )
    const legend = page.getByTestId('i18n-languages-legend')
    await expect(
      legend.getByTestId('i18n-languages-legend-default'),
    ).toBeVisible()
    await expect(legend.getByTestId('i18n-languages-legend-own')).toBeVisible()
    const search = page.getByTestId('hilos-table-search')
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
    await clickSubmit(expect, row.getByTestId('i18n-languages-view-en'))
    await expect(page.getByTestId('i18n-language-edit-title')).toHaveText(
      'Language · English',
    )
    await expect(
      page.getByTestId('i18n-language-edit-native-name'),
    ).toBeDisabled()
    await expect(page.getByTestId('i18n-language-edit-rtl')).toBeDisabled()
    await expect(page.getByTestId('i18n-language-edit-plate')).toContainText(
      'switch it off, edit it and switch it back on',
    )
    await expect(page.getByTestId('i18n-language-edit-submit')).toHaveCount(0)
    await clickSubmit(expect, page.getByTestId('i18n-language-edit-close'))
    await expect(page.getByTestId('i18n-language-edit-window')).toHaveCount(0)
    await typeInto(search, 'en')
    await expect(row).toBeVisible()
    await typeInto(search, 'zzzz-absent')
    await expect(
      page.locator('table').getByTestId('hilos-table-no-matches'),
    ).toContainText('Nothing found')
    await expect(row).toHaveCount(0)
    await expect(legend).toBeVisible()
    await typeInto(search, '')
  })

  await test.step('Reject invalid and duplicate input, then add a known language', async () => {
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

  await test.step('Cancel custom upkeep once, then confirm it and preserve the code/name draft rules', async () => {
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

  await test.step('Switch on without a locale, read the frozen view, then switch off and edit from both places', async () => {
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
      'Enabled',
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
      'Disabled',
    )
    await expect(page.getByTestId('language-card-frozen')).toHaveCount(0)
    await clickSubmit(expect, page.getByTestId('language-card-edit'))
    const name = page.getByTestId('i18n-language-edit-native-name')
    await expect(page.getByTestId('i18n-language-edit-code')).toBeDisabled()
    await expect(page.getByTestId('i18n-language-edit-code')).toHaveValue('pt')
    await expect(page.getByTestId('i18n-language-edit-plate')).toHaveAttribute(
      'data-kind',
      'known',
    )
    await typeInto(name, '')
    const beforeEmpty = updates()
    await expect(page.getByTestId('i18n-language-edit-submit')).toBeDisabled()
    await name.press('Enter')
    expect(updates()).toBe(beforeEmpty)
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
    expect(updates()).toBeGreaterThan(beforeEmpty)
  })

  await test.step('Switch on with a countryless locale and explain blocked deletion accessibly', async () => {
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
    await expect(page.getByTestId('language-card-enabled')).toHaveText(
      'Enabled',
    )
    await clickSubmit(expect, page.getByTestId('language-card-switch-off'))
    await clickSubmit(
      expect,
      page.getByTestId('language-card-switch-off-confirm'),
    )
    await expect(page.getByTestId('language-card-enabled')).toHaveText(
      'Disabled',
    )
    const cardRemove = page.getByTestId('language-card-delete')
    await expect(cardRemove).toBeDisabled()
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
    const description = 'Cannot delete: the language has locales.'
    await expect(remove).toBeDisabled()
    await expect(remove).toHaveAccessibleName('Delete language Português')
    await expect(remove).toHaveAttribute(
      'aria-describedby',
      (await reason.getAttribute('id'))!,
    )
    await expect(remove).toHaveAccessibleDescription(description)
    await expect(reason).not.toHaveAttribute('aria-hidden')
    const reasons = page.getByTestId('i18n-languages-delete-reason-pt')
    const ids = await reasons.evaluateAll((nodes) =>
      nodes.map((node) => node.id),
    )
    expect(ids).toHaveLength(2)
    expect(new Set(ids).size).toBe(2)
    await expect(why).toHaveRole('button')
    await expect(why).toHaveAccessibleName('Why this action is unavailable')
    await expect(why).toHaveAttribute('title', description)
    await expect(why.locator('i')).toHaveAttribute('aria-hidden', 'true')
    const modal = page.getByTestId('modal')
    for (const gesture of ['click', 'Enter', 'Space'] as const) {
      await why.scrollIntoViewIfNeeded()
      await expect(why).toBeVisible()
      await expect(why).toBeEnabled()
      await why.focus()
      if (gesture === 'click') await why.click()
      else await why.press(gesture)
      await expect(modal).toBeVisible()
      await expect(modal).toHaveAccessibleName('Action unavailable')
      await expect(modal).toBeFocused()
      expect(
        await why.evaluate(
          (button) => button.querySelector('[data-id="modal"]') === null,
        ),
      ).toBe(true)
      await expect(page.getByTestId('hilos-action-reason-body')).toHaveText(
        description,
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
    await expect(page.getByTestId('i18n-language-delete-window')).toHaveCount(0)
    await why.focus()
    await why.press('Enter')
    await expect(modal).toBeFocused()
    await clearLocale(locale.code)
    await expect(remove).toBeEnabled()
    await expect(why).toBeHidden()
    await expect(reason).toHaveCount(0)
    await expect(modal).toBeVisible()
    await expect(modal).toBeFocused()
    await expect(page.getByTestId('hilos-action-reason-body')).toHaveText(
      description,
    )
    await clickSubmit(expect, page.getByTestId('hilos-action-reason-close'))
    await expect(modal).toHaveCount(0)
    await expect(page.getByTestId('hilos-table-search')).toBeFocused()
    await expect(page.getByTestId('i18n-language-delete-window')).toHaveCount(0)
  })

  await test.step('Cancel and confirm deletion from the card, then delete the custom row from the list', async () => {
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
  // The owner identity stays bound through a delete/recreate of the same public code.
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

  await test.step('Save from card and list while the open Names head follows the language', async () => {
    const cardLive = await watchI18nLivePages(expect, [page])
    await clickSubmit(expect, page.getByTestId('language-card-edit'))
    await expect(page.getByTestId('i18n-language-edit-code')).toBeDisabled()
    await expect(page.getByTestId('i18n-language-edit-code')).toHaveValue('uk')
    await page.getByTestId('i18n-language-edit-rtl').check()
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
      appName: 'Hilos Polls',
    })
    await expect(page.getByTestId('language-head-direction')).toHaveText(
      'Right to left',
    )
    await expectLanguagePageHead(expect, observer, {
      code: 'uk',
      nativeName: 'Українська card',
      currentPage: 'hilos_i18n_language_names',
      appName: 'Hilos Polls',
    })
    await expect(observer.getByTestId('language-head-direction')).toHaveText(
      'Right to left',
    )
    await expect(
      observer.getByRole('table', { name: 'Українська card' }),
    ).toBeVisible()
    await expect(page).toHaveURL(/\/hilos\/i18n\/languages\/uk$/)
    await expect(observer).toHaveURL(/\/hilos\/i18n\/languages\/uk\/names$/)
    await cardLive.unchanged()
    await gotoPage(expect, page, '/hilos/i18n/languages', PAGE_READY)
    const row = shownByTestId(page, /^hilos-table-row-/).filter({
      has: page.getByTestId('i18n-languages-link-uk'),
    })
    await expect(row).toContainText('Українська card')
    await dismissToasts(page)
    await clickSubmit(expect, row.getByTestId('i18n-languages-edit-uk'))
    await expect(name).toHaveValue('Українська card')
    await expect(page.getByTestId('i18n-language-edit-rtl')).toBeChecked()
    await typeInto(name, 'Українська list')
    await clickSubmit(expect, save)
    await expect(page.getByTestId('i18n-language-edit-window')).toHaveCount(0)
    await expect(row).toContainText('Українська list')
    await expectLanguagePageHead(expect, observer, {
      code: 'uk',
      nativeName: 'Українська list',
      currentPage: 'hilos_i18n_language_names',
      appName: 'Hilos Polls',
    })
    await expect(observer.getByTestId('language-head-direction')).toHaveText(
      'Right to left',
    )
    await expect(
      observer.getByRole('table', { name: 'Українська list' }),
    ).toBeVisible()
    await expect(page).toHaveURL(/\/hilos\/i18n\/languages$/)
    await expect(observer).toHaveURL(/\/hilos\/i18n\/languages\/uk\/names$/)
    expect(writes).toEqual([
      { languageId: original.id, nativeName: 'Українська card', rtl: true },
      { languageId: original.id, nativeName: 'Українська list', rtl: true },
    ])
    await live.unchanged()
  })

  await test.step('Refuse the captured old id after recreation without retrying it, then save explicitly', async () => {
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
    await expect(page.getByTestId('i18n-language-edit-rtl')).not.toBeChecked()
    await typeInto(name, 'Must not reach replacement')
    const beforeStaleSave = writes.length
    await clickSubmit(expect, save)
    await expect(page.getByTestId('i18n-language-edit-error')).toContainText(
      `Unknown language: ${original.id}`,
    )
    await expect(save).toBeEnabled()
    expect(writes).toHaveLength(beforeStaleSave + 1)
    expect(writes[beforeStaleSave]).toEqual({
      languageId: replacement.id,
      nativeName: 'Must not reach replacement',
      rtl: false,
    })
    await expect(name).toHaveValue('Must not reach replacement')
    await expect(page.getByTestId('hilos-page-head-title')).toHaveText(
      'Українська',
    )
    await expect(observer.getByTestId('hilos-page-head-title')).toHaveText(
      'Українська',
    )
    expect(writes).toHaveLength(beforeStaleSave + 1)

    staleId = null
    await clickSubmit(expect, save)
    await expect(page.getByTestId('i18n-language-edit-window')).toHaveCount(0)
    await expectLanguagePageHead(expect, observer, {
      code: 'uk',
      nativeName: 'Must not reach replacement',
      currentPage: 'hilos_i18n_language_names',
      appName: 'Hilos Polls',
    })
    expect(writes).toHaveLength(beforeStaleSave + 2)
    expect(writes[beforeStaleSave + 1]).toEqual({
      languageId: replacement.id,
      nativeName: 'Must not reach replacement',
      rtl: false,
    })
  })
})
