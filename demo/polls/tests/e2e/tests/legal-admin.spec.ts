import type { Page } from '@playwright/test'
import { expect, test } from '../test.js'

import { setAdminViewMode } from '../../../../../framework/frontend/scripts/adminViewMode.mjs'

import {
  PASSWORD,
  createTestUser,
  downloadBytes,
  shownByTestId,
  clickSubmit,
  typeInto,
  expectPageReady,
  gotoPage,
  PAGE_READY,
  PAGE_REFUSED,
  grantAdminToSelf,
  createTestAdmin,
  tableRowKeys,
  deliveryObject as object,
  watchDeliveryFrames,
} from '../../../../../framework/frontend/e2e/index.js'

// spec-owner: demo — Polls' published revision dates and historical demo-reset wording.

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

test('reads the legal catalog, deviations and revision comparison', async ({
  page,
}) => {
  const log = watchDeliveryFrames(page)
  await createTestAdmin(expect, page)
  const documentPath = '/hilos/legal/document/terms'
  const documentTabs = [
    {
      suffix: 'set',
      rows: {
        terms: [
          'standard.file_access',
          'standard.retention',
          'standard.moderation',
          'standard.availability',
          'standard.account_rules',
          'standard.ownership',
        ],
        privacy: [
          'standard.no_sale',
          'standard.deletion',
          'standard.export',
          'standard.passwords',
          'standard.access_log',
          'standard.session_data',
          'standard.breach_notice',
        ],
      },
      statement: {
        terms: 'Content is kept for 12 months.',
        privacy: 'Your data is not sold.',
      },
    },
    {
      suffix: 'deviations',
      rows: {
        terms: ['standard.availability'],
        privacy: ['standard.deletion'],
      },
      statement: {
        terms: 'This is a demo: its data may be wiped at any time',
        privacy:
          'Account deletion leaves numbered analytics events and network addresses',
      },
    },
    {
      suffix: 'revisions',
      rows: {
        terms: ['2026-10-07', '2026-10-01', '2026-09-17'],
        privacy: ['2026-10-09', '2026-09-17'],
      },
      statement: {
        terms: '2026-10-07',
        privacy: '2026-10-09',
      },
    },
  ]
  await gotoPage(expect, page, '/hilos/legal')
  // The Overview reads the summary the Polls server hands its root (HIL-1853).
  for (const document of ['terms', 'privacy']) {
    for (const count of ['window', 'lapsed']) {
      await expect(
        page
          .getByTestId(`legal-overview-${count}`)
          .locator(`[data-document="${document}"]`)
          .getByTestId(`legal-count-${count}`),
      ).toHaveText('0')
    }
  }
  // A lapsed count leads to the people past the deadline only when there are any (HIL-945).
  await expect(page.getByTestId('legal-count-lapsed-link')).toHaveCount(0)
  await expect(shownByTestId(page, 'legal-check-row')).toHaveCount(4)
  const documentsTab = page.getByTestId('hilos-page-tab-hilos_legal_documents')
  await clickSubmit(expect, documentsTab)
  await expect(page).toHaveURL('/hilos/legal/documents')
  await expectPageReady(expect, page)
  await expect(shownByTestId(page, 'legal-document-row')).toHaveCount(2)
  // The suite keeps earlier accounts; each document now has at least this administrator's acceptance.
  await expect(shownByTestId(page, 'legal-count-covered')).toHaveText([
    /^[1-9]\d*$/,
    /^[1-9]\d*$/,
  ])
  await expect(shownByTestId(page, 'legal-count-window')).toHaveText(['0', '0'])
  await expect(shownByTestId(page, 'legal-count-lapsed')).toHaveText(['0', '0'])
  await expect(page.getByTestId('legal-count-lapsed-link')).toHaveCount(0)
  const documentOpen = shownByTestId(page, 'legal-document-open').and(
    page.locator('[data-document="terms"]'),
  )
  await expect(documentOpen).toHaveAttribute('href', documentPath)
  await clickSubmit(expect, documentOpen)
  await expect(page).toHaveURL(documentPath)
  await expectPageReady(expect, page)
  await expect(page.getByRole('heading', { level: 1 })).toHaveCount(1)
  await expect(page.getByRole('heading', { level: 1 })).toHaveText('Terms')
  await expect(page.getByTestId('legal-document-key')).toHaveText('terms')
  await expect(page.getByTestId('legal-document-sources')).toContainText(
    'Demo\\Polls\\Legal\\PollsLegalCatalog',
  )
  for (const tab of documentTabs) {
    await test.step(`The ${tab.suffix} tab opens the Terms window`, async () => {
      const path = `${documentPath}/${tab.suffix}`
      const link = page.getByTestId(
        `hilos-page-tab-hilos_legal_document_${tab.suffix}`,
      )
      await expect(link).toHaveAttribute('href', path)
      await clickSubmit(expect, link)
      await expect(page).toHaveURL(path)
      await expectPageReady(expect, page)
      await expect(link).toHaveAttribute('aria-current', 'page')
      await expect(page.getByRole('heading', { level: 1 })).toHaveCount(1)
      await expect(page.getByRole('heading', { level: 1 })).toHaveText('Terms')
      await expect(page.getByTestId('legal-document-key')).toHaveText('terms')
      await expect.poll(() => tableRowKeys(page)).toEqual(tab.rows.terms)
      await expect(page.getByRole('table')).toContainText(tab.statement.terms)
      if (tab.suffix === 'set') {
        await expect(page.getByTestId('legal-set-metadata')).toContainText(
          'Set 1 from 2026-09-17',
        )
      }
    })
  }
  const revisionPath = '/hilos/legal/document/terms/revision/2026-10-01'
  const revisionTextPath = `${revisionPath}/text`
  const revisionOpen = shownByTestId(page, 'legal-revision-open').and(
    page.locator('[data-revision="2026-10-01"]'),
  )
  await expect(revisionOpen).toHaveAttribute('href', revisionPath)
  await clickSubmit(expect, revisionOpen)
  await expect(page).toHaveURL(revisionPath)
  await expectPageReady(expect, page)
  const openingReply = () =>
    log.packets
      .flatMap(({ frames }) => frames)
      .find(({ wire }) => {
        const frame = object(wire)
        return (
          frame.type === 'page_response' &&
          object(frame.data).page === 'hilos_legal_revision'
        )
      })
  await expect.poll(openingReply).toBeDefined()
  const opening = openingReply()!
  expect(opening.failure).toBeUndefined()
  const payload = object(object(object(opening.wire).data).payload)
  const exactWindow = object(object(payload.windows).hilosLegalRevisions)
  expect(exactWindow.totalCount).toBe(1)
  const rows = exactWindow.rows as unknown[]
  expect(rows).toHaveLength(1)
  expect(object(rows[0]).rowKey).toBe('2026-10-01')
  const acceptedCount = object(
    object(object(rows[0]).slots).revision,
  ).acceptedCount
  expect(acceptedCount).toEqual(expect.any(Number))

  const expectRevisionIdentity = async () => {
    await expect(page.getByRole('heading', { level: 1 })).toHaveCount(1)
    await expect(page.getByRole('heading', { level: 1 })).toHaveText(
      'Revision of 2026-10-01',
    )
    await expect(page).toHaveTitle('Revision of 2026-10-01 · Hilos Polls')
    await expect(page.getByTestId('legal-revision-metadata')).toHaveText(
      'Terms · Published 2026-10-01 · Effective 2026-10-01 · Set 1',
    )
    await expect(page.getByTestId('legal-revision-significance')).toHaveText(
      'substantial',
    )
    await expect(page.getByTestId('legal-revision-current')).toHaveCount(0)
  }
  const expectRevisionOverview = async () => {
    await expectRevisionIdentity()
    await expect(
      page.getByTestId('hilos-page-tab-hilos_legal_revision'),
    ).toHaveAttribute('aria-current', 'page')
    await expect(page.getByTestId('legal-revision-text')).toHaveCount(0)
    const comparison = page.getByTestId('legal-changes-wide')
    await expect(comparison.getByTestId('legal-change-row')).toHaveCount(1)
    await expect(comparison).toContainText('Before — revision 2026-09-17')
    await expect(comparison).toContainText('After — revision 2026-10-01')
    await expect(comparison).toContainText(
      'The service is provided as is, with no uptime promise.',
    )
    await expect(comparison).toContainText(
      'This installation is a demonstration of the framework, and it is reset without notice',
    )
    await expect(page.getByTestId('legal-revision-accepted')).toHaveText(
      `${acceptedCount} acceptances`,
    )
  }
  const expectRevisionText = async () => {
    await expectRevisionIdentity()
    await expect(
      page.getByTestId('hilos-page-tab-hilos_legal_revision_text'),
    ).toHaveAttribute('aria-current', 'page')
    const text = page.getByTestId('legal-revision-text')
    await expect(text).toBeVisible()
    const clauses = text.getByTestId('legal-revision-clause')
    await expect(clauses).toHaveCount(6)
    await expect(clauses.nth(1)).toContainText('Content is kept for 12 months.')
    // Availability replaces its fourth standard clause; it is not appended.
    await expect(clauses.nth(3)).toContainText(
      'This installation is a demonstration of the framework, and it is reset without notice',
    )
    await expect(
      clauses.nth(3).getByTestId('legal-revision-clause-deviation'),
    ).toHaveText(
      'Project deviation from: The service is provided as is, with no uptime promise. · stricter',
    )
    await expect(text).not.toContainText(
      'This installation only demonstrates the framework and is reset without warning',
    )
  }
  await expectRevisionOverview()
  await test.step('Back and Forward restore the document pages and their data', async () => {
    for (const tab of [...documentTabs].reverse()) {
      await page.goBack()
      await expect(page).toHaveURL(`${documentPath}/${tab.suffix}`)
      await expectPageReady(expect, page)
      await expect(page.getByTestId('legal-document-key')).toHaveText('terms')
      await expect(
        page.getByTestId(`hilos-page-tab-hilos_legal_document_${tab.suffix}`),
      ).toHaveAttribute('aria-current', 'page')
      await expect.poll(() => tableRowKeys(page)).toEqual(tab.rows.terms)
      await expect(page.getByRole('table')).toContainText(tab.statement.terms)
    }
    await page.goBack()
    await expect(page).toHaveURL(documentPath)
    await expectPageReady(expect, page)
    await expect(
      page.getByTestId('hilos-page-tab-hilos_legal_document'),
    ).toHaveAttribute('aria-current', 'page')
    await expect(page.getByTestId('legal-document-sources')).toContainText(
      'Demo\\Polls\\Legal\\PollsLegalCatalog',
    )
    for (const tab of documentTabs) {
      await page.goForward()
      await expect(page).toHaveURL(`${documentPath}/${tab.suffix}`)
      await expectPageReady(expect, page)
      await expect(page.getByTestId('legal-document-key')).toHaveText('terms')
      await expect(
        page.getByTestId(`hilos-page-tab-hilos_legal_document_${tab.suffix}`),
      ).toHaveAttribute('aria-current', 'page')
      await expect.poll(() => tableRowKeys(page)).toEqual(tab.rows.terms)
      await expect(page.getByRole('table')).toContainText(tab.statement.terms)
    }
    await page.goForward()
    await expect(page).toHaveURL(revisionPath)
    await expectPageReady(expect, page)
    await expectRevisionOverview()
  })
  await test.step('Text opens the selected historical revision by keyboard and survives Back and Forward', async () => {
    const textLink = page.getByTestId(
      'hilos-page-tab-hilos_legal_revision_text',
    )
    await expect(textLink).toHaveAttribute('href', revisionTextPath)
    await expect(textLink).toHaveAccessibleName('Text')
    await textLink.focus()
    await expect(textLink).toBeFocused()
    await textLink.press('Enter')
    await expect(page).toHaveURL(revisionTextPath)
    await expectPageReady(expect, page)
    await expectRevisionText()
    await page.goBack()
    await expect(page).toHaveURL(revisionPath)
    await expectPageReady(expect, page)
    await expectRevisionOverview()
    await page.goForward()
    await expect(page).toHaveURL(revisionTextPath)
    await expectPageReady(expect, page)
    await expectRevisionText()
  })
  await test.step('Document and revision addresses support direct entry and F5', async () => {
    await gotoPage(expect, page, revisionPath, PAGE_READY)
    await expectRevisionOverview()
    await page.reload()
    await expectPageReady(expect, page)
    await expectRevisionOverview()
    await gotoPage(expect, page, revisionTextPath, PAGE_READY)
    await expect(page).toHaveURL(revisionTextPath)
    await expectRevisionText()
    await page.reload()
    await expectPageReady(expect, page)
    await expect(page).toHaveURL(revisionTextPath)
    await expectRevisionText()
    await gotoPage(expect, page, documentPath, PAGE_READY)
    await expect(page.getByTestId('legal-document-key')).toHaveText('terms')
    await expect(page.getByTestId('legal-document-sources')).toContainText(
      'Demo\\Polls\\Legal\\PollsLegalCatalog',
    )
    await page.reload()
    await expectPageReady(expect, page)
    await expect(page.getByTestId('legal-document-key')).toHaveText('terms')
    await expect(page.getByTestId('legal-document-sources')).toContainText(
      'Demo\\Polls\\Legal\\PollsLegalCatalog',
    )
  })
  for (const tab of documentTabs) {
    await test.step(`Direct entry and F5 load the Privacy ${tab.suffix} window`, async () => {
      const path = `/hilos/legal/document/privacy/${tab.suffix}`
      await gotoPage(expect, page, path, PAGE_READY)
      await expect(page).toHaveURL(path)
      await expect(page.getByRole('heading', { level: 1 })).toHaveCount(1)
      await expect(page.getByRole('heading', { level: 1 })).toHaveText(
        'Privacy policy',
      )
      await expect(page.getByTestId('legal-document-key')).toHaveText('privacy')
      await expect(
        page.getByTestId(`hilos-page-tab-hilos_legal_document_${tab.suffix}`),
      ).toHaveAttribute('aria-current', 'page')
      await expect.poll(() => tableRowKeys(page)).toEqual(tab.rows.privacy)
      await expect(page.getByRole('table')).toContainText(tab.statement.privacy)
      await page.reload()
      await expectPageReady(expect, page)
      await expect(page).toHaveURL(path)
      await expect(page.getByRole('heading', { level: 1 })).toHaveCount(1)
      await expect(page.getByRole('heading', { level: 1 })).toHaveText(
        'Privacy policy',
      )
      await expect(page.getByTestId('legal-document-key')).toHaveText('privacy')
      await expect(
        page.getByTestId(`hilos-page-tab-hilos_legal_document_${tab.suffix}`),
      ).toHaveAttribute('aria-current', 'page')
      await expect.poll(() => tableRowKeys(page)).toEqual(tab.rows.privacy)
      await expect(page.getByRole('table')).toContainText(tab.statement.privacy)
    })
  }
  await test.step('Documents supports direct entry and F5', async () => {
    await gotoPage(expect, page, '/hilos/legal/documents', PAGE_READY)
    await expect(shownByTestId(page, 'legal-document-row')).toHaveCount(2)
    await page.reload()
    await expectPageReady(expect, page)
    await expect(shownByTestId(page, 'legal-document-row')).toHaveCount(2)
  })
})

