import { type HilosLegalConsentTerms } from '../../src/legal/legalConsent.js'

/** Current documents for tests of the consent lifecycle, independent of clause rendering. */
export function consentTerms(
  revision = 'terms-v1',
  form: HilosLegalConsentTerms['form'] = 'checkbox',
): HilosLegalConsentTerms {
  return {
    form,
    documents: (['terms', 'privacy'] as const).map((document) => ({
      document,
      revision: {
        revisionId: document === 'terms' ? revision : 'privacy-v1',
        publishedOn: '2026-09-17',
        effectiveOn: '2026-09-17',
        significance: 'substantial',
        setVersion: 1,
        deviationCount: 0,
      },
      clauses: [],
    })),
  }
}

/** All thirteen clause slots, with chat's three stricter and one looser deviations. */
export function consentTermsWithClauses(): HilosLegalConsentTerms {
  const terms = consentTerms()
  const keys = [
    [
      'file_access',
      'retention',
      'moderation',
      'availability',
      'account_rules',
      'ownership',
    ],
    [
      'no_sale',
      'deletion',
      'export',
      'passwords',
      'access_log',
      'session_data',
      'breach_notice',
    ],
  ]
  terms.documents.forEach((document, documentIndex) => {
    document.clauses = keys[documentIndex]!.map((key, index) => {
      const deviating =
        documentIndex === 0 ? [1, 2, 4].includes(index) : index === 0
      return {
        clauseKey: `standard.${key}`,
        standardStatement: `Standard ${key}`,
        source: deviating ? 'deviation' : 'standard',
        direction: deviating
          ? documentIndex === 0
            ? 'stricter'
            : 'looser'
          : null,
        statement: `${deviating ? 'Project' : 'Standard'} ${key}`,
        text: `Full text of ${key}.`,
      }
    })
  })
  return terms
}
