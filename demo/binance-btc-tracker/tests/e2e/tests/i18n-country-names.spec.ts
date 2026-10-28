import { expect, test as base } from '@playwright/test'
import {
  cancelI18nName,
  clickSubmit,
  confirmI18nNameDelete,
  countSentFrames,
  dismissToasts,
  expandI18nNameCorrections,
  expectCountryPageHead,
  expectI18nName,
  expectPageReady,
  gotoPage,
  grantAdminToSelf,
  i18nNameRow,
  openI18nName,
  PAGE_READY,
  saveI18nName,
  shownByTestId,
  typeInto,
  watchDeliveryFrames,
  watchHeight,
  watchI18nLivePages,
  watchTop,
} from '../../../../../framework/frontend/e2e/index.js'
import { setAdminViewMode } from '../../../../../framework/frontend/scripts/adminViewMode.mjs'
import {
  i18nFixtures,
  type I18nFixtureScope,
} from '../../../../../framework/frontend/scripts/i18nFixtures.mjs'

const test = base.extend<{ i18n: I18nFixtureScope }>({ i18n: i18nFixtures })
const PATH = '/hilos/i18n/countries/qj/names'
const WINDOW = 'i18n-country-name-window'
const INPUT = 'i18n-country-name-input'
const SUBMIT = 'i18n-country-name-submit'
const NOTICE = 'i18n-country-name-notice'
const DELETE = 'i18n-country-name-delete-window'
const CONFIRM = 'i18n-country-name-delete-confirm'
const CANCEL_DELETE = 'i18n-country-name-delete-cancel'

