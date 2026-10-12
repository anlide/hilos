import { expect, test } from '@playwright/test'
import { signUpAdmin } from '../helpers/adminGrant.js'
import { deleteLanguage, switchLanguageOn } from '../helpers/i18n.js'
import { gotoPage, PAGE_READY } from '../helpers/page.js'

test('edits a switched-off language from its card and from the languages table', async ({
  page,
}) => {
  await signUpAdmin(page)
  try {
    await switchLanguageOn('uk')
    await gotoPage(page, '/hilos/i18n/languages/uk', PAGE_READY)

    const switchOff = page.getByTestId('language-card-switch-off')
    await switchOff.scrollIntoViewIfNeeded()
    await expect(switchOff).toBeVisible()
    await expect(switchOff).toBeEnabled()
    await switchOff.focus()
    await switchOff.click()

    const confirm = page.getByTestId('language-card-switch-off-confirm')
    await confirm.scrollIntoViewIfNeeded()
    await expect(confirm).toBeVisible()
    await expect(confirm).toBeEnabled()
    await confirm.focus()
    await confirm.click()
    await expect(confirm).toHaveCount(0)
    await expect(page.getByTestId('language-card-enabled')).toHaveText(
      'Disabled',
    )
    await expect(page.getByTestId('language-card-view')).toHaveCount(0)

    const cardEdit = page.getByTestId('language-card-edit')
    await cardEdit.scrollIntoViewIfNeeded()
    await expect(cardEdit).toBeVisible()
    await expect(cardEdit).toBeEnabled()
    await cardEdit.focus()
    await cardEdit.click()
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
    await save.scrollIntoViewIfNeeded()
    await expect(save).toBeVisible()
    await expect(save).toBeEnabled()
    await save.focus()
    await save.click()
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
    const row = page.getByTestId('hilos-table-row-uk')
    await expect(row).toContainText(cardName)
    await expect(page.getByTestId('i18n-languages-view-uk')).toHaveCount(0)

    const rowEdit = row.getByTestId('i18n-languages-edit-uk')
    await rowEdit.scrollIntoViewIfNeeded()
    await expect(rowEdit).toBeVisible()
    await expect(rowEdit).toBeEnabled()
    await rowEdit.focus()
    await rowEdit.click()
    await expect(nativeName).toHaveValue(cardName)

    const tableName = `${cardName} · 2`
    await nativeName.fill('')
    await nativeName.pressSequentially(tableName, { delay: 10 })
    await save.scrollIntoViewIfNeeded()
    await expect(save).toBeVisible()
    await expect(save).toBeEnabled()
    await save.focus()
    await save.click()
    await expect(page.getByTestId('i18n-language-edit-window')).toHaveCount(0)
    await expect(
      page
        .getByTestId('hilos-toast-success')
        .filter({ hasText: 'Language saved.' }),
    ).toBeVisible()
    await expect(row).toContainText(tableName)
  } finally {
    // This scenario owns uk; leave the following names-table empty-state check intact.
    await deleteLanguage('uk')
    // A live removal waits for Apply; check cleanup against a fresh page snapshot.
    await gotoPage(page, '/hilos/i18n/languages', PAGE_READY)
    await expect(page.getByTestId('hilos-table-row-uk')).toHaveCount(0)
  }
})

test('opens a switched-on language read-only and says how to edit it', async ({
  page,
}) => {
  await signUpAdmin(page)
  await gotoPage(page, '/hilos/i18n/languages', PAGE_READY)
  await expect(page.getByTestId('i18n-languages-edit-en')).toHaveCount(0)

  const rowView = page
    .getByTestId('hilos-table-row-en')
    .getByTestId('i18n-languages-view-en')
  await rowView.scrollIntoViewIfNeeded()
  await expect(rowView).toBeVisible()
  await expect(rowView).toBeEnabled()
  await rowView.focus()
  await rowView.click()
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
  await close.scrollIntoViewIfNeeded()
  await expect(close).toBeVisible()
  await expect(close).toBeEnabled()
  await close.focus()
  await close.click()
  await expect(page.getByTestId('i18n-language-edit-window')).toHaveCount(0)

  await gotoPage(page, '/hilos/i18n/languages/en', PAGE_READY)
  await expect(page.getByTestId('language-card-frozen')).toBeVisible()
  const cardView = page.getByTestId('language-card-view')
  await cardView.scrollIntoViewIfNeeded()
  await expect(cardView).toBeVisible()
  await expect(cardView).toBeEnabled()
  await cardView.focus()
  await cardView.click()
  await expect(page.getByTestId('i18n-language-edit-title')).toHaveText(
    'Language · English',
  )
  await close.scrollIntoViewIfNeeded()
  await expect(close).toBeVisible()
  await expect(close).toBeEnabled()
  await close.focus()
  await close.click()
  await expect(page.getByTestId('i18n-language-edit-window')).toHaveCount(0)
})
