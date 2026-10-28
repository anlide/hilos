import { expect, test as base } from '@playwright/test'
import {
  cancelI18nName,
  clickSubmit,
  clickUnavailableControl,
  confirmI18nNameDelete,
  countSentFrames,
  createSocketFrameReader,
  deliveryObject,
  dismissToasts,
  expandI18nNameCorrections,
  expectI18nName,
  expectLanguagePageHead,
  expectNoOtherLanguageNames,
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
import {
  i18nFixtures,
  type I18nFixtureScope,
} from '../../../../../framework/frontend/scripts/i18nFixtures.mjs'

const test = base.extend<{ i18n: I18nFixtureScope }>({ i18n: i18nFixtures })
const PATH = '/hilos/i18n/languages/qw/names'
const WINDOW = 'i18n-language-name-window'
const INPUT = 'i18n-language-name-input'
const SUBMIT = 'i18n-language-name-submit'
const NOTICE = 'i18n-language-name-notice'
const DELETE = 'i18n-language-name-delete-window'
const CONFIRM = 'i18n-language-name-delete-confirm'
const CANCEL_DELETE = 'i18n-language-name-delete-cancel'
const BASE_ADDED =
  'Name of HIL-1490 writing (qw) in HIL-1490 target (qv) added.'
const BASE_SAVED =
  'Name of HIL-1490 writing (qw) in HIL-1490 target (qv) saved.'
const BASE_DELETED =
  'Name of HIL-1490 writing (qw) in HIL-1490 target (qv) deleted.'
const CORRECTION_ADDED =
  'Correction qv-GB of the name of HIL-1490 writing (qw) added.'
const CORRECTION_SAVED =
  'Correction qv-GB of the name of HIL-1490 writing (qw) saved.'

test('language-names.life', async ({ page, context, i18n }) => {
  // Literal edits, two live names pages and both deletion branches share one administrator.
  const actions = countSentFrames(page, '"action":"hilos_i18n_language_name_')
  const log = watchDeliveryFrames(page)
  i18n.defer(async () => log.stop())
  await grantAdminToSelf(expect, page)

  await test.step('Read the empty default-language carrier, its hint and native Overview/Names navigation', async () => {
    const before = actions()
    await gotoPage(expect, page, '/hilos/i18n/languages/en/names', PAGE_READY)
    await expectNoOtherLanguageNames(expect, page)
    await expect(
      page.locator('table').getByTestId('hilos-table-empty-hint'),
    ).toHaveText(
      'A name in another language can be written once that language is added.',
    )
    const table = page.locator('table')
    await expect(table).toHaveAccessibleName('English')
    await expect(table.locator('button, input')).toHaveCount(0)
    await expectLanguagePageHead(expect, page, {
      code: 'en',
      nativeName: 'English',
      currentPage: 'hilos_i18n_language_names',
      appName: 'Hilos Polls',
    })
    await clickSubmit(
      expect,
      page.getByTestId('hilos-page-tab-hilos_i18n_language'),
    )
    await expectPageReady(expect, page)
    await expect(page).toHaveURL(/\/languages\/en$/)
    await expect(page.getByTestId('language-card-main')).toBeVisible()
    await clickSubmit(
      expect,
      page.getByTestId('hilos-page-tab-hilos_i18n_language_names'),
    )
    await expectPageReady(expect, page)
    await expectNoOtherLanguageNames(expect, page)
    expect(actions()).toBe(before)
  })

  await i18n.languageNames()
  const observer = await context.newPage()
  i18n.defer(() => observer.close())
  const otherLog = watchDeliveryFrames(observer)
  i18n.defer(async () => otherLog.stop())
  for (const tab of [page, observer]) {
    await gotoPage(expect, tab, PATH, PAGE_READY)
    await expect(shownByTestId(tab, 'i18n-names-none-qv')).toHaveText('No name')
    await expect(
      tab.getByTestId('i18n-language-name-locale-add-qv-GB'),
    ).toHaveCount(0)
  }
  const live = await watchI18nLivePages(expect, [page, observer])

  await test.step('UI-add a stored empty base; reopen and preserve spaces in base and correction edits', async () => {
    await openI18nName(expect, page, 'language', 'add-qv')
    await expect(page.getByTestId(INPUT)).toHaveValue('')
    const added = await saveI18nName(
      expect,
      page,
      'language',
      log,
      'add',
      BASE_ADDED,
    )
    expect(added).toEqual({
      languageId: expect.any(Number),
      inLanguageId: expect.any(Number),
      localeId: null,
      baseNameId: null,
      name: '',
    })
    expect(added.languageId).not.toBe(added.inLanguageId)
    for (const tab of [page, observer]) {
      await expect(tab.getByTestId('i18n-names-none-qv')).toHaveCount(0)
      await expectI18nName(expect, tab, 'language', 'qv', '')
      await expandI18nNameCorrections(expect, tab, 'language', 'qv')
      await expect(shownByTestId(tab, 'i18n-names-inherit-qv-GB')).toHaveText(
        'as in “HIL-1490 target”',
      )
    }
    const before = actions()
    await openI18nName(expect, page, 'language', 'edit-qv')
    await expect(page.getByTestId(INPUT)).toHaveValue('')
    await expect(page.getByTestId(SUBMIT)).toBeDisabled()
    await page.getByTestId(INPUT).press('Escape')
    await expect(page.getByTestId(WINDOW)).toHaveCount(0)
    await expect(
      shownByTestId(page, 'i18n-language-name-edit-qv'),
    ).toBeFocused()
    expect(actions()).toBe(before)
    await openI18nName(expect, page, 'language', 'locale-add-qv-GB')
    await typeInto(page.getByTestId(INPUT), 'First correction')
    const correction = await saveI18nName(
      expect,
      page,
      'language',
      log,
      'add',
      CORRECTION_ADDED,
    )
    expect(correction).toMatchObject({
      languageId: added.languageId,
      inLanguageId: added.inLanguageId,
      baseNameId: expect.any(Number),
      localeId: expect.any(Number),
      name: 'First correction',
    })
    for (const [control, value, expectedName, message] of [
      ['edit-qv', '  Edited base  ', '', BASE_SAVED],
      [
        'locale-edit-qv-GB',
        '  Edited correction  ',
        'First correction',
        CORRECTION_SAVED,
      ],
      ['locale-edit-qv-GB', '', '  Edited correction  ', CORRECTION_SAVED],
    ]) {
      await openI18nName(expect, page, 'language', control!)
      await typeInto(page.getByTestId(INPUT), value!)
      const sent = await saveI18nName(
        expect,
        page,
        'language',
        log,
        'update',
        message!,
        value === '',
      )
      expect(sent).toMatchObject({ name: value, expectedName })
      for (const tab of [page, observer]) {
        await expectI18nName(
          expect,
          tab,
          'language',
          control!.includes('locale') ? 'qv-GB' : 'qv',
          value!,
        )
        await openI18nName(expect, tab, 'language', control!)
        await expect(tab.getByTestId(INPUT)).toHaveValue(value!)
        await expect(tab.getByTestId(SUBMIT)).toBeDisabled()
        await cancelI18nName(expect, tab, 'language')
      }
      await live.unchanged()
    }
    for (const tab of [page, observer]) {
      await expect(tab.getByTestId('i18n-names-inherit-qv-GB')).toHaveCount(0)
      await expect(shownByTestId(tab, 'i18n-names-delete-qv-GB')).toBeVisible()
    }
  })

  await test.step('Cancel then delete the reverse base and its saved empty correction from both live Names pages', async () => {
    const before = actions()
    await dismissToasts(page)
    const trash = shownByTestId(page, 'i18n-names-delete-qv')
    await clickSubmit(expect, trash)
    await expect(page.getByTestId('modal')).toBeFocused()
    await expect(page.getByTestId(DELETE)).toContainText(
      'All locale corrections of this base name will also be deleted.',
    )
    await clickSubmit(expect, page.getByTestId(CANCEL_DELETE))
    await expect(page.getByTestId(DELETE)).toHaveCount(0)
    await expect(trash).toBeFocused()
    expect(actions()).toBe(before)
    await clickSubmit(expect, trash)
    const removed = await confirmI18nNameDelete(
      expect,
      page,
      'language',
      log,
      false,
      BASE_DELETED,
    )
    expect(removed.expectedName).toBe('  Edited base  ')
    for (const tab of [page, observer]) {
      await expect(shownByTestId(tab, 'i18n-names-none-qv')).toHaveText(
        'No name',
      )
      await expect(tab.getByTestId('i18n-names-correction-qv-GB')).toHaveCount(
        0,
      )
      await expect(tab.getByTestId('hilos-table-apply')).toHaveCount(0)
    }
    await live.unchanged()
  })

  await test.step('Cancel/remove a correction, explain a missing saved-empty name, then cascade the seeded base', async () => {
    for (const tab of [page, observer]) {
      await gotoPage(expect, tab, '/hilos/i18n/languages/qv/names', PAGE_READY)
      await expectI18nName(expect, tab, 'language', 'qw', 'Fixture base')
      await expandI18nNameCorrections(expect, tab, 'language', 'qw')
    }
    const seededLive = await watchI18nLivePages(expect, [page, observer])
    const before = actions()
    const trash = shownByTestId(page, 'i18n-names-delete-qw-GB')
    await expect(trash).toHaveAccessibleName('Remove locale correction qw-GB')
    await clickSubmit(expect, trash)
    await expect(page.getByTestId('modal')).toBeFocused()
    await expect(page.getByTestId(DELETE)).toContainText(
      /Base name to inherit:\s*Fixture base/,
    )
    await page.getByTestId('modal').press('Escape')
    await expect(page.getByTestId(DELETE)).toHaveCount(0)
    await expect(trash).toBeFocused()
    expect(actions()).toBe(before)
    await clickSubmit(expect, trash)
    await confirmI18nNameDelete(
      expect,
      page,
      'language',
      log,
      true,
      'Correction qw-GB of the name of HIL-1490 target (qv) removed.',
    )
    for (const tab of [page, observer]) {
      await expect(shownByTestId(tab, 'i18n-names-inherit-qw-GB')).toHaveText(
        'as in “HIL-1490 writing”',
      )
      await expect(shownByTestId(tab, 'i18n-names-delete-qw-US')).toBeVisible()
    }
    await dismissToasts(page)
    await clickSubmit(expect, shownByTestId(page, 'i18n-names-delete-qw-US'))
    await expect(page.getByTestId(DELETE)).toContainText('Empty value')
    await expect(page.getByTestId(DELETE)).toContainText(
      'A saved empty string, not an absent name.',
    )
    await clickSubmit(
      expect,
      shownByTestId(observer, 'i18n-names-delete-qw-US'),
    )
    const empty = await confirmI18nNameDelete(
      expect,
      observer,
      'language',
      otherLog,
      true,
      'Correction qw-US of the name of HIL-1490 target (qv) removed.',
    )
    expect(empty.expectedName).toBe('')
    await expect(page.getByTestId(CONFIRM)).toBeDisabled()
    const notice = 'i18n-language-name-delete-notice'
    await expect(page.getByTestId(notice)).toContainText(
      'The name no longer exists.',
    )
    await expect(page.getByTestId(`${notice}-slot`)).toHaveAttribute(
      'aria-live',
      'polite',
    )
    const noWrite = actions()
    await page.getByTestId('modal').press('Enter')
    await expect(page.getByTestId(DELETE)).toBeVisible()
    await clickSubmit(expect, page.getByTestId(`${notice}-details`))
    await expect(page.getByTestId(`${notice}-full`)).toContainText(
      'The name no longer exists.',
    )
    await clickSubmit(expect, page.getByTestId(`${notice}-close`))
    await expect(page.getByTestId(`${notice}-details`)).toBeFocused()
    await clickSubmit(expect, page.getByTestId(CANCEL_DELETE))
    expect(actions()).toBe(noWrite)
    for (const tab of [page, observer])
      await expect(shownByTestId(tab, 'i18n-names-inherit-qw-US')).toHaveText(
        'as in “HIL-1490 writing”',
      )
    await seededLive.unchanged()
    await dismissToasts(page)
    const baseTrash = shownByTestId(page, 'i18n-names-delete-qw')
    await expect(baseTrash).toHaveAccessibleName(
      'Delete language name in HIL-1490 writing',
    )
    await clickSubmit(expect, shownByTestId(observer, 'i18n-names-delete-qw'))
    await clickSubmit(expect, baseTrash)
    await confirmI18nNameDelete(
      expect,
      page,
      'language',
      log,
      false,
      'Name of HIL-1490 target (qv) in HIL-1490 writing (qw) deleted.',
    )
    for (const tab of [page, observer]) {
      await expect(shownByTestId(tab, 'i18n-names-none-qw')).toHaveText(
        'No name',
      )
      await expect(i18nNameRow(tab, 'language', 'qw')).toBeVisible()
      for (const country of ['GB', 'US'])
        await expect(
          tab.getByTestId(`i18n-names-correction-qw-${country}`),
        ).toHaveCount(0)
      await expect(tab.getByTestId('hilos-table-apply')).toHaveCount(0)
    }
    await expect(observer.getByTestId(DELETE)).toBeVisible()
    await expect(observer.getByTestId(CONFIRM)).toBeDisabled()
    await expect(
      observer.getByTestId('i18n-language-name-delete-notice'),
    ).toContainText('The name no longer exists.')
    await clickSubmit(expect, observer.getByTestId(CANCEL_DELETE))
    await seededLive.unchanged()
  })
})

test('language-names.live-conflict', async ({ page, context, i18n }) => {
  // Observed 29.8s at scale 1; conflict choices, no-conflict delivery and stale-ID refusal approach the base cap.
  test.slow()
  await i18n.languageNames()
  const log = watchDeliveryFrames(page)
  i18n.defer(async () => log.stop())
  const actions = countSentFrames(page, '"action":"hilos_i18n_language_name_')
  await grantAdminToSelf(expect, page)
  const observer = await context.newPage()
  i18n.defer(() => observer.close())
  const otherLog = watchDeliveryFrames(observer)
  i18n.defer(async () => otherLog.stop())
  const otherActions = countSentFrames(
    observer,
    '"action":"hilos_i18n_language_name_',
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
          await expandI18nNameCorrections(expect, tab, 'language', 'qv')
      }
      const key = episode.correction ? 'qv-GB' : 'qv'
      const control = `${episode.correction ? 'locale-' : ''}${episode.add ? 'add' : 'edit'}-${key}`
      const edit = `i18n-language-name-${episode.correction ? 'locale-' : ''}edit-${key}`
      const title = `${episode.add ? 'Add' : 'Edit'} ${episode.correction ? 'locale correction' : 'name'} · HIL-1490 writing`
      // Keyboard open and clean Escape preserve the opening control and its accessible name.
      const trigger = shownByTestId(observer, `i18n-language-name-${control}`)
      await dismissToasts(observer)
      await expect(trigger).toHaveAccessibleName(
        `${episode.add ? 'Add' : 'Edit'} ${episode.correction ? 'locale correction' : 'name'} · HIL-1490 writing in HIL-1490 target${episode.correction ? ' · qv-GB' : ''}`,
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
        await openI18nName(expect, tab, 'language', control)
      await typeInto(observer.getByTestId(INPUT), episode.mine)
      await typeInto(page.getByTestId(INPUT), episode.theirs)
      const rooms = await Promise.all([
        watchHeight(observer.getByTestId('i18n-language-name-error')),
        watchHeight(observer.getByTestId(`${NOTICE}-slot`)),
        watchHeight(observer.getByTestId(WINDOW)),
        watchTop(observer.getByTestId(SUBMIT)),
      ])
      const before = otherActions()
      const sent = await saveI18nName(
        expect,
        page,
        'language',
        log,
        episode.add ? 'add' : 'update',
        episode.add
          ? episode.correction
            ? CORRECTION_ADDED
            : BASE_ADDED
          : episode.correction
            ? CORRECTION_SAVED
            : BASE_SAVED,
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
          languageId: original.languageId,
          inLanguageId: original.inLanguageId,
          nameId: expect.any(Number),
          expectedName: episode.correction
            ? 'Their correction add'
            : 'My base add',
        })
      }
      await expectI18nName(expect, observer, 'language', key, episode.theirs)
      await expect(observer.getByTestId(INPUT)).toHaveValue(episode.mine)
      await expect(observer.getByTestId(NOTICE)).toContainText(
        `Name changed elsewhere to "${episode.theirs}".`,
      )
      await expect(observer.getByTestId('i18n-language-name-title')).toHaveText(
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
      await clickUnavailableControl(observer, observer.getByTestId(SUBMIT))
      await expect(observer.getByTestId(WINDOW)).toBeVisible()
      expect(otherActions()).toBe(before)
      await clickSubmit(
        expect,
        observer.getByTestId(`conflict-accept-${episode.choice}`),
      )
      await expect(observer.getByTestId('conflict-choices')).toHaveCount(0)
      await expect(observer.getByTestId('i18n-language-name-title')).toHaveText(
        title,
      )
      for (const room of rooms) await room.unchanged()
      expect(otherActions()).toBe(before)
      await expectI18nName(expect, page, 'language', key, episode.theirs)
      if (episode.choice === 'theirs') {
        await expect(observer.getByTestId(INPUT)).toHaveValue(episode.theirs)
        await expect(observer.getByTestId(SUBMIT)).toBeDisabled()
        if (episode.add) {
          await cancelI18nName(expect, observer, 'language')
        } else await typeInto(observer.getByTestId(INPUT), episode.mine)
      } else await expect(observer.getByTestId(INPUT)).toHaveValue(episode.mine)
      if (episode.choice === 'mine' || !episode.add) {
        const saved = await saveI18nName(
          expect,
          observer,
          'language',
          otherLog,
          'update',
          episode.correction ? CORRECTION_SAVED : BASE_SAVED,
        )
        expect(saved).toEqual({
          languageId: sent.languageId,
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
          'language',
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
      await openI18nName(expect, tab, 'language', 'locale-edit-qv-GB')
    const before = otherActions()
    await typeInto(page.getByTestId(INPUT), 'No-conflict incoming')
    const sent = await saveI18nName(
      expect,
      page,
      'language',
      log,
      'update',
      CORRECTION_SAVED,
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
      shownByTestId(observer, 'i18n-language-name-locale-edit-qv-GB'),
    ).toBeFocused()
    expect(otherActions()).toBe(before)
    for (const tab of [page, observer])
      await expectI18nName(
        expect,
        tab,
        'language',
        'qv-GB',
        'No-conflict incoming',
      )
    await live.unchanged()
    expect(actions()).toBe(5)
    expect(otherActions()).toBe(3)
  })
  await test.step('Send a captured old name ID after deleting and recreating identical text; retry only by an explicit new Save', async () => {
    await openI18nName(expect, page, 'language', 'edit-qv')
    await typeInto(page.getByTestId(INPUT), 'Same text')
    await saveI18nName(expect, page, 'language', log, 'update', BASE_SAVED)
    await expectI18nName(expect, observer, 'language', 'qv', 'Same text')
    const writes: Array<{
      requestId: string
      action: string
      data: Record<string, unknown>
      forwarded: Record<string, unknown>
    }> = []
    const replies: Record<string, unknown>[] = []
    let staleNameId: number | null = null
    await page.routeWebSocket(/.*/, (socket) => {
      const server = socket.connectToServer()
      const read = createSocketFrameReader(1)
      server.onMessage((message) => {
        for (const frame of read(message))
          replies.push(deliveryObject(frame.wire))
        socket.send(message)
      })
      socket.onMessage((message) => {
        if (typeof message !== 'string') {
          server.send(message)
          return
        }
        const frame = JSON.parse(message)
        if (
          frame.type === 'action' &&
          frame.action.startsWith('hilos_i18n_language_name_')
        ) {
          const original = { ...frame.data }
          if (
            frame.action === 'hilos_i18n_language_name_update' &&
            staleNameId !== null
          )
            frame.data.nameId = staleNameId
          writes.push({
            requestId: frame.requestId,
            action: frame.action,
            data: original,
            forwarded: { ...frame.data },
          })
          server.send(JSON.stringify(frame))
        } else server.send(message)
      })
    })
    // The proxy hooks the next real connection; all earlier fanout checks used the existing sockets.
    await page.reload()
    await expectPageReady(expect, page)
    await dismissToasts(page)
    await clickSubmit(expect, shownByTestId(page, 'i18n-names-delete-qv'))
    await clickSubmit(expect, page.getByTestId(CONFIRM))
    await expect(page.getByTestId(DELETE)).toHaveCount(0)
    await expect(shownByTestId(page, 'i18n-language-name-add-qv')).toBeVisible()
    expect(writes).toHaveLength(1)
    staleNameId = writes[0]!.data.nameId as number
    expect(Number.isInteger(staleNameId)).toBe(true)
    expect(staleNameId).toBeGreaterThan(0)
    await openI18nName(expect, page, 'language', 'add-qv')
    await typeInto(page.getByTestId(INPUT), 'Same text')
    await clickSubmit(expect, page.getByTestId(SUBMIT))
    await expect(page.getByTestId(WINDOW)).toHaveCount(0)
    await expectI18nName(expect, observer, 'language', 'qv', 'Same text')
    expect(writes).toHaveLength(2)
    expect(writes[1]!.data).toEqual({
      languageId: baseCoordinates.languageId,
      inLanguageId: baseCoordinates.inLanguageId,
      localeId: null,
      baseNameId: null,
      name: 'Same text',
    })
    await openI18nName(expect, page, 'language', 'edit-qv')
    await typeInto(page.getByTestId(INPUT), 'New text')
    const errorRoom = await watchHeight(
      page.getByTestId('i18n-language-name-error'),
    )
    const bodyRoom = await watchHeight(page.getByTestId(WINDOW))
    await clickSubmit(expect, page.getByTestId(SUBMIT))
    await expect(page.getByTestId('i18n-language-name-error')).toContainText(
      'The name no longer exists.',
    )
    await expect(page.getByTestId(SUBMIT)).toBeEnabled()
    await expect(page.getByTestId(INPUT)).toHaveValue('New text')
    await errorRoom.unchanged()
    await bodyRoom.unchanged()
    expect(writes).toHaveLength(3)
    expect(writes[2]!.data).toEqual({
      languageId: baseCoordinates.languageId,
      inLanguageId: baseCoordinates.inLanguageId,
      localeId: null,
      nameId: expect.any(Number),
      name: 'New text',
      expectedName: 'Same text',
    })
    expect(writes[2]!.data.nameId).not.toBe(staleNameId)
    expect(writes[2]!.forwarded).toEqual({
      ...writes[2]!.data,
      nameId: staleNameId,
    })
    const refused = replies.filter(
      (reply) =>
        reply.requestId === writes[2]!.requestId &&
        deliveryObject(reply.data).action === writes[2]!.action,
    )
    expect(refused).toHaveLength(1)
    expect(refused[0]).toMatchObject({
      type: 'action_error',
      outcome: 'fail',
      data: { reason: 'The name no longer exists.' },
    })
    await expect(page.getByTestId('hilos-action-error-slot')).toHaveAttribute(
      'aria-live',
      'assertive',
    )
    await clickSubmit(expect, page.getByTestId('hilos-action-error-details'))
    await expect(page.getByTestId('hilos-action-error-full')).toContainText(
      'The name no longer exists.',
    )
    await clickSubmit(expect, page.getByTestId('hilos-action-error-close'))
    await expect(page.getByTestId('hilos-action-error-details')).toBeFocused()
    for (const tab of [page, observer])
      await expectI18nName(expect, tab, 'language', 'qv', 'Same text')
    expect(writes).toHaveLength(3)
    staleNameId = null
    await clickSubmit(expect, page.getByTestId(SUBMIT))
    await expect(page.getByTestId(WINDOW)).toHaveCount(0)
    for (const tab of [page, observer])
      await expectI18nName(expect, tab, 'language', 'qv', 'New text')
    expect(writes).toHaveLength(4)
    expect(writes[3]!.data).toEqual(writes[2]!.data)
    expect(writes[3]!.forwarded).toEqual(writes[3]!.data)
    const accepted = replies.filter(
      (reply) =>
        reply.requestId === writes[3]!.requestId &&
        deliveryObject(reply.data).action === writes[3]!.action,
    )
    expect(accepted).toHaveLength(1)
    expect(accepted[0]).toMatchObject({
      type: 'action_success',
      outcome: 'success',
      data: { message: BASE_SAVED },
    })
  })
})