test('country-names.life', async ({ page, context, i18n }) => {
  // Catalog navigation, literal edits and three live subscribers cover the complete name lifecycle.
  test.slow()
  const actions = countSentFrames(page, '"action":"hilos_i18n_country_name_')
  const log = watchDeliveryFrames(page)
  i18n.defer(async () => log.stop())
  await grantAdminToSelf(expect, page)
  await test.step('Read reflowed catalog names and carrier values through code-based Names/Overview navigation without actions', async () => {
    const before = actions()
    await gotoPage(expect, page, '/hilos/i18n/countries/us/names', PAGE_READY)
    const row = i18nNameRow(page, 'country', 'en')
    await expect(row).toContainText('English')
    await expect(row).toContainText('United States')
    await expect(page.getByTestId('i18n-names-none-en')).toHaveCount(0)
    await expect(
      shownByTestId(page, 'i18n-country-name-edit-en'),
    ).toHaveAccessibleName('Edit name · United States in English')
    await expect(
      shownByTestId(page, 'i18n-names-delete-en'),
    ).toHaveAccessibleName('Delete country name in English')
    await expect(
      page
        .getByTestId('country-names')
        .locator('input, select, textarea, form, [contenteditable="true"]'),
    ).toHaveCount(0)
    // Earlier independent scenarios restore their locales; catalog English has no country corrections.
    await expect(row.getByTestId(/^hilos-table-expand-/)).toHaveCount(0)
    await expectCountryPageHead(expect, page, {
      code: 'us',
      name: 'United States',
      appName: 'BTC Tracker',
      currentPage: 'hilos_i18n_country_names',
    })
    await clickSubmit(
      expect,
      page.getByTestId('hilos-page-tab-hilos_i18n_country'),
    )
    await expectPageReady(expect, page)
    await expect(page).toHaveURL(/\/countries\/us$/)
    await clickSubmit(
      expect,
      page.getByTestId('hilos-page-tab-hilos_i18n_country_names'),
    )
    await expectPageReady(expect, page)
    await expect(row).toContainText('United States')
    await gotoPage(expect, page, '/hilos/i18n/countries/us/names', PAGE_READY)
    await expect(row).toContainText('English')
    await expect(row).toContainText('United States')
    expect(actions()).toBe(before)
  })

  await i18n.countryNames()
  const observer = await context.newPage()
  i18n.defer(() => observer.close())
  const overview = await context.newPage()
  i18n.defer(() => overview.close())
  for (const tab of [page, observer]) {
    await gotoPage(expect, tab, PATH, PAGE_READY)
    await expect(shownByTestId(tab, 'i18n-names-none-en')).toHaveText('No name')
    await expectI18nName(expect, tab, 'country', 'qk', 'Fixture base')
    await expandI18nNameCorrections(expect, tab, 'country', 'qk')
    await expect(shownByTestId(tab, 'i18n-names-inherit-qk-FR')).toHaveText(
      'as in “HIL-1501 writing”',
    )
  }
  await gotoPage(expect, overview, '/hilos/i18n/countries/qj', PAGE_READY)
  const live = await watchI18nLivePages(expect, [page, observer, overview])

  await test.step('Cancel an Add, then add/rename/save empty/delete the default-language name; both Names and Overview heads follow live', async () => {
    const before = actions()
    await openI18nName(expect, page, 'country', 'add-en')
    await expect(page.getByTestId(WINDOW)).toContainText('built-in catalog')
    await typeInto(page.getByTestId(INPUT), 'Canceled base')
    await cancelI18nName(expect, page, 'country')
    expect(actions()).toBe(before)
    await expect(shownByTestId(page, 'i18n-names-none-en')).toHaveText(
      'No name',
    )
    let previous = ''
    for (const episode of [
      {
        control: 'add-en',
        value: 'Country from UI',
        operation: 'add',
        message: 'Name of Country from UI (qj) in English (en) added.',
      },
      {
        control: 'edit-en',
        value: 'Renamed country',
        operation: 'update',
        message: 'Name of Renamed country (qj) in English (en) saved.',
      },
      {
        control: 'edit-en',
        value: '',
        operation: 'update',
        message: 'Name of qj in English (en) saved.',
      },
    ] as const) {
      await openI18nName(expect, page, 'country', episode.control)
      await typeInto(page.getByTestId(INPUT), episode.value)
      const sent = await saveI18nName(
        expect,
        page,
        'country',
        log,
        episode.operation,
        episode.message,
      )
      expect(sent).toMatchObject({
        countryId: expect.any(Number),
        inLanguageId: expect.any(Number),
        localeId: null,
        name: episode.value,
      })
      expect(sent).not.toHaveProperty('languageId')
      if (episode.operation === 'update')
        expect(sent.expectedName).toBe(previous)
      previous = episode.value
      for (const [tab, key] of [
        [page, 'hilos_i18n_country_names'],
        [observer, 'hilos_i18n_country_names'],
        [overview, 'hilos_i18n_country'],
      ] as const) {
        await expectCountryPageHead(expect, tab, {
          code: 'qj',
          name: episode.value || 'Unnamed country',
          appName: 'BTC Tracker',
          currentPage: key,
        })
        await expect(tab.getByTestId('country-head-code')).toHaveText('qj')
      }
      for (const tab of [page, observer])
        await expectI18nName(expect, tab, 'country', 'en', episode.value)
      await live.unchanged()
    }
    for (const tab of [page, observer]) {
      await openI18nName(expect, tab, 'country', 'edit-en')
      await expect(tab.getByTestId(INPUT)).toHaveValue('')
      await expect(tab.getByTestId(SUBMIT)).toBeDisabled()
      await tab.getByTestId(INPUT).press('Escape')
      await expect(tab.getByTestId(WINDOW)).toHaveCount(0)
      await expect(
        shownByTestId(tab, 'i18n-country-name-edit-en'),
      ).toBeFocused()
    }
    const coldEmpty = await context.newPage()
    i18n.defer(() => coldEmpty.close())
    await gotoPage(expect, coldEmpty, PATH, PAGE_READY)
    await coldEmpty.reload()
    await expectPageReady(expect, coldEmpty)
    await expectCountryPageHead(expect, coldEmpty, {
      code: 'qj',
      name: 'Unnamed country',
      appName: 'BTC Tracker',
      currentPage: 'hilos_i18n_country_names',
    })
    await openI18nName(expect, coldEmpty, 'country', 'edit-en')
    await expect(coldEmpty.getByTestId(INPUT)).toHaveValue('')
    await expect(coldEmpty.getByTestId(SUBMIT)).toBeDisabled()
    await cancelI18nName(expect, coldEmpty, 'country')
    await coldEmpty.close()
    await dismissToasts(page)
    await clickSubmit(expect, shownByTestId(page, 'i18n-names-delete-en'))
    const sent = await confirmI18nNameDelete(
      expect,
      page,
      'country',
      log,
      false,
      'Name of qj in English (en) removed.',
    )
    expect(sent.expectedName).toBe('')
    for (const tab of [page, observer])
      await expect(shownByTestId(tab, 'i18n-names-none-en')).toHaveText(
        'No name',
      )
    await expect(overview.getByTestId('hilos-page-head-title')).toHaveText(
      'Unnamed country',
    )
    await live.unchanged()
  })

  await test.step('Edit foreign-language base/corrections with literal spaces and empty values without changing the default-language head', async () => {
    for (const episode of [
      {
        control: 'edit-qk',
        key: 'qk',
        value: '  Edited base  ',
        previous: 'Fixture base',
        message: 'Name of qj in HIL-1501 writing (qk) saved.',
      },
      {
        control: 'locale-edit-qk-GB',
        key: 'qk-GB',
        value: '  Edited British correction  ',
        previous: 'Fixture correction',
        message: 'Correction qk-GB of the name of qj saved.',
      },
      {
        control: 'locale-edit-qk-US',
        key: 'qk-US',
        value: 'American correction',
        previous: '',
        message: 'Correction qk-US of the name of qj saved.',
      },
      {
        control: 'locale-edit-qk-US',
        key: 'qk-US',
        value: '',
        previous: 'American correction',
        message: 'Correction qk-US of the name of qj saved.',
      },
      {
        control: 'edit-qk',
        key: 'qk',
        value: '',
        previous: '  Edited base  ',
        message: 'Name of qj in HIL-1501 writing (qk) saved.',
      },
    ]) {
      await openI18nName(expect, page, 'country', episode.control)
      await expect(page.getByTestId(INPUT)).toHaveValue(episode.previous)
      await typeInto(page.getByTestId(INPUT), episode.value)
      const sent = await saveI18nName(
        expect,
        page,
        'country',
        log,
        'update',
        episode.message,
        episode.value === '',
      )
      expect(sent).toMatchObject({
        name: episode.value,
        expectedName: episode.previous,
      })
      expect(sent).not.toHaveProperty('languageId')
      for (const tab of [page, observer]) {
        await expectI18nName(expect, tab, 'country', episode.key, episode.value)
        await openI18nName(expect, tab, 'country', episode.control)
        await expect(tab.getByTestId(INPUT)).toHaveValue(episode.value)
        await expect(tab.getByTestId(SUBMIT)).toBeDisabled()
        await cancelI18nName(expect, tab, 'country')
        if (episode.value === '') {
          await expect(
            tab.getByTestId(
              `i18n-names-${episode.key.includes('-') ? 'inherit' : 'none'}-${episode.key}`,
            ),
          ).toHaveCount(0)
          await expect(
            shownByTestId(tab, `i18n-names-delete-${episode.key}`),
          ).toBeVisible()
        }
      }
      for (const tab of [page, observer, overview])
        await expect(tab.getByTestId('hilos-page-head-title')).toHaveText(
          'Unnamed country',
        )
      await live.unchanged()
    }
    // A disabled locale accepts its own empty correction under an existing empty base.
    await openI18nName(expect, page, 'country', 'locale-add-qk-FR')
    await expect(page.getByTestId(INPUT)).toHaveValue('')
    await saveI18nName(
      expect,
      page,
      'country',
      log,
      'add',
      'Correction qk-FR of the name of qj added.',
    )
    for (const tab of [page, observer]) {
      await expectI18nName(expect, tab, 'country', 'qk-FR', '')
      await expect(tab.getByTestId('i18n-names-inherit-qk-FR')).toHaveCount(0)
    }
  })

  await test.step('Cancel/remove corrections, inherit the base, and delete it while the other confirmation explains its disappearance', async () => {
    await dismissToasts(page)
    const trash = shownByTestId(page, 'i18n-names-delete-qk-GB')
    await expect(trash).toHaveAccessibleName('Remove locale correction qk-GB')
    const before = actions()
    await clickSubmit(expect, trash)
    await expect(page.getByTestId('modal')).toBeFocused()
    await expect(page.getByTestId(DELETE)).toContainText(
      'Edited British correction',
    )
    await expect(page.getByTestId(DELETE)).toContainText(
      'Base name to inherit:',
    )
    await expect(page.getByTestId(DELETE)).toContainText(
      'This correction will not return by itself.',
    )
    await page.getByTestId('modal').press('Escape')
    await expect(page.getByTestId(DELETE)).toHaveCount(0)
    await expect(trash).toBeFocused()
    expect(actions()).toBe(before)
    for (const key of ['qk-GB', 'qk-US']) {
      await dismissToasts(page)
      await clickSubmit(expect, shownByTestId(page, `i18n-names-delete-${key}`))
      if (key === 'qk-US') {
        await expect(page.getByTestId(DELETE)).toContainText('Empty value')
        await expect(page.getByTestId(DELETE)).toContainText(
          'A saved empty string, not an absent name.',
        )
      }
      const sent = await confirmI18nNameDelete(
        expect,
        page,
        'country',
        log,
        true,
        `Correction ${key} of the name of qj removed.`,
      )
      expect(sent.expectedName).toBe(
        key === 'qk-US' ? '' : '  Edited British correction  ',
      )
      for (const tab of [page, observer]) {
        await expect(
          shownByTestId(tab, `i18n-names-inherit-${key}`),
        ).toHaveText('as in “HIL-1501 writing”')
        await expectI18nName(expect, tab, 'country', 'qk', '')
      }
      await live.unchanged()
    }
    await dismissToasts(page)
    const baseTrash = shownByTestId(page, 'i18n-names-delete-qk')
    const noWrite = actions()
    await clickSubmit(expect, baseTrash)
    await expect(page.getByTestId(DELETE)).toContainText(
      'All locale corrections of this base name will also be deleted.',
    )
    await expect(page.getByTestId(DELETE)).toContainText(
      'If the built-in catalog knows this name, it returns immediately as an unlocked name. Otherwise, there will be no name in this language.',
    )
    await expect(page.getByTestId(DELETE)).not.toContainText(/36|hours/)
    await clickSubmit(expect, page.getByTestId(CANCEL_DELETE))
    await expect(page.getByTestId(DELETE)).toHaveCount(0)
    await expect(baseTrash).toBeFocused()
    expect(actions()).toBe(noWrite)
    await clickSubmit(expect, shownByTestId(observer, 'i18n-names-delete-qk'))
    await clickSubmit(expect, baseTrash)
    await confirmI18nNameDelete(
      expect,
      page,
      'country',
      log,
      false,
      'Name of qj in HIL-1501 writing (qk) removed.',
    )
    for (const tab of [page, observer]) {
      await expect(shownByTestId(tab, 'i18n-names-none-qk')).toHaveText(
        'No name',
      )
      await expect(i18nNameRow(tab, 'country', 'qk')).toBeVisible()
      for (const country of ['GB', 'US', 'FR'])
        await expect(
          tab.getByTestId(`i18n-names-correction-qk-${country}`),
        ).toHaveCount(0)
      await expect(tab.getByTestId('hilos-table-apply')).toHaveCount(0)
    }
    await expect(observer.getByTestId(DELETE)).toBeVisible()
    await expect(observer.getByTestId(CONFIRM)).toBeDisabled()
    await expect(
      observer.getByTestId('i18n-country-name-delete-notice'),
    ).toContainText('The name no longer exists.')
    await clickSubmit(expect, observer.getByTestId(CANCEL_DELETE))
    for (const tab of [page, observer, overview])
      await expect(tab.getByTestId('hilos-page-head-title')).toHaveText(
        'Unnamed country',
      )
    await live.unchanged()
  })

  await test.step('Keep the final Unnamed head and code address on direct entry, F5 and a cold page', async () => {
    await gotoPage(expect, page, PATH, PAGE_READY)
    await overview.reload()
    await expectPageReady(expect, overview)
    const cold = await context.newPage()
    i18n.defer(() => cold.close())
    await gotoPage(expect, cold, PATH, PAGE_READY)
    for (const [tab, key, suffix] of [
      [page, 'hilos_i18n_country_names', '/names'],
      [overview, 'hilos_i18n_country', ''],
      [cold, 'hilos_i18n_country_names', '/names'],
    ] as const) {
      await expectCountryPageHead(expect, tab, {
        code: 'qj',
        name: 'Unnamed country',
        appName: 'BTC Tracker',
        currentPage: key,
      })
      await expect(tab).toHaveURL(new RegExp(`/countries/qj${suffix}$`))
    }
  })
})