/** Edits one legal setting through its page, leaving a no-op through Cancel. */
async function setLegalSetting(
  page: Page,
  key: string,
  value: string,
): Promise<void> {
  await gotoPage(expect, page, '/hilos/legal/settings')
  await clickSubmit(expect, shownByTestId(page, `legal-setting-edit-${key}`))
  const input = page.getByTestId('legal-setting-input')
  await expect(input).toBeVisible()
  if ((await input.inputValue()) === value) {
    await clickSubmit(expect, page.getByTestId('legal-setting-cancel'))
  } else {
    await input.selectOption(value)
    await clickSubmit(expect, page.getByTestId('legal-setting-save'))
  }
  await expect(input).toBeHidden()
}

test('offers complete acceptance filters and merges legal setting changes across tabs', async ({
  page,
}) => {
  const userId = await createTestAdmin(expect, page)
  await gotoPage(expect, page, '/hilos/legal/acceptances')
  const table = page.getByTestId('legal-acceptances-table')
  await expect(
    shownByTestId(table, 'legal-acceptance-person').and(
      page.locator(`[href="/hilos/user/${userId}"]`),
    ),
  ).toHaveCount(2)
  const documentFilter = table.getByTestId('hilos-table-filter-document')
  await clickSubmit(expect, documentFilter.getByTestId('hilos-dropdown-toggle'))
  await expect(
    documentFilter.getByTestId('hilos-dropdown-option-0'),
  ).toContainText('Terms')
  await expect(
    documentFilter.getByTestId('hilos-dropdown-option-1'),
  ).toContainText('Privacy policy')
  await clickSubmit(
    expect,
    documentFilter.getByTestId('hilos-dropdown-option-0'),
  )
  const revisionFilter = table.getByTestId('hilos-table-filter-revision')
  await clickSubmit(expect, revisionFilter.getByTestId('hilos-dropdown-toggle'))
  await expect(
    revisionFilter.getByTestId('hilos-dropdown-option-0'),
  ).toContainText('2026-10-07')
  await clickSubmit(expect, revisionFilter.getByTestId('hilos-dropdown-toggle'))

  const other = await page.context().newPage()
  try {
    await gotoPage(expect, page, '/hilos/legal/settings')
    await clickSubmit(
      expect,
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
    await clickSubmit(expect, page.getByTestId('legal-setting-cancel'))
    await page.reload()
    await expectPageReady(expect, page)
    await expect(
      shownByTestId(page, 'legal-setting-value-legal.consent_form'),
    ).toHaveText('Line below the button')
    await setLegalSetting(page, 'legal.refusal_after_deadline', 'remind')
    await expect(
      shownByTestId(other, 'legal-setting-value-legal.refusal_after_deadline'),
    ).toHaveText('Keep reminding')
    await clickSubmit(
      expect,
      shownByTestId(page, 'legal-setting-edit-legal.consent_form'),
    )
    await expect(page.getByTestId('legal-setting-input')).toHaveValue('line')
    await expect(page.getByTestId('legal-setting-save')).toBeDisabled()
    await clickSubmit(expect, page.getByTestId('legal-setting-cancel'))
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
  await grantAdminToSelf(expect, page)
  await gotoPage(expect, page, '/hilos/legal/document/terms')
  await clickSubmit(expect, page.getByTestId('legal-preview-reconsent'))
  const preview = page.getByTestId('legal-reconsent-preview')
  await expect(preview).toBeVisible()
  await expect(preview.getByTestId('legal-reconsent-badge')).toHaveText(
    'Editorial · nobody is asked to accept it',
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
  await clickSubmit(expect, page.getByTestId('legal-reconsent-preview-close'))
  await expect(preview).toBeHidden()

  await gotoPage(expect, page, '/hilos/legal/document/privacy')
  await clickSubmit(expect, page.getByTestId('legal-preview-reconsent'))
  const privacyPreview = page.getByTestId('legal-reconsent-preview')
  await expect(privacyPreview.getByTestId('legal-reconsent-badge')).toHaveText(
    'No window · in force since 9 October 2026',
  )
  await expect(
    privacyPreview.getByTestId('legal-reconsent-change'),
  ).toHaveCount(1)
  await expect(
    privacyPreview.locator(
      '[data-id="legal-reconsent-change"][data-clause="standard.deletion"]',
    ),
  ).toContainText(
    'Account deletion leaves numbered analytics events and network addresses',
  )
  await clickSubmit(expect, page.getByTestId('legal-reconsent-preview-close'))
})

test('exports the acceptance records the table shows, confirming once', async ({
  page,
}) => {
  const admin = await createTestUser(expect, page)
  await grantAdminToSelf(expect, page)
  await expect(page.getByTestId('nav-admin')).toHaveAttribute(
    'data-access',
    'full',
  )
  await gotoPage(expect, page, '/hilos/legal/acceptances')

  await clickSubmit(expect, page.getByTestId('legal-acceptances-export'))
  await typeInto(page.getByTestId('step-up-password'), PASSWORD)
  await clickSubmit(
    expect,
    page.getByTestId('legal-acceptances-export-confirm'),
  )
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
  await clickSubmit(expect, documentFilter.getByTestId('hilos-dropdown-toggle'))
  await clickSubmit(
    expect,
    documentFilter.getByTestId('hilos-dropdown-option-0'),
  )
  // The confirmation is still alive, so the second order leaves without a step.
  await clickSubmit(expect, page.getByTestId('legal-acceptances-export'))
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
  await grantAdminToSelf(expect, page)
  for (const path of [
    '/hilos/legal/document/missing-document',
    '/hilos/legal/document/terms/revision/missing-revision',
  ]) {
    await gotoPage(expect, page, path, PAGE_REFUSED)
    await expect(page.getByTestId('page-error')).toHaveAttribute(
      'data-error-code',
      '404',
    )
  }
})

test.describe('in the admin view mode', () => {
  test.afterEach(() => setAdminViewMode(false))

  // A viewer has no export of their own, and the order is an action like any other (HIL-1234).
  test('a guest sees the export of acceptances switched off', async ({
    page,
  }) => {
    await setAdminViewMode(true)
    await gotoPage(expect, page, '/hilos/legal/acceptances', PAGE_READY)
    const exportButton = page.getByTestId('legal-acceptances-export')
    await expect(exportButton).toBeDisabled()
    await expect(exportButton).toHaveAttribute(
      'aria-describedby',
      /(^| )hilos-view-mode-strip-text( |$)/,
    )
  })
})
