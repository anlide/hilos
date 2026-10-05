import { expect, test, type Page } from '@playwright/test'
import { setAdmin, signUpAdmin } from '../helpers/adminGrant.js'
import { setAdminViewMode } from '../helpers/adminViewMode.js'
import {
  expectPageReady,
  gotoPage,
  PAGE_READY,
  PAGE_REFUSED,
} from '../helpers/page.js'
import { clickSubmit, PASSWORD, signUp, typeInto } from '../helpers/session.js'
import {
  downloadBytes,
  shownByTestId,
  sidewaysOverflow,
} from '../../../../../framework/frontend/e2e/index.js'

/** The header line of a file of acceptance records (HIL-1234). */
const EXPORT_HEADER = 'user_id,name,email,document,revision,in_code,accepted_at'

/**
 * Downloads the ready export and returns the lines of one person, the file's own shape checked on the way.
 * @param page The administrator's page with a ready export.
 * @param userId The person whose lines are kept.
 */
async function exportedLinesOf(page: Page, userId: number): Promise<string[]> {
  const { filename, bytes } = await downloadBytes(
    page,
    page.getByTestId('legal-acceptances-export-download'),
  )
  expect(filename).toMatch(/^legal-acceptances-\d{4}-\d{2}-\d{2}\.csv$/)
  expect([...bytes.subarray(0, 3)]).toEqual([0xef, 0xbb, 0xbf])
  const lines = bytes.subarray(3).toString('utf8').split('\r\n')
  expect(lines[0]).toBe(EXPORT_HEADER)

  return lines.filter((line) => line.startsWith(`${userId},`))
}

test('reads the legal catalog, deviations and revision comparison at desktop and mobile widths', async ({
  page,
}) => {
  await signUpAdmin(page)
  await gotoPage(page, '/hilos/legal')
  await expect(shownByTestId(page, 'legal-document-row')).toHaveCount(2)
  // The suite keeps earlier accounts; each document now has at least this administrator's acceptance.
  await expect(shownByTestId(page, 'legal-count-covered')).toHaveText([
    /^[1-9]\d*$/,
    /^[1-9]\d*$/,
  ])
  await expect(shownByTestId(page, 'legal-count-window')).toHaveText(['0', '0'])
  await expect(shownByTestId(page, 'legal-count-lapsed')).toHaveText(['0', '0'])
  // A lapsed count leads to the people past the deadline only when there are any (HIL-945).
  await expect(page.getByTestId('legal-count-lapsed-link')).toHaveCount(0)
  await expect(shownByTestId(page, 'legal-check-row')).toHaveCount(4)
  await clickSubmit(
    shownByTestId(page, 'legal-document-open').and(
      page.locator('[data-document="terms"]'),
    ),
  )
  await expect(page.getByTestId('legal-set')).toContainText(
    'Hilos standard set 1',
  )
  // The third terms revision adds the demo's availability clause (HIL-500).
  await expect(page.getByTestId('legal-deviation-row')).toHaveCount(4)
  await expect(shownByTestId(page, 'legal-revision-row')).toHaveCount(3)
  await clickSubmit(
    shownByTestId(page, 'legal-revision-open').and(
      page.locator('[data-revision="2026-10-01"]'),
    ),
  )
  await expect(page.getByTestId('legal-revision-text')).toBeVisible()
  await expect(page.getByTestId('legal-revision-clause')).toHaveCount(6)
  await expect(
    page.getByTestId('legal-changes-wide').getByTestId('legal-change-row'),
  ).toHaveCount(1)
  await expect(page.getByTestId('legal-revision-accepted')).toHaveText(
    /^[1-9]\d* acceptances$/,
  )

  await page.setViewportSize({ width: 375, height: 812 })
  await expect(page.getByTestId('legal-changes-narrow')).toBeVisible()
  await expect(page.getByTestId('legal-changes-wide')).toBeHidden()
  expect(await sidewaysOverflow(page)).toEqual([0, 0])
  await gotoPage(page, '/hilos/legal/terms')
  await expect(page.getByTestId('legal-set')).toBeVisible()
  await expect(shownByTestId(page, 'legal-revision-row')).toHaveCount(3)
  expect(await sidewaysOverflow(page)).toEqual([0, 0])
  await gotoPage(page, '/hilos/legal')
  await expect(shownByTestId(page, 'legal-document-row')).toHaveCount(2)
  expect(await sidewaysOverflow(page)).toEqual([0, 0])
})

/** Edits one legal setting through its page, leaving a no-op through Cancel. */
async function setLegalSetting(
  page: Page,
  key: string,
  value: string,
): Promise<void> {
  await gotoPage(page, '/hilos/legal/settings')
  await clickSubmit(shownByTestId(page, `legal-setting-edit-${key}`))
  const input = page.getByTestId('legal-setting-input')
  await expect(input).toBeVisible()
  if ((await input.inputValue()) === value) {
    await clickSubmit(page.getByTestId('legal-setting-cancel'))
  } else {
    await input.selectOption(value)
    await clickSubmit(page.getByTestId('legal-setting-save'))
  }
  await expect(input).toBeHidden()
}