test('country-names.live-conflict', async ({ page, context, i18n }) => {
  // Four sequential conflict episodes, a no-conflict delivery and explicit saves cross two live tabs.
  test.slow()
  await i18n.countryNames()
  const log = watchDeliveryFrames(page)
  i18n.defer(async () => log.stop())
  const actions = countSentFrames(page, '"action":"hilos_i18n_country_name_')
  await grantAdminToSelf(expect, page)
  const observer = await context.newPage()
  i18n.defer(() => observer.close())
  const otherLog = watchDeliveryFrames(observer)
  i18n.defer(async () => otherLog.stop())
  const otherActions = countSentFrames(
    observer,
    '"action":"hilos_i18n_country_name_',
  )
  for (const tab of [page, observer])
    await gotoPage(expect, tab, PATH, PAGE_READY)
  const live = await watchI18nLivePages(expect, [page, observer])
  let baseCoordinates: Record<string, unknown> = {}
  let correctionCoordinates: Record<string, unknown> = {}

  for (const episode of [
    {
      step: 'ADD base / Keep mine',
      correction: false,
      add: true,
      choice: 'mine',
      mine: 'My base add',
      theirs: 'Their base add',
    },
    {
      step: 'ADD correction / Take theirs',
      correction: true,
      add: true,
      choice: 'theirs',
      mine: 'My correction add',
      theirs: 'Their correction add',
    },
    {
      step: 'EDIT base / Take theirs',
      correction: false,
      add: false,
      choice: 'theirs',
      mine: 'My base edit',
      theirs: 'Their base edit',
    },
    {
      step: 'EDIT correction / Keep mine',
      correction: true,
      add: false,
      choice: 'mine',
      mine: 'My correction edit',
      theirs: 'Their correction edit',
    },
  ] as const) {
    await test.step(episode.step, async () => {
      if (episode.correction && episode.add) {
        for (const tab of [page, observer])
          await expandI18nNameCorrections(expect, tab, 'country', 'qk')
      }
      const key = episode.correction ? 'qk-FR' : 'en'
      const control = `${episode.correction ? 'locale-' : ''}${episode.add ? 'add' : 'edit'}-${key}`
      const edit = `i18n-country-name-${episode.correction ? 'locale-' : ''}edit-${key}`
      const title = `${episode.add ? 'Add' : 'Edit'} ${episode.correction ? 'locale correction' : 'name'} · ${episode.correction ? (episode.add ? 'My base add' : 'My base edit') : episode.add ? 'QJ' : 'My base add'}`
      // Keyboard open and clean Escape preserve the opening control and its accessible name.
      const trigger = shownByTestId(observer, `i18n-country-name-${control}`)
      await dismissToasts(observer)
      await expect(trigger).toHaveAccessibleName(
        `${episode.add ? 'Add' : 'Edit'} ${episode.correction ? 'locale correction' : 'name'} · ${episode.correction ? (episode.add ? 'My base add' : 'My base edit') : episode.add ? 'QJ' : 'My base add'} in ${episode.correction ? 'HIL-1501 writing · qk-FR' : 'English'}`,
      )
      await trigger.focus()
      await trigger.press('Enter')
      await expect(observer.getByTestId(INPUT)).toBeFocused()
      await expect(observer.getByTestId('modal')).toHaveAccessibleName(title)
      await expect(observer.getByTestId('modal')).toHaveAttribute(
        'aria-modal',
        'true',
      )
      await observer.getByTestId(INPUT).press('Escape')
      await expect(observer.getByTestId(WINDOW)).toHaveCount(0)
      await expect(trigger).toBeFocused()
      for (const tab of [page, observer])
        await openI18nName(expect, tab, 'country', control)
      await typeInto(observer.getByTestId(INPUT), episode.mine)
      await typeInto(page.getByTestId(INPUT), episode.theirs)
      const rooms = await Promise.all([
        watchHeight(observer.getByTestId('i18n-country-name-error')),
        watchHeight(observer.getByTestId(`${NOTICE}-slot`)),
        watchHeight(observer.getByTestId(WINDOW)),
        watchTop(observer.getByTestId(SUBMIT)),
      ])
      const before = otherActions()
      const sent = await saveI18nName(
        expect,
        page,
        'country',
        log,
        episode.add ? 'add' : 'update',
        episode.correction
          ? `Correction qk-FR of the name of ${episode.add ? 'My base add' : 'My base edit'} (qj) ${episode.add ? 'added' : 'saved'}.`
          : `Name of ${episode.theirs} (qj) in English (en) ${episode.add ? 'added' : 'saved'}.`,
      )
      expect(sent).toMatchObject({
        name: episode.theirs,
        localeId: episode.correction ? expect.any(Number) : null,
      })
      if (episode.add) {
        expect(sent.baseNameId).toEqual(
          episode.correction ? expect.any(Number) : null,
        )
        if (episode.correction) correctionCoordinates = sent
        else baseCoordinates = sent
      } else {
        const original = episode.correction
          ? correctionCoordinates
          : baseCoordinates
        expect(sent).toMatchObject({
          countryId: original.countryId,
          inLanguageId: original.inLanguageId,
          nameId: expect.any(Number),
          expectedName: episode.correction
            ? 'Their correction add'
            : 'My base add',
        })
      }
      await expectI18nName(expect, observer, 'country', key, episode.theirs)
      await expect(observer.getByTestId(INPUT)).toHaveValue(episode.mine)
      await expect(observer.getByTestId(NOTICE)).toContainText(
        `Name changed elsewhere to "${episode.theirs}".`,
      )
      await expect(observer.getByTestId('i18n-country-name-title')).toHaveText(
        `${title}conflict`,
      )
      await expect(observer.getByTestId('modal')).toHaveAccessibleName(title)
      await expect(observer.getByTestId(SUBMIT)).toHaveText('Save')
      await expect(observer.getByTestId(SUBMIT)).toBeDisabled()
      await expect(observer.getByTestId('hilos-action-error')).toHaveCount(0)
      await expect(observer.getByTestId('conflict-choices')).toBeVisible()
      await expect(observer.getByTestId('conflict-merge')).toHaveCount(0)
      for (const room of rooms) await room.unchanged()
      await observer.getByTestId(INPUT).press('Enter')
      await observer
        .getByTestId(SUBMIT)
        .evaluate((button: HTMLButtonElement) => button.click())
      await expect(observer.getByTestId(WINDOW)).toBeVisible()
      expect(otherActions()).toBe(before)
      await clickSubmit(
        expect,
        observer.getByTestId(`conflict-accept-${episode.choice}`),
      )
      await expect(observer.getByTestId('conflict-choices')).toHaveCount(0)
      await expect(observer.getByTestId('i18n-country-name-title')).toHaveText(
        title,
      )
      for (const room of rooms) await room.unchanged()
      expect(otherActions()).toBe(before)
      await expectI18nName(expect, page, 'country', key, episode.theirs)
      if (episode.choice === 'theirs') {
        await expect(observer.getByTestId(INPUT)).toHaveValue(episode.theirs)
        await expect(observer.getByTestId(SUBMIT)).toBeDisabled()
        if (episode.add) {
          await cancelI18nName(expect, observer, 'country')
        } else await typeInto(observer.getByTestId(INPUT), episode.mine)
      } else await expect(observer.getByTestId(INPUT)).toHaveValue(episode.mine)
      if (episode.choice === 'mine' || !episode.add) {
        const saved = await saveI18nName(
          expect,
          observer,
          'country',
          otherLog,
          'update',
          episode.correction
            ? 'Correction qk-FR of the name of My base edit (qj) saved.'
            : `Name of ${episode.mine} (qj) in English (en) saved.`,
        )
        expect(saved).toEqual({
          countryId: sent.countryId,
          inLanguageId: sent.inLanguageId,
          localeId: sent.localeId,
          nameId: expect.any(Number),
          name: episode.mine,
          expectedName: episode.theirs,
        })
        expect(otherActions()).toBe(before + 1)
      } else expect(otherActions()).toBe(before)
      for (const tab of [page, observer])
        await expectI18nName(
          expect,
          tab,
          'country',
          key,
          episode.choice === 'theirs' && episode.add
            ? episode.theirs
            : episode.mine,
        )
      await expect(shownByTestId(observer, edit)).toBeFocused()
      await live.unchanged()
    })
  }

  await test.step('Accept one untouched incoming edit without a conflict or mutation; dirty Escape still confirms', async () => {
    for (const tab of [page, observer])
      await openI18nName(expect, tab, 'country', 'locale-edit-qk-FR')
    const before = otherActions()
    await typeInto(page.getByTestId(INPUT), 'No-conflict incoming')
    const sent = await saveI18nName(
      expect,
      page,
      'country',
      log,
      'update',
      'Correction qk-FR of the name of My base edit (qj) saved.',
    )
    expect(sent.expectedName).toBe('My correction edit')
    await expect(observer.getByTestId(INPUT)).toHaveValue(
      'No-conflict incoming',
    )
    await expect(observer.getByTestId(NOTICE)).toHaveText('Updated just now')
    await expect(observer.getByTestId('conflict-choices')).toHaveCount(0)
    await expect(observer.getByTestId(SUBMIT)).toBeDisabled()
    await observer.getByTestId(INPUT).press('Enter')
    await expect(observer.getByTestId(WINDOW)).toBeVisible()
    expect(otherActions()).toBe(before)
    await typeInto(observer.getByTestId(INPUT), 'Canceled dirty draft')
    await observer.getByTestId(INPUT).press('Escape')
    await expect(observer.getByTestId('modal-confirm-discard')).toBeVisible()
    await clickSubmit(expect, observer.getByTestId('modal-confirm-discard'))
    await expect(observer.getByTestId(WINDOW)).toHaveCount(0)
    await expect(
      shownByTestId(observer, 'i18n-country-name-locale-edit-qk-FR'),
    ).toBeFocused()
    expect(otherActions()).toBe(before)
    for (const tab of [page, observer])
      await expectI18nName(
        expect,
        tab,
        'country',
        'qk-FR',
        'No-conflict incoming',
      )
    await live.unchanged()
    expect(actions()).toBe(5)
    expect(otherActions()).toBe(3)
  })
})

