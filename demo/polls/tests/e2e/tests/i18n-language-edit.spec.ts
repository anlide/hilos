import { expect, test } from '@playwright/test'
import { shownByTestId } from '../../../../../framework/frontend/e2e/index.js'
import { signUpAdmin } from '../helpers/adminGrant.js'
import {
  deleteLanguage,
  switchLanguageOn,
  switchLanguageOff,
} from '../helpers/i18n.js'
import { gotoPage, PAGE_READY } from '../helpers/page.js'
import { clickSubmit } from '../helpers/session.js'

test('edits a switched-off language from its card and from the languages table', async ({
  page,
}) => {
  // Card and list saves plus deletion/recreation and a stale-ID refusal cross several owner writes.
  test.slow()
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
  await signUpAdmin(page)
  try {
    await switchLanguageOn('uk')
    await gotoPage(page, '/hilos/i18n/languages/uk', PAGE_READY)

    const switchOff = page.getByTestId('language-card-switch-off')
    await clickSubmit(switchOff)

    const confirm = page.getByTestId('language-card-switch-off-confirm')
    await clickSubmit(confirm)
    await expect(confirm).toHaveCount(0)
    await expect(page.getByTestId('language-card-enabled')).toHaveText(
      'Disabled',
    )
    await expect(page.getByTestId('language-card-view')).toHaveCount(0)

    const cardEdit = page.getByTestId('language-card-edit')
    await clickSubmit(cardEdit)
    await expect(page.getByTestId('i18n-language-edit-title')).toContainText(
      'Edit language · ',
    )
    await expect(page.getByTestId('i18n-language-edit-code')).toBeDisabled()
    await expect(page.getByTestId('i18n-language-edit-code')).toHaveValue('uk')
    await expect(page.getByTestId('i18n-language-edit-plate')).toHaveAttribute(
      'data-kind',
      'known',
    )

    const cardName = `Українська ${Date.now()}`
    const nativeName = page.getByTestId('i18n-language-edit-native-name')
    await nativeName.fill('')
    await nativeName.pressSequentially(cardName, { delay: 10 })

    const save = page.getByTestId('i18n-language-edit-submit')
    await clickSubmit(save)
    await expect(page.getByTestId('i18n-language-edit-window')).toHaveCount(0)
    await expect(
      page
        .getByTestId('hilos-toast-success')
        .filter({ hasText: 'Language saved.' }),
    ).toBeVisible()
    await expect(page.getByTestId('language-card-name')).toHaveText(cardName)
    await expect(page.getByTestId('language-card-native-name')).toHaveText(
      cardName,
    )

    await gotoPage(page, '/hilos/i18n/languages', PAGE_READY)
    const row = page
      .getByTestId(/^hilos-table-row-/)
      .filter({ has: page.getByTestId('i18n-languages-link-uk') })
    await expect(row).toContainText(cardName)
    await expect(page.getByTestId('i18n-languages-view-uk')).toHaveCount(0)

    const rowEdit = row.getByTestId('i18n-languages-edit-uk')
    await clickSubmit(rowEdit)
    await expect(nativeName).toHaveValue(cardName)

    const tableName = `${cardName} · 2`
    await nativeName.fill('')
    await nativeName.pressSequentially(tableName, { delay: 10 })
    await clickSubmit(save)
    await expect(page.getByTestId('i18n-language-edit-window')).toHaveCount(0)
    await expect(
      page
        .getByTestId('hilos-toast-success')
        .filter({ hasText: 'Language saved.' }),
    ).toBeVisible()
    await expect(row).toContainText(tableName)
    expect(writes).toHaveLength(2)
    expect(Number.isInteger(writes[0].languageId)).toBe(true)
    expect(writes[0].languageId).toBeGreaterThan(0)
    expect(writes[0]).toEqual({
      languageId: writes[0].languageId,
      nativeName: cardName,
      rtl: false,
    })
    expect(writes[1]).toEqual({
      languageId: writes[0].languageId,
      nativeName: tableName,
      rtl: false,
    })

    staleId = writes[0].languageId
    await deleteLanguage('uk')
    await switchLanguageOff('uk')
    await gotoPage(page, '/hilos/i18n/languages/uk', PAGE_READY)
    await expect(page.getByTestId('language-card-native-name')).toHaveText(
      'Українська',
    )
    await clickSubmit(cardEdit)
    await nativeName.fill('')
    await nativeName.pressSequentially('Must not reach replacement', {
      delay: 10,
    })
    await clickSubmit(save)
    await expect(page.getByTestId('i18n-language-edit-error')).toContainText(
      `Unknown language: ${staleId}`,
    )
    await expect(save).toBeEnabled()
    expect(writes).toHaveLength(3)
    expect(writes[2].languageId).not.toBe(staleId)
    await expect(page.getByTestId('language-card-native-name')).toHaveText(
      'Українська',
    )
    await expect(nativeName).toHaveValue('Must not reach replacement')

    staleId = null
    await clickSubmit(save)
    await expect(page.getByTestId('i18n-language-edit-window')).toHaveCount(0)
    await expect(page.getByTestId('language-card-native-name')).toHaveText(
      'Must not reach replacement',
    )
    expect(writes).toHaveLength(4)
    expect(writes[3].languageId).toBe(writes[2].languageId)
  } finally {
    // This scenario owns uk; leave the following names-table empty-state check intact.
    await deleteLanguage('uk')
    // A live removal waits for Apply; check cleanup against a fresh page snapshot.
    await gotoPage(page, '/hilos/i18n/languages', PAGE_READY)
    await expect(
      page
        .getByTestId(/^hilos-table-row-/)
        .filter({ has: page.getByTestId('i18n-languages-link-uk') }),
    ).toHaveCount(0)
  }
})

test('opens a switched-on language read-only and says how to edit it', async ({
  page,
}) => {
  await signUpAdmin(page)
  await gotoPage(page, '/hilos/i18n/languages', PAGE_READY)
  await expect(page.getByTestId('i18n-languages-edit-en')).toHaveCount(0)

  const rowView = shownByTestId(page, 'i18n-languages-view-en')
  await clickSubmit(rowView)
  await expect(page.getByTestId('i18n-language-edit-title')).toHaveText(
    'Language · English',
  )
  await expect(
    page.getByTestId('i18n-language-edit-native-name'),
  ).toBeDisabled()
  await expect(page.getByTestId('i18n-language-edit-native-name')).toHaveValue(
    'English',
  )
  await expect(page.getByTestId('i18n-language-edit-rtl')).toBeDisabled()
  await expect(page.getByTestId('i18n-language-edit-plate')).toHaveAttribute(
    'data-kind',
    'on',
  )
  await expect(page.getByTestId('i18n-language-edit-plate')).toContainText(
    'switch it off, edit it and switch it back on',
  )
  await expect(page.getByTestId('i18n-language-edit-submit')).toHaveCount(0)

  const close = page.getByTestId('i18n-language-edit-close')
  await clickSubmit(close)
  await expect(page.getByTestId('i18n-language-edit-window')).toHaveCount(0)

  await gotoPage(page, '/hilos/i18n/languages/en', PAGE_READY)
  await expect(page.getByTestId('language-card-frozen')).toBeVisible()
  const cardView = page.getByTestId('language-card-view')
  await clickSubmit(cardView)
  await expect(page.getByTestId('i18n-language-edit-title')).toHaveText(
    'Language · English',
  )
  await clickSubmit(close)
  await expect(page.getByTestId('i18n-language-edit-window')).toHaveCount(0)
})