test('offers complete acceptance filters and merges legal setting changes across tabs', async ({
  page,
}) => {
  const userId = await signUpAdmin(page)
  await gotoPage(page, '/hilos/legal/acceptances')
  const table = page.getByTestId('legal-acceptances-table')
  await expect(
    shownByTestId(table, 'legal-acceptance-person').and(
      page.locator(`[href="/hilos/user/${userId}"]`),
    ),
  ).toHaveCount(2)
  const documentFilter = table.getByTestId('hilos-table-filter-document')
  await clickSubmit(documentFilter.getByTestId('hilos-dropdown-toggle'))
  await expect(
    documentFilter.getByTestId('hilos-dropdown-option-0'),
  ).toContainText('Terms')
  await expect(
    documentFilter.getByTestId('hilos-dropdown-option-1'),
  ).toContainText('Privacy policy')
  await clickSubmit(documentFilter.getByTestId('hilos-dropdown-option-0'))
  const revisionFilter = table.getByTestId('hilos-table-filter-revision')
  await clickSubmit(revisionFilter.getByTestId('hilos-dropdown-toggle'))
  await expect(
    revisionFilter.getByTestId('hilos-dropdown-option-0'),
  ).toContainText('2026-10-01')
  await clickSubmit(revisionFilter.getByTestId('hilos-dropdown-toggle'))

  const other = await page.context().newPage()
  try {
    await gotoPage(page, '/hilos/legal/settings')
    await clickSubmit(
      shownByTestId(page, 'legal-setting-edit-legal.consent_form'),
    )
    await expect(page.getByTestId('legal-setting-input')).toHaveValue(
      'checkbox',
    )
    await expect(page.getByTestId('legal-setting-save')).toBeDisabled()
    await setLegalSetting(other, 'legal.consent_form', 'line')
    await expect(page.getByTestId('legal-setting-input')).toHaveValue('line')
    await expect(page.getByTestId('legal-setting-notice')).toContainText(
      'Updated just now',
    )
    await expect(page.getByTestId('legal-setting-save')).toBeDisabled()
    await clickSubmit(page.getByTestId('legal-setting-cancel'))
    await page.reload()
    await expectPageReady(page)
    await expect(
      shownByTestId(page, 'legal-setting-value-legal.consent_form'),
    ).toHaveText('Line below the button')
    await setLegalSetting(page, 'legal.refusal_after_deadline', 'remind')
    await expect(
      shownByTestId(other, 'legal-setting-value-legal.refusal_after_deadline'),
    ).toHaveText('Keep reminding')
    await page.setViewportSize({ width: 375, height: 812 })
    await clickSubmit(
      shownByTestId(page, 'legal-setting-edit-legal.consent_form'),
    )
    await expect(page.getByTestId('legal-setting-input')).toHaveValue('line')
    await expect(page.getByTestId('legal-setting-save')).toBeDisabled()
    expect(await sidewaysOverflow(page)).toEqual([0, 0])
    await clickSubmit(page.getByTestId('legal-setting-cancel'))
  } finally {
    const cleanup = await page.context().newPage()
    try {
      await setLegalSetting(cleanup, 'legal.consent_form', 'checkbox')
      await setLegalSetting(cleanup, 'legal.refusal_after_deadline', 'freeze')
    } finally {
      await cleanup.close()
      await other.close()
    }
  }
})

test("previews the re-consent screen through the previous revision's holder", async ({
  page,
}) => {
  await signUpAdmin(page)
  await gotoPage(page, '/hilos/legal/terms')
  await clickSubmit(page.getByTestId('legal-preview-reconsent'))
  const preview = page.getByTestId('legal-reconsent-preview')
  await expect(preview).toBeVisible()
  // The third terms revision took effect the day it was published: no window (HIL-500).
  await expect(preview.getByTestId('legal-reconsent-badge')).toHaveText(
    /No window · in force since 1 October 2026/,
  )
  const change = preview.getByTestId('legal-reconsent-change')
  await expect(change).toHaveCount(1)
  await expect(change).toHaveAttribute('data-clause', 'standard.availability')
  await expect(change.getByTestId('legal-reconsent-change-kind')).toHaveText(
    'Changed',
  )
  await expect(change).toContainText(
    'This is a demo: its data may be wiped at any time',
  )
  await expect(preview.getByTestId('legal-reconsent-accept')).toBeDisabled()
  await expect(preview.getByTestId('legal-reconsent-later')).toBeDisabled()
  await clickSubmit(page.getByTestId('legal-reconsent-preview-close'))
  await expect(preview).toBeHidden()

  await gotoPage(page, '/hilos/legal/privacy')
  await clickSubmit(page.getByTestId('legal-preview-reconsent'))
  const privacyPreview = page.getByTestId('legal-reconsent-preview')
  await expect(privacyPreview.getByTestId('legal-reconsent-badge')).toHaveText(
    'No window · in force since 5 October 2026',
  )
  await expect(
    privacyPreview.getByTestId('legal-reconsent-change'),
  ).toHaveCount(2)
  await expect(
    privacyPreview.locator(
      '[data-id="legal-reconsent-change"][data-clause="standard.deletion"]',
    ),
  ).toContainText('Account deletion leaves numbered analytics events')
  await expect(
    privacyPreview.locator(
      '[data-id="legal-reconsent-change"][data-clause="standard.access_log"]',
    ),
  ).toContainText('The separate access log is disabled')
  await clickSubmit(page.getByTestId('legal-reconsent-preview-close'))
})