test('country-names.anonymous-view', async ({ page, i18n }) => {
  // All four forms retain local draft/cancel behavior under the real anonymous view-mode gate.
  test.slow()
  await i18n.countryNames()
  i18n.defer(() => setAdminViewMode(false))
  await setAdminViewMode(true)
  const actions = countSentFrames(page, '"type":"action"')
  await gotoPage(expect, page, PATH, PAGE_READY)
  await expect(page.getByTestId('view-mode-banner')).toBeVisible()
  await expandI18nNameCorrections(expect, page, 'country', 'qk')
  for (const [control, original] of [
    ['add-en', ''],
    ['edit-qk', 'Fixture base'],
    ['locale-add-qk-FR', ''],
    ['locale-edit-qk-US', ''],
  ]) {
    await test.step(`Draft and cancel ${control} without sending a mutation`, async () => {
      await openI18nName(expect, page, 'country', control!)
      await expect(page.getByTestId(INPUT)).toHaveValue(original!)
      await expect(page.getByTestId(INPUT)).toBeEnabled()
      await typeInto(page.getByTestId(INPUT), 'Viewer draft')
      await expect(page.getByTestId(SUBMIT)).toBeDisabled()
      await expect(page.getByTestId(SUBMIT)).toHaveAttribute(
        'aria-describedby',
        /view-mode/,
      )
      await expect(page.getByTestId(SUBMIT)).toHaveAccessibleDescription(
        'View mode · You can look around, but not change anything.',
      )
      await page.getByTestId(INPUT).press('Enter')
      await page
        .getByTestId(SUBMIT)
        .evaluate((button: HTMLButtonElement) => button.click())
      await expect(page.getByTestId(WINDOW)).toBeVisible()
      expect(actions()).toBe(0)
      await cancelI18nName(expect, page, 'country')
      await expect(
        shownByTestId(page, `i18n-country-name-${control}`),
      ).toBeFocused()
      expect(actions()).toBe(0)
      await openI18nName(expect, page, 'country', control!)
      await expect(page.getByTestId(INPUT)).toHaveValue(original!)
      await cancelI18nName(expect, page, 'country')
    })
  }
  await test.step('Leave every saved name, inherited correction and the anonymous head unchanged', async () => {
    await expect(page.getByTestId('hilos-page-head-title')).toHaveText(
      'Unnamed country',
    )
    await expect(shownByTestId(page, 'i18n-names-none-en')).toHaveText(
      'No name',
    )
    await expectI18nName(expect, page, 'country', 'qk', 'Fixture base')
    await expectI18nName(expect, page, 'country', 'qk-GB', 'Fixture correction')
    await expectI18nName(expect, page, 'country', 'qk-US', '')
    await expect(shownByTestId(page, 'i18n-names-inherit-qk-FR')).toHaveText(
      'as in “HIL-1501 writing”',
    )
    expect(actions()).toBe(0)
  })
})
