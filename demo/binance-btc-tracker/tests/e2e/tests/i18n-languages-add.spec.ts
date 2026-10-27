import { expect, test } from '@playwright/test'
import {
  clickSubmit,
  gotoPage,
  PAGE_READY,
  signUpAdmin,
} from '../../../../../framework/frontend/e2e/index.js'

test('adds a known language switched off from the built-in catalog', async ({
  page,
}) => {
  await signUpAdmin(expect, page)
  await gotoPage(expect, page, '/hilos/i18n/languages', PAGE_READY)

  const opener = page.getByTestId('hilos-table-main-action')
  await clickSubmit(expect, opener)

  const code = page.getByTestId('i18n-language-add-code')
  await expect(code).toHaveValue('')
  await code.fill('')
  await code.pressSequentially('pt', { delay: 10 })
  await expect(page.getByTestId('i18n-language-add-native-name')).toHaveValue(
    'Português',
  )
  const plate = page.getByTestId('i18n-language-add-plate')
  await expect(plate).toBeVisible()
  await expect(plate).toHaveAttribute('data-kind', 'known')

  const submit = page.getByTestId('i18n-language-add-submit')
  await clickSubmit(expect, submit)
  await expect(submit).toHaveCount(0)

  await expect(page.getByTestId('hilos-toast-success')).toContainText(
    'Language Português (pt) added and switched off for now.',
  )
  const row = page
    .getByTestId(/^hilos-table-row-/)
    .filter({ has: page.getByTestId('i18n-languages-link-pt') })
  await expect(row).toBeVisible()
  await expect(row.getByTestId('i18n-languages-enabled-pt')).not.toBeChecked()
})

test('adds a custom language switched off after confirmation', async ({
  page,
}) => {
  await signUpAdmin(expect, page)
  await gotoPage(expect, page, '/hilos/i18n/languages', PAGE_READY)

  const opener = page.getByTestId('hilos-table-main-action')
  await clickSubmit(expect, opener)

  const code = page.getByTestId('i18n-language-add-code')
  await expect(code).toHaveValue('')
  await code.fill('')
  await code.pressSequentially('cy', { delay: 10 })
  const plate = page.getByTestId('i18n-language-add-plate')
  await expect(plate).toBeVisible()
  await expect(plate).toHaveAttribute('data-kind', 'own')
  const nativeName = page.getByTestId('i18n-language-add-native-name')
  await expect(nativeName).toHaveValue('')
  await nativeName.fill('')
  await nativeName.pressSequentially('Cymraeg', { delay: 10 })

  const submit = page.getByTestId('i18n-language-add-submit')
  await clickSubmit(expect, submit)
  await expect(submit).toHaveCount(0)

  await expect(page.getByTestId('i18n-language-confirm')).toBeVisible()
  await expect(page.getByTestId('modal')).toHaveAttribute(
    'aria-label',
    'The system does not know this language',
  )
  const confirmCode = page.getByTestId('i18n-language-confirm-code')
  await expect(confirmCode).toHaveText('cy')
  await expect(confirmCode).toHaveCSS('text-transform', 'uppercase')

  const confirm = page.getByTestId('i18n-language-confirm-submit')
  await clickSubmit(expect, confirm)
  await expect(confirm).toHaveCount(0)

  await expect(page.getByTestId('hilos-toast-success')).toContainText(
    'Custom language Cymraeg (cy) added and switched off for now.',
  )
  const row = page
    .getByTestId(/^hilos-table-row-/)
    .filter({ has: page.getByTestId('i18n-languages-link-cy') })
  await expect(row).toBeVisible()
  await expect(row.getByTestId('i18n-languages-own-cy')).toBeVisible()
})

test('keeps the add form open when the language already exists', async ({
  page,
}) => {
  await signUpAdmin(expect, page)
  await gotoPage(expect, page, '/hilos/i18n/languages', PAGE_READY)

  const opener = page.getByTestId('hilos-table-main-action')
  await clickSubmit(expect, opener)

  const code = page.getByTestId('i18n-language-add-code')
  await expect(code).toHaveValue('')
  await code.fill('')
  await code.pressSequentially('en', { delay: 10 })
  await expect(page.getByTestId('i18n-language-add-native-name')).toHaveValue(
    'English',
  )

  const submit = page.getByTestId('i18n-language-add-submit')
  await clickSubmit(expect, submit)
  await expect(page.getByTestId('i18n-language-add-error')).toContainText(
    "Language 'en' already exists.",
  )
  await expect(submit).toBeEnabled()
  await expect(submit).toBeVisible()
})