// An administrator takes the records the table shows away as a file (HIL-1234): a new
// administrator has two records of their own - the registration accepted Terms and the
// privacy policy - and the second export, under the Terms filter, keeps one of them.
test('exports the acceptance records the table shows, confirming once', async ({
  page,
}) => {
  const admin = await signUp(page)
  await setAdmin(admin.userId, true)
  await expect(page.getByTestId('nav-admin')).toHaveAttribute(
    'data-access',
    'full',
  )
  await gotoPage(page, '/hilos/legal/acceptances')

  await clickSubmit(page.getByTestId('legal-acceptances-export'))
  await typeInto(page.getByTestId('step-up-password'), PASSWORD)
  await clickSubmit(page.getByTestId('legal-acceptances-export-confirm'))
  await expect(page.getByTestId('legal-acceptances-export-ready')).toBeVisible()
  await expect(page.getByTestId('legal-acceptances-export-filter')).toHaveText(
    'All records',
  )
  const all = await exportedLinesOf(page, admin.userId)
  expect(all.map((line) => line.split(',')[3]).sort()).toEqual([
    'privacy',
    'terms',
  ])
  for (const line of all) {
    expect(line.split(',').slice(1, 3)).toEqual([admin.name, admin.email])
  }

  const documentFilter = page
    .getByTestId('legal-acceptances-table')
    .getByTestId('hilos-table-filter-document')
  await clickSubmit(documentFilter.getByTestId('hilos-dropdown-toggle'))
  await clickSubmit(documentFilter.getByTestId('hilos-dropdown-option-0'))
  // The confirmation is still alive, so the second order leaves without a step.
  await clickSubmit(page.getByTestId('legal-acceptances-export'))
  await expect(page.getByTestId('legal-acceptances-export-filter')).toHaveText(
    'Terms',
  )
  await expect(page.getByTestId('legal-acceptances-export-ready')).toBeVisible()
  const terms = await exportedLinesOf(page, admin.userId)
  expect(terms).toHaveLength(1)
  expect(terms[0].split(',')[3]).toBe('terms')
})

test('unknown legal documents and revisions are not-found subscription refusals', async ({
  page,
}) => {
  await signUpAdmin(page)
  for (const path of [
    '/hilos/legal/missing-document',
    '/hilos/legal/terms/missing-revision',
  ]) {
    await gotoPage(page, path, PAGE_REFUSED)
    await expect(page.getByTestId('page-error')).toHaveAttribute(
      'data-error-code',
      '404',
    )
  }
})

test.describe('in the admin view mode', () => {
  test.afterEach(() => setAdminViewMode(false))

  // The value of a setting is kept from a viewer (HIL-1258), so the window shows
  // the hidden mark in place of the list and holds no draft (HIL-1260).
  test('a guest opens a legal setting, reads its value as hidden and has nothing to save it with', async ({
    page,
  }) => {
    await setAdminViewMode(true)
    await gotoPage(page, '/hilos/legal/settings', PAGE_READY)
    await expect(
      shownByTestId(page, 'legal-setting-value-legal.consent_form').getByTestId(
        'hilos-hidden',
      ),
    ).toHaveText('Hidden')
    await clickSubmit(
      shownByTestId(page, 'legal-setting-edit-legal.consent_form'),
    )
    const dialog = page.getByTestId('modal')
    await expect(dialog.getByTestId('hilos-hidden')).toHaveText('Hidden')
    await expect(page.getByTestId('legal-setting-input')).toHaveCount(0)
    const save = page.getByTestId('legal-setting-save')
    await expect(save).toBeDisabled()
    await expect(save).toHaveAttribute(
      'aria-describedby',
      /(^| )hilos-view-mode-strip-text( |$)/,
    )
    await clickSubmit(page.getByTestId('legal-setting-cancel'))
    await expect(dialog).toBeHidden()
  })

  // A viewer has no export of their own, and the order is an action like any other (HIL-1234).
  test('a guest sees the export of acceptances switched off', async ({
    page,
  }) => {
    await setAdminViewMode(true)
    await gotoPage(page, '/hilos/legal/acceptances', PAGE_READY)
    const exportButton = page.getByTestId('legal-acceptances-export')
    await expect(exportButton).toBeDisabled()
    await expect(exportButton).toHaveAttribute(
      'aria-describedby',
      /(^| )hilos-view-mode-strip-text( |$)/,
    )
  })
})
