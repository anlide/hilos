import { expect, test } from '@playwright/test'
import {
  settleRemovedRow,
  shownByTestId,
} from '../../../../../framework/frontend/e2e/index.js'
import { signUpAdmin } from '../helpers/adminGrant.js'
import {
  deleteLanguage,
  deleteLocale,
  switchLanguageOff,
  switchLocaleOn,
} from '../helpers/i18n.js'
import { gotoPage, PAGE_READY } from '../helpers/page.js'
import { clickSubmit } from '../helpers/session.js'

test('creates a free language and deletes it from the languages table', async ({
  page,
}) => {
  await signUpAdmin(page)
  try {
    await gotoPage(page, '/hilos/i18n/languages', PAGE_READY)
    const add = page.getByTestId('hilos-table-main-action')
    await clickSubmit(add)

    const code = page.getByTestId('i18n-language-add-code')
    await code.fill('')
    await code.pressSequentially('uk', { delay: 10 })
    const create = page.getByTestId('i18n-language-add-submit')
    await clickSubmit(create)
    await expect(create).toHaveCount(0)
    const freshRow = page
      .getByTestId(/^hilos-table-row-/)
      .filter({ has: page.getByTestId('i18n-languages-link-uk') })
    await expect(freshRow).toBeVisible()
    const row = page.getByTestId((await freshRow.getAttribute('data-id'))!)

    const opener = row.getByTestId('i18n-languages-delete-uk')
    await opener.scrollIntoViewIfNeeded()
    await expect(opener).toBeVisible()
    await expect(opener).toBeEnabled()
    await expect(opener).toHaveAccessibleName('Delete language Українська')
    await opener.focus()
    await opener.click()
    await expect(page.getByTestId('i18n-language-delete-window')).toContainText(
      "Deleting it also removes the built-in catalog's country names written in it.",
    )
    const confirm = page.getByTestId('i18n-language-delete-confirm')
    await clickSubmit(confirm)
    await expect(confirm).toHaveCount(0)
    await expect(
      page
        .getByTestId('hilos-toast-success')
        .filter({ hasText: 'Language Українська (uk) deleted.' }),
    ).toBeVisible()
    await expect(page).toHaveURL(/\/hilos\/i18n\/languages$/)
    // Membership uses the table's existing removal gate; acknowledge it if this window owes Apply.
    await settleRemovedRow(expect, page, 'i18n-languages-delete-uk')
    await expect(row.getByTestId('hilos-table-placeholder')).toBeVisible()
  } finally {
    await deleteLanguage('uk')
    // A fresh snapshot proves cleanup for the names-table empty-state spec that follows.
    await gotoPage(page, '/hilos/i18n/languages', PAGE_READY)
    await expect(
      page
        .getByTestId(/^hilos-table-row-/)
        .filter({ has: page.getByTestId('i18n-languages-link-uk') }),
    ).toHaveCount(0)
  }
})

test('explains a disabled trash through its accessible description and native question button', async ({
  page,
}) => {
  await signUpAdmin(page)
  try {
    await switchLanguageOff('es')
    await switchLocaleOn('es')
    await gotoPage(page, '/hilos/i18n/languages', PAGE_READY)
    const row = shownByTestId(page, /^hilos-table-row-/).filter({
      has: page.getByTestId('i18n-languages-delete-es'),
    })
    const opener = shownByTestId(page, 'i18n-languages-delete-es')
    const reason = row.getByTestId('i18n-languages-delete-reason-es')
    const why = row.getByTestId('hilos-action-reason')
    await expect(opener).toBeVisible()
    await expect(opener).toBeDisabled()
    await expect(opener).toHaveAccessibleName('Delete language Español')
    await expect(opener).toHaveAttribute(
      'aria-describedby',
      (await reason.getAttribute('id'))!,
    )
    await expect(opener).toHaveAccessibleDescription(
      'Cannot delete: the language has locales.',
    )
    await expect(reason).toHaveClass('visually-hidden')
    await expect(reason).not.toHaveAttribute('aria-hidden')
    await expect(reason).toHaveText('Cannot delete: the language has locales.')
    const descriptions = page.getByTestId('i18n-languages-delete-reason-es')
    await expect(descriptions).toHaveCount(2)
    const ids = await descriptions.evaluateAll((nodes) =>
      nodes.map((node) => node.id),
    )
    expect(new Set(ids).size).toBe(2)
    await expect(why).toHaveRole('button')
    await expect(why).toBeEnabled()
    await expect(why).toHaveAccessibleName('Why this action is unavailable')
    await expect(why).toHaveAttribute(
      'title',
      'Cannot delete: the language has locales.',
    )
    await expect(why.locator('i')).toHaveAttribute('aria-hidden', 'true')
    const modal = page.getByTestId('modal')
    const body = page.getByTestId('hilos-action-reason-body')
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
      await expect(body).toHaveText('Cannot delete: the language has locales.')
      await expect(page.getByTestId('i18n-language-delete-window')).toHaveCount(
        0,
      )
      await expect(
        page.getByTestId(/^hilos-toast-(success|info|error|warning)$/),
      ).toHaveCount(0)
      if (gesture === 'Enter') await modal.press('Escape')
      else await clickSubmit(page.getByTestId('hilos-action-reason-close'))
      await expect(modal).toHaveCount(0)
      await expect(why).toBeFocused()
      await expect(opener).toBeDisabled()
    }
    await expect(page.getByTestId('i18n-language-delete-window')).toHaveCount(0)
  } finally {
    await deleteLocale('es')
    await deleteLanguage('es')
    await gotoPage(page, '/hilos/i18n/languages', PAGE_READY)
    await expect(
      page
        .getByTestId(/^hilos-table-row-/)
        .filter({ has: page.getByTestId('i18n-languages-link-es') }),
    ).toHaveCount(0)
  }
})
